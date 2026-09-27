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

	var $root, $log, $input, $send, history = [], busy = false, mounted = false;

	function esc( s ) {
		return $( '<div>' ).text( String( s == null ? '' : s ) ).html();
	}

	/**
	 * A small, SAFE markdown subset for model replies: the text is escaped first, then only
	 * **bold**, `code`, [text](http… or /…) links and "- " bullet lists are turned into markup.
	 */
	function md( text ) {
		var out = [], list = null;
		String( text == null ? '' : text ).split( /\r?\n/ ).forEach( function ( line ) {
			var li = /^\s*[-*•]\s+(.*)$/.exec( line );
			var inline = function ( t ) {
				return esc( t )
					.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
					.replace( /`([^`]+)`/g, '<code>$1</code>' )
					.replace( /\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/g, function ( m, label, url ) {
						return '<a href="' + url.replace( /"/g, '&quot;' ) + '" target="_blank" rel="noopener">' + label + '</a>';
					} );
			};
			if ( li ) {
				if ( ! list ) { list = []; }
				list.push( '<li>' + inline( li[1] ) + '</li>' );
				return;
			}
			if ( list ) { out.push( '<ul class="upw-aip__md-list">' + list.join( '' ) + '</ul>' ); list = null; }
			if ( line.replace( /\s+/g, '' ) !== '' ) { out.push( '<p>' + inline( line ) + '</p>' ); }
		} );
		if ( list ) { out.push( '<ul class="upw-aip__md-list">' + list.join( '' ) + '</ul>' ); }
		return out.join( '' );
	}

	function mount() {
		if ( mounted ) {
			return;
		}
		mounted = true;

		$root = $(
			'<div class="upw-aip" data-host="' + esc( cfg.host ) + '">' +
				'<button type="button" class="upw-aip__launch" aria-expanded="false">' +
					'<span class="upw-aip__spark" aria-hidden="true">✦</span> ' + esc( l.open ) +
				'</button>' +
				'<section class="upw-aip__panel" role="dialog" aria-label="' + esc( l.title ) + '" hidden>' +
					'<header class="upw-aip__head">' +
						'<strong>' + esc( l.title ) + '</strong> <span class="upw-aip__beta">' + esc( l.beta ) + '</span>' +
						'<button type="button" class="upw-aip__close" aria-label="Close">×</button>' +
					'</header>' +
					'<div class="upw-aip__log" aria-live="polite"></div>' +
					'<form class="upw-aip__form">' +
						'<textarea class="upw-aip__input" rows="3" placeholder="' + esc( l.placeholder ) + '"></textarea>' +
						'<button type="submit" class="upw-aip__send">' + esc( l.send ) + '</button>' +
					'</form>' +
				'</section>' +
			'</div>'
		).appendTo( document.body );

		$log   = $root.find( '.upw-aip__log' );
		$input = $root.find( '.upw-aip__input' );
		$send  = $root.find( '.upw-aip__send' );

		$root.on( 'click', '.upw-aip__launch', function () { toggle( true ); } );
		$root.on( 'click', '.upw-aip__close', function () { toggle( false ); } );
		$root.on( 'submit', '.upw-aip__form', function ( e ) { e.preventDefault(); send(); } );
		$input.on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				send();
			}
		} );
		$root.on( 'click', '.upw-aip__starter', function () {
			$input.val( $( this ).text() );
			send();
		} );
		$root.on( 'click', '.upw-aip__undo', function () {
			var $b = $( this );
			undo( $b.data( 'before' ), $b.data( 'after' ), $b );
		} );

		if ( ! cfg.backend ) {
			note( l.noBackend + ' <a href="' + esc( cfg.setupUrl ) + '" target="_blank" rel="noopener">' + esc( l.setup ) + '</a>', true );
			$input.prop( 'disabled', true );
			$send.prop( 'disabled', true );
		} else {
			var chips = ( l.starters || [] ).map( function ( s ) {
				return '<button type="button" class="upw-aip__starter">' + esc( s ) + '</button>';
			} ).join( '' );
			$log.append( '<div class="upw-aip__starters">' + chips + '</div>' );
		}
	}

	function toggle( open ) {
		$root.find( '.upw-aip__panel' ).prop( 'hidden', ! open );
		$root.find( '.upw-aip__launch' ).attr( 'aria-expanded', open ? 'true' : 'false' ).prop( 'hidden', open );
		place();
		if ( open ) {
			$input.trigger( 'focus' );
		}
	}

	/**
	 * In the backend builder, keep clear of the right-hand sidebar (the Publish box): anchor the
	 * panel to the left edge of that sidebar instead of the window edge.
	 */
	function place() {
		if ( cfg.host !== 'builder' || ! $root ) { return; }
		var $side = $( '#postbox-container-1' );
		var right = 20;
		if ( $side.length && $side.is( ':visible' ) && $side.offset().left > window.innerWidth / 2 ) {
			right = Math.max( 20, window.innerWidth - $side.offset().left + 16 );
		}
		$root.css( 'right', right + 'px' );
	}
	$( window ).on( 'resize', function () { place(); } );

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
		var $w = $(
			'<div class="upw-aip__working" role="status">' +
				'<span class="upw-aip__dots" aria-hidden="true"><i></i><i></i><i></i></span>' +
				'<span class="upw-aip__working-text"></span>' +
			'</div>'
		);
		function render() {
			var secs = Math.floor( ( Date.now() - started ) / 1000 );
			var t = ( l.working || 'Working on it…' ).replace( /[.…]+$/, '' );
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
		$w.upwStop = function () { clearInterval( timer ); $w.remove(); };
		return $w;
	}

	function send() {
		var text = $.trim( $input.val() );
		if ( ! text || busy || ! cfg.backend ) {
			return;
		}
		if ( ! host.ready() ) {
			note( l.error + ' builder not ready' );
			return;
		}
		$root.find( '.upw-aip__starters' ).remove();
		$input.val( '' );
		bubble( 'user', esc( text ) );
		var $wait = working();
		setBusy( true );

		var before = isSite ? null : host.getTree();
		var beforeJson = isSite ? '' : JSON.stringify( before );
		var body = isSite
			? { message: text, history: history.slice( -12 ) }
			: { post_id: cfg.postId, message: text, tree: before, history: history.slice( -12 ) };

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
		if ( steps.length ) {
			// One line per thing changed: several steps on the same page / screen share one link.
			var rows = [], byUrl = {};
			steps.forEach( function ( s ) {
				if ( s.url && byUrl[ s.url ] ) { byUrl[ s.url ].n++; return; }
				var row = { note: s.note || s.ability, url: s.url || '', n: 1 };
				if ( s.url ) { byUrl[ s.url ] = row; }
				rows.push( row );
			} );
			html += '<ul class="upw-aip__steps">' + rows.map( function ( r ) {
				var more = r.n > 1 ? ' <span class="upw-aip__more">(+' + ( r.n - 1 ) + ')</span>' : '';
				var link = r.url ? ' <a href="' + esc( r.url ) + '" target="_blank" rel="noopener">' + esc( l.open_link || 'Open' ) + ' ↗</a>' : '';
				return '<li>' + esc( r.note ) + more + link + '</li>';
			} ).join( '' ) + '</ul>';
		}

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

	function request( url, method, body ) {
		return $.ajax( {
			url: url,
			method: method,
			contentType: 'application/json',
			data: body ? JSON.stringify( body ) : undefined,
			dataType: 'json',
			timeout: 330000,
			beforeSend: function ( x ) {
				x.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
			}
		} );
	}

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
