/**
 * PF Filter — вкладка «Диагностика» страницы настроек.
 * AJAX-запуск проверок окружения, сверки настроек с разметкой, разметки
 * страницы и REST API. Запускается при первом открытии вкладки, повторно —
 * кнопкой. Результаты не кешируются — каждый запуск делает новый запрос.
 *
 * Вывод: сводка проблем сверху (все ошибки и предупреждения со всех
 * разделов), в разделах — сначала проблемы, успешные проверки свёрнуты.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var runBtn = document.getElementById( 'pf-diag-run' );
		if ( ! runBtn || ! window.pfDiagnosticsConfig ) {
			return;
		}

		var ctx = {
			runBtn: runBtn,
			urlInput: document.getElementById( 'pf-diag-url' ),
			spinner: document.getElementById( 'pf-diag-spinner' ),
			summaryEl: document.getElementById( 'pf-diag-summary' ),
			envEl: document.getElementById( 'pf-diag-environment' ),
			consistencyEl: document.getElementById( 'pf-diag-consistency' ),
			markupEl: document.getElementById( 'pf-diag-markup' ),
			restEl: document.getElementById( 'pf-diag-restapi' ),
		};

		var started = false;
		function runOnce() {
			if ( ! started ) {
				started = true;
				runDiagnostics( ctx );
			}
		}

		runBtn.addEventListener( 'click', function () {
			started = true;
			runDiagnostics( ctx );
		} );

		// Автозапуск при открытии вкладки — чтобы проблема была видна сразу,
		// а не только после того, как о кнопке вспомнили.
		var tab = document.querySelector( '.pf-tabs .nav-tab[data-tab="diagnostics"]' );
		if ( tab ) {
			tab.addEventListener( 'click', runOnce );
		}
		if ( '#pf-tab-diagnostics' === window.location.hash ) {
			runOnce();
		}
	}

	function runDiagnostics( ctx ) {
		ctx.runBtn.disabled = true;
		if ( ctx.spinner ) {
			ctx.spinner.classList.add( 'is-active' );
		}
		setHtml( ctx.summaryEl, '<p class="description">Проверка выполняется…</p>' );

		var body = new URLSearchParams();
		body.set( 'action', 'pf_run_diagnostics' );
		body.set( 'nonce', window.pfDiagnosticsConfig.nonce );
		body.set( 'url', ctx.urlInput ? ctx.urlInput.value : '' );
		body.set( 'profile', window.pfDiagnosticsConfig.profile || '' );

		fetch( window.pfDiagnosticsConfig.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( response ) {
				if ( ! response.success ) {
					renderFatal( ctx, response.data && response.data.message ? response.data.message : 'Ошибка запроса.' );
					return;
				}

				var data = response.data;
				var sections = [
					{ title: 'Окружение и REST API', checks: data.environment || [] },
					{ title: 'Настройки и разметка', checks: data.consistency || [] },
					{ title: 'Разметка страницы', checks: markupChecks( data.markup ) },
					{ title: 'Запросы фильтра', checks: restChecks( data.rest_api ) },
				];

				renderSummary( ctx.summaryEl, sections );
				renderSection( ctx.envEl, sections[ 0 ].checks );
				renderSection( ctx.consistencyEl, sections[ 1 ].checks );
				renderMarkup( ctx.markupEl, data.markup );
				renderRestApi( ctx.restEl, data.rest_api );
			} )
			.catch( function ( err ) {
				renderFatal( ctx, 'Не удалось выполнить запрос: ' + err.message );
			} )
			.finally( function () {
				ctx.runBtn.disabled = false;
				if ( ctx.spinner ) {
					ctx.spinner.classList.remove( 'is-active' );
				}
			} );
	}

	// ---- Данные -----------------------------------------------------------

	var STATUS_MAP = {
		ok: { icon: '✓', cssClass: 'notice-success' },
		error: { icon: '✗', cssClass: 'notice-error' },
		warning: { icon: '⚠', cssClass: 'notice-warning' },
		info: { icon: 'ℹ', cssClass: 'notice-info' },
	};

	var STATUS_ORDER = { error: 0, warning: 1, info: 2, ok: 3 };

	function markupChecks( markup ) {
		if ( ! markup ) {
			return [];
		}
		return ( markup.checks || [] ).concat( markup.auto_hooks || [] );
	}

	/** Результаты REST-запросов в общем формате проверок — для сводки. */
	function restChecks( restApi ) {
		var checks = [];
		if ( ! restApi ) {
			return checks;
		}
		if ( restApi.config ) {
			checks.push( restCheck( 'GET /config', restApi.config, 'групп: ' + restApi.config.groups_count ) );
		}
		if ( restApi.products ) {
			checks.push( restCheck( 'POST /products', restApi.products, 'записей: ' + restApi.products.count + ', html: ' + restApi.products.html_length + ' байт' ) );
		}
		return checks;
	}

	function restCheck( label, result, okMessage ) {
		if ( result.error ) {
			return { status: 'error', label: label + ' — HTTP ' + ( result.status || '—' ), message: result.error + ' (' + result.time_ms + ' мс)' };
		}
		return { status: 'ok', label: label + ' — HTTP ' + result.status, message: result.time_ms + ' мс, ' + okMessage };
	}

	// ---- Рендер -----------------------------------------------------------

	function setHtml( el, html ) {
		if ( el ) {
			el.innerHTML = html;
		}
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = null === str || undefined === str ? '' : String( str );
		return div.innerHTML;
	}

	function checkRow( check ) {
		var meta = STATUS_MAP[ check.status ] || STATUS_MAP.info;
		var message = check.message ? ' — ' + escapeHtml( check.message ) : '';
		return (
			'<div class="notice ' + meta.cssClass + ' inline"><p><strong>' + meta.icon + ' ' + escapeHtml( check.label ) + '</strong>' + message + '</p></div>'
		);
	}

	/** Проблемы — сразу видны (ошибки первыми), успешные — свёрнуты. */
	function checksHtml( checks ) {
		if ( ! checks.length ) {
			return '<p class="description">Нет данных.</p>';
		}

		var problems = checks.filter( function ( c ) {
			return 'ok' !== c.status;
		} ).sort( function ( a, b ) {
			return ( STATUS_ORDER[ a.status ] || 0 ) - ( STATUS_ORDER[ b.status ] || 0 );
		} );
		var passed = checks.filter( function ( c ) {
			return 'ok' === c.status;
		} );

		var html = problems.map( checkRow ).join( '' );
		if ( passed.length ) {
			html += '<details class="pf-diag-passed"' + ( problems.length ? '' : ' open' ) + '><summary>Успешные проверки: ' + passed.length + '</summary>';
			html += passed.map( checkRow ).join( '' );
			html += '</details>';
		}
		return html;
	}

	function renderSection( container, checks ) {
		setHtml( container, checksHtml( checks ) );
	}

	function renderSummary( container, sections ) {
		var errors = [];
		var warnings = [];
		sections.forEach( function ( section ) {
			section.checks.forEach( function ( check ) {
				var item = { section: section.title, check: check };
				if ( 'error' === check.status ) {
					errors.push( item );
				} else if ( 'warning' === check.status ) {
					warnings.push( item );
				}
			} );
		} );

		if ( ! errors.length && ! warnings.length ) {
			setHtml( container, '<div class="notice notice-success inline pf-diag-summary__box"><p><strong>✓ Проблем не найдено.</strong></p></div>' );
			return;
		}

		var html = '<div class="notice ' + ( errors.length ? 'notice-error' : 'notice-warning' ) + ' inline pf-diag-summary__box">';
		html += '<p><strong>' + ( errors.length ? '✗ Ошибок: ' + errors.length : '' ) + ( errors.length && warnings.length ? ' · ' : '' ) + ( warnings.length ? '⚠ Предупреждений: ' + warnings.length : '' ) + '</strong></p><ul>';
		errors.concat( warnings ).forEach( function ( item ) {
			var meta = STATUS_MAP[ item.check.status ];
			html += '<li>' + meta.icon + ' <strong>' + escapeHtml( item.check.label ) + '</strong>' + ( item.check.message ? ' — ' + escapeHtml( item.check.message ) : '' ) + ' <span class="description">(' + escapeHtml( item.section ) + ')</span></li>';
		} );
		html += '</ul></div>';
		setHtml( container, html );
	}

	function renderFatal( ctx, message ) {
		setHtml( ctx.summaryEl, checkRow( { status: 'error', label: 'Ошибка', message: message } ) );
		[ ctx.envEl, ctx.consistencyEl, ctx.markupEl, ctx.restEl ].forEach( function ( el ) {
			setHtml( el, '' );
		} );
	}

	function renderMarkup( container, markup ) {
		if ( ! container ) {
			return;
		}

		if ( ! markup ) {
			container.innerHTML = '<p class="description">Укажите URL страницы каталога, чтобы проанализировать разметку.</p>';
			return;
		}

		var html = '';

		if ( markup.found_templates && markup.found_templates.length ) {
			html += '<p><strong>Найденные шаблоны (pf-template):</strong> ';
			html += markup.found_templates.map( function ( tpl ) {
				return '<span class="pf-diag-tag">' + escapeHtml( tpl ) + '</span>';
			} ).join( ' ' );
			html += '</p>';
		}

		html += checksHtml( markup.checks || [] );

		if ( markup.auto_hooks && markup.auto_hooks.length ) {
			html += '<h3>Автоматические зацепки (без атрибутов)</h3>';
			html += checksHtml( markup.auto_hooks );
		}

		container.innerHTML = html;
	}

	function renderRestApi( container, restApi ) {
		if ( ! container ) {
			return;
		}
		if ( ! restApi ) {
			container.innerHTML = '<p class="description">Нет данных.</p>';
			return;
		}

		var html = '<p class="description">Запросы с правами администратора — показывают, что отвечает сервер. Доступность для посетителей — в разделе «Окружение и REST API».</p>';
		html += renderConfigResult( restApi.config ) + renderProductsResult( restApi.products );
		container.innerHTML = html;
	}

	function renderConfigResult( result ) {
		if ( ! result ) {
			return '';
		}

		var html = '<h3>GET /wp-json/pf/v1/config</h3>' + checkRow( restCheck( 'GET /config', result, 'групп: ' + result.groups_count ) );

		if ( ! result.error && result.groups && result.groups.length ) {
			html += '<table class="widefat striped"><thead><tr><th>field</th><th>template</th><th>values</th></tr></thead><tbody>';
			result.groups.forEach( function ( group ) {
				html += '<tr><td>' + escapeHtml( group.field ) + '</td><td>' + escapeHtml( group.template ) + '</td><td>' + escapeHtml( group.values_count ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}

		return html;
	}

	function renderProductsResult( result ) {
		if ( ! result ) {
			return '';
		}

		var html = '<h3>POST /wp-json/pf/v1/products</h3>' + checkRow( restCheck( 'POST /products', result, 'записей: ' + result.count + ', html: ' + result.html_length + ' байт' ) );

		if ( result.error && result.debug_log_tail ) {
			html += '<p><strong>Последние строки debug.log:</strong></p><pre class="pf-diag-log">' + escapeHtml( result.debug_log_tail ) + '</pre>';
		}

		return html;
	}
} )();
