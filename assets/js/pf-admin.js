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
		initTemplateVariantFilter( root );
		initDragAndDrop( root );
		initAddGroup( root );
		initAddSort( root );
		initRemoveRow( root );
		initAcfColorFieldFilter( root );
		initGroupSettingsToggle( root );
	}

	// ---- Пара строк группы: основная + раскрывающаяся с доп. настройками ---
	// (см. PF_Admin::render_group_row() — каждая группа таблицы pf-groups-table
	// это ДВЕ соседние <tr>, не одна: tr.pf-group-row всегда видна и несёт
	// drag-and-drop, следом за ней — tr.pf-group-detail-row со свёрнутыми по
	// умолчанию настройками. Все операции над строками группы (реордер,
	// переиндексация, добавление, удаление, поиск полей внутри группы) должны
	// учитывать обе строки как одно целое — иначе detail-строка отвяжется от
	// своей главной строки или получит чужой индекс. У pf-sort-table (опции
	// сортировки) такой пары нет — detailOf()/queryInPair() для неё просто
	// всегда возвращают null/ищут только в самой строке, поведение как раньше.

	/** Detail-строка, следующая сразу за главной строкой группы, или null. */
	function detailOf( row ) {
		var next = row ? row.nextElementSibling : null;
		return ( next && next.classList.contains( 'pf-group-detail-row' ) ) ? next : null;
	}

	/** Найти элемент по селектору в главной строке ИЛИ в её detail-строке. */
	function queryInPair( row, selector ) {
		if ( ! row ) {
			return null;
		}
		var found = row.querySelector( selector );
		if ( found ) {
			return found;
		}
		var detail = detailOf( row );
		return detail ? detail.querySelector( selector ) : null;
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

			// Поле группы «Дерево: глубина» имеет смысл для ЛЮБОГО шаблона (не
			// только category-tree) на иерархической таксономии — ограничивает
			// показанные значения по уровню вложенности даже в плоском списке
			// (см. PF_Attributes::build_taxonomy_group()). "Иерархичность" поля
			// узнаём по тому же признаку, что и сервер: category-tree предлагается
			// только для иерархических таксономий (get_compatible_templates()).
			if ( row ) {
				var isHierarchical = !! ( compatible && -1 !== compatible.indexOf( 'category-tree' ) );
				row.setAttribute( 'data-hierarchical', isHierarchical ? '1' : '0' );
			}

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

	// ---- Список вариантов сужается до совместимых с выбранным [template] ----

	/**
	 * Селект «Вариант оформления» (.pf-template-variant-select) в таблице групп
	 * содержит СРАЗУ все варианты, найденные в разметке для ЛЮБОГО значения
	 * pf-template (каждый option несёт data-template, см. PF_Admin::render_group_row()).
	 * Здесь список видимых опций сужается до вариантов, реально относящихся к
	 * шаблону, выбранному в этой же строке — тот же приём, что и у
	 * initFieldTemplateFilter() выше (там поле сужает список шаблонов).
	 */
	function initTemplateVariantFilter( root ) {
		function allOptionsOf( variantSelect ) {
			if ( ! variantSelect._pfAllOptions ) {
				variantSelect._pfAllOptions = Array.prototype.map.call( variantSelect.options, function ( o ) {
					return { value: o.value, label: o.textContent, template: o.getAttribute( 'data-template' ) || '' };
				} );
			}
			return variantSelect._pfAllOptions;
		}

		function applyFilter( templateSelect ) {
			var row           = templateSelect.closest( 'tr' );
			// .pf-template-variant-select теперь живёт в раскрывающейся detail-строке
			// этой же группы, не в главной — см. queryInPair().
			var variantSelect = queryInPair( row, '.pf-template-variant-select' );
			if ( ! variantSelect ) {
				return;
			}

			var all = allOptionsOf( variantSelect );
			// data-template="" — служебная опция "— по умолчанию —", видна всегда,
			// независимо от того, какой шаблон выбран.
			var visible = all.filter( function ( o ) {
				return '' === o.template || o.template === templateSelect.value;
			} );

			var currentValue = variantSelect.value;

			variantSelect.innerHTML = '';
			visible.forEach( function ( o ) {
				var opt = document.createElement( 'option' );
				opt.value = o.value;
				opt.textContent = o.label;
				variantSelect.appendChild( opt );
			} );

			var stillValid = visible.some( function ( o ) { return o.value === currentValue; } );
			variantSelect.value = stillValid ? currentValue : '';
		}

		root.addEventListener( 'change', function ( e ) {
			if ( e.target.classList && e.target.classList.contains( 'pf-template-select' ) ) {
				applyFilter( e.target );
			}
		} );

		// Применить сразу к уже отрисованным строкам (в т.ч. по умолчанию при
		// первом заходе на страницу) — не полагаемся на каскад из
		// initFieldTemplateFilter(), у него есть собственный ранний return.
		root.querySelectorAll( '.pf-template-select' ).forEach( applyFilter );
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

			// dragging/target — главные строки (только они draggable="true"); если
			// у любой из них есть detail-строка (см. detailOf()), та должна
			// переехать вместе со своей главной, иначе отвяжется от неё.
			var draggingDetail = detailOf( dragging );
			var targetDetail   = detailOf( target );

			if ( draggingIndex < targetIndex ) {
				// Вставить ПОСЛЕ target — а если у target есть своя detail-строка,
				// то после неё, иначе dragging окажется между главной target-строкой
				// и её собственной detail-строкой.
				( targetDetail || target ).after( dragging );
			} else {
				target.before( dragging );
			}
			if ( draggingDetail ) {
				dragging.after( draggingDetail );
			}

			reindexTable( body.closest( 'table' ) );
		} );
	}

	/**
	 * После реордера/добавления/удаления строк — переиндексировать имена
	 * input/select полей внутри таблицы, чтобы индексы шли подряд с 0.
	 *
	 * У pf-groups-table строка группы — это ДВЕ соседние <tr> (главная +
	 * detail, см. queryInPair() выше): обе должны получить ОДИН и тот же
	 * индекс, иначе поля detail-строки попадут в чужой (соседний) элемент
	 * массива groups при сохранении настроек. detail-строки сами по себе не
	 * считаются — индекс им проставляется вместе с предшествующей главной.
	 * У pf-sort-table такой пары нет — там каждая <tr> просто по порядку
	 * получает свой индекс, как и раньше.
	 */
	function reindexTable( table ) {
		if ( ! table ) {
			return;
		}
		var prefix = table.getAttribute( 'data-name-prefix' );
		if ( ! prefix ) {
			return;
		}

		function applyIndex( row, index ) {
			row.setAttribute( 'data-index', index );
			row.querySelectorAll( '[name]' ).forEach( function ( field ) {
				var name = field.getAttribute( 'name' );
				var re = new RegExp( '^' + escapeRegExp( prefix ) + '\\[[^\\]]*\\]' );
				field.setAttribute( 'name', name.replace( re, prefix + '[' + index + ']' ) );
			} );
		}

		var rows  = table.querySelectorAll( 'tbody > tr' );
		var index = 0;
		rows.forEach( function ( row ) {
			if ( row.classList.contains( 'pf-group-detail-row' ) ) {
				return; // переиндексируется вместе с предшествующей главной строкой ниже.
			}
			applyIndex( row, index );
			var detail = detailOf( row );
			if ( detail ) {
				applyIndex( detail, index );
			}
			index++;
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
				// Шаблон содержит ДВЕ <tr> на группу (главная + detail, см.
				// PF_Admin::render_group_row()) — клонировать нужно обе, иначе у новой
				// группы не будет раскрывающейся панели настроек вообще.
				var newRows = Array.prototype.map.call( tpl.content.querySelectorAll( 'tr' ), function ( tr ) {
					return tr.cloneNode( true );
				} );
				// Индекс — по числу уже существующих ГЛАВНЫХ строк, не всех <tr>
				// (иначе detail-строки задвоили бы счёт и новой группе достался бы
				// вдвое больший индекс, чем реальное число групп).
				var index = body.querySelectorAll( 'tr.pf-group-row' ).length;

				newRows.forEach( function ( newRow ) {
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
				} );

				initTemplateDataAttr( root );

				var newRow = newRows[ 0 ]; // главная строка новой группы.

				// Список шаблонов новой строки сразу сузить до совместимых с её
				// полем по умолчанию (тот же делегированный обработчик change).
				var newFieldSelect = newRow.querySelector( '.pf-field-select' );
				if ( newFieldSelect ) {
					newFieldSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}

				// Список вариантов новой строки сразу сузить до совместимых с её
				// шаблоном по умолчанию — не полагаемся на каскад из события выше
				// (initFieldTemplateFilter() может рано выйти, если карта не пришла).
				var newTemplateSelect = newRow.querySelector( '.pf-template-select' );
				if ( newTemplateSelect ) {
					newTemplateSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
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
				// У pf-groups-table удаление главной строки должно забрать с собой и
				// её detail-строку (см. detailOf()) — иначе она осиротеет в DOM и
				// займёт чужой индекс при следующей reindexTable().
				var detail = detailOf( row );
				if ( detail ) {
					detail.remove();
				}
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

	// ---- Раскрывающаяся панель настроек группы (аккордеон) -----------------

	/**
	 * Кнопка "Настройки" в главной строке группы разворачивает/сворачивает её
	 * detail-строку (см. detailOf()). Свёрнута по умолчанию — так задано в
	 * самой разметке (PF_Admin::render_group_row(): без класса is-open и с
	 * aria-expanded="false"), здесь только переключение состояния по клику.
	 */
	function initGroupSettingsToggle( root ) {
		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.pf-group-settings-toggle' );
			if ( ! btn ) {
				return;
			}
			var row = btn.closest( 'tr' );
			var detail = detailOf( row );
			if ( ! detail ) {
				return;
			}
			var expanded = detail.classList.toggle( 'is-open' );
			btn.classList.toggle( 'is-open', expanded );
			btn.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
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
			// .pf-color-meta-select теперь живёт в раскрывающейся detail-строке этой
			// же группы, не в главной — см. queryInPair().
			var metaSelect = queryInPair( row, '.pf-color-meta-select' );
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
