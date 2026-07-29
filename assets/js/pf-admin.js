/**
 * PF Filter — JS страницы настроек (Настройки → PF Filter).
 * Табы, drag-and-drop порядка строк, добавление/удаление строк групп и
 * опций сортировки, выбор ACF/term-meta поля с цветом термина.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var root = document.querySelector( '.pf-filter-admin' );
		if ( ! root ) {
			return;
		}

		initTabs( root );
		initTemplateDataAttr( root );
		initFieldTemplateFilter( root );
		initDragAndDrop( root );
		initAddGroup( root );
		initAddSort( root );
		initRemoveRow( root );
		initAcfColorFieldFilter( root );
	}

	// ---- Табы -----------------------------------------------------------

	function initTabs( root ) {
		var tabs = root.querySelectorAll( '.pf-tabs .nav-tab' );
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var target = tab.getAttribute( 'data-tab' );

				tabs.forEach( function ( t ) {
					t.classList.toggle( 'nav-tab-active', t === tab );
				} );

				root.querySelectorAll( '.pf-tab-panel' ).forEach( function ( panel ) {
					panel.style.display = panel.getAttribute( 'data-tab' ) === target ? '' : 'none';
				} );
			} );
		} );
	}

	// ---- data-template на <tr> для показа доп. полей через CSS ----------

	function initTemplateDataAttr( root ) {
		function sync( select ) {
			var row = select.closest( '.pf-group-row' );
			if ( row ) {
				row.setAttribute( 'data-template', select.value );
			}
		}

		root.querySelectorAll( '.pf-template-select' ).forEach( function ( select ) {
			sync( select );
			select.addEventListener( 'change', function () {
				sync( select );
			} );
		} );

		root.addEventListener( 'change', function ( e ) {
			if ( e.target.classList && e.target.classList.contains( 'pf-template-select' ) ) {
				sync( e.target );
			}
		} );
	}

	// ---- Список шаблонов сужается до совместимых с выбранным полем -------

	function initFieldTemplateFilter( root ) {
		var map = ( window.pfAdminConfig && window.pfAdminConfig.fieldCompatibleTemplates ) || null;
		if ( ! map ) {
			return;
		}

		function allOptionsOf( templateSelect ) {
			if ( ! templateSelect._pfAllOptions ) {
				templateSelect._pfAllOptions = Array.prototype.map.call( templateSelect.options, function ( o ) {
					return { value: o.value, label: o.textContent };
				} );
			}
			return templateSelect._pfAllOptions;
		}

		function applyFilter( fieldSelect ) {
			var row = fieldSelect.closest( 'tr' );
			var templateSelect = row ? row.querySelector( '.pf-template-select' ) : null;
			if ( ! templateSelect ) {
				return;
			}

			var all = allOptionsOf( templateSelect );
			var compatible = map[ fieldSelect.value ];
			var filtered = compatible ? all.filter( function ( o ) { return compatible.indexOf( o.value ) !== -1; } ) : all;
			if ( ! filtered.length ) {
				filtered = all; // неизвестное поле — не оставлять пустой список.
			}

			var currentValue = templateSelect.value;

			templateSelect.innerHTML = '';
			filtered.forEach( function ( o ) {
				var opt = document.createElement( 'option' );
				opt.value = o.value;
				opt.textContent = o.label;
				templateSelect.appendChild( opt );
			} );

			var stillValid = filtered.some( function ( o ) { return o.value === currentValue; } );
			templateSelect.value = stillValid ? currentValue : filtered[ 0 ].value;

			templateSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		root.addEventListener( 'change', function ( e ) {
			if ( e.target.classList && e.target.classList.contains( 'pf-field-select' ) ) {
				applyFilter( e.target );
			}
		} );

		// Применить сразу к уже отрисованным строкам (в т.ч. по умолчанию при
		// первом заходе на страницу).
		root.querySelectorAll( '.pf-field-select' ).forEach( applyFilter );
	}

	// ---- Drag-and-drop реордера строк ------------------------------------

	function initDragAndDrop( root ) {
		root.addEventListener( 'dragstart', function ( e ) {
			var row = e.target.closest( 'tr[draggable="true"]' );
			if ( ! row ) {
				return;
			}
			row.classList.add( 'pf-dragging' );
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', '' );
		} );

		root.addEventListener( 'dragend', function ( e ) {
			var row = e.target.closest( 'tr[draggable="true"]' );
			if ( row ) {
				row.classList.remove( 'pf-dragging' );
			}
			root.querySelectorAll( '.pf-drag-over' ).forEach( function ( el ) {
				el.classList.remove( 'pf-drag-over' );
			} );
		} );

		root.addEventListener( 'dragover', function ( e ) {
			var row = e.target.closest( 'tr[draggable="true"]' );
			if ( ! row ) {
				return;
			}
			e.preventDefault();
			row.classList.add( 'pf-drag-over' );
		} );

		root.addEventListener( 'dragleave', function ( e ) {
			var row = e.target.closest( 'tr[draggable="true"]' );
			if ( row ) {
				row.classList.remove( 'pf-drag-over' );
			}
		} );

		root.addEventListener( 'drop', function ( e ) {
			var target = e.target.closest( 'tr[draggable="true"]' );
			if ( ! target ) {
				return;
			}
			e.preventDefault();
			target.classList.remove( 'pf-drag-over' );

			var dragging = root.querySelector( '.pf-dragging' );
			if ( ! dragging || dragging === target ) {
				return;
			}

			var body = target.parentElement;
			var rows = Array.prototype.slice.call( body.children );
			var draggingIndex = rows.indexOf( dragging );
			var targetIndex = rows.indexOf( target );

			if ( draggingIndex < targetIndex ) {
				target.after( dragging );
			} else {
				target.before( dragging );
			}

			reindexTable( body.closest( 'table' ) );
		} );
	}

	/**
	 * После реордера/добавления/удаления строк — переиндексировать имена
	 * input/select полей внутри таблицы, чтобы индексы шли подряд с 0.
	 */
	function reindexTable( table ) {
		if ( ! table ) {
			return;
		}
		var prefix = table.getAttribute( 'data-name-prefix' );
		if ( ! prefix ) {
			return;
		}

		var rows = table.querySelectorAll( 'tbody > tr' );
		rows.forEach( function ( row, index ) {
			row.setAttribute( 'data-index', index );
			row.querySelectorAll( '[name]' ).forEach( function ( field ) {
				var name = field.getAttribute( 'name' );
				var re = new RegExp( '^' + escapeRegExp( prefix ) + '\\[[^\\]]*\\]' );
				field.setAttribute( 'name', name.replace( re, prefix + '[' + index + ']' ) );
			} );
		} );
	}

	function escapeRegExp( str ) {
		return str.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	// ---- Добавление строки группы из <template> --------------------------

	function initAddGroup( root ) {
		root.querySelectorAll( '.pf-add-group' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var panel = btn.closest( '.pf-tab-panel' );
				var table = panel ? panel.querySelector( 'table.pf-groups-table' ) : null;
				var tpl = document.getElementById( 'pf-group-row-template' );
				if ( ! table || ! tpl ) {
					return;
				}

				var body = table.querySelector( 'tbody.pf-groups-body' );
				var newRow = tpl.content.querySelector( 'tr' ).cloneNode( true );
				var index = body.children.length;

				newRow.querySelectorAll( '[name]' ).forEach( function ( field ) {
					field.setAttribute( 'name', field.getAttribute( 'name' ).replace( /__INDEX__/g, index ) );
				} );

				var customPrefix = table.getAttribute( 'data-name-prefix' );
				if ( customPrefix ) {
					newRow.querySelectorAll( '[name]' ).forEach( function ( field ) {
						var name = field.getAttribute( 'name' );
						field.setAttribute( 'name', name.replace( /^pf_filter_settings\[groups\]/, customPrefix ) );
					} );
				}

				body.appendChild( newRow );
				initTemplateDataAttr( root );

				// Список шаблонов новой строки сразу сузить до совместимых с её
				// полем по умолчанию (тот же делегированный обработчик change).
				var newFieldSelect = newRow.querySelector( '.pf-field-select' );
				if ( newFieldSelect ) {
					newFieldSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			} );
		} );
	}

	// ---- Добавление опции сортировки --------------------------------------

	function initAddSort( root ) {
		root.querySelectorAll( '.pf-add-sort' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var table = root.querySelector( 'table.pf-sort-table' );
				var tpl = document.getElementById( 'pf-sort-row-template' );
				if ( ! table || ! tpl ) {
					return;
				}
				var body = table.querySelector( 'tbody.pf-sort-body' );
				var newRow = tpl.content.querySelector( 'tr' ).cloneNode( true );
				var index = body.children.length;

				newRow.querySelectorAll( '[name]' ).forEach( function ( field ) {
					field.setAttribute( 'name', field.getAttribute( 'name' ).replace( /__INDEX__/g, index ) );
				} );

				body.appendChild( newRow );
			} );
		} );
	}

	// ---- Удаление строки (группа или сортировка) --------------------------

	function initRemoveRow( root ) {
		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.pf-remove-row' );
			if ( ! btn ) {
				return;
			}
			var row = btn.closest( 'tr' );
			var table = row ? row.closest( 'table' ) : null;
			if ( row ) {
				row.remove();
			}
			if ( table && table.classList.contains( 'pf-groups-table' ) ) {
				reindexTable( table );
			}
			if ( table && table.classList.contains( 'pf-sort-table' ) ) {
				reindexTable( table );
			}
		} );
	}

	// ---- Выбор ACF/term-meta поля с цветом термина ------------------------

	/**
	 * Выпадашка «поле с цветом» (.pf-color-meta-select) в колонке «Цвета» —
	 * список опций сужается под выбранное в этой же строке поле группы
	 * (только реальные таксономии имеют термины и, соответственно, поля ACF
	 * термина — pfAdminConfig.acfFieldsByField приходит с сервера, см.
	 * PF_Admin::get_acf_fields_map()).
	 */
	function initAcfColorFieldFilter( root ) {
		var map = ( window.pfAdminConfig && window.pfAdminConfig.acfFieldsByField ) || null;

		function applyFieldOptions( fieldSelect ) {
			if ( ! map ) {
				return;
			}
			var row = fieldSelect.closest( 'tr' );
			var metaSelect = row ? row.querySelector( '.pf-color-meta-select' ) : null;
			if ( ! metaSelect ) {
				return;
			}

			var currentValue = metaSelect.value;
			var fields = map[ fieldSelect.value ] || [];

			metaSelect.innerHTML = '';
			var emptyOpt = document.createElement( 'option' );
			emptyOpt.value = '';
			emptyOpt.textContent = metaSelect.dataset.emptyLabel || '— нет —';
			metaSelect.appendChild( emptyOpt );

			fields.forEach( function ( f ) {
				var opt = document.createElement( 'option' );
				opt.value = f.name;
				opt.textContent = f.label + ' (' + f.name + ')';
				metaSelect.appendChild( opt );
			} );

			var stillValid = fields.some( function ( f ) { return f.name === currentValue; } );
			metaSelect.value = stillValid ? currentValue : '';
		}

		root.querySelectorAll( '.pf-color-meta-select' ).forEach( function ( metaSelect ) {
			// Запоминаем подпись "нет" из уже отрисованного сервером первого
			// option — используется при перестроении списка после смены поля.
			var emptyOpt = metaSelect.querySelector( 'option[value=""]' );
			if ( emptyOpt ) {
				metaSelect.dataset.emptyLabel = emptyOpt.textContent;
			}
		} );

		root.addEventListener( 'change', function ( e ) {
			if ( e.target.classList && e.target.classList.contains( 'pf-field-select' ) ) {
				applyFieldOptions( e.target );
			}
		} );
	}

} )();
