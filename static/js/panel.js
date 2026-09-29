/**
 * AI Assistant (Beta) — the builder panel.
 *
 * Sends the tree the person has OPEN to /panel/run, and applies the tree that comes back as ONE
 * step on the host's own undo history. Nothing is saved until the person presses Update / Save.
 *
 * Hosts:
 *   builder — the backend page builder. The Backbone builder instance is captured from the
 *             `fw:option-type:builder:init` event (this script loads in the head so it is bound
 *             first); a whole-tree replace is rootItems.reset() + one `builder:change`, which the
 *             builder's history records as a single undo step (same path the Templates loader uses).
 *   live    — the Live Page Editor: window.fwLiveEditor.model, replaced the way its own
 *             "restore revision" does (recordHistory → model → rebuildIndex → markDirty → render).
 *   site    — every other admin screen: the site-wide assistant. No tree; it works on the real site
 *             and reports each change it made with a link. Opened from the ✦ AI Assistant admin-bar
 *             item (which opens the page panel on builder screens).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.upwAiPanel;
	if ( ! cfg ) {
		return;
	}
	var l = cfg.l10n || {};
	var builder = null;

	// Capture the page builder as soon as it initialises (event bubbles to document).
	$( document ).on( 'fw:option-type:builder:init', function ( e, data ) {
		if ( data && data.builder && data.builder.get && data.builder.get( 'type' ) === 'page-builder' ) {
			builder = data.builder;
			mount();
		}
	} );

	/* ------------------------------------------------------------------ *
	 * Host adapters
	 * ------------------------------------------------------------------ */

	var isSite = cfg.host === 'site';
	if ( isSite ) {
		l.title = l.siteTitle || l.title;
		l.placeholder = l.sitePlaceholder || l.placeholder;
		l.starters = l.siteStarters || l.starters;
	}
	// Suggestions chosen for this screen / page from its real state (FW_AI_Context) win over the generic ones.
	if ( cfg.ideas && cfg.ideas.length ) {
		l.starters = cfg.ideas;
	}
	var saved = ( cfg.history && cfg.history.saved ) || [];

	var host = {
		ready: function () {
			if ( isSite ) { return true; }
			return cfg.host === 'live' ? !! window.fwLiveEditor : !! builder;
		},
		getTree: function () {
			var src = cfg.host === 'live' ? window.fwLiveEditor.model : builder.rootItems;
			return JSON.parse( JSON.stringify( src || [] ) );
		},
		setTree: function ( tree ) {
			if ( cfg.host === 'live' ) {
				var le = window.fwLiveEditor;
				le.recordHistory();
				le.model = tree;
				le.rebuildIndex();
				le.markDirty();
				le.refreshNavigator();
				if ( le.frameReady ) {
					le.renderPageToCanvas();
				} else {
					le.pendingRender = true;
				}
				return;
			}
			builder.rootItems.reset( tree );
			// reset() alone does not reach the hidden input for a flat tree; one change event
			// writes it (debounced) and becomes a single entry on the builder's Undo history.
			builder.rootItems.trigger( 'builder:change' );
		}
	};

	/* ------------------------------------------------------------------ *
	 * UI
	 * ------------------------------------------------------------------ */

	var $root, $log, $input, $send, $focus, history = [], busy = false, mounted = false;

	// The element the Live Editor asked us to focus on (its "Ask AI" button):
	// { id, label, type, path }. Pre-attached to each request so "this"/"here"
	// mean that item. Persists across messages until cleared or replaced.
	var focusItem = null;

	function esc( s ) {
		// .text().html() escapes < > & but not quotes, and esc() also feeds attribute values.
		return $( '<div>' ).text( String( s == null ? '' : s ) ).html().replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	/**
	 * A small, SAFE markdown subset for model replies: the text is escaped first, then only
	 * **bold**, *italic*, `code`, [text](http… or /…) links, "- " and "1." lists, "#" headings and
	 * pipe tables are turned into markup.
	 */
	function md( text ) {
		var out = [], list = null, table = null;
		var inline = function ( t ) {
			return esc( t )
				.replace( /`([^`]+)`/g, '<code>$1</code>' )
				.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' )
				.replace( /(^|[^*\w])\*(?!\s)([^*]+?)\*(?!\*)/g, '$1<em>$2</em>' )
				.replace( /\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/g, function ( m, label, url ) {
					return '<a href="' + url.replace( /"/g, '&quot;' ) + '" target="_blank" rel="noopener">' + label + '</a>';
				} );
		};
		var cells = function ( line ) {
			return line.trim().replace( /^\|/, '' ).replace( /\|$/, '' ).split( '|' ).map( function ( c ) { return c.trim(); } );
		};
		var flushList = function () {
			if ( list ) { out.push( '<' + list.tag + ' class="upw-aip__md-list">' + list.items.join( '' ) + '</' + list.tag + '>' ); list = null; }
		};
		var flushTable = function () {
			if ( ! table ) { return; }
			var head = table.shift();
			out.push( '<div class="upw-aip__md-table"><table><thead><tr>' + head.map( function ( c ) { return '<th>' + inline( c ) + '</th>'; } ).join( '' ) + '</tr></thead><tbody>' +
				table.map( function ( r ) { return '<tr>' + r.map( function ( c ) { return '<td>' + inline( c ) + '</td>'; } ).join( '' ) + '</tr>'; } ).join( '' ) +
				'</tbody></table></div>' );
			table = null;
		};
		String( text == null ? '' : text ).split( /\r?\n/ ).forEach( function ( line ) {
			if ( /^\s*\|.*\|\s*$/.test( line ) ) {
				flushList();
				if ( /^\s*\|?[\s:|-]+\|?\s*$/.test( line ) && line.indexOf( '-' ) > -1 ) { return; } // the |---| row
				( table = table || [] ).push( cells( line ) );
				return;
			}
			flushTable();
			// "→ something to ask" lines are ideas the AI offers: one click sends that request.
			var idea = /^\s*→\s+(.+)$/.exec( line );
			if ( idea ) {
				flushList();
				var said = idea[1].replace( /[*`]/g, '' ).trim();
				out.push( '<button type="button" class="upw-aip__starter upw-aip__idea" data-prompt="' + esc( said ) + '">' + inline( idea[1] ) + '</button>' );
				return;
			}
			var li = /^\s*[-*•]\s+(.*)$/.exec( line ), ol = /^\s*\d+[.)]\s+(.*)$/.exec( line );
			if ( li || ol ) {
				var tag = li ? 'ul' : 'ol';
				if ( list && list.tag !== tag ) { flushList(); }
				list = list || { tag: tag, items: [] };
				list.items.push( '<li>' + inline( ( li || ol )[1] ) + '</li>' );
				return;
			}
			flushList();
			var h = /^\s*#{1,6}\s+(.*)$/.exec( line );
			if ( h ) { out.push( '<p><strong>' + inline( h[1] ) + '</strong></p>' ); return; }
			if ( line.replace( /\s+/g, '' ) !== '' ) { out.push( '<p>' + inline( line ) + '</p>' ); }
		} );
		flushTable();
		flushList();
		return out.join( '' );
	}

	function mount() {
		if ( mounted ) {
			return;
		}
		mounted = true;

		$root = $(
			'<div class="upw-aip upw-aip--' + esc( cfg.position || 'bottom-right' ) + '" data-host="' + esc( cfg.host ) + '">' +
				'<button type="button" class="upw-aip__launch" aria-expanded="false">' +
					'<span class="upw-aip__spark" aria-hidden="true">✦</span> ' + esc( l.open ) +
				'</button>' +
				'<section class="upw-aip__panel" role="dialog" aria-label="' + esc( l.title ) + '" hidden>' +
					'<header class="upw-aip__head">' +
						'<strong>' + esc( l.title ) + '</strong> <span class="upw-aip__beta">' + esc( l.beta ) + '</span>' +
						'<button type="button" class="upw-aip__clear" title="' + esc( l.clearTitle || '' ) + '"' + ( saved.length ? '' : ' hidden' ) + '>' + esc( l.clear || 'New chat' ) + '</button>' +
						'<button type="button" class="upw-aip__close" aria-label="Close">×</button>' +
					'</header>' +
					'<div class="upw-aip__log" aria-live="polite"></div>' +
					'<form class="upw-aip__form">' +
						'<div class="upw-aip__focus" hidden></div>' +
						'<div class="upw-aip__attached" hidden></div>' +
						'<textarea class="upw-aip__input" rows="3" placeholder="' + esc( l.placeholder ) + '"></textarea>' +
						'<div class="upw-aip__side">' +
							( cfg.mediaUrl ? '<button type="button" class="upw-aip__attach" title="' + esc( l.attach || '' ) + '" aria-label="' + esc( l.attach || '' ) + '">' +
								'<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M19 5v14H5V5h14m0-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm-4.86 8.86-3 3.87L9 13.14 6 17h12l-3.86-5.14z"/></svg>' +
							'</button><input type="file" class="upw-aip__file" accept="image/png,image/jpeg,image/webp,image/gif" hidden>' : '' ) +
							'<button type="submit" class="upw-aip__send">' + esc( l.send ) + '</button>' +
						'</div>' +
					'</form>' +
				'</section>' +
			'</div>'
		).appendTo( document.body );

		$log   = $root.find( '.upw-aip__log' );
		$input = $root.find( '.upw-aip__input' );
		$send  = $root.find( '.upw-aip__send' );
		$focus = $root.find( '.upw-aip__focus' );
		renderFocus();

		$root.on( 'click', '.upw-aip__launch', function () { toggle( true ); } );
		$root.on( 'click', '.upw-aip__focus-clear', function () { setFocus( null ); } );

		/**
		 * Draw attention to queued suggestions -- once.
		 *
		 * The badge is the signal that LASTS; the pulse only points at it. That split is deliberate: motion
		 * stops being information within seconds and turns into a nag on a page left open all day, while a
		 * count is still legible an hour later and survives the user looking away while it played. So the
		 * pulse runs for one short burst per suggestion id and never again, and the badge stays until the
		 * job is taken.
		 */
		( function () {
			var n = ( cfg.suggestions || [] ).length;
			if ( ! n ) { return; }
			var $launch = $root.find( '.upw-aip__launch' );
			$launch.append( '<span class="upw-aip__badge" aria-hidden="true">' + n + '</span>' );
			// The count belongs in the accessible name too: a badge nobody can see is not a notification,
			// and the pulse is invisible to a screen reader by design.
			$launch.attr( 'aria-label', ( l.open || 'AI Assistant' ) + ' — ' + n + ' ' + ( n === 1 ? ( l.suggestion || 'suggestion' ) : ( l.suggestions || 'suggestions' ) ) );

			var unseen = cfg.unseen || [];
			if ( ! unseen.length ) { return; }
			$launch.addClass( 'is-new' );
			// Remove the class when the burst ends so a later re-render cannot restart it.
			setTimeout( function () { $launch.removeClass( 'is-new' ); }, 2600 );

			var fd = new FormData();
			fd.append( 'action', 'upw_ai_suggestions_seen' );
			fd.append( '_wpnonce', cfg.seenNonce || '' );
			unseen.forEach( function ( id ) { fd.append( 'ids[]', id ); } );
			fetch( cfg.seenUrl, { method: 'POST', credentials: 'same-origin', body: fd } ).catch( function () {} );
		} )();
		$root.on( 'click', '.upw-aip__close', function () { toggle( false ); } );
		// Images: the attach button, pasting into the box, or dropping on the panel.
		$root.on( 'click', '.upw-aip__attach', function () { $root.find( '.upw-aip__file' ).trigger( 'click' ); } );
		$root.on( 'change', '.upw-aip__file', function () { if ( this.files && this.files[0] ) { attachImage( this.files[0] ); } this.value = ''; } );
		$root.on( 'click', '.upw-aip__attached-remove', function () { attachImage( null ); } );
		$input.on( 'paste', function ( e ) {
			var items = ( e.originalEvent.clipboardData || {} ).items || [];
			for ( var i = 0; i < items.length; i++ ) {
				if ( items[ i ].kind === 'file' && /^image\//.test( items[ i ].type ) && cfg.mediaUrl ) {
					e.preventDefault();
					attachImage( items[ i ].getAsFile() );
					return;
				}
			}
		} );
		$root.on( 'dragover', '.upw-aip__panel', function ( e ) { if ( cfg.mediaUrl ) { e.preventDefault(); } } );
		$root.on( 'drop', '.upw-aip__panel', function ( e ) {
			var f = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files && e.originalEvent.dataTransfer.files[0];
			if ( f && cfg.mediaUrl ) { e.preventDefault(); attachImage( f ); }
		} );
		$root.on( 'submit', '.upw-aip__form', function ( e ) { e.preventDefault(); send(); } );
		$input.on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				send();
			}
		} );
		$root.on( 'click', '.upw-aip__starter', function () {
			// A suggestion carries its own prompt; a plain starter is its own prompt.
			var prompt = $( this ).attr( 'data-prompt' );
			if ( prompt ) {
				send( prompt, null, $( this ).attr( 'data-label' ) || '' );
				return;
			}
			$input.val( $( this ).text() );
			send();
		} );
		$root.on( 'click', '.upw-aip__undo', function () {
			var $b = $( this );
			undo( $b.data( 'before' ), $b.data( 'after' ), $b );
		} );

		$root.on( 'click', '.upw-aip__local-retry', function () { checkLocal(); } );
		$root.on( 'click', '.upw-aip__clear', function () { clearChat(); } );

		if ( ! cfg.backend ) {
			note( esc( l.noBackend ) + ( cfg.setupUrl ? ' <a href="' + esc( cfg.setupUrl ) + '" target="_blank" rel="noopener">' + esc( l.setup ) + '</a>' : '' ), true );
			$input.prop( 'disabled', true );
			$send.prop( 'disabled', true );
		} else {
			if ( cfg.backend === 'browser' ) {
				$input.prop( 'disabled', true );
				$send.prop( 'disabled', true );
			} else if ( cfg.backendLabel ) {
				$log.append( $( '<div class="upw-aip__msg upw-aip__msg--note upw-aip__local is-claude"></div>' ).text( cfg.backendLabel ) );
			}
			// A saved conversation comes back first; queued suggestions stay offered under it (they are
			// jobs, not conversation starters), the generic ideas only on an empty conversation.
			if ( saved.length ) {
				restoreSaved();
				showStarters( true );
			} else {
				showStarters();
			}
		}
		place();
	}

	/**
	 * @param {boolean} jobsOnly Only the queued suggestions, not the generic ideas.
	 */
	function showStarters( jobsOnly ) {
		// A SUGGESTION is a job another extension queued for the user (e.g. the Site Converter's list
		// of findings). Its visible title is short; the prompt behind it is long, so unlike a generic
		// starter it cannot use its own label as the message -- hence data-prompt.
		var sugg = ( cfg.suggestions || [] ).map( function ( s ) {
			return '<button type="button" class="upw-aip__starter upw-aip__starter--sugg" data-id="' + esc( s.id ) +
				'" data-prompt="' + esc( s.prompt ) + '">' + esc( s.title ) + '</button>';
		} ).join( '' );
		var chips = jobsOnly ? '' : ( l.starters || [] ).map( function ( s ) {
			return '<button type="button" class="upw-aip__starter">' + esc( s ) + '</button>';
		} ).join( '' );
		// Rule-based ideas are instant but generic; this asks the AI itself, about this exact place.
		if ( ! jobsOnly && cfg.backend && l.moreIdeas ) {
			chips += '<button type="button" class="upw-aip__starter upw-aip__starter--more" data-label="' + esc( l.moreIdeas ) +
				'" data-prompt="' + esc( l.moreIdeasPrompt || '' ) + '">✦ ' + esc( l.moreIdeas ) + '</button>';
		}
		if ( sugg + chips ) {
			$log.append( '<div class="upw-aip__starters">' + sugg + chips + '</div>' );
		}
	}

	/** One line per thing changed; several steps on the same page / screen share one link. */
	function stepsHtml( steps ) {
		if ( ! steps || ! steps.length ) {
			return '';
		}
		var rows = [], byUrl = {};
		steps.forEach( function ( s ) {
			if ( s.url && byUrl[ s.url ] ) { byUrl[ s.url ].n++; return; }
			var row = { note: s.note || s.ability, url: s.url || '', n: 1 };
			if ( s.url ) { byUrl[ s.url ] = row; }
			rows.push( row );
		} );
		return '<ul class="upw-aip__steps">' + rows.map( function ( r ) {
			var more = r.n > 1 ? ' <span class="upw-aip__more">(+' + ( r.n - 1 ) + ')</span>' : '';
			var link = r.url ? ' <a href="' + esc( r.url ) + '" target="_blank" rel="noopener">' + esc( l.open_link || 'Open' ) + ' ↗</a>' : '';
			return '<li>' + esc( r.note ) + more + link + '</li>';
		} ).join( '' ) + '</ul>';
	}

	/** The saved conversation (FW_AI_History), shown as it was — without Undo buttons (see below). */
	function restoreSaved() {
		saved.forEach( function ( m ) {
			if ( m.role === 'user' ) {
				bubble( 'user', esc( m.text ) );
			} else {
				bubble( 'assistant', '<div class="upw-aip__md">' + md( m.text || '' ) + '</div>' + stepsHtml( m.steps ) +
					( m.steps && m.steps.length ? '<p class="upw-aip__meta">' + esc( l.earlier || '' ) + '</p>' : '' ) );
			}
			history.push( { role: m.role === 'assistant' ? 'assistant' : 'user', text: m.text || '' } );
		} );
	}

	/** Remember a finished turn for this page / screen, so a reload picks the conversation up. */
	function saveTurn( text, res ) {
		if ( ! cfg.history || ! cfg.history.url ) {
			return;
		}
		var steps = ( res.steps || [] ).map( function ( s ) { return { note: s.note || s.ability || '', url: s.url || '' }; } );
		request( cfg.history.url, 'POST', { key: cfg.history.key, items: [
			{ role: 'user', text: text },
			{ role: 'assistant', text: res.reply || '', steps: steps }
		] } );
		$root.find( '.upw-aip__clear' ).prop( 'hidden', false );
	}

	function clearChat() {
		if ( busy ) {
			return;
		}
		if ( cfg.history && cfg.history.clearUrl ) {
			request( cfg.history.clearUrl, 'POST', { key: cfg.history.key } );
		}
		history = [];
		$log.children().not( '.upw-aip__local' ).remove();
		showStarters();
		$root.find( '.upw-aip__clear' ).prop( 'hidden', true );
		$input.trigger( 'focus' );
	}

	/** The page title typed so far (a new page has none saved yet), for the instructions. */
	function pageTitle() {
		if ( isSite ) {
			return '';
		}
		var el = document.getElementById( 'title' );
		if ( el && el.value ) {
			return el.value;
		}
		var le = window.fwLiveEditor;
		return ( le && ( le.postTitle || le.title ) ) || '';
	}

	function toggle( open ) {
		$root.find( '.upw-aip__panel' ).prop( 'hidden', ! open );
		$root.find( '.upw-aip__launch' ).attr( 'aria-expanded', open ? 'true' : 'false' ).prop( 'hidden', open );
		place();
		if ( open && cfg.backend === 'browser' && ! local && ! localChecking ) {
			checkLocal();
		}
		if ( open ) {
			$input.trigger( 'focus' );
		}
	}

	/**
	 * Bottom right is plain CSS. Bottom left sits clear of the wp-admin menu column, measured (admin
	 * skins change its width; it is folded or hidden on small screens). "Beside the sidebar", backend
	 * builder only, anchors the panel to the left edge of the right-hand sidebar so the Publish box
	 * stays clear.
	 */
	function place() {
		if ( ! $root ) { return; }
		if ( cfg.position === 'bottom-left' ) {
			var menu = document.getElementById( 'adminmenuwrap' );
			var r    = menu ? menu.getBoundingClientRect() : null; // fixed-position: offsetParent is always null
			$root.css( 'left', ( r && r.width && r.right < window.innerWidth / 2 ? Math.round( r.right ) + 20 : 20 ) + 'px' );
			return;
		}
		if ( cfg.host !== 'builder' || cfg.position !== 'beside-sidebar' ) { return; }
		var $side = $( '#postbox-container-1' );
		var right = 20;
		if ( $side.length && $side.is( ':visible' ) && $side.offset().left > window.innerWidth / 2 ) {
			right = Math.max( 20, window.innerWidth - $side.offset().left + 16 );
		}
		$root.css( 'right', right + 'px' );
	}
	$( window ).on( 'resize', function () { place(); } );
	// wp-admin fires this when the admin menu is folded / unfolded.
	$( document ).on( 'wp-collapse-menu', function () { place(); } );

	// The admin-bar ✦ item opens the panel (the page panel on builder screens, the site one elsewhere).
	$( document ).on( 'click', '#wp-admin-bar-upw-ai-assistant > a, #wp-admin-bar-upw-ai-assistant > .ab-item', function ( e ) {
		e.preventDefault();
		if ( ! mounted ) {
			if ( ! host.ready() ) { return; }
			mount();
		}
		toggle( $root.find( '.upw-aip__panel' ).prop( 'hidden' ) );
	} );

	function bubble( role, html ) {
		var $b = $( '<div class="upw-aip__msg upw-aip__msg--' + role + '">' ).html( html );
		$log.append( $b );
		$log.scrollTop( $log[ 0 ].scrollHeight );
		return $b;
	}

	function note( html, raw ) {
		return bubble( 'note', raw ? html : esc( html ) );
	}

	function setBusy( on ) {
		busy = on;
		$send.prop( 'disabled', on );
		$input.prop( 'disabled', on );
		$root.toggleClass( 'is-busy', on );
	}

	/* ------------------------------------------------------------------ *
	 * Run
	 * ------------------------------------------------------------------ */

	/**
	 * The "working" indicator: three bouncing dots, a status line and a live timer, plus the
	 * number of changes made so far when the backend reports progress (local agent polling).
	 */
	function working() {
		var started = Date.now();
		var steps = 0;
		var status = '';
		var $w = $(
			'<div class="upw-aip__working" role="status">' +
				'<span class="upw-aip__dots" aria-hidden="true"><i></i><i></i><i></i></span>' +
				'<span class="upw-aip__working-text"></span>' +
			'</div>'
		);
		function render() {
			var secs = Math.floor( ( Date.now() - started ) / 1000 );
			var t = ( l.working || 'Working on it…' ).replace( /[.…]+$/, '' );
			if ( status ) {
				t = status;
			}
			if ( steps ) {
				t += ' · ' + steps + ' ' + ( steps === 1 ? ( l.change || 'change' ) : ( l.changes || 'changes' ) );
			}
			$w.find( '.upw-aip__working-text' ).text( t + ' · ' + secs + 's' );
		}
		render();
		var timer = setInterval( render, 1000 );
		$log.append( $w );
		$log.scrollTop( $log[ 0 ].scrollHeight );
		$w.upwSteps = function ( n ) { steps = n || 0; render(); };
		$w.upwStatus = function ( s ) { status = s || ''; render(); };
		$w.upwStop = function () { clearInterval( timer ); $w.remove(); };
		return $w;
	}

	/* ---- element focus (the Live Editor's "Ask AI about this") ---------- */

	function focusLabel( f ) {
		return ( f && f.label ) ? f.label : ( ( l.thisElement ) || 'this element' );
	}

	/** Draw / hide the "Editing: <label> ✕" chip above the input and steer the
	 *  placeholder toward the focused element. */
	function renderFocus() {
		if ( ! $focus || ! $focus.length ) { return; }
		if ( ! focusItem ) {
			$focus.prop( 'hidden', true ).empty();
			if ( $input && $input.length ) { $input.attr( 'placeholder', l.placeholder ); }
			return;
		}
		$focus.prop( 'hidden', false ).html(
			'<span class="upw-aip__focus-spark" aria-hidden="true">✦</span>' +
			'<span class="upw-aip__focus-text">' + esc( l.editing || 'Editing' ) + ': <b>' + esc( focusLabel( focusItem ) ) + '</b></span>' +
			'<button type="button" class="upw-aip__focus-clear" aria-label="' + esc( l.clearFocus || 'Clear selection' ) + '" title="' + esc( l.clearFocus || 'Clear selection' ) + '">×</button>'
		);
		if ( $input && $input.length ) {
			$input.attr( 'placeholder', ( l.askAbout || 'Ask about this' ) + ' ' + focusLabel( focusItem ) + '…' );
		}
	}

	function setFocus( f ) {
		focusItem = ( f && f.id ) ? f : null;
		renderFocus();
	}

	/** A sentence describing the focused element for the model — includes the
	 *  tree unique_id so it can target the exact item. '' when nothing is focused. */
	function focusString() {
		if ( ! focusItem ) { return ''; }
		var f = focusItem;
		return 'The user has this element SELECTED in the editor and is asking about it: '
			+ ( f.label || 'element' )
			+ ( f.type ? ' [' + f.type + ']' : '' )
			+ ( f.path ? ' at ' + f.path : '' )
			+ ( f.id ? ' — its unique_id in the page tree is "' + f.id + '"' : '' )
			+ '. When they say "this", "here", "it" or name this kind of element, act on THAT item unless they clearly mean something else.';
	}

	/* An image attached to the next message: { file, url (preview), id (once uploaded) }. */
	var attached = null;

	function attachImage( file ) {
		var $box = $root.find( '.upw-aip__attached' );
		if ( attached && attached.url ) { URL.revokeObjectURL( attached.url ); }
		attached = null;
		if ( file && ! /^image\/(png|jpe?g|webp|gif)$/.test( file.type ) ) {
			note( l.attachType || 'Attach an image.' );
			file = null;
		}
		if ( ! file ) {
			$box.empty().prop( 'hidden', true );
			return;
		}
		attached = { file: file, url: URL.createObjectURL( file ) };
		$box.html( '<img src="' + esc( attached.url ) + '" alt=""><span>' + esc( file.name || 'image' ) + '</span>' +
			'<button type="button" class="upw-aip__attached-remove" aria-label="' + esc( l.attachRemove || 'Remove' ) + '">×</button>' ).prop( 'hidden', false );
		$input.trigger( 'focus' );
	}

	/** Upload the attached image, then send the message naming it. */
	function sendWithImage( text ) {
		var img = attached;
		var fd = new FormData();
		fd.append( 'file', img.file, img.file.name || 'chat-image.png' );
		fd.append( 'title', ( l.attachTitle || 'AI chat image' ) + ' — ' + new Date().toISOString().slice( 0, 10 ) );
		setBusy( true );
		var $n = note( l.attachUploading || 'Uploading…' );
		fetch( cfg.mediaUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd } )
			.then( function ( r ) { return r.json().then( function ( j ) { if ( ! r.ok ) { throw new Error( ( j && j.message ) || ( 'HTTP ' + r.status ) ); } return j; } ); } )
			.then( function ( media ) {
				$n.remove();
				setBusy( false );
				attachImage( null );
				send( text, { id: media.id, url: img.url } );
			}, function ( e ) {
				$n.remove();
				setBusy( false );
				note( ( l.attachFailed || 'Upload failed:' ) + ' ' + ( e.message || e ) );
			} );
	}

	function send( forcedText, image, label ) {
		var text = typeof forcedText === 'string' ? forcedText : $.trim( $input.val() );
		if ( busy || ! cfg.backend ) {
			return;
		}
		if ( attached && ! image ) {
			$input.val( '' );
			sendWithImage( text || l.attachDefault || 'Build a draft page that looks like this image.' );
			return;
		}
		if ( ! text ) {
			return;
		}
		var shown = label || text;
		if ( image ) {
			text += '\n\n[The person attached an image: Media Library id ' + image.id + '. Look at it with view_media (ids: [' + image.id + '], size: "large") before answering.]';
		}
		if ( ! host.ready() ) {
			note( l.error + ' builder not ready' );
			return;
		}
		$root.find( '.upw-aip__starters' ).remove();
		$input.val( '' );
		bubble( 'user', ( image ? '<img class="upw-aip__msg-img" src="' + esc( image.url ) + '" alt="">' : '' ) + esc( shown ) );
		var $wait = working();
		setBusy( true );

		var before = isSite ? null : host.getTree();
		var beforeJson = isSite ? '' : JSON.stringify( before );
		if ( cfg.backend === 'browser' ) {
			runBrowser( text, $wait, before, beforeJson );
			return;
		}
		var body = isSite
			? { message: text, history: history.slice( -12 ), context: cfg.context || '' }
			: { post_id: cfg.postId, message: text, tree: before, history: history.slice( -12 ), title: pageTitle(), context: cfg.context || '', focus: focusString() };

		request( cfg.runUrl, 'POST', body ).done( function ( res ) {
			if ( res && res.status === 'running' && res.session ) {
				poll( res.session, $wait, text, before, beforeJson );
				return;
			}
			finish( res, $wait, text, before, beforeJson );
		} ).fail( function ( xhr ) {
			fail( xhr, $wait );
		} );
	}

	function poll( session, $wait, text, before, beforeJson ) {
		setTimeout( function () {
			request( cfg.pollUrl + ( cfg.pollUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'session=' + encodeURIComponent( session ), 'GET' )
				.done( function ( res ) {
					if ( res && res.status === 'running' ) {
						var n = ( res.steps || [] ).length;
						$wait.upwSteps( n );
						poll( session, $wait, text, before, beforeJson );
						return;
					}
					finish( res, $wait, text, before, beforeJson );
				} )
				.fail( function ( xhr ) {
					fail( xhr, $wait );
				} );
		}, 2000 );
	}

	function finish( res, $wait, text, before, beforeJson ) {
		$wait.upwStop();
		setBusy( false );
		res = res || {};
		if ( res.status === 'error' ) {
			note( l.error + ' ' + ( res.reply || '' ) );
			return;
		}

		var html = '<div class="upw-aip__md">' + md( res.reply || '' ) + '</div>';
		var steps = res.steps || [];
		html += stepsHtml( steps );
		// Saved changes (site-wide work, or a page the AI saved) are listed with their undo on AI Changes.
		if ( steps.length && isSite && cfg.changesUrl ) {
			html += '<p class="upw-aip__meta"><a href="' + esc( cfg.changesUrl ) + '" target="_blank" rel="noopener">' + esc( l.allChanges || 'See all AI changes' ) + ' ↗</a></p>';
		}
		saveTurn( text, res );

		if ( isSite ) {
			html += '<p class="upw-aip__meta">' + esc( steps.length ? l.siteDone : l.siteNoChange ) + '</p>';
			bubble( 'assistant', html );
			history.push( { role: 'user', text: text } );
			history.push( { role: 'assistant', text: res.reply || '' } );
			return;
		}

		if ( res.check ) {
			var issues = res.check.issues || [];
			html += '<div class="upw-aip__check' + ( issues.length ? ' has-issues' : '' ) + '">' +
				'<strong>' + esc( l.check ) + '</strong> ' + esc( res.check.summary || '' );
			if ( issues.length ) {
				html += '<ul>' + issues.slice( 0, 6 ).map( function ( i ) {
					return '<li class="is-' + esc( i.severity ) + '">' + ( i.path ? '<code>' + esc( i.path ) + '</code> ' : '' ) + esc( i.message ) + '</li>';
				} ).join( '' ) + ( issues.length > 6 ? '<li>…</li>' : '' ) + '</ul>';
			}
			html += '</div>';
		}

		if ( res.changed && res.tree ) {
			if ( JSON.stringify( host.getTree() ) !== beforeJson ) {
				bubble( 'assistant', html );
				note( l.stale );
				return;
			}
			host.setTree( res.tree );
			html += '<p class="upw-aip__meta">' + esc( l.applied ) + '</p>' +
				'<button type="button" class="button-link upw-aip__undo">' + esc( l.undo ) + '</button>';
			var $b = bubble( 'assistant', html );
			// Fingerprint the host's OWN state after the apply: the builder normalises items as it
			// loads them, so it no longer equals res.tree byte-for-byte.
			$b.find( '.upw-aip__undo' ).data( 'before', before ).data( 'after', JSON.stringify( host.getTree() ) );
		} else {
			bubble( 'assistant', html || esc( l.noChange ) );
		}

		history.push( { role: 'user', text: text } );
		history.push( { role: 'assistant', text: res.reply || '' } );
	}

	function undo( before, afterJson, $btn ) {
		// Only when the page is still exactly what the assistant produced — otherwise the builder's
		// own Undo is the right tool (it knows about the edits made since).
		if ( JSON.stringify( host.getTree() ) !== afterJson ) {
			note( l.stale );
			return;
		}
		host.setTree( before );
		$btn.replaceWith( '<span class="upw-aip__meta">' + esc( l.undone ) + '</span>' );
	}

	function fail( xhr, $wait ) {
		$wait.upwStop();
		setBusy( false );
		var msg = ( xhr && xhr.responseJSON && xhr.responseJSON.message ) || ( xhr && xhr.statusText ) || '';
		note( l.error + ' ' + msg );
	}

	function request( url, method, body, headers ) {
		return $.ajax( {
			url: url,
			method: method,
			contentType: 'application/json',
			data: body ? JSON.stringify( body ) : undefined,
			dataType: 'json',
			timeout: 330000,
			beforeSend: function ( x ) {
				x.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
				$.each( headers || {}, function ( k, v ) { x.setRequestHeader( k, v ); } );
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Browser backend — local AI on this computer
	 *
	 * The site's server cannot reach the editor's computer, but this page can: it talks to the local
	 * model (the AI Dev Kit's capture service, or Ollama itself) on localhost, and to the site's MCP
	 * endpoint for the tools, inside a sandbox session the server opened. The loop runs here.
	 * ------------------------------------------------------------------ */

	var local = null, localChecking = false, rpcId = 0;
	var WRITE_TOOLS = [ 'insert_items', 'update_element', 'move_element', 'remove_element', 'apply_template', 'create_page', 'update_theme_settings', 'undo_theme_settings' ];
	var NUM_CTX = 24576;

	function fetchJson( url, opts, ms ) {
		var ctl = window.AbortController ? new AbortController() : null;
		var t = ctl ? setTimeout( function () { ctl.abort(); }, ms || 8000 ) : null;
		opts = opts || {};
		if ( ctl ) { opts.signal = ctl.signal; }
		return fetch( url, opts ).then( function ( r ) {
			clearTimeout( t );
			return r.json().catch( function () { return {}; } ).then( function ( j ) {
				if ( ! r.ok ) {
					var e = new Error( ( j && ( j.error || j.message ) ) || ( 'HTTP ' + r.status ) );
					e.status = r.status;
					throw e;
				}
				return j;
			} );
		}, function ( e ) {
			clearTimeout( t );
			throw e;
		} );
	}

	/** The model to use: the configured one, the kit's pick, then the best pulled tool-capable one. */
	function pickModel( names, selected ) {
		function has( m ) {
			return m && names.some( function ( n ) { return n === m || n.split( ':' )[ 0 ] === m; } );
		}
		var want = cfg.local && cfg.local.model;
		if ( has( want ) ) { return want; }
		if ( has( selected ) ) { return selected; }
		var pref = [ /^qwen3(?!-vl)/i, /mistral|granite|llama3\.[1-9]|qwen2\.5/i ];
		for ( var i = 0; i < pref.length; i++ ) {
			for ( var j = 0; j < names.length; j++ ) {
				if ( pref[ i ].test( names[ j ] ) ) { return names[ j ]; }
			}
		}
		return '';
	}

	function findLocal() {
		var base = String( ( cfg.local && cfg.local.url ) || 'http://localhost:8787' ).replace( /\/+$/, '' );
		var ua = navigator.userAgent;
		if ( /safari/i.test( ua ) && ! /chrome|chromium|crios|edg|fxios|android/i.test( ua ) ) {
			return Promise.reject( new Error( l.localSafari ) );
		}
		var known = [ l.localOllamaDown, l.localNoModel ];
		// The kit may have Claude Code signed in (its own AI setting): then Claude does the work, through
		// the kit, and the panel says so. Otherwise its local model, or Ollama itself.
		return fetchJson( base + '/health', {}, 5000 ).then( function ( h ) {
			if ( h && h.service === 'unysonplus-design-capture' && h.aiBackend === 'claude-code' ) {
				return { kind: 'kit', base: base, model: 'Claude', claude: true };
			}
			return fetchJson( base + '/local-ai', {}, 5000 );
		}, function () {
			return fetchJson( base + '/local-ai', {}, 5000 );
		} ).then( function ( s ) {
			if ( s && s.claude ) { return s; }
			if ( ! s || typeof s.up === 'undefined' ) { throw new Error( 'not the kit' ); }
			if ( ! s.up ) { throw new Error( l.localOllamaDown ); }
			var model = pickModel( s.pulled || [], s.selected || '' );
			if ( ! model ) { throw new Error( l.localNoModel ); }
			return { kind: 'kit', base: base, model: model };
		} ).catch( function ( e ) {
			if ( e && known.indexOf( e.message ) > -1 ) { throw e; }
			// Not the kit: maybe Ollama itself.
			return fetchJson( base + '/api/tags', {}, 5000 ).then( function ( t ) {
				var names = ( t.models || [] ).map( function ( m ) { return m.name; } );
				var model = pickModel( names, '' );
				if ( ! model ) { throw new Error( l.localNoModel ); }
				return { kind: 'ollama', base: base, model: model };
			} );
		} ).catch( function ( e ) {
			if ( e && known.indexOf( e.message ) > -1 ) { throw e; }
			throw new Error( l.localNone );
		} );
	}

	/** Look for the local model and show what was found (or how to set it up) at the top of the log. */
	function checkLocal() {
		localChecking = true;
		var $s = $log.find( '.upw-aip__local' );
		if ( ! $s.length ) {
			$s = $( '<div class="upw-aip__msg upw-aip__msg--note upw-aip__local"></div>' ).prependTo( $log );
		}
		$s.removeClass( 'is-error' ).text( '…' );
		return findLocal().then( function ( f ) {
			local = f;
			localChecking = false;
			$s.text( f.claude ? ( l.localClaude || 'Connected: Claude' ) : ( l.localReady || '%s' ).replace( '%s', f.model ) );
			$s.toggleClass( 'is-claude', !! f.claude );
			$input.prop( 'disabled', false );
			$send.prop( 'disabled', false );
		}, function ( e ) {
			localChecking = false;
			var settings = cfg.setupUrl ? ' <a href="' + esc( cfg.setupUrl ) + '" target="_blank" rel="noopener">' + esc( l.localSettings || 'AI Assistant settings' ) + '</a> ·' : '';
			$s.addClass( 'is-error' ).html( esc( e.message ) + settings + ' <button type="button" class="button-link upw-aip__local-retry">' + esc( l.localRetry || 'Check again' ) + '</button>' );
			$input.prop( 'disabled', true );
			$send.prop( 'disabled', true );
		} );
	}

	/*
	 * The protocol: no native tool calling. The model answers every turn with ONE JSON object —
	 * {"tool": "<name>", "arguments": {…}} or {"reply": "…"} — constrained by Ollama's `format` (a JSON
	 * schema), so the output always parses. Native tool calls from a 4–14B model are fragile: one
	 * stray token in a long nested argument (a section with its items) and Ollama drops the call,
	 * returning an empty message. This works with any model, not only tool-calling ones.
	 */

	/** One model turn (non-streaming). Returns the raw JSON text. */
	function chat( messages, format ) {
		var json = { 'content-type': 'application/json' };
		if ( local.kind === 'kit' ) {
			return fetchJson( local.base + '/local-ai/tool-chat', {
				method: 'POST',
				headers: json,
				body: JSON.stringify( { messages: messages, format: format, model: local.model, num_ctx: NUM_CTX } )
			}, 330000 ).then( function ( r ) { return ( r.message && r.message.content ) || ''; }, function ( e ) {
				// A kit from before capture service 1.11.60 has /local-ai but not /local-ai/tool-chat.
				throw ( e && e.status === 404 ) ? new Error( l.localOldKit ) : e;
			} );
		}
		return fetchJson( local.base + '/api/chat', {
			method: 'POST',
			headers: json,
			// think:false as the kit sends it: without it a hybrid-reasoning model (Qwen3) reasons at length first.
			body: JSON.stringify( { model: local.model, stream: false, think: false, format: format, messages: messages, options: { temperature: 0.2, num_ctx: NUM_CTX, num_predict: 3072 } } )
		}, 330000 ).then( function ( r ) {
			return String( ( r.message && r.message.content ) || '' ).replace( /<think>[\s\S]*?<\/think>/gi, '' ).replace( /^[\s\S]*<\/think>/i, '' ).trim();
		} );
	}

	/** Parse a turn: { tool, arguments } | { reply } | {} when it is not usable JSON. */
	function parseAction( raw ) {
		var t = String( raw || '' ).trim(), a = null;
		try { a = JSON.parse( t ); } catch ( e ) {
			var m = t.match( /\{[\s\S]*\}/ );
			if ( m ) { try { a = JSON.parse( m[ 0 ] ); } catch ( e2 ) { a = null; } }
		}
		return ( a && typeof a === 'object' && ! Array.isArray( a ) ) ? a : {};
	}

	/**
	 * Drop stray non-object entries from item lists before a tool sees them: a small model sometimes
	 * appends a bare word ("parent_path") to an otherwise perfect items array, which the ability rejects.
	 */
	function tidyArgs( o ) {
		if ( o && typeof o === 'object' ) {
			[ 'items', '_items' ].forEach( function ( k ) {
				if ( Array.isArray( o[ k ] ) ) {
					o[ k ] = o[ k ].filter( function ( x ) { return x && typeof x === 'object' && ! Array.isArray( x ); } );
				}
			} );
			Object.keys( o ).forEach( function ( k ) {
				if ( o[ k ] && typeof o[ k ] === 'object' ) { tidyArgs( o[ k ] ); }
			} );
		}
		return o;
	}

	/** The tool list as text for the instructions: name, first sentence, argument names and types. */
	function toolsText( tools ) {
		return tools.map( function ( t ) {
			var props = ( t.inputSchema && t.inputSchema.properties ) || {};
			var args = Object.keys( props ).map( function ( k ) {
				var ty = props[ k ] && props[ k ].type;
				return k + ( ty ? ': ' + ( Array.isArray( ty ) ? ty.join( '|' ) : ty ) : '' );
			} ).join( ', ' );
			var desc = String( t.description || '' ).split( /\.\s/ )[ 0 ];
			return '- ' + t.name + ' — ' + desc + '. Arguments: {' + args + '}';
		} ).join( '\n' );
	}

	function mcp( session, method, params ) {
		return Promise.resolve( request( cfg.local.mcpUrl, 'POST', { jsonrpc: '2.0', id: ++rpcId, method: method, params: params || {} }, { 'X-UPW-AI-Session': session } ) );
	}

	function errText( e ) {
		if ( e && e.responseJSON ) { return e.responseJSON.message || e.statusText || ''; }
		if ( e && e.name === 'AbortError' ) { return l.localSlow; } // a turn ran past the time limit
		if ( e && e instanceof TypeError ) { return l.localNone; } // fetch() could not reach it
		return ( e && ( e.message || e.statusText ) ) || String( e || '' );
	}

	/** Poll a background agent job on the kit until it finishes (25 minutes at most). */
	function waitAgent( base, job ) {
		var started = Date.now(), misses = 0;
		return new Promise( function ( resolve, reject ) {
			( function tick() {
				fetchJson( base + '/local-ai/agent?job=' + encodeURIComponent( job ), {}, 15000 ).then( function ( r ) {
					misses = 0;
					if ( r.status === 'done' ) { resolve( r ); return; }
					if ( r.status === 'error' ) { reject( new Error( r.error || 'The agent failed.' ) ); return; }
					if ( Date.now() - started > 25 * 60000 ) { reject( new Error( l.localSlow || 'Timed out.' ) ); return; }
					setTimeout( tick, 2500 );
				}, function ( e ) {
					// A missed poll is not a failure — the job keeps running on the kit.
					if ( ++misses > 8 || ( e && e.status === 404 ) ) { reject( e ); return; }
					setTimeout( tick, 4000 );
				} );
			} )();
		} );
	}

	function runBrowser( text, $wait, before, beforeJson ) {
		var session = null, format = null, names = [], messages = [], rounds = 0, writes = 0;
		var max = ( cfg.local && cfg.local.rounds ) || 14;
		// Small models often stop half way or skip fixing what the check found.
		// dirty = a change since the last render_check; issues = what that check reported.
		var dirty = false, issues = 0, nudges = 0;

		function step() {
			if ( rounds++ >= max ) {
				return l.localTooMany;
			}
			$wait.upwStatus( l.localThinking || 'Thinking' );
			return chat( messages, format ).then( function ( raw ) {
				var act = parseAction( raw );
				messages.push( { role: 'assistant', content: raw || '{}' } );
				if ( act.tool && names.indexOf( act.tool ) > -1 ) {
					return runTool( act.tool, act.arguments ).then( step );
				}
				var nudge = '';
				if ( nudges < 3 && dirty ) {
					nudge = 'Not done yet: finish the WHOLE request with tool calls, then call render_check. Answer with a JSON tool call.';
				} else if ( nudges < 3 && issues ) {
					nudge = 'render_check reported problems in what you added. Fix them with a JSON tool call (describe_element shows the right option ids), then call render_check again.';
				} else if ( nudges < 3 && ! act.reply ) {
					nudge = 'Answer with ONE JSON object: {"tool": "<name>", "arguments": {…}} or, when everything is done, {"reply": "…"}.';
				}
				if ( nudge ) {
					nudges++;
					messages.push( { role: 'user', content: nudge } );
					return step();
				}
				return String( act.reply || '' );
			} );
		}

		function runTool( name, args ) {
			args = tidyArgs( args && typeof args === 'object' ? args : {} );
			$wait.upwStatus( ( l.localUsing || 'Using %s' ).replace( '%s', String( name ).replace( /_/g, ' ' ) ) );
			return mcp( session, 'tools/call', { name: name, arguments: args } ).then( function ( r ) {
				// visual_check on a live site: the server cannot reach the kit on this computer, but this
				// browser can. Run the measurement here and hand it back to the ability to summarize.
				var sc0 = r && r.result && r.result.structuredContent;
				if ( name === 'visual_check' && sc0 && sc0.reason === 'service_unreachable' && sc0.verify_request && ! args.measured ) {
					var kit = String( ( cfg.local && cfg.local.url ) || 'http://localhost:8787' ).replace( /\/+$/, '' );
					return fetchJson( kit + sc0.verify_request.path, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( sc0.verify_request.body )
					}, 300000 ).then( function ( measured ) {
						return mcp( session, 'tools/call', { name: name, arguments: $.extend( {}, args, { measured: measured } ) } );
					}, function () {
						return r;
					} );
				}
				return r;
			} ).then( function ( r ) {
				var out, res = ( r && r.result ) || {};
				if ( r && r.error ) {
					out = 'ERROR: ' + r.error.message;
				} else {
					out = ( res.isError ? 'ERROR: ' : '' ) + ( ( res.content && res.content[ 0 ] && res.content[ 0 ].text ) || '' );
					if ( ! res.isError && WRITE_TOOLS.indexOf( name ) > -1 ) {
						$wait.upwSteps( ++writes );
						dirty = true;
					}
					if ( ! res.isError && name === 'render_check' ) {
						dirty = false;
						var sc = res.structuredContent || {};
						issues = ( sc.issues || [] ).filter( function ( i ) { return i.severity === 'error' || i.severity === 'warning'; } ).length;
					}
				}
				if ( out.length > 8000 ) {
					out = out.slice( 0, 8000 ) + ' …[truncated]';
				}
				messages.push( { role: 'user', content: 'Result of ' + name + ':\n' + out } );
			} );
		}

		function end( reply, error ) {
			return Promise.resolve( request( cfg.local.finishUrl, 'POST', { session: session, reply: reply, error: error } ) ).then( function ( res ) {
				finish( res, $wait, text, before, beforeJson );
			}, function ( xhr ) {
				fail( xhr, $wait );
			} );
		}

		var system = '';
		var found = local ? Promise.resolve( local ) : findLocal().then( function ( f ) { local = f; return f; } );

		found.then( function ( f ) {
			if ( ! f.claude ) {
				return runModel();
			}
			// Claude Code on this computer does the whole request itself, against the site's MCP endpoint,
			// with a temporary password the site issues for this one session.
			$wait.upwStatus( l.claudeWorking || 'Claude is working' );
			return Promise.resolve( request( cfg.local.startUrl, 'POST', {
				mode: isSite ? 'site' : 'page',
				post_id: isSite ? 0 : cfg.postId,
				tree: isSite ? [] : before,
				title: pageTitle(),
				context: cfg.context || '',
				focus: focusString(),
				agent: true,
				message: text,
				history: history.slice( -12 )
			} ) ).then( function ( res ) {
				session = res.session;
				// A background job the browser polls: one request held open for a multi-minute build gets
				// dropped, and a retried POST ran the agent twice. request_id makes a retry reuse the job.
				return fetchJson( f.base + '/local-ai/agent', {
					method: 'POST',
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify( { mcp: res.mcp, prompt: res.prompt, async: true, request_id: session } )
				}, 60000 ).then( function ( r ) {
					if ( r && r.job ) {
						return waitAgent( f.base, r.job ).then( function ( out ) { return end( out.reply || '', '' ); } );
					}
					return end( ( r && r.reply ) || '', '' ); // an older kit answers when done
				}, function ( e ) {
					throw ( e && e.status === 404 ) ? new Error( l.localOldKit ) : e;
				} );
			} );
		} ).catch( function ( e ) {
			var msg = errText( e );
			if ( session ) {
				return end( '', msg );
			}
			$wait.upwStop();
			setBusy( false );
			note( l.error + ' ' + msg );
		} );

		function runModel() {
			return Promise.resolve( local ).then( function () {
				return Promise.resolve( request( cfg.local.startUrl, 'POST', isSite
					? { mode: 'site', context: cfg.context || '', message: text }
					: { mode: 'page', post_id: cfg.postId, tree: before, title: pageTitle(), context: cfg.context || '', focus: focusString(), message: text } ) );
			} ).then( function ( res ) {
				session = res.session;
				system = res.system;
				return mcp( session, 'tools/list' );
			} ).then( function ( r ) {
				var tools = ( r && r.result && r.result.tools ) || [];
				names = tools.map( function ( t ) { return t.name; } );
				format = {
					type: 'object',
					properties: {
						tool: { type: 'string', 'enum': names },
						arguments: { type: 'object' },
						reply: { type: 'string' }
					}
				};
				// Qwen3's soft switch: no out-loud reasoning (think:false alone does not fully silence it,
				// and on a modest GPU a reasoning ramble costs minutes).
				messages.push( { role: 'system', content: system +
					'\n\nHOW TO ACT: answer with ONE JSON object and nothing else.' +
					'\nTo use a tool: {"tool": "<name>", "arguments": { … }}' +
					'\nWhen the whole request is done: {"reply": "<one or two sentences for the person>"}' +
					'\nTools:\n' + toolsText( tools ) +
					( /qwen3/i.test( local.model ) ? '\n/no_think' : '' ) } );
				history.slice( -8 ).forEach( function ( h ) {
					messages.push( { role: h.role === 'assistant' ? 'assistant' : 'user', content: h.role === 'assistant' ? JSON.stringify( { reply: h.text } ) : h.text } );
				} );
				messages.push( { role: 'user', content: text } );
				return step();
			} ).then( function ( reply ) {
				return end( reply, '' );
			} );
		}
	}

	// For the AI Assistant settings screen: its live status uses the same check the panel makes, and its
	// "Open the AI Assistant" button opens this panel.
	window.upwAiAssistant = {
		backend: cfg.backend,
		findLocal: findLocal,
		open: function () {
			mount();
			toggle( true );
		},
		// Called by the Live Editor's per-element "Ask AI" button. Pre-attaches the
		// element as context so prompts target it; pass null to clear the focus.
		openForElement: function ( focus ) {
			mount();
			setFocus( focus );
			toggle( true );
			if ( $input && $input.length ) { $input.trigger( 'focus' ); }
		},
		setFocus: function ( focus ) { mount(); setFocus( focus ); }
	};

	// The site host mounts on ready; the Live Editor when its instance appears; the builder from its init event.
	if ( isSite ) {
		$( mount );
	}
	if ( cfg.host === 'live' ) {
		$( function () {
			var tries = 0;
			( function wait() {
				if ( window.fwLiveEditor ) {
					mount();
				} else if ( tries++ < 50 ) {
					setTimeout( wait, 200 );
				}
			}() );
		} );
	}
}( jQuery ) );
