/**
 * AI Assistant (Beta) — the Try it box on Chat → Settings → AI assistant.
 *
 * Sends one question to /visitor/test (administrators only) and shows the answer and what it was
 * based on: the pages, knowledge sections and business details the model was given. With the local
 * agent (AI Dev Kit on this computer) the answer comes back through /visitor/status polling.
 */
( function () {
	'use strict';

	var cfg = window.upwAiTry;
	var box = document.querySelector( '.upw-ai-try' );
	if ( ! cfg || ! box ) {
		return;
	}
	var l = cfg.l10n || {};
	var q = box.querySelector( '.upw-ai-try__q' );
	var go = box.querySelector( '.upw-ai-try__go' );
	var out = box.querySelector( '.upw-ai-try__out' );
	var busy = false;

	function el( tag, text, style ) {
		var n = document.createElement( tag );
		if ( text != null ) { n.textContent = text; }
		if ( style ) { n.setAttribute( 'style', style ); }
		return n;
	}

	function request( method, url, body ) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' } };
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( url, opts ).then( function ( r ) {
			return r.json().catch( function () { return {}; } ).then( function ( d ) {
				if ( ! r.ok ) { throw new Error( ( d && d.message ) || l.error ); }
				return d;
			} );
		} );
	}

	function poll( session, used ) {
		return new Promise( function ( resolve, reject ) {
			( function tick() {
				setTimeout( function () {
					request( 'GET', cfg.pollUrl + ( cfg.pollUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'session=' + encodeURIComponent( session ) )
						.then( function ( d ) {
							if ( d && d.status === 'running' ) { tick(); return; }
							if ( d && ! d.used ) { d.used = used; }
							resolve( d );
						} )
						.catch( reject );
				}, 1500 );
			}() );
		} );
	}

	function show( d ) {
		out.innerHTML = '';
		var card = el( 'div', null, 'padding:12px 14px;border:1px solid #dcdcde;border-radius:6px;background:#fff' );
		card.appendChild( el( 'p', l.answer, 'margin:0 0 4px;font-weight:600' ) );
		card.appendChild( el( 'p', d.reply || '', 'margin:0;white-space:pre-wrap' ) );
		if ( d.handoff ) {
			card.appendChild( el( 'p', l.handoff, 'margin:8px 0 0;color:#646970;font-style:italic' ) );
		}
		var used = d.used || [];
		card.appendChild( el( 'p', l.used, 'margin:12px 0 4px;font-weight:600' ) );
		if ( ! used.length ) {
			card.appendChild( el( 'p', l.nothing, 'margin:0;color:#646970' ) );
		} else {
			var ul = el( 'ul', null, 'margin:0;padding-left:18px;list-style:disc' );
			used.forEach( function ( u ) {
				var li = el( 'li', null, 'margin:0' );
				if ( u.type === 'page' ) {
					li.appendChild( document.createTextNode( l.page + ': ' ) );
					var a = el( 'a', u.title );
					a.href = u.url;
					a.target = '_blank';
					a.rel = 'noopener noreferrer';
					li.appendChild( a );
				} else if ( u.type === 'facts' ) {
					li.textContent = l.facts + ': ' + u.title;
				} else {
					li.textContent = l.business;
				}
				ul.appendChild( li );
			} );
			card.appendChild( ul );
		}
		out.appendChild( card );
	}

	function ask() {
		var text = q.value.replace( /^\s+|\s+$/g, '' );
		if ( ! text || busy ) { return; }
		busy = true;
		go.disabled = true;
		out.innerHTML = '';
		out.appendChild( el( 'p', l.thinking, 'margin:0;color:#646970' ) );
		request( 'POST', cfg.testUrl, { message: text } )
			.then( function ( d ) {
				return d && d.status === 'running' && d.session ? poll( d.session, d.used ) : d;
			} )
			.then( show )
			.catch( function ( e ) {
				out.innerHTML = '';
				out.appendChild( el( 'p', ( e && e.message ) || l.error, 'margin:0;color:#b32d2e' ) );
			} )
			.then( function () {
				busy = false;
				go.disabled = false;
			} );
	}

	go.addEventListener( 'click', ask );
	q.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault(); // inside the settings form: never submit it
			ask();
		}
	} );
}() );
