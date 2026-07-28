/**
 * PF Filter — основной JS-движок фильтрации.
 * Нативный ES2020+, без jQuery и фреймворков.
 *
 * Принцип устойчивости: каждое обращение к DOM через pf-* атрибут
 * оборачивается в проверку наличия элемента. Отсутствие опционального
 * атрибута — console.warn и пропуск только этой функции. Отсутствие
 * обязательного (pf-form/pf-list) — console.error и остановка
 * инициализации только для этой формы. pf-target нигде не обязателен —
 * его отсутствие разрешается автоматически или через console.warn (см. resolveListElement).
 */
( function () {
	'use strict';

	// ---------------------------------------------------------------------
	// Утилиты
	// ---------------------------------------------------------------------

	/** Безопасный querySelector — не бросает если root отсутствует. */
	function qs( root, selector ) {
		return root ? root.querySelector( selector ) : null;
	}

	/** Безопасный querySelectorAll, всегда возвращает массив. */
	function qsa( root, selector ) {
		if ( ! root ) {
			return [];
		}
		return Array.prototype.slice.call( root.querySelectorAll( selector ) );
	}

	function clamp( value, min, max ) {
		return Math.min( Math.max( value, min ), max );
	}

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait );
		};
	}

	var uidCounter = 0;
	function uid( prefix ) {
		uidCounter += 1;
		return prefix + '-' + uidCounter;
	}

	/** Прочитать/записать значение элемента диапазона: input -> .value, иначе textContent. */
	function getRangeDisplayValue( el ) {
		if ( ! el ) {
			return null;
		}
		return el.tagName === 'INPUT' ? parseFloat( el.value ) : parseFloat( el.textContent );
	}

	function setRangeDisplayValue( el, value ) {
		if ( ! el ) {
			return;
		}
		if ( el.tagName === 'INPUT' ) {
			el.value = value;
		} else {
			el.textContent = value;
		}
	}

	// ---------------------------------------------------------------------
	// PFForm — один экземпляр на каждую найденную [pf-form]
	// ---------------------------------------------------------------------

	function PFForm( formEl ) {
		this.formEl = formEl;
		this.listEl = null;
		this.outputEl = null;
		this.templatesEl = null;
		this.loadingEl = null;
		this.emptyEl = null;
		this.sortEl = null;
		this.paginationEl = null;
		this.paginationMode = null;

		this.config = null;
		this.groups = {}; // field -> { config, el }
		this.showCounts = true;
		this.searchThreshold = 7;
		this.logic = 'and';
		this.perPage = 12;

		this.state = {
			filters: {},
			orderby: 'menu_order',
			order: 'ASC',
			paged: 1,
		};

		this.totalPages = 1;
		this.totalCount = 0;
		this.abortController = null;
		this.infiniteObserver = null;
		this._forceReplace = false; // true когда список нужно заменить целиком (фильтр/сортировка/сброс), даже в режиме load-more/infinite.

		this.runFilterDebounced = debounce( this.runFilter.bind( this ), 0 );
		this.rangeDebounced = debounce( this.runFilter.bind( this ), 400 );
	}

	/**
	 * Найти [pf-list] для этой формы. pf-target нигде не обязателен:
	 * - если задан — используется как CSS-селектор (явная связь);
	 * - если на странице ровно одна форма и один список — связываются автоматически;
	 * - если форм/списков несколько и pf-target не задан — неоднозначность:
	 *   console.warn с подсказкой, best-effort — первая форма связывается с первым списком.
	 *
	 * @return {Element|null}
	 */
	PFForm.prototype.resolveListElement = function () {
		var targetSelector = this.formEl.getAttribute( 'pf-target' );

		if ( targetSelector ) {
			var explicitList = document.querySelector( targetSelector );
			if ( ! explicitList ) {
				console.error( 'PF Filter: элемент [pf-list] по селектору "' + targetSelector + '" не найден, форма не инициализирована.' );
				return null;
			}
			return explicitList;
		}

		var allForms = qsa( document, '[pf-form]' );
		var allLists = qsa( document, '[pf-list]' );

		if ( 1 === allForms.length && 1 === allLists.length ) {
			return allLists[ 0 ];
		}

		console.warn( 'PF Filter: атрибут pf-target не указан, а на странице несколько [pf-form] и/или [pf-list] — однозначно связать их нельзя. Добавьте pf-target="#selector" на форму. Пробую связать первую форму с первым списком.' );

		if ( allLists.length && allForms[ 0 ] === this.formEl ) {
			return allLists[ 0 ];
		}

		return null;
	};

	PFForm.prototype.init = function () {
		if ( ! this.formEl.hasAttribute( 'pf-form' ) ) {
			// На случай если элемент передан ошибочно.
			return;
		}

		this.listEl = this.resolveListElement();
		if ( ! this.listEl ) {
			// resolveListElement() уже вывел подходящее сообщение (error для явного,
			// но не найденного pf-target; warn для неоднозначного случая без него).
			return;
		}

		this.outputEl = qs( this.formEl, '[pf-output]' );
		if ( ! this.outputEl ) {
			console.error( 'PF Filter: [pf-output] не найден, группы фильтров не будут рендериться.' );
		}

		this.templatesEl = qs( this.formEl, '[pf-templates]' );
		if ( this.templatesEl ) {
			// [pf-templates] — библиотека шаблонов для клонирования, на живой странице
			// её саму видно быть не должно (иначе пользователь увидит сырую разметку
			// верстальщика вместо реальных данных).
			this.templatesEl.classList.add( 'pf-hidden' );
		} else {
			console.error( 'PF Filter: [pf-templates] не найден, фильтр не будет построен.' );
		}

		this.loadingEl = document.querySelector( '[pf-loading]' );
		this.emptyEl = document.querySelector( '[pf-empty]' );
		// Плагин сам гарантирует, что оба скрыты по умолчанию — не полагается на то,
		// что тема пропишет для них display:none. is-hidden снимается/добавляется
		// в runFilter()/handleResponse(), а не только после первого запроса.
		if ( this.loadingEl ) {
			this.loadingEl.classList.add( 'is-hidden' );
		}
		if ( this.emptyEl ) {
			this.emptyEl.classList.add( 'is-hidden' );
		}
		this.paginationEl = document.querySelector( '[pf-pagination]' );
		this.paginationMode = this.paginationEl ? this.paginationEl.getAttribute( 'pf-pagination' ) : null;

		var explicitLogic = this.formEl.getAttribute( 'pf-logic' );
		this.logic = ( 'and' === explicitLogic || 'or' === explicitLogic ) ? explicitLogic : 'and';

		var self = this;
		this.fetchConfig()
			.then( function ( config ) {
				self.config = config;
				self.showCounts = !! ( config.settings && config.settings.show_counts );
				self.searchThreshold = ( config.settings && config.settings.search_threshold ) || 7;
				if ( ! self.formEl.getAttribute( 'pf-logic' ) ) {
					self.logic = ( config.settings && config.settings.logic ) || 'and';
				}

				if ( self.outputEl && self.templatesEl ) {
					self.buildGroups( config.groups || [] );
				}

				self.initSort( config.sort_options || [] );
				self.initPagination();
				self.initActiveFilters();

				self.restoreFromUrl();
			} )
			.catch( function ( err ) {
				console.error( 'PF Filter: не удалось загрузить /config.', err );
			} );
	};

	/** GET /wp-json/pf/v1/config, опционально с ?category=. */
	PFForm.prototype.fetchConfig = function ( category ) {
		var url = window.pfConfig.restUrl + 'config';
		if ( category ) {
			url += '?category=' + encodeURIComponent( category );
		}
		return fetch( url, {
			headers: { 'X-WP-Nonce': window.pfConfig.nonce },
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} );
	};

	// -----------------------------------------------------------------
	// Построение формы фильтров из конфига
	// -----------------------------------------------------------------

	PFForm.prototype.buildGroups = function ( groups ) {
		var self = this;

		// [pf-output] — точка вставки плагина. Если верстальщик оставил там
		// дизайн-превью (например, не убрал пример разметки после вёрстки в Webflow),
		// оно должно быть заменено реальными группами, а не остаться рядом с ними.
		this.outputEl.innerHTML = '';

		if ( ! this._consumedTemplates ) {
			this._consumedTemplates = new WeakSet();
		}
		if ( ! this._templateCache ) {
			// Кэш узлов-шаблонов по имени: первую группу с данным pf-template мы
			// физически ПЕРЕМЕЩАЕМ из [pf-templates] (см. ниже), и после этого его
			// уже не найти свежим querySelector внутри templatesEl. Кэш хранит
			// ссылку на узел независимо от того, где он сейчас находится в DOM.
			this._templateCache = {};
		}

		groups.forEach( function ( groupConfig ) {
			var tpl = self._templateCache[ groupConfig.template ];
			if ( undefined === tpl ) {
				tpl = qs( self.templatesEl, '[pf-template="' + groupConfig.template + '"]' );
				self._templateCache[ groupConfig.template ] = tpl;
			}
			if ( ! tpl ) {
				console.warn( 'PF Filter: шаблон [pf-template="' + groupConfig.template + '"] не найден, группа "' + groupConfig.field + '" пропущена.' );
				return;
			}

			// Дропдауны и другие Webflow-интерактивы (w-dropdown-toggle и т.п.) внутри
			// шаблона работают через обработчики, которые Webflow навешивает на
			// оригинальный узел при загрузке страницы — cloneNode() их не копирует.
			// Поэтому для ПЕРВОЙ группы, использующей этот тип шаблона, забираем
			// оригинальный узел целиком (перемещаем — Webflow-интерактивность
			// сохраняется), а не клонируем. Если тот же pf-template используется ещё
			// одной группой — для неё клонируем, как раньше (иначе взять нечего).
			var groupNode = self._consumedTemplates.has( tpl ) ? tpl.cloneNode( true ) : tpl;
			self._consumedTemplates.add( tpl );

			var clone;
			if ( 'range' === groupConfig.template ) {
				clone = self.buildRangeGroup( groupConfig, groupNode );
			} else if ( 'category-tree' === groupConfig.template ) {
				clone = self.buildCategoryTreeGroup( groupConfig, groupNode );
			} else {
				clone = self.buildListGroup( groupConfig, groupNode );
			}

			if ( clone ) {
				self.outputEl.appendChild( clone );
				self.groups[ groupConfig.field ] = { config: groupConfig, el: clone };
			}
		} );
	};

	/** checkbox / radio / tags / dropdown-checkbox / dropdown-radio — общий алгоритм строк. */
	PFForm.prototype.buildListGroup = function ( groupConfig, groupNode ) {
		var self = this;
		// groupNode уже решён вызывающим кодом (buildGroups) — это либо перемещённый
		// оригинальный узел шаблона (первое использование, Webflow-интерактивность
		// сохранена), либо его клон (повторное использование того же pf-template).
		var clone = groupNode;

		var nameEl = qs( clone, '[pf-filter-name]' );
		if ( nameEl ) {
			nameEl.textContent = groupConfig.label;
		}

		// Снимок ДО клонирования: дизайн-шаблон группы часто содержит несколько
		// примеров [pf-filter-row] сразу (разные визуальные состояния), а не один.
		// Все они должны быть удалены после наполнения реальными значениями —
		// не только тот единственный элемент, который взят как шаблон.
		var existingRows = qsa( clone, '[pf-filter-row]' );
		var rowTpl = existingRows[ 0 ];
		if ( ! rowTpl ) {
			console.warn( 'PF Filter: [pf-filter-row] не найден в шаблоне группы "' + groupConfig.field + '", группа не наполнена.' );
			return clone;
		}

		var rowParent = rowTpl.parentElement;
		var isTags = 'tags' === groupConfig.template;
		var isRadio = 'radio' === groupConfig.template || 'dropdown-radio' === groupConfig.template;
		var values = groupConfig.values || [];

		values.forEach( function ( value ) {
			var rowClone = rowTpl.cloneNode( true );
			self.fillRow( rowClone, value );

			var input = qs( rowClone, 'input' );
			if ( input ) {
				var id = uid( 'pf-input' );
				input.id = id;
				input.value = value.value;
				if ( isRadio ) {
					input.name = 'pf-group-' + groupConfig.field;
				}
				var label = qs( rowClone, 'label' );
				if ( label ) {
					label.setAttribute( 'for', id );
				}
				input.addEventListener( 'change', function () {
					self.runFilter( { resetPage: true, forceReplace: true } );
				} );
			} else if ( isTags ) {
				rowClone.addEventListener( 'click', function () {
					rowClone.classList.toggle( 'is-active' );
					self.runFilter( { resetPage: true, forceReplace: true } );
				} );
			}

			rowParent.appendChild( rowClone );
		} );

		existingRows.forEach( function ( el ) {
			el.remove();
		} );

		this.initGroupSearch( clone, groupConfig, values.length );

		return clone;
	};

	/** Наполнить одну строку значения: value/count/swatch, пометить data-pf-value. */
	PFForm.prototype.fillRow = function ( rowClone, value ) {
		rowClone.setAttribute( 'data-pf-value', value.value );

		var valueEl = qs( rowClone, '[pf-filter-value]' );
		if ( valueEl ) {
			valueEl.textContent = value.label;
		}

		var countEl = qs( rowClone, '[pf-filter-count]' );
		if ( countEl ) {
			if ( this.showCounts ) {
				countEl.textContent = value.count;
			} else {
				countEl.textContent = '';
			}
		}

		var swatchEl = qs( rowClone, '[pf-filter-swatch]' );
		if ( swatchEl ) {
			if ( value.color ) {
				swatchEl.style.setProperty( '--swatch-color', value.color );
				swatchEl.classList.remove( 'pf-hidden' );
			} else {
				swatchEl.classList.add( 'pf-hidden' );
			}
		}
	};

	/** Поиск внутри группы: показать/скрыть по порогу, фильтровать строки по вводу. */
	PFForm.prototype.initGroupSearch = function ( groupClone, groupConfig, valuesCount ) {
		var searchInput = qs( groupClone, 'input[type="text"]' );
		if ( ! searchInput ) {
			return;
		}

		if ( valuesCount < this.searchThreshold ) {
			searchInput.classList.add( 'pf-hidden' );
		} else {
			searchInput.classList.remove( 'pf-hidden' );
		}

		searchInput.addEventListener( 'input', function () {
			var query = searchInput.value.trim().toLowerCase();
			qsa( groupClone, '[data-pf-value]' ).forEach( function ( row ) {
				var valueEl = qs( row, '[pf-filter-value]' );
				var text = valueEl ? valueEl.textContent.toLowerCase() : '';
				row.classList.toggle( 'pf-hidden', query.length > 0 && ! text.includes( query ) );
			} );
		} );
	};

	/** Диапазон цены (или любой другой range-группы). */
	PFForm.prototype.buildRangeGroup = function ( groupConfig, groupNode ) {
		var self = this;
		var clone = groupNode; // см. комментарий в buildGroups() про move-vs-clone.

		var nameEl = qs( clone, '[pf-filter-name]' );
		if ( nameEl ) {
			nameEl.textContent = groupConfig.label;
		}

		var slider = qs( clone, '[pf-filter-range-slider]' );
		if ( ! slider ) {
			console.warn( 'PF Filter: [pf-filter-range-slider] не найден для группы "' + groupConfig.field + '", range недоступен.' );
			return clone;
		}

		var min = parseFloat( groupConfig.min ) || 0;
		var max = parseFloat( groupConfig.max ) || 0;
		var step = parseFloat( groupConfig.step ) || 1;

		slider.dataset.min = min;
		slider.dataset.max = max;
		slider.dataset.step = step;
		slider.dataset.currentMin = min;
		slider.dataset.currentMax = max;

		var minBoundEl = qs( clone, '[pf-filter-range="min"]' );
		if ( minBoundEl ) {
			minBoundEl.textContent = min;
		}
		var maxBoundEl = qs( clone, '[pf-filter-range="max"]' );
		if ( maxBoundEl ) {
			maxBoundEl.textContent = max;
		}

		var minHandle = qs( clone, '[pf-filter-range-handle="min"]' );
		var maxHandle = qs( clone, '[pf-filter-range-handle="max"]' );
		var minValueEl = qs( clone, '[pf-filter-range-value="min"]' );
		var maxValueEl = qs( clone, '[pf-filter-range-value="max"]' );

		if ( ! minHandle || ! maxHandle ) {
			console.warn( 'PF Filter: [pf-filter-range-handle] не найден для группы "' + groupConfig.field + '", drag недоступен.' );
		}
		if ( ! minValueEl || ! maxValueEl ) {
			console.warn( 'PF Filter: [pf-filter-range-value] не найден для группы "' + groupConfig.field + '", значения не отображаются.' );
		}

		setRangeDisplayValue( minValueEl, min );
		setRangeDisplayValue( maxValueEl, max );

		function percentFor( value ) {
			if ( max === min ) {
				return 0;
			}
			return clamp( ( value - min ) / ( max - min ) * 100, 0, 100 );
		}

		function positionHandle( handle, value ) {
			if ( handle ) {
				handle.style.left = percentFor( value ) + '%';
			}
		}

		positionHandle( minHandle, min );
		positionHandle( maxHandle, max );

		function roundToStep( value ) {
			var rounded = Math.round( ( value - min ) / step ) * step + min;
			return clamp( rounded, min, max );
		}

		function updateFromDrag( type, clientX ) {
			var rect = slider.getBoundingClientRect();
			var percent = rect.width > 0 ? clamp( ( clientX - rect.left ) / rect.width, 0, 1 ) : 0;
			var raw = min + percent * ( max - min );
			var value = roundToStep( raw );

			var currentMin = parseFloat( slider.dataset.currentMin );
			var currentMax = parseFloat( slider.dataset.currentMax );

			if ( 'min' === type ) {
				value = clamp( value, min, currentMax );
				slider.dataset.currentMin = value;
				positionHandle( minHandle, value );
				setRangeDisplayValue( minValueEl, value );
			} else {
				value = clamp( value, currentMin, max );
				slider.dataset.currentMax = value;
				positionHandle( maxHandle, value );
				setRangeDisplayValue( maxValueEl, value );
			}
		}

		function bindDrag( handle, type ) {
			if ( ! handle ) {
				return;
			}
			handle.setAttribute( 'tabindex', '0' );

			function onMove( e ) {
				var clientX = e.touches ? e.touches[ 0 ].clientX : e.clientX;
				updateFromDrag( type, clientX );
			}
			function onUp() {
				document.removeEventListener( 'mousemove', onMove );
				document.removeEventListener( 'mouseup', onUp );
				document.removeEventListener( 'touchmove', onMove );
				document.removeEventListener( 'touchend', onUp );
				self.rangeDebounced( { resetPage: true, forceReplace: true } );
			}
			function onStart( e ) {
				e.preventDefault();
				document.addEventListener( 'mousemove', onMove );
				document.addEventListener( 'mouseup', onUp );
				document.addEventListener( 'touchmove', onMove, { passive: false } );
				document.addEventListener( 'touchend', onUp );
			}

			handle.addEventListener( 'mousedown', onStart );
			handle.addEventListener( 'touchstart', onStart, { passive: false } );

			handle.addEventListener( 'keydown', function ( e ) {
				if ( 'ArrowLeft' !== e.key && 'ArrowRight' !== e.key ) {
					return;
				}
				e.preventDefault();
				var delta = 'ArrowLeft' === e.key ? -step : step;
				var currentMin = parseFloat( slider.dataset.currentMin );
				var currentMax = parseFloat( slider.dataset.currentMax );
				if ( 'min' === type ) {
					var newMin = clamp( currentMin + delta, min, currentMax );
					slider.dataset.currentMin = newMin;
					positionHandle( minHandle, newMin );
					setRangeDisplayValue( minValueEl, newMin );
				} else {
					var newMax = clamp( currentMax + delta, currentMin, max );
					slider.dataset.currentMax = newMax;
					positionHandle( maxHandle, newMax );
					setRangeDisplayValue( maxValueEl, newMax );
				}
				self.rangeDebounced( { resetPage: true, forceReplace: true } );
			} );
		}

		bindDrag( minHandle, 'min' );
		bindDrag( maxHandle, 'max' );

		function bindNumberInput( el, type ) {
			if ( ! el || 'INPUT' !== el.tagName ) {
				return;
			}
			el.addEventListener( 'change', function () {
				var currentMin = parseFloat( slider.dataset.currentMin );
				var currentMax = parseFloat( slider.dataset.currentMax );
				var value = parseFloat( el.value );
				if ( isNaN( value ) ) {
					return;
				}
				if ( 'min' === type ) {
					value = clamp( value, min, currentMax );
					slider.dataset.currentMin = value;
					positionHandle( minHandle, value );
				} else {
					value = clamp( value, currentMin, max );
					slider.dataset.currentMax = value;
					positionHandle( maxHandle, value );
				}
				el.value = value;
				self.rangeDebounced( { resetPage: true, forceReplace: true } );
			} );
		}

		bindNumberInput( minValueEl, 'min' );
		bindNumberInput( maxValueEl, 'max' );

		return clone;
	};

	/** Дерево категорий — рекурсивный алгоритм buildLevel по нумерованным уровням. */
	PFForm.prototype.buildCategoryTreeGroup = function ( groupConfig, groupNode ) {
		var clone = groupNode; // см. комментарий в buildGroups() про move-vs-clone.

		var nameEl = qs( clone, '[pf-filter-name]' );
		if ( nameEl ) {
			nameEl.textContent = groupConfig.label;
		}

		var rootContainer = qs( clone, '[pf-filter-list-1]' );
		if ( ! rootContainer ) {
			console.warn( 'PF Filter: [pf-filter-list-1] не найден для группы "' + groupConfig.field + '", дерево не построено.' );
			return clone;
		}

		this.buildTreeLevel( groupConfig.values || [], rootContainer, 1, clone );

		return clone;
	};

	PFForm.prototype.buildTreeLevel = function ( items, container, level, templateRoot ) {
		var self = this;
		if ( ! container ) {
			return;
		}

		// container на этом уровне уже содержит шаблонные узлы pf-filter-parent-N /
		// pf-filter-row-N — либо оригинальные (уровень 1, внутри templateRoot),
		// либо их неиспользуемые копии (если container — часть только что
		// склонированного родителя предыдущего уровня, а вместе с ним скопировалось
		// и всё вложенное поддерево шаблонов). В обоих случаях после наполнения
		// контейнера реальными данными эти узлы — лишние, их нужно убрать.
		var staleTemplateNodes = qsa( container, '[pf-filter-parent-' + level + '], [pf-filter-row-' + level + ']' );

		items.forEach( function ( item ) {
			if ( item.children && item.children.length > 0 ) {
				var parentTpl = qs( templateRoot, '[pf-filter-parent-' + level + ']' );
				if ( ! parentTpl ) {
					// Реальная глубина данных превышает глубину шаблона — тихо обрезать
					// (предупреждение показывается только в админке, не в консоли сайта).
					return;
				}
				var parentClone = parentTpl.cloneNode( true );
				self.fillRow( parentClone, item );
				self.wireTreeInputs( parentClone, item.value );

				var childContainer = qs( parentClone, '[pf-filter-list-' + ( level + 1 ) + ']' );
				self.buildTreeLevel( item.children, childContainer, level + 1, templateRoot );

				container.appendChild( parentClone );
			} else {
				var rowTpl = qs( templateRoot, '[pf-filter-row-' + level + ']' );
				if ( ! rowTpl ) {
					return;
				}
				var rowClone = rowTpl.cloneNode( true );
				self.fillRow( rowClone, item );
				self.wireTreeInputs( rowClone, item.value );

				container.appendChild( rowClone );
			}
		} );

		staleTemplateNodes.forEach( function ( el ) {
			el.remove();
		} );
	};

	/** Найти собственные (не дочерние — они ещё не вставлены) input'ы клона узла дерева и подключить фильтрацию. */
	PFForm.prototype.wireTreeInputs = function ( clone, value ) {
		var self = this;
		qsa( clone, 'input[type="checkbox"], input[type="radio"]' ).forEach( function ( input ) {
			input.value = value;
			input.addEventListener( 'change', function () {
				self.runFilter( { resetPage: true, forceReplace: true } );
			} );
		} );
	};

	// -----------------------------------------------------------------
	// Сбор состояния фильтров
	// -----------------------------------------------------------------

	PFForm.prototype.collectFilters = function () {
		var filters = {};

		Object.keys( this.groups ).forEach( function ( field ) {
			var group = this.groups[ field ];
			var template = group.config.template;

			if ( 'range' === template ) {
				var slider = qs( group.el, '[pf-filter-range-slider]' );
				if ( slider ) {
					filters[ field ] = {
						min: parseFloat( slider.dataset.currentMin ),
						max: parseFloat( slider.dataset.currentMax ),
					};
				}
				return;
			}

			if ( 'tags' === template ) {
				// Каждая строка помечена data-pf-value в fillRow(), этого достаточно
				// чтобы найти активные теги независимо от исходной разметки шаблона.
				var values = qsa( group.el, '[data-pf-value].is-active' ).map( function ( row ) {
					return row.getAttribute( 'data-pf-value' );
				} );
				if ( values.length ) {
					filters[ field ] = values;
				}
				return;
			}

			// checkbox / radio / dropdown-checkbox / dropdown-radio / category-tree
			var checked = qsa( group.el, 'input:checked' ).map( function ( input ) {
				return input.value;
			} );
			if ( checked.length ) {
				filters[ field ] = checked;
			}
		}, this );

		return filters;
	};

	// -----------------------------------------------------------------
	// AJAX-запрос при изменении фильтров
	// -----------------------------------------------------------------

	PFForm.prototype.runFilter = function ( opts ) {
		opts = opts || {};
		if ( opts.resetPage ) {
			this.state.paged = 1;
		}
		this._forceReplace = !! opts.forceReplace;

		if ( this.abortController ) {
			this.abortController.abort();
		}
		this.abortController = new AbortController();

		if ( this.loadingEl ) {
			this.loadingEl.classList.remove( 'is-hidden' );
		}

		var body = {
			filters: this.collectFilters(),
			logic: this.logic,
			orderby: this.state.orderby,
			order: this.state.order,
			paged: this.state.paged,
			posts_per_page: this.perPage,
			// Тема часто строит ссылки карточки (например «В корзину») от текущего URL —
			// сервер подставляет этот URL на время рендера вместо адреса REST-эндпоинта.
			page_url: window.location.href,
		};

		var self = this;
		fetch( window.pfConfig.restUrl + 'products', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.pfConfig.nonce,
			},
			body: JSON.stringify( body ),
			signal: this.abortController.signal,
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				self.handleResponse( data );
			} )
			.catch( function ( err ) {
				if ( 'AbortError' === err.name ) {
					return;
				}
				console.error( 'PF Filter: ошибка запроса /products.', err );
				if ( self.loadingEl ) {
					self.loadingEl.classList.add( 'is-hidden' );
				}
			} );
	};

	PFForm.prototype.handleResponse = function ( data ) {
		if ( this.loadingEl ) {
			this.loadingEl.classList.add( 'is-hidden' );
		}

		this.totalPages = data.pages || 1;
		this.totalCount = data.count || 0;
		this.state.paged = data.current_page || this.state.paged;

		if ( 0 === data.count ) {
			if ( this.emptyEl ) {
				this.emptyEl.classList.remove( 'is-hidden' );
			}
			if ( this.listEl ) {
				this.listEl.innerHTML = '';
			}
		} else {
			if ( this.emptyEl ) {
				this.emptyEl.classList.add( 'is-hidden' );
			}

			if ( this.listEl ) {
				var appendModes = [ 'load-more', 'both', 'infinite' ];
				if ( appendModes.includes( this.paginationMode ) && ! this._forceReplace ) {
					this.listEl.insertAdjacentHTML( 'beforeend', data.html );
				} else {
					this.listEl.innerHTML = data.html;
				}
			}
		}

		this.updateCounters( data );
		this.updateGroupCounts( data.counts || {} );
		this.updatePaginationUI();
		this.updateActiveFilters();
		this.updateUrl();
	};

	PFForm.prototype.updateCounters = function ( data ) {
		qsa( document, '[pf-count]' ).forEach( function ( el ) {
			el.textContent = data.count;
		} );
	};

	PFForm.prototype.updateGroupCounts = function ( counts ) {
		var self = this;
		Object.keys( counts ).forEach( function ( field ) {
			var group = self.groups[ field ];
			if ( ! group ) {
				return;
			}

			if ( 'price' === field ) {
				return; // границы цены не пересчитываются повторно на клиенте.
			}

			var fieldCounts = counts[ field ];
			qsa( group.el, '[data-pf-value]' ).forEach( function ( row ) {
				var value = row.getAttribute( 'data-pf-value' );
				if ( ! ( value in fieldCounts ) ) {
					return;
				}
				var countEl = qs( row, '[pf-filter-count]' );
				if ( countEl && self.showCounts ) {
					countEl.textContent = fieldCounts[ value ];
				}
			} );
		} );
	};

	// -----------------------------------------------------------------
	// Сортировка
	// -----------------------------------------------------------------

	PFForm.prototype.initSort = function ( sortOptions ) {
		this.sortEl = document.querySelector( '[pf-sort]' );
		if ( ! this.sortEl ) {
			return;
		}

		var self = this;
		var triggerLabel = qs( this.sortEl, '[pf-sort-trigger-label]' );
		// Снимок ДО клонирования: может содержать не только сам шаблон, но и
		// дизайн-превью — например несколько статичных «Опция» внутри вложенной
		// обёртки, которые верстальщик не убрал. Все они должны быть удалены,
		// а не только тот единственный элемент, который мы взяли как шаблон.
		var existingOptions = qsa( this.sortEl, '[pf-sort-option]' );
		var optionTpl = existingOptions[ 0 ];

		if ( ! optionTpl ) {
			console.warn( 'PF Filter: [pf-sort-option] не найден, список сортировки не построен.' );
			return;
		}

		// Контейнер для вставки — родитель уже существующего элемента списка
		// (найденного по pf-sort-option), а не угаданный по классу Webflow/темы:
		// реальный родитель может быть на уровень глубже общей обёртки дропдауна.
		var list = optionTpl.parentElement;
		if ( ! list ) {
			console.warn( 'PF Filter: у [pf-sort-option] нет родителя, список сортировки не построен.' );
			return;
		}

		this.sortList = list;
		this.sortTriggerLabel = triggerLabel;

		sortOptions.forEach( function ( option, index ) {
			var clone = optionTpl.cloneNode( true );
			clone.textContent = option.label;
			clone.setAttribute( 'data-value', option.value );
			clone.classList.toggle( 'is-active', 0 === index );

			clone.addEventListener( 'click', function () {
				self.activateSortOption( option );
				self.runFilter( { resetPage: true, forceReplace: true } );
			} );

			list.appendChild( clone );
		} );

		existingOptions.forEach( function ( el ) {
			el.remove();
		} );

		if ( sortOptions.length ) {
			this.applyOrderby( sortOptions[ 0 ].value );
			if ( triggerLabel ) {
				triggerLabel.textContent = sortOptions[ 0 ].label;
			}
		}
	};

	/**
	 * Пометить опцию сортировки активной в UI и обновить this.state, но НЕ запускать
	 * фильтрацию — используется при восстановлении из URL, где итоговый runFilter
	 * должен произойти один раз после применения всех параметров, а не сразу здесь.
	 */
	PFForm.prototype.activateSortOption = function ( option ) {
		if ( this.sortList ) {
			qsa( this.sortList, '[data-value]' ).forEach( function ( el ) {
				el.classList.toggle( 'is-active', el.getAttribute( 'data-value' ) === option.value );
			} );
		}
		if ( this.sortTriggerLabel ) {
			this.sortTriggerLabel.textContent = option.label;
		}
		this.applyOrderby( option.value );
	};

	PFForm.prototype.applyOrderby = function ( value ) {
		if ( 'price-desc' === value ) {
			this.state.orderby = 'price';
			this.state.order = 'DESC';
		} else {
			this.state.orderby = value;
			this.state.order = 'ASC';
		}
		this.state.orderbyRaw = value;
	};

	// -----------------------------------------------------------------
	// Пагинация
	// -----------------------------------------------------------------

	PFForm.prototype.initPagination = function () {
		if ( ! this.paginationEl || ! this.paginationMode ) {
			return;
		}

		var self = this;

		if ( 'load-more' === this.paginationMode || 'both' === this.paginationMode ) {
			var loadMoreBtn = document.querySelector( '[pf-load-more]' );
			if ( loadMoreBtn ) {
				loadMoreBtn.addEventListener( 'click', function () {
					self.state.paged += 1;
					self.runFilter( { forceReplace: false } );
				} );
			}
		}

		if ( 'pages' === this.paginationMode || 'both' === this.paginationMode ) {
			var prevBtn = document.querySelector( '[pf-page-prev]' );
			var nextBtn = document.querySelector( '[pf-page-next]' );
			// pf-page-prev/next обычно <a href="#"> — без preventDefault клик
			// прыгал бы в начало страницы вместо запуска AJAX-фильтрации.
			if ( prevBtn ) {
				prevBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( self.state.paged > 1 ) {
						self.state.paged -= 1;
						self.runFilter( { forceReplace: true } );
					}
				} );
			}
			if ( nextBtn ) {
				nextBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( self.state.paged < self.totalPages ) {
						self.state.paged += 1;
						self.runFilter( { forceReplace: true } );
					}
				} );
			}
		}

		if ( 'infinite' === this.paginationMode ) {
			var trigger = document.querySelector( '[pf-infinite-trigger]' );
			if ( trigger && 'IntersectionObserver' in window ) {
				this.infiniteObserver = new IntersectionObserver( function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting && self.state.paged < self.totalPages ) {
							self.state.paged += 1;
							self.runFilter( { forceReplace: false } );
						}
					} );
				} );
				this.infiniteObserver.observe( trigger );
			} else if ( ! trigger ) {
				console.warn( 'PF Filter: [pf-infinite-trigger] не найден, автоподгрузка недоступна.' );
			}
		}
	};

	PFForm.prototype.updatePaginationUI = function () {
		if ( ! this.paginationMode ) {
			return;
		}

		if ( 'pages' === this.paginationMode || 'both' === this.paginationMode ) {
			var pagesContainer = document.querySelector( '[pf-pagination-pages]' );
			var itemTpl = document.querySelector( '[pf-page-item]' );
			if ( pagesContainer && itemTpl ) {
				// Кроме самого шаблона и наших предыдущих клонов, в разметке может
				// остаться статичный дизайн-превью (например, Webflow-пример «активной
				// второй страницы») — его тоже нужно убрать, иначе он будет висеть рядом
				// с реальной пагинацией.
				qsa( pagesContainer, '[pf-page-item]' ).forEach( function ( el ) {
					if ( el !== itemTpl && ! el.hasAttribute( 'data-pf-generated' ) ) {
						el.remove();
					}
				} );
				qsa( pagesContainer, '[data-pf-generated]' ).forEach( function ( el ) {
					el.remove();
				} );
				for ( var i = 1; i <= this.totalPages; i++ ) {
					var clone = itemTpl.cloneNode( true );
					clone.setAttribute( 'data-pf-generated', '1' );
					clone.textContent = i;
					clone.classList.toggle( 'is-active', i === this.state.paged );
					clone.classList.remove( 'pf-hidden' );

					( function ( pageNum ) {
						clone.addEventListener( 'click', function ( e ) {
							e.preventDefault();
							this.state.paged = pageNum;
							this.runFilter( { forceReplace: true } );
						}.bind( this ) );
					}.bind( this ) )( i );

					pagesContainer.appendChild( clone );
				}
				itemTpl.classList.add( 'pf-hidden' );
			}
		}

		var prevBtn = document.querySelector( '[pf-page-prev]' );
		if ( prevBtn ) {
			var isFirst = this.state.paged <= 1;
			prevBtn.classList.toggle( 'is-disabled', isFirst );
			if ( isFirst ) {
				prevBtn.setAttribute( 'disabled', 'disabled' );
			} else {
				prevBtn.removeAttribute( 'disabled' );
			}
		}

		var nextBtn = document.querySelector( '[pf-page-next]' );
		if ( nextBtn ) {
			var isLast = this.state.paged >= this.totalPages;
			nextBtn.classList.toggle( 'is-disabled', isLast );
			if ( isLast ) {
				nextBtn.setAttribute( 'disabled', 'disabled' );
			} else {
				nextBtn.removeAttribute( 'disabled' );
			}
		}

		var loadMoreBtn = document.querySelector( '[pf-load-more]' );
		if ( loadMoreBtn ) {
			loadMoreBtn.classList.toggle( 'pf-hidden', this.state.paged >= this.totalPages );
		}

		if ( 'infinite' === this.paginationMode && this.infiniteObserver && this.state.paged >= this.totalPages ) {
			this.infiniteObserver.disconnect();
		}
	};

	// -----------------------------------------------------------------
	// Активные фильтры
	// -----------------------------------------------------------------

	PFForm.prototype.initActiveFilters = function () {
		var self = this;

		// [pf-active-chip] — шаблон одного чипа, который клонируется в updateActiveFilters().
		// Сам шаблон должен быть скрыт сразу, а не только после первого реального
		// обновления фильтров — иначе на пустой странице виден необработанный чип
		// с текстом-заглушкой из разметки.
		qsa( document, '[pf-active-chip]' ).forEach( function ( chipTpl ) {
			chipTpl.classList.add( 'pf-hidden' );
		} );

		qsa( document, '[pf-active-reset]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				self.resetAllFilters();
			} );
		} );
	};

	PFForm.prototype.resetAllFilters = function () {
		Object.keys( this.groups ).forEach( function ( field ) {
			var group = this.groups[ field ];
			qsa( group.el, 'input[type="checkbox"], input[type="radio"]' ).forEach( function ( input ) {
				input.checked = false;
			} );
			qsa( group.el, '[data-pf-value].is-active' ).forEach( function ( row ) {
				row.classList.remove( 'is-active' );
			} );

			if ( 'range' === group.config.template ) {
				var slider = qs( group.el, '[pf-filter-range-slider]' );
				if ( slider ) {
					slider.dataset.currentMin = slider.dataset.min;
					slider.dataset.currentMax = slider.dataset.max;
					var minHandle = qs( group.el, '[pf-filter-range-handle="min"]' );
					var maxHandle = qs( group.el, '[pf-filter-range-handle="max"]' );
					if ( minHandle ) {
						minHandle.style.left = '0%';
					}
					if ( maxHandle ) {
						maxHandle.style.left = '100%';
					}
					setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="min"]' ), slider.dataset.min );
					setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="max"]' ), slider.dataset.max );
				}
			}
		}, this );

		this.runFilter( { resetPage: true, forceReplace: true } );
	};

	PFForm.prototype.updateActiveFilters = function () {
		var self = this;
		var filters = this.collectFilters();

		qsa( document, '[pf-active-filters]' ).forEach( function ( container ) {
			var chipTpl = qs( container, '[pf-active-chip]' );
			if ( ! chipTpl ) {
				return;
			}

			qsa( container, '[data-pf-generated]' ).forEach( function ( el ) {
				el.remove();
			} );

			Object.keys( filters ).forEach( function ( field ) {
				var group = self.groups[ field ];
				var groupLabel = group ? group.config.label : field;

				if ( 'price' === field ) {
					var priceFilter = filters.price;
					var slider = group ? qs( group.el, '[pf-filter-range-slider]' ) : null;
					var fullMin = slider ? parseFloat( slider.dataset.min ) : null;
					var fullMax = slider ? parseFloat( slider.dataset.max ) : null;
					if ( priceFilter.min === fullMin && priceFilter.max === fullMax ) {
						return;
					}
					self.appendChip( container, chipTpl, groupLabel + ': ' + priceFilter.min + '–' + priceFilter.max, function () {
						self.resetGroupFilter( field );
					} );
					return;
				}

				( filters[ field ] || [] ).forEach( function ( value ) {
					var valueLabel = self.findValueLabel( field, value );
					self.appendChip( container, chipTpl, groupLabel + ': ' + valueLabel, function () {
						self.removeFilterValue( field, value );
					} );
				} );
			} );
		} );
	};

	PFForm.prototype.appendChip = function ( container, chipTpl, text, onRemove ) {
		var clone = chipTpl.cloneNode( true );
		clone.classList.remove( 'pf-hidden' );
		clone.setAttribute( 'data-pf-generated', '1' );

		var labelEl = qs( clone, '[pf-chip-label]' );
		if ( labelEl ) {
			labelEl.textContent = text;
		}

		var removeBtn = qs( clone, '[pf-chip-remove]' );
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', onRemove );
		}

		container.appendChild( clone );
	};

	PFForm.prototype.findValueLabel = function ( field, value ) {
		var group = this.groups[ field ];
		if ( ! group ) {
			return value;
		}
		var row = qs( group.el, '[data-pf-value="' + CSS.escape( value ) + '"]' );
		var valueEl = row ? qs( row, '[pf-filter-value]' ) : null;
		return valueEl ? valueEl.textContent : value;
	};

	PFForm.prototype.removeFilterValue = function ( field, value ) {
		var group = this.groups[ field ];
		if ( ! group ) {
			return;
		}
		var row = qs( group.el, '[data-pf-value="' + CSS.escape( value ) + '"]' );
		if ( row ) {
			var input = qs( row, 'input' );
			if ( input ) {
				input.checked = false;
			}
			row.classList.remove( 'is-active' );
		}
		this.runFilter( { resetPage: true, forceReplace: true } );
	};

	PFForm.prototype.resetGroupFilter = function ( field ) {
		var group = this.groups[ field ];
		if ( ! group ) {
			return;
		}
		if ( 'range' === group.config.template ) {
			var slider = qs( group.el, '[pf-filter-range-slider]' );
			if ( slider ) {
				slider.dataset.currentMin = slider.dataset.min;
				slider.dataset.currentMax = slider.dataset.max;
				setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="min"]' ), slider.dataset.min );
				setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="max"]' ), slider.dataset.max );
				var minHandle = qs( group.el, '[pf-filter-range-handle="min"]' );
				var maxHandle = qs( group.el, '[pf-filter-range-handle="max"]' );
				if ( minHandle ) {
					minHandle.style.left = '0%';
				}
				if ( maxHandle ) {
					maxHandle.style.left = '100%';
				}
			}
		}
		this.runFilter( { resetPage: true, forceReplace: true } );
	};

	// -----------------------------------------------------------------
	// URL и история браузера
	// -----------------------------------------------------------------

	PFForm.prototype.updateUrl = function () {
		var params = new URLSearchParams();
		var filters = this.collectFilters();

		Object.keys( filters ).forEach( function ( field ) {
			if ( 'price' === field ) {
				if ( null !== filters.price.min && ! isNaN( filters.price.min ) ) {
					params.set( 'price_min', filters.price.min );
				}
				if ( null !== filters.price.max && ! isNaN( filters.price.max ) ) {
					params.set( 'price_max', filters.price.max );
				}
				return;
			}
			if ( filters[ field ] && filters[ field ].length ) {
				params.set( field, filters[ field ].join( ',' ) );
			}
		} );

		if ( this.state.orderbyRaw ) {
			params.set( 'orderby', this.state.orderbyRaw );
		}
		if ( this.state.paged > 1 ) {
			params.set( 'paged', this.state.paged );
		}

		var query = params.toString();
		var newUrl = window.location.pathname + ( query ? '?' + query : '' );
		window.history.pushState( null, '', newUrl );
	};

	PFForm.prototype.restoreFromUrl = function () {
		var params = new URLSearchParams( window.location.search );
		if ( ! Array.from( params.keys() ).length ) {
			return;
		}

		var self = this;
		var hasRelevantParams = false;

		params.forEach( function ( rawValue, key ) {
			if ( 'orderby' === key ) {
				hasRelevantParams = true;
				self.selectSortOption( rawValue );
				return;
			}
			if ( 'paged' === key ) {
				hasRelevantParams = true;
				self.state.paged = parseInt( rawValue, 10 ) || 1;
				return;
			}
			if ( 'price_min' === key || 'price_max' === key ) {
				hasRelevantParams = true;
				self.restorePriceFromUrl( key, rawValue );
				return;
			}

			var group = self.groups[ key ];
			if ( ! group ) {
				return;
			}
			hasRelevantParams = true;
			var values = rawValue.split( ',' );
			values.forEach( function ( value ) {
				var row = qs( group.el, '[data-pf-value="' + CSS.escape( value ) + '"]' );
				if ( ! row ) {
					return;
				}
				var input = qs( row, 'input' );
				if ( input ) {
					input.checked = true;
				} else {
					row.classList.add( 'is-active' );
				}
			} );
		} );

		if ( hasRelevantParams ) {
			this.runFilter( { forceReplace: true } );
		}
	};

	PFForm.prototype.restorePriceFromUrl = function ( key, rawValue ) {
		var group = this.groups.price;
		if ( ! group ) {
			return;
		}
		var slider = qs( group.el, '[pf-filter-range-slider]' );
		if ( ! slider ) {
			return;
		}
		var value = parseFloat( rawValue );
		if ( isNaN( value ) ) {
			return;
		}
		if ( 'price_min' === key ) {
			slider.dataset.currentMin = value;
			setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="min"]' ), value );
		} else {
			slider.dataset.currentMax = value;
			setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="max"]' ), value );
		}
	};

	PFForm.prototype.selectSortOption = function ( value ) {
		if ( ! this.sortList ) {
			this.applyOrderby( value );
			return;
		}
		var option = qs( this.sortList, '[data-value="' + CSS.escape( value ) + '"]' );
		if ( option ) {
			// Только применяем состояние — итоговый runFilter() запустится один раз
			// в конце restoreFromUrl(), после того как применены все параметры URL.
			this.activateSortOption( { value: value, label: option.textContent } );
		} else {
			this.applyOrderby( value );
		}
	};

	// ---------------------------------------------------------------------
	// Инициализация: найти все [pf-form] на странице
	// ---------------------------------------------------------------------

	function initAll() {
		if ( ! window.pfConfig ) {
			console.error( 'PF Filter: window.pfConfig отсутствует, движок не инициализирован.' );
			return;
		}

		var forms = qsa( document, '[pf-form]' );
		if ( ! forms.length ) {
			return;
		}

		forms.forEach( function ( formEl ) {
			try {
				new PFForm( formEl ).init();
			} catch ( err ) {
				console.error( 'PF Filter: ошибка инициализации формы.', err );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
