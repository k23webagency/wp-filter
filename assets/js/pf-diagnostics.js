/**
 * PF Filter — вкладка «Диагностика» страницы настроек.
 * AJAX-запуск проверок окружения, разметки страницы и REST API.
 * Результаты не кешируются — каждый клик по кнопке делает новый запрос.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var runBtn = document.getElementById( 'pf-diag-run' );
		if ( ! runBtn || ! window.pfDiagnosticsConfig ) {
			return;
		}

		var urlInput = document.getElementById( 'pf-diag-url' );
		var spinner = document.getElementById( 'pf-diag-spinner' );
		var envEl = document.getElementById( 'pf-diag-environment' );
		var markupEl = document.getElementById( 'pf-diag-markup' );
		var restEl = document.getElementById( 'pf-diag-restapi' );

		runBtn.addEventListener( 'click', function () {
			runDiagnostics( { runBtn: runBtn, urlInput: urlInput, spinner: spinner, envEl: envEl, markupEl: markupEl, restEl: restEl } );
		} );
	}

	function runDiagnostics( ctx ) {
		ctx.runBtn.disabled = true;
		if ( ctx.spinner ) {
			ctx.spinner.classList.add( 'is-active' );
		}

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
					renderError( ctx.envEl, response.data && response.data.message ? response.data.message : 'Ошибка запроса.' );
					ctx.markupEl.innerHTML = '';
					ctx.restEl.innerHTML = '';
					return;
				}

				renderChecks( ctx.envEl, response.data.environment || [] );
				renderMarkup( ctx.markupEl, response.data.markup );
				renderRestApi( ctx.restEl, response.data.rest_api );
			} )
			.catch( function ( err ) {
				renderError( ctx.envEl, 'Не удалось выполнить запрос: ' + err.message );
			} )
			.finally( function () {
				ctx.runBtn.disabled = false;
				if ( ctx.spinner ) {
					ctx.spinner.classList.remove( 'is-active' );
				}
			} );
	}

	// ---- Рендер -----------------------------------------------------------

	var STATUS_MAP = {
		ok: { icon: '✓', cssClass: 'notice-success' },
		error: { icon: '✗', cssClass: 'notice-error' },
		warning: { icon: '⚠', cssClass: 'notice-warning' },
		info: { icon: 'ℹ', cssClass: 'notice-info' },
	};

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

	function renderChecks( container, checks ) {
		if ( ! container ) {
			return;
		}
		if ( ! checks.length ) {
			container.innerHTML = '<p class="description">Нет данных.</p>';
			return;
		}
		container.innerHTML = checks.map( checkRow ).join( '' );
	}

	function renderError( container, message ) {
		if ( ! container ) {
			return;
		}
		container.innerHTML = checkRow( { status: 'error', label: 'Ошибка', message: message } );
	}

	function renderMarkup( container, markup ) {
		if ( ! container ) {
			return;
		}

		if ( ! markup ) {
			container.innerHTML = '<p class="description">Укажите URL страницы каталога чтобы проанализировать разметку.</p>';
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

		html += ( markup.checks || [] ).map( checkRow ).join( '' );

		if ( markup.auto_hooks && markup.auto_hooks.length ) {
			html += '<h3>Автоматические зацепки (без атрибутов)</h3>';
			html += markup.auto_hooks.map( checkRow ).join( '' );
		}

		container.innerHTML = html || '<p class="description">Нет данных.</p>';
	}

	function renderRestApi( container, restApi ) {
		if ( ! container ) {
			return;
		}
		if ( ! restApi ) {
			container.innerHTML = '<p class="description">Нет данных.</p>';
			return;
		}

		container.innerHTML = renderConfigResult( restApi.config ) + renderProductsResult( restApi.products );
	}

	function renderConfigResult( result ) {
		if ( ! result ) {
			return '';
		}

		var html = '<h3>GET /wp-json/pf/v1/config</h3>';

		if ( result.error ) {
			html += checkRow( { status: 'error', label: 'Статус ' + ( result.status || '—' ), message: result.error + ' (' + result.time_ms + ' мс)' } );
			return html;
		}

		html += checkRow( {
			status: 'ok',
			label: 'HTTP ' + result.status,
			message: result.time_ms + ' мс, групп фильтров: ' + result.groups_count,
		} );

		if ( result.groups && result.groups.length ) {
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

		var html = '<h3>POST /wp-json/pf/v1/products</h3>';

		if ( result.error ) {
			html += checkRow( { status: 'error', label: 'Статус ' + ( result.status || '—' ), message: result.error + ' (' + result.time_ms + ' мс)' } );
			if ( result.debug_log_tail ) {
				html += '<p><strong>Последние строки debug.log:</strong></p><pre class="pf-diag-log">' + escapeHtml( result.debug_log_tail ) + '</pre>';
			}
			return html;
		}

		html += checkRow( {
			status: 'ok',
			label: 'HTTP ' + result.status,
			message: result.time_ms + ' мс, count: ' + result.count + ', html: ' + result.html_length + ' байт',
		} );

		return html;
	}
} )();
