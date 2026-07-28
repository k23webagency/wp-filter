/**
 * PF Filter — JS страницы настроек (Настройки → PF Filter).
 * Табы, drag-and-drop порядка строк, добавление/удаление строк групп,
 * опций сортировки и строк цветов.
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
		initDragAndDrop( root );
		initAddGroup( root );
		initAddSort( root );
		initRemoveRow( root );
		initColorRows( root );
		initCategorySelect( root );
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
				var panel = btn.closest( '.pf-tab-panel, .pf-category-override' );
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
				initColorRows( root );
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

	// ---- Строки цветов внутри группы --------------------------------------

	function initColorRows( root ) {
		root.querySelectorAll( '.pf-add-color' ).forEach( function ( btn ) {
			if ( btn.dataset.pfBound ) {
				return;
			}
			btn.dataset.pfBound = '1';
			btn.addEventListener( 'click', function () {
				var cell = btn.closest( 'td' );
				var row = btn.closest( '.pf-group-row' );
				var container = cell.querySelector( '.pf-color-rows' );
				var slug = window.prompt( 'Slug значения (напр. red):' );
				if ( ! slug ) {
					return;
				}
				var index = row.getAttribute( 'data-index' );
				var table = row.closest( 'table' );
				var prefix = table.getAttribute( 'data-name-prefix' ) || 'pf_filter_settings[groups]';

				var wrap = document.createElement( 'div' );
				wrap.className = 'pf-color-row';
				wrap.innerHTML =
					'<input type="text" name="' + prefix + '[' + index + '][colors][' + slug + ']" value="" placeholder="#hex" />' +
					'<span class="pf-color-slug">' + slug + '</span>' +
					'<button type="button" class="button-link pf-remove-color">&times;</button>';
				container.appendChild( wrap );
			} );
		} );

		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.pf-remove-color' );
			if ( ! btn ) {
				return;
			}
			var wrap = btn.closest( '.pf-color-row' );
			if ( wrap ) {
				wrap.remove();
			}
		} );
	}

	// ---- Переключение видимости блоков переопределения по категории ------

	function initCategorySelect( root ) {
		var select = root.querySelector( '#pf-category-select' );
		if ( ! select ) {
			return;
		}
		select.addEventListener( 'change', function () {
			root.querySelectorAll( '.pf-category-override' ).forEach( function ( block ) {
				block.style.display = block.getAttribute( 'data-category' ) === select.value ? '' : 'none';
			} );
		} );
	}
} )();
