/**
 * AI Assistant (Beta) — the Chat extension's visitor channel.
 *
 * Opens when the Chat button's "Ask our assistant" channel fires `upw-chat:action` (action "ai").
 * Answers come from /visitor/ask (or, on a development host's local agent, /visitor/status polling).
 * When the assistant hands off, the site's other chat channels are offered as buttons, with the
 * visitor's question pre-filled where the channel supports it (WhatsApp text, email body, SMS body).
 */
( function () {
	'use strict';

	var cfg = window.upwAiVisitor;
	if ( ! cfg ) {
		return;
	}
	var l = cfg.l10n || {};
	var root, log, input, send, history = [], busy = false, lastQuestion = '', opener = null;

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text != null ) { n.textContent = text; }
		return n;
	}

	function build() {
		root = el( 'section', 'upw-aiv upw-aiv--' + cfg.position );
		root.setAttribute( 'role', 'dialog' );
		root.setAttribute( 'aria-label', cfg.title );
		root.hidden = true;

		var head = el( 'header', 'upw-aiv__head' );
		head.appendChild( el( 'strong', 'upw-aiv__title', cfg.title ) );
		head.appendChild( el( 'span', 'upw-aiv__beta', l.beta ) );
		var close = el( 'button', 'upw-aiv__close', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', l.close );
		close.addEventListener( 'click', hide );
		head.appendChild( close );

		log = el( 'div', 'upw-aiv__log' );
		log.setAttribute( 'aria-live', 'polite' );

		var form = el( 'form', 'upw-aiv__form' );
		input = el( 'input', 'upw-aiv__input' );
		input.type = 'text';
		input.maxLength = 500;
		input.placeholder = l.placeholder;
		input.setAttribute( 'aria-label', l.placeholder );
		send = el( 'button', 'upw-aiv__send', l.send );
		send.type = 'submit';
		form.appendChild( input );
		form.appendChild( send );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			ask();
		} );

		root.appendChild( head );
		root.appendChild( log );
		root.appendChild( form );
		root.appendChild( el( 'p', 'upw-aiv__note', l.disclaimer ) );
		document.body.appendChild( root );

		if ( cfg.greeting ) {
			bubble( 'assistant', cfg.greeting );
		}
		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) { hide(); }
		} );
	}

	function show( trigger ) {
		if ( ! root ) { build(); }
		opener = trigger || null;
		root.hidden = false;
		document.documentElement.classList.add( 'upw-aiv-open' );
		input.focus();
	}

	function hide() {
		root.hidden = true;
		document.documentElement.classList.remove( 'upw-aiv-open' );
		if ( opener && opener.focus ) { opener.focus(); }
	}

	function bubble( role, text ) {
		var b = el( 'div', 'upw-aiv__msg upw-aiv__msg--' + role, text );
		log.appendChild( b );
		log.scrollTop = log.scrollHeight;
		return b;
	}

	function setBusy( on ) {
		busy = on;
		input.disabled = on;
		send.disabled = on;
	}

	function ask() {
		var q = input.value.replace( /^\s+|\s+$/g, '' );
		if ( ! q || busy ) { return; }
		input.value = '';
		lastQuestion = q;
		bubble( 'user', q );
		var wait = el( 'div', 'upw-aiv__msg upw-aiv__msg--assistant upw-aiv__typing' );
		wait.setAttribute( 'role', 'status' );
		wait.setAttribute( 'aria-label', l.thinking );
		wait.innerHTML = '<i></i><i></i><i></i>';
		log.appendChild( wait );
		log.scrollTop = log.scrollHeight;
		setBusy( true );

		post( cfg.askUrl, { message: q, history: history.slice( -6 ), nonce: cfg.nonce } )
			.then( function ( res ) {
				if ( res && res.status === 'running' && res.session ) {
					return poll( res.session );
				}
				return res;
			} )
			.then( function ( res ) {
				wait.remove();
				answer( res, q );
			} )
			.catch( function ( err ) {
				wait.remove();
				var b = bubble( 'assistant', ( err && err.message ) || l.error );
				handoff( b );
			} )
			.then( function () {
				setBusy( false );
				input.focus();
			} );
	}

	function poll( session ) {
		return new Promise( function ( resolve, reject ) {
			( function tick() {
				setTimeout( function () {
					get( cfg.pollUrl + ( cfg.pollUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'session=' + encodeURIComponent( session ) )
						.then( function ( res ) {
							if ( res && res.status === 'running' ) { tick(); } else { resolve( res ); }
						} )
						.catch( reject );
				}, 1500 );
			}() );
		} );
	}

	function answer( res, q ) {
		res = res || {};
		var b = bubble( 'assistant', res.reply || l.error );
		if ( res.sources && res.sources.length && ! res.handoff ) {
			var src = el( 'p', 'upw-aiv__sources', l.sources + ' ' );
			res.sources.forEach( function ( s, i ) {
				var a = el( 'a', '', s.title );
				a.href = s.url;
				src.appendChild( a );
				if ( i < res.sources.length - 1 ) { src.appendChild( document.createTextNode( ', ' ) ); }
			} );
			b.appendChild( src );
		}
		if ( res.handoff ) {
			handoff( b );
		}
		history.push( { role: 'user', text: q } );
		history.push( { role: 'assistant', text: res.reply || '' } );
	}

	/**
	 * Offer the site's other chat channels (the link channels in the Chat button), with the
	 * visitor's question carried over where the channel supports a pre-filled message.
	 */
	function handoff( b ) {
		var links = document.querySelectorAll( '.upw-chat a.upw-chat-item[href], .upw-chat a.upw-chat-float[href]' );
		if ( ! links.length ) { return; }
		var wrap = el( 'div', 'upw-aiv__handoff' );
		wrap.appendChild( el( 'span', 'upw-aiv__handoff-label', l.handoff ) );
		Array.prototype.forEach.call( links, function ( src ) {
			var a = el( 'a', 'upw-aiv__channel' );
			a.href = prefill( src.getAttribute( 'href' ), lastQuestion );
			if ( src.target ) { a.target = src.target; a.rel = 'noopener noreferrer'; }
			var name = src.querySelector( '.upw-chat-item__name, .upw-chat-float__label' );
			a.textContent = name ? name.textContent : ( src.getAttribute( 'aria-label' ) || src.textContent );
			wrap.appendChild( a );
		} );
		b.appendChild( wrap );
		log.scrollTop = log.scrollHeight;
	}

	function prefill( href, text ) {
		if ( ! text || ! href ) { return href; }
		var enc = encodeURIComponent( text );
		if ( /^https:\/\/wa\.me\//i.test( href ) ) {
			return href.split( '?' )[ 0 ] + '?text=' + enc;
		}
		if ( /^mailto:/i.test( href ) ) {
			return href + ( href.indexOf( '?' ) > -1 ? '&' : '?' ) + 'body=' + enc;
		}
		if ( /^sms:/i.test( href ) ) {
			return href.split( '?' )[ 0 ] + '?body=' + enc;
		}
		return href;
	}

	function post( url, body ) {
		return fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( parse );
	}

	function get( url ) {
		return fetch( url, { credentials: 'same-origin' } ).then( parse );
	}

	function parse( r ) {
		return r.json().catch( function () { return {}; } ).then( function ( data ) {
			if ( ! r.ok ) {
				throw new Error( ( data && data.message ) || l.error );
			}
			return data;
		} );
	}

	document.addEventListener( 'upw-chat:action', function ( e ) {
		if ( e.detail && e.detail.action === 'ai' ) {
			show( e.detail.trigger );
		}
	} );
}() );
