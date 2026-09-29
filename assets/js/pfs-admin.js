/**
 * Страница настроек модуля поиска (Настройки → PF Search).
 */
( function () {
	'use strict';

	var cfg = window.pfsAdminConfig || {};
	var i18n = cfg.i18n || {};

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	function format( template, a, b ) {
		return String( template ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	/* Режим «Где искать» и видимость ACF-полей по типам профиля. */
	function initTypeMode() {
		var radios = document.querySelectorAll( '[data-pfs-type-mode]' );
		if ( ! radios.length ) {
			return;
		}
		var fixedSelect = document.querySelector( 'select[name="pfs[post_type]"]' );
		var allowed = document.querySelectorAll( '[data-pfs-allowed-type]' );

		function currentMode() {
			var mode = 'fixed';
			radios.forEach( function ( r ) {
				if ( r.checked ) {
					mode = r.value;
				}
			} );
			return mode;
		}

		function update() {
			var mode = currentMode();
			document.querySelectorAll( '.pfs-mode-panel' ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-pfs-mode' ) !== mode;
			} );

			var types = [];
			if ( 'fixed' === mode ) {
				if ( fixedSelect ) {
					types.push( fixedSelect.value );
				}
			} else {
				allowed.forEach( function ( cb ) {
					if ( cb.checked ) {
						types.push( cb.getAttribute( 'data-pfs-allowed-type' ) );
					}
				} );
			}
			document.querySelectorAll( '[data-pfs-acf-type]' ).forEach( function ( fs ) {
				fs.hidden = types.indexOf( fs.getAttribute( 'data-pfs-acf-type' ) ) === -1;
			} );
		}

		radios.forEach( function ( r ) { r.addEventListener( 'change', update ); } );
		allowed.forEach( function ( cb ) { cb.addEventListener( 'change', update ); } );
		if ( fixedSelect ) {
			fixedSelect.addEventListener( 'change', update );
		}
		update();
	}

	/* Предупреждение о нагрузке при большом лимите выпадашки. */
	function initLimitWarning() {
		var input = document.querySelector( '[data-pfs-limit]' );
		var warning = document.querySelector( '[data-pfs-limit-warning]' );
		if ( ! input || ! warning ) {
			return;
		}
		function update() {
			warning.hidden = ! ( parseInt( input.value, 10 ) > ( cfg.limitWarn || 6 ) );
		}
		input.addEventListener( 'input', update );
		update();
	}

	/* Прогресс индексации: пока страница открыта — сами двигаем пачки. */
	function initReindexProgress() {
		var card = document.getElementById( 'pfs-index-card' );
		if ( ! card || ! cfg.state || 'running' !== cfg.state.status ) {
			return;
		}
		var box = card.querySelector( '.pfs-progress' );
		var bar = card.querySelector( '.pfs-progress-bar span' );
		var text = card.querySelector( '.pfs-progress-text' );

		function render( state, summary ) {
			var total = parseInt( state.total, 10 ) || 0;
			var done = parseInt( state.done, 10 ) || 0;
			box.hidden = false;
			bar.style.width = ( total ? Math.round( 100 * done / total ) : 0 ) + '%';
			text.textContent = 'running' === state.status ? format( i18n.running, done, total ) : i18n.done;
			if ( summary ) {
				var docs = card.querySelector( '[data-pfs-summary="docs"]' );
				var terms = card.querySelector( '[data-pfs-summary="terms"]' );
				if ( docs ) {
					docs.textContent = Number( summary.docs ).toLocaleString();
				}
				if ( terms ) {
					terms.textContent = Number( summary.terms ).toLocaleString();
				}
			}
		}

		function step() {
			post( 'pfs_run_batch' ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					text.textContent = i18n.error;
					return;
				}
				render( res.data.state, res.data.summary );
				if ( 'running' === res.data.state.status ) {
					setTimeout( step, 300 );
				}
			} ).catch( function () {
				text.textContent = i18n.error;
				setTimeout( step, 5000 );
			} );
		}

		render( cfg.state );
		step();
	}

	/* Пробный поиск. */
	function initTestSearch() {
		var form = document.querySelector( '[data-pfs-test]' );
		var out = document.querySelector( '[data-pfs-test-results]' );
		if ( ! form || ! out ) {
			return;
		}

		function el( tag, text, attrs ) {
			var node = document.createElement( tag );
			if ( null != text ) {
				node.textContent = text;
			}
			Object.keys( attrs || {} ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
			return node;
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var q = form.q.value.trim();
			if ( q.length < 3 ) {
				out.textContent = i18n.tooShort;
				return;
			}
			out.textContent = i18n.searching;

			post( 'pfs_test_search', {
				profile: form.getAttribute( 'data-profile' ),
				q: q,
				type: form.type ? form.type.value : ''
			} ).then( function ( res ) {
				out.textContent = '';
				if ( ! res || ! res.success ) {
					out.textContent = ( res && res.data && res.data.message ) || i18n.error;
					return;
				}
				var d = res.data;
				out.appendChild( el( 'p', 'Найдено: ' + d.total + ' · ' + d.took_ms + ' мс', { 'class': 'pfs-muted' } ) );
				if ( 'partial' === d.mode ) {
					out.appendChild( el( 'p', i18n.partial, { 'class': 'pfs-warning' } ) );
				}
				if ( ! d.items.length ) {
					out.appendChild( el( 'p', i18n.nothing ) );
					return;
				}
				var table = el( 'table', null, { 'class': 'widefat striped' } );
				var head = el( 'tr' );
				[ '#', 'Запись', 'Совпало в полях', 'Релевантность' ].forEach( function ( h ) {
					head.appendChild( el( 'th', h ) );
				} );
				table.appendChild( el( 'thead' ) ).appendChild( head );
				var body = table.appendChild( el( 'tbody' ) );
				d.items.forEach( function ( item, i ) {
					var row = el( 'tr' );
					row.appendChild( el( 'td', String( i + 1 ) ) );
					var cell = el( 'td' );
					cell.appendChild( el( 'a', item.title || ( '#' + item.id ), { href: item.url, target: '_blank', rel: 'noopener' } ) );
					row.appendChild( cell );
					row.appendChild( el( 'td', item.fields.join( ', ' ) ) );
					row.appendChild( el( 'td', String( item.score ) ) );
					body.appendChild( row );
				} );
				out.appendChild( table );
			} ).catch( function () {
				out.textContent = i18n.error;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initTypeMode();
		initLimitWarning();
		initReindexProgress();
		initTestSearch();
	} );
}() );
