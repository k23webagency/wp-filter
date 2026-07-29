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

	/**
	 * Программно установить input.checked и разослать события 'click' и 'change'.
	 *
	 * Многие конструкторы (в т.ч. Webflow) рисуют кастомный чекбокс/радио
	 * отдельным элементом рядом со скрытым нативным input и обновляют его по
	 * событию change/click на этом input — простое присвоение input.checked
	 * само по себе такое событие не порождает, визуальный элемент остаётся в
	 * старом состоянии, хотя реальное значение уже изменилось.
	 *
	 * Для СНЯТИЯ отметки (value === false) этого недостаточно для Webflow:
	 * его виджет держит "визуально отмечен" отдельным классом
	 * w--redirected-checked на соседнем элементе, который сама Webflow
	 * добавляет/убирает ТОЛЬКО в ответ на клик по конкретному input. Кликом
	 * принципиально нельзя представить "сними выбор со всех" (клик по radio
	 * всегда означает "выбери этот один") — событий тут ждать бессмысленно,
	 * класс снимается явно. На темах без Webflow такой класс просто не найдётся.
	 */
	function setChecked( input, value ) {
		if ( ! input || input.checked === value ) {
			return;
		}
		input.checked = value;
		input.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		if ( false === value ) {
			var row = input.closest( 'label' ) || input.parentElement;
			qsa( row, '.w--redirected-checked' ).forEach( function ( el ) {
				el.classList.remove( 'w--redirected-checked' );
			} );
		}
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

	/**
	 * Позиция значения в процентах по границам [min, max].
	 *
	 * @param {boolean} isMax Какой из двух ползунков позиционируется — нужно
	 *   только на случай схлопнувшегося диапазона (min === max: очень узкий
	 *   диапазон цены или вообще один товар). Без этого оба ползунка легли бы
	 *   в одну точку — не различить, не за что зацепиться мышью/пальцем.
	 *   Вместо этого разносим их по разным краям трека (min — 0%, max — 100%):
	 *   оба остаются на виду и доступны для взаимодействия.
	 */
	function percentForRange( value, min, max, isMax ) {
		if ( max === min ) {
			return isMax ? 100 : 0;
		}
		return clamp( ( value - min ) / ( max - min ) * 100, 0, 100 );
	}

	/**
	 * Спозиционировать ползунок диапазона по значению относительно [min, max].
	 * left:X% позиционирует ЛЕВЫЙ КРАЙ ползунка, а не его центр — без
	 * компенсации transform ползунок на 100% вылезает вправо за пределы трека
	 * на свою собственную ширину (симметрично на 0% — влево). translateX(-50%)
	 * центрирует ползунок ровно на вычисленном проценте.
	 *
	 * @param {boolean} isMax См. percentForRange() — какой это ползунок, min или max.
	 */
	function positionRangeHandle( handle, value, min, max, isMax ) {
		if ( ! handle ) {
			return;
		}
		handle.style.left = percentForRange( value, min, max, isMax ) + '%';
		handle.style.transform = 'translateX(-50%)';
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
		this.paginationMode = null;

		this.config = null;
		this.groups = {}; // field -> { config, el }
		this.showCounts = true;
		this.searchThreshold = 7;
		this.logic = 'and';
		this.perPage = 12;
		this.syncUrl = true;

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
		this._suppressAutoFilter = false; // true во время пачечного восстановления/сброса контролов — подавляет автозапуск runFilter() по change.

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

		// [pf-form] — реальный <form>, но никогда не должен отправляться нативно
		// (это чисто клиентский UI фильтра, а не форма с реальным действием).
		// Без этой защиты Enter в поле поиска группы или клик по элементу без
		// явного preventDefault (например <button> без type="button" в вёрстке
		// темы) вызывает настоящий submit — на Webflow-сайтах это показывает
		// служебную ошибку самого Webflow вроде "Формы не настроены".
		this.formEl.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
		} );

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
		// Активная стратегия пагинации — исключительно настройка админки
		// (pagination_strategy), устанавливается ниже, после загрузки /config.
		// В разметке страницы могут одновременно лежать готовые элементы сразу
		// под несколько стратегий (страницы + "загрузить ещё" + бесконечная
		// прокрутка) — по их наличию нельзя однозначно судить, какая должна
		// быть активна, поэтому разметка на выбор стратегии не влияет.
		this.paginationMode = null;

		var explicitLogic = this.formEl.getAttribute( 'pf-logic' );
		this.logic = ( 'and' === explicitLogic || 'or' === explicitLogic ) ? explicitLogic : 'and';

		var self = this;
		this.fetchConfig()
			.then( function ( config ) {
				self.config = config;
				self.showCounts = !! ( config.settings && config.settings.show_counts );
				self.searchThreshold = ( config.settings && config.settings.search_threshold ) || 7;
				self.perPage = ( config.settings && config.settings.posts_per_page ) || 12;
				if ( ! self.formEl.getAttribute( 'pf-logic' ) ) {
					self.logic = ( config.settings && config.settings.logic ) || 'and';
				}
				self.paginationMode = ( config.settings && config.settings.pagination_strategy ) || null;
				self.syncUrl = ! config.settings || false !== config.settings.sync_url;

				if ( self.outputEl && self.templatesEl ) {
					self.buildGroups( config.groups || [] );
					self.reinitWebflow();
					self.dispatchBuiltEvent();
				}

				self.initSort( config.sort_options || [] );
				self.initPagination();
				self.initActiveFilters();

				var restoredFromUrl = self.restoreFromUrl();
				if ( ! restoredFromUrl ) {
					// Первая загрузка страницы без фильтров в URL — [pf-list] не трогаем
					// (уже отрендерен обычным циклом WordPress), но [pf-count], счётчики
					// групп и пагинация должны сразу показывать реальные цифры, а не
					// ждать первого изменения фильтра пользователем.
					self.runFilter( { silent: true } );
				}
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
				self.initGroupRemove( clone, groupConfig.field );
			}
		} );
	};

	/**
	 * [pf-filter-remove] — необязательная кнопка сброса фильтра КОНКРЕТНО
	 * этой группы, может быть где угодно внутри pf-template. В отличие от
	 * [pf-active-reset] (сбрасывает вообще все фильтры сразу), эта — только
	 * значения своей группы. Работает одинаково для любого типа шаблона —
	 * checkbox/radio/tags/range/dropdown-checkbox/dropdown-radio/category-tree.
	 * Скрыта по умолчанию (как и [pf-active-reset]/[pf-active-chip]) — до
	 * первого ответа сервера показываться нечему, видимость обновляется в
	 * updateActiveFilters() по факту реально активного фильтра этой группы.
	 */
	PFForm.prototype.initGroupRemove = function ( groupClone, field ) {
		var self = this;
		qsa( groupClone, '[pf-filter-remove]' ).forEach( function ( btn ) {
			btn.classList.add( 'pf-hidden' );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.resetGroupFilter( field );
			} );
		} );
	};

	/**
	 * Попросить сам Webflow пересканировать текущий DOM и заново
	 * инициализировать свои дропдауны/интерэкшены (анимации открытия-закрытия
	 * и т.п. настроены в самом Webflow — плагин их не повторяет и не должен).
	 *
	 * Зачем это вообще нужно: Webflow инициализирует свои виджеты один раз при
	 * загрузке страницы. Форма фильтра строится позже — асинхронно, уже после
	 * ответа /config — поэтому построенные узлы (включая клоны дерева
	 * категорий, где, в отличие от групп верхнего уровня, нельзя один раз
	 * "переместить" оригинал: узлов на одном уровне обычно несколько) Webflow
	 * при своей исходной инициализации просто не видел.
	 *
	 * Намеренно НЕ вызывается общий Webflow.destroy()+Webflow.ready(): это
	 * перезапускает ВСЕ модули Webflow разом, включая "forms" — а он управляет
	 * той же <form>, что и pf-form, и его переинициализация ломала другую
	 * функциональность плагина (например, обработчик поиска внутри группы
	 * фильтра переставал получать события). Вместо этого дропдауны
	 * переинициализируются точечно через их собственный модуль — у него
	 * .ready() безопасно вызывать повторно: перед новой привязкой обработчиков
	 * он сам снимает старые (.off() перед .on()), дублей не будет.
	 *
	 * На сайтах без Webflow window.Webflow не существует — вызов no-op.
	 */
	PFForm.prototype.reinitWebflow = function () {
		if ( ! window.Webflow || ! window.Webflow.require ) {
			return;
		}
		try {
			var dropdown = window.Webflow.require( 'dropdown' );
			if ( dropdown && dropdown.ready ) {
				dropdown.ready();
			}
			var ix2 = window.Webflow.require( 'ix2' );
			if ( ix2 && ix2.init ) {
				ix2.init();
			}
		} catch ( err ) {
			console.warn( 'PF Filter: не удалось переинициализировать Webflow.', err );
		}
	};

	/**
	 * Событие "группы фильтра построены" — универсальный хук для ЛЮБОЙ
	 * сторонней интерактивности темы, не только Webflow. reinitWebflow() выше
	 * решает конкретно Webflow-случай; плагин не может знать заранее, чем
	 * именно верстальщик реализует, например, раскрытие дропдаунов дерева
	 * категорий — своим кастомным кодом, другой библиотекой, чем угодно.
	 * Всплывает до document, слушать можно и там, и на самой форме.
	 *
	 * @example
	 * document.addEventListener('pf-filter:groups-built', function (e) {
	 *   // e.detail.form — форма [pf-form], e.detail.output — [pf-output]
	 *   // здесь безопасно навешивать/переинициализировать свою интерактивность
	 * });
	 */
	PFForm.prototype.dispatchBuiltEvent = function () {
		this.formEl.dispatchEvent( new CustomEvent( 'pf-filter:groups-built', {
			bubbles: true,
			detail: { form: this.formEl, output: this.outputEl },
		} ) );
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
					if ( self._suppressAutoFilter ) {
						return;
					}
					self.runFilter( { resetPage: true, forceReplace: true } );
				} );
			} else if ( isTags ) {
				// preventDefault обязателен: если верстальщик сделал строку через
				// <button> (тип по умолчанию submit) внутри <form pf-form>, клик без
				// него реально отправляет форму — Webflow в ответ показывает свой
				// служебный "Ошибка отправки / Формы не настроены".
				rowClone.addEventListener( 'click', function ( e ) {
					e.preventDefault();
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

	/** Поиск внутри группы: показать/скрыть по порогу (или отключить принудительно), фильтровать строки по вводу. */
	PFForm.prototype.initGroupSearch = function ( groupClone, groupConfig, valuesCount ) {
		var searchInput = qs( groupClone, 'input[type="text"]' );
		if ( ! searchInput ) {
			return;
		}

		// Админ может полностью отключить поиск для конкретной группы (search:
		// false в настройках группы) — независимо от порога и числа значений.
		// По умолчанию (search не задан) — обычное поведение по порогу.
		var searchDisabled = false === groupConfig.search;

		if ( searchDisabled || valuesCount < this.searchThreshold ) {
			searchInput.classList.add( 'pf-hidden' );
		} else {
			searchInput.classList.remove( 'pf-hidden' );
		}

		// Событие вешается делегированно на groupClone, а не напрямую на
		// searchInput: сторонний JS темы (например, переинициализация Webflow —
		// см. reinitWebflow()) тоже управляет полями этой же <form> и может
		// заново инициализировать/подменить обработчики конкретно этого input,
		// из-за чего прямой addEventListener на searchInput можно потерять.
		// groupClone плагин больше не трогает после построения — делегирование
		// на нём не зависит от того, что сторонний код сделает с самим полем.
		groupClone.addEventListener( 'input', function ( e ) {
			if ( ! e.target || 'text' !== e.target.type ) {
				return;
			}
			var query = e.target.value.trim().toLowerCase();
			var rows  = qsa( groupClone, '[data-pf-value]' );

			if ( ! query ) {
				rows.forEach( function ( row ) {
					row.classList.remove( 'pf-hidden' );
				} );
				return;
			}

			// Для плоских списков "своё совпадение" и "видимость" — одно и то же.
			// Но для дерева категорий строка-родитель со своим pf-hidden скрывает
			// и всех потомков (display:none у предка перекрывает детей), даже если
			// у совпавшего потомка pf-hidden уже снят. Поэтому строка должна быть
			// видима, если совпала ОНА САМА, ЛИБО совпал любой её потомок (тогда
			// её саму нужно показать как контейнер) — предки совпавших строк
			// собираются проходом вверх по дереву.
			var visible = [];

			rows.forEach( function ( row ) {
				var valueEl = qs( row, '[pf-filter-value]' );
				var text = valueEl ? valueEl.textContent.toLowerCase() : '';
				if ( ! text.includes( query ) ) {
					return;
				}
				if ( visible.indexOf( row ) === -1 ) {
					visible.push( row );
				}
				var ancestor = row.parentElement;
				while ( ancestor && ancestor !== groupClone ) {
					if ( ancestor.hasAttribute( 'data-pf-value' ) && visible.indexOf( ancestor ) === -1 ) {
						visible.push( ancestor );
					}
					ancestor = ancestor.parentElement;
				}
			} );

			rows.forEach( function ( row ) {
				row.classList.toggle( 'pf-hidden', visible.indexOf( row ) === -1 );
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

		positionRangeHandle( minHandle, min, min, max, false );
		positionRangeHandle( maxHandle, max, min, max, true );

		// min/max/step ниже читаются из slider.dataset заново при каждом
		// взаимодействии, а не захватываются в замыкание один раз — границы
		// диапазона могут поменяться после первой сборки: см.
		// PFForm.prototype.updatePriceBounds (цена подстраивается под
		// остальные активные фильтры так же, как счётчики других групп).
		// Если бы drag/клавиши/числовые поля продолжали клэмпиться по старым
		// min/max, перетаскивание после такого обновления работало бы неверно.

		function roundToStep( value, rangeMin, rangeMax, rangeStep ) {
			var rounded = Math.round( ( value - rangeMin ) / rangeStep ) * rangeStep + rangeMin;
			return clamp( rounded, rangeMin, rangeMax );
		}

		function updateFromDrag( type, clientX ) {
			var rangeMin = parseFloat( slider.dataset.min );
			var rangeMax = parseFloat( slider.dataset.max );
			var rangeStep = parseFloat( slider.dataset.step ) || 1;
			var rect = slider.getBoundingClientRect();
			var percent = rect.width > 0 ? clamp( ( clientX - rect.left ) / rect.width, 0, 1 ) : 0;
			var raw = rangeMin + percent * ( rangeMax - rangeMin );
			var value = roundToStep( raw, rangeMin, rangeMax, rangeStep );

			var currentMin = parseFloat( slider.dataset.currentMin );
			var currentMax = parseFloat( slider.dataset.currentMax );

			if ( 'min' === type ) {
				value = clamp( value, rangeMin, currentMax );
				slider.dataset.currentMin = value;
				positionRangeHandle( minHandle, value, rangeMin, rangeMax, false );
				setRangeDisplayValue( minValueEl, value );
			} else {
				value = clamp( value, currentMin, rangeMax );
				slider.dataset.currentMax = value;
				positionRangeHandle( maxHandle, value, rangeMin, rangeMax, true );
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
				var rangeMin = parseFloat( slider.dataset.min );
				var rangeMax = parseFloat( slider.dataset.max );
				var rangeStep = parseFloat( slider.dataset.step ) || 1;
				var delta = 'ArrowLeft' === e.key ? -rangeStep : rangeStep;
				var currentMin = parseFloat( slider.dataset.currentMin );
				var currentMax = parseFloat( slider.dataset.currentMax );
				if ( 'min' === type ) {
					var newMin = clamp( currentMin + delta, rangeMin, currentMax );
					slider.dataset.currentMin = newMin;
					positionRangeHandle( minHandle, newMin, rangeMin, rangeMax, false );
					setRangeDisplayValue( minValueEl, newMin );
				} else {
					var newMax = clamp( currentMax + delta, currentMin, rangeMax );
					slider.dataset.currentMax = newMax;
					positionRangeHandle( maxHandle, newMax, rangeMin, rangeMax, true );
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
				var rangeMin = parseFloat( slider.dataset.min );
				var rangeMax = parseFloat( slider.dataset.max );
				var currentMin = parseFloat( slider.dataset.currentMin );
				var currentMax = parseFloat( slider.dataset.currentMax );
				var value = parseFloat( el.value );
				if ( isNaN( value ) ) {
					return;
				}
				if ( 'min' === type ) {
					value = clamp( value, rangeMin, currentMax );
					slider.dataset.currentMin = value;
					positionRangeHandle( minHandle, value, rangeMin, rangeMax, false );
				} else {
					value = clamp( value, currentMin, rangeMax );
					slider.dataset.currentMax = value;
					positionRangeHandle( maxHandle, value, rangeMin, rangeMax, true );
				}
				el.value = value;
				self.rangeDebounced( { resetPage: true, forceReplace: true } );
			} );
		}

		bindNumberInput( minValueEl, 'min' );
		bindNumberInput( maxValueEl, 'max' );

		return clone;
	};

	/**
	 * Подстроить границы (не только текущий выбор, а сам диапазон трека)
	 * price-группы под остальные активные фильтры — точно так же, как уже
	 * подстраиваются счётчики у остальных групп (data.counts на каждый
	 * /products-ответ). Сервер уже считает min/max среди товаров, подходящих
	 * под остальные фильтры (см. PF_Renderer::count_price_bounds) — раньше
	 * клиент эти данные получал, но игнорировал.
	 *
	 * Если пользователь ещё не двигал ползунок (стоит на всём диапазоне) —
	 * текущее выделение "едет" вместе с новыми границами, оставаясь "весь
	 * диапазон". Если уже выбрал конкретный поддиапазон — граница просто
	 * обрезается по новым min/max, чтобы не оказаться "снаружи" трека.
	 *
	 * @param {{el: Element}} group  Группа из this.groups (see collectFilters).
	 * @param {{min: number, max: number}} bounds Новые границы из data.counts.price.
	 */
	PFForm.prototype.updatePriceBounds = function ( group, bounds ) {
		if ( ! bounds ) {
			return;
		}

		var slider = qs( group.el, '[pf-filter-range-slider]' );
		if ( ! slider ) {
			return;
		}

		var newMin = parseFloat( bounds.min );
		var newMax = parseFloat( bounds.max );
		if ( isNaN( newMin ) || isNaN( newMax ) ) {
			return;
		}

		var oldMin = parseFloat( slider.dataset.min );
		var oldMax = parseFloat( slider.dataset.max );
		var curMin = parseFloat( slider.dataset.currentMin );
		var curMax = parseFloat( slider.dataset.currentMax );
		var wasFullRange = curMin === oldMin && curMax === oldMax;

		slider.dataset.min = newMin;
		slider.dataset.max = newMax;

		var newCurMin = wasFullRange ? newMin : clamp( curMin, newMin, newMax );
		var newCurMax = wasFullRange ? newMax : clamp( curMax, newMin, newMax );
		if ( newCurMin > newCurMax ) {
			newCurMin = newMin;
			newCurMax = newMax;
		}
		slider.dataset.currentMin = newCurMin;
		slider.dataset.currentMax = newCurMax;

		var minBoundEl = qs( group.el, '[pf-filter-range="min"]' );
		if ( minBoundEl ) {
			minBoundEl.textContent = newMin;
		}
		var maxBoundEl = qs( group.el, '[pf-filter-range="max"]' );
		if ( maxBoundEl ) {
			maxBoundEl.textContent = newMax;
		}

		positionRangeHandle( qs( group.el, '[pf-filter-range-handle="min"]' ), newCurMin, newMin, newMax, false );
		positionRangeHandle( qs( group.el, '[pf-filter-range-handle="max"]' ), newCurMax, newMin, newMax, true );
		setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="min"]' ), newCurMin );
		setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="max"]' ), newCurMax );
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

		// В отличие от плоских списков, дерево категорий раньше вообще не
		// получало обработку поиска — поле поиска, если оно есть в шаблоне,
		// никогда не скрывалось и не фильтровало. Считаем общее число узлов
		// на всех уровнях дерева и применяем ту же логику, что и для остальных
		// групп (порог показа + отключение через настройки группы).
		this.initGroupSearch( clone, groupConfig, this.countTreeValues( groupConfig.values || [] ) );

		return clone;
	};

	/** Посчитать общее количество узлов дерева на всех уровнях (для порога показа поиска). */
	PFForm.prototype.countTreeValues = function ( items ) {
		var total = 0;
		items.forEach( function ( item ) {
			total += 1;
			if ( item.children && item.children.length ) {
				total += this.countTreeValues( item.children );
			}
		}, this );
		return total;
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
				if ( self._suppressAutoFilter ) {
					return;
				}
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
		// forceReplace/silent захватываются ЛОКАЛЬНО для этого конкретного
		// запроса (а не пишутся в this.*) и передаются в handleResponse()
		// явно, вместе с ответом. Раньше это были общие поля на инстансе,
		// выставляемые в момент ВЫЗОВА runFilter(), но читавшиеся в
		// handleResponse() в момент ОТВЕТА — асинхронно, позже. Если между
		// вызовом и ответом успевал выполниться ДРУГОЙ runFilter() (например,
		// отложенный rangeDebounced — отдельная функция со своим таймером,
		// прямой вызов runFilter() её не отменяет), ответ первого запроса читал
		// уже перезаписанные чужие флаги — например, список товаров не
		// заменялся, потому что forceReplace успел стать false от чужого
		// вызова. this._forceReplace вдобавок никогда не сбрасывался обратно.
		var forceReplace = !! opts.forceReplace;
		var silent = !! opts.silent;

		if ( this.abortController ) {
			this.abortController.abort();
		}
		this.abortController = new AbortController();

		if ( this.loadingEl && ! silent ) {
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
				self.handleResponse( data, { forceReplace: forceReplace, silent: silent } );
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

	PFForm.prototype.handleResponse = function ( data, opts ) {
		opts = opts || {};
		var silent = !! opts.silent;
		var forceReplace = !! opts.forceReplace;

		if ( this.loadingEl ) {
			this.loadingEl.classList.add( 'is-hidden' );
		}

		this.totalPages = data.pages || 1;
		this.totalCount = data.count || 0;
		this.state.paged = data.current_page || this.state.paged;

		// silent: [pf-list] на первой загрузке уже отрендерен обычным циклом
		// WordPress — трогать его не нужно, запрос был только за цифрами.
		if ( ! silent ) {
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
					if ( appendModes.includes( this.paginationMode ) && ! forceReplace ) {
						this.listEl.insertAdjacentHTML( 'beforeend', data.html );
					} else {
						this.listEl.innerHTML = data.html;
					}
				}
			}
		}

		this.updateCounters( data );
		this.updateGroupCounts( data.counts || {} );
		this.updatePaginationUI();
		this.updateActiveFilters();
		if ( ! silent ) {
			this.updateUrl();
		}
	};

	PFForm.prototype.updateCounters = function ( data ) {
		qsa( document, '[pf-count]' ).forEach( function ( el ) {
			el.textContent = data.count;
		} );
	};

	PFForm.prototype.updateGroupCounts = function ( counts ) {
		var self = this;

		// Сервер пересчитывает релевантность группы под текущие фильтры (в т.ч.
		// авто-релевантность по категории) на каждый /products-запрос — если у
		// поля группы нет ни одного подходящего значения, его просто нет среди
		// ключей counts. Раньше это использовалось только для обновления цифр у
		// уже показанных строк, а сам факт отсутствия поля никак не влиял на
		// видимость группы целиком — из-за этого нерелевантные группы продолжали
		// висеть в форме, хотя реально ни один товар из текущей выборки под них
		// не попадал.
		Object.keys( this.groups ).forEach( function ( field ) {
			if ( 'price' === field ) {
				return; // цена всегда видна, релевантность по счётчикам к ней не применяется.
			}
			self.groups[ field ].el.classList.toggle( 'pf-hidden', ! ( field in counts ) );
		} );

		Object.keys( counts ).forEach( function ( field ) {
			var group = self.groups[ field ];
			if ( ! group ) {
				return;
			}

			if ( 'price' === field ) {
				// В отличие от остальных групп — не список значений со счётчиком
				// у каждого, а min/max среди товаров, подходящих под остальные
				// активные фильтры. Ползунок подстраивается под них так же, как
				// подстраиваются счётчики остальных групп.
				self.updatePriceBounds( group, counts.price );
				return;
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

			clone.addEventListener( 'click', function ( e ) {
				e.preventDefault();
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
		// Ссылки на элементы пагинации кэшируются один раз здесь, а не через
		// document.querySelector('[pf-page-item]') при каждом рендере в
		// updatePaginationUI(). Причина: сгенерированные клоны номеров страниц
		// (cloneNode от этого же шаблона) тоже несут атрибут pf-page-item и после
		// первого рендера идут в DOM раньше оригинала — повторный querySelector
		// нашёл бы уже сгенерированный клон вместо настоящего шаблона, что ведёт
		// к его ошибочному удалению и потере номеров страниц после первого клика.
		this._pagesContainer  = document.querySelector( '[pf-pagination-pages]' );
		this._pageItemTpl     = document.querySelector( '[pf-page-item]' );
		this._prevBtn         = document.querySelector( '[pf-page-prev]' );
		this._nextBtn         = document.querySelector( '[pf-page-next]' );
		this._loadMoreBtn     = document.querySelector( '[pf-load-more]' );
		this._infiniteTrigger = document.querySelector( '[pf-infinite-trigger]' );

		// this.paginationMode берётся исключительно из настроек админки
		// (pagination_strategy) — см. init().
		if ( ! this.paginationMode ) {
			return;
		}

		var self = this;

		if ( 'load-more' === this.paginationMode || 'both' === this.paginationMode ) {
			if ( this._loadMoreBtn ) {
				this._loadMoreBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					self.state.paged += 1;
					self.runFilter( { forceReplace: false } );
				} );
			}
		}

		if ( 'pages' === this.paginationMode || 'both' === this.paginationMode ) {
			// pf-page-prev/next обычно <a href="#"> — без preventDefault клик
			// прыгал бы в начало страницы вместо запуска AJAX-фильтрации.
			if ( this._prevBtn ) {
				this._prevBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( self.state.paged > 1 ) {
						self.state.paged -= 1;
						self.runFilter( { forceReplace: true } );
					}
				} );
			}
			if ( this._nextBtn ) {
				this._nextBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( self.state.paged < self.totalPages ) {
						self.state.paged += 1;
						self.runFilter( { forceReplace: true } );
					}
				} );
			}
		}

		if ( 'infinite' === this.paginationMode ) {
			if ( this._infiniteTrigger && 'IntersectionObserver' in window ) {
				this.infiniteObserver = new IntersectionObserver( function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting && self.state.paged < self.totalPages ) {
							self.state.paged += 1;
							self.runFilter( { forceReplace: false } );
						}
					} );
				} );
				this.infiniteObserver.observe( this._infiniteTrigger );
			} else if ( ! this._infiniteTrigger ) {
				console.warn( 'PF Filter: [pf-infinite-trigger] не найден, автоподгрузка недоступна.' );
			}
		}
	};

	PFForm.prototype.updatePaginationUI = function () {
		if ( ! this.paginationMode ) {
			return;
		}

		// Элементы, не принадлежащие активному режиму, всегда полностью скрыты
		// (pf-hidden), даже если в разметке присутствуют шаблоны сразу для
		// нескольких стратегий пагинации (страницы + "загрузить ещё" + бесконечная
		// прокрутка) — активна должна быть ровно одна.
		var showPages     = 'pages' === this.paginationMode || 'both' === this.paginationMode;
		var showLoadMore  = 'load-more' === this.paginationMode || 'both' === this.paginationMode;
		var showInfinite  = 'infinite' === this.paginationMode;
		// Постраничная пагинация не имеет смысла, когда результат целиком
		// умещается на одной странице — тогда единственная кнопка "1" и стрелки
		// прячутся целиком, а не просто выглядят неактивными.
		var hasMultiplePages = this.totalPages > 1;

		if ( this._pagesContainer ) {
			this._pagesContainer.classList.toggle( 'pf-hidden', ! showPages || ! hasMultiplePages );
		}

		if ( showPages && hasMultiplePages && this._pagesContainer && this._pageItemTpl ) {
			var pagesContainer = this._pagesContainer;
			var itemTpl        = this._pageItemTpl;

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

				// Вставляем на место шаблона (перед itemTpl), а не в конец
				// контейнера — иначе номера страниц оказываются ПОСЛЕ
				// pf-page-next, если в разметке стрелки лежат внутри того же
				// контейнера, что и pf-page-item (частый случай).
				itemTpl.insertAdjacentElement( 'beforebegin', clone );
			}
			itemTpl.classList.add( 'pf-hidden' );
		}

		// Стрелки — часть режима "pages"/"both". В остальных режимах скрыты всегда;
		// внутри режима показываются только когда реально есть куда листать —
		// не просто "неактивны", а полностью скрыты (pf-hidden).
		if ( this._prevBtn ) {
			var isFirst = ! showPages || this.state.paged <= 1;
			this._prevBtn.classList.toggle( 'is-disabled', isFirst );
			this._prevBtn.classList.toggle( 'pf-hidden', isFirst );
			if ( isFirst ) {
				this._prevBtn.setAttribute( 'disabled', 'disabled' );
			} else {
				this._prevBtn.removeAttribute( 'disabled' );
			}
		}

		if ( this._nextBtn ) {
			var isLast = ! showPages || this.state.paged >= this.totalPages;
			this._nextBtn.classList.toggle( 'is-disabled', isLast );
			this._nextBtn.classList.toggle( 'pf-hidden', isLast );
			if ( isLast ) {
				this._nextBtn.setAttribute( 'disabled', 'disabled' );
			} else {
				this._nextBtn.removeAttribute( 'disabled' );
			}
		}

		if ( this._loadMoreBtn ) {
			this._loadMoreBtn.classList.toggle( 'pf-hidden', ! showLoadMore || this.state.paged >= this.totalPages );
		}

		if ( this._infiniteTrigger ) {
			this._infiniteTrigger.classList.toggle( 'pf-hidden', ! showInfinite );
		}

		if ( showInfinite && this.infiniteObserver && this.state.paged >= this.totalPages ) {
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

		// Кнопка сброса по умолчанию скрыта — показывается только когда есть
		// хоть один реально активный фильтр (см. updateActiveFilters()).
		qsa( document, '[pf-active-reset]' ).forEach( function ( btn ) {
			btn.classList.add( 'pf-hidden' );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.resetAllFilters();
			} );
		} );
	};

	/**
	 * Активен ли фильтр конкретно этого поля. Для price — «активен» только
	 * если диапазон отличается от полного (min/max шаблона), иначе он
	 * технически присутствует в collectFilters(), но фильтром не является.
	 *
	 * @param {string} field   Поле группы.
	 * @param {Object} filters Результат collectFilters().
	 * @return {boolean}
	 */
	PFForm.prototype.isFieldActive = function ( field, filters ) {
		if ( 'price' === field ) {
			var priceFilter = filters.price;
			if ( ! priceFilter ) {
				return false;
			}
			var group = this.groups.price;
			var slider = group ? qs( group.el, '[pf-filter-range-slider]' ) : null;
			var fullMin = slider ? parseFloat( slider.dataset.min ) : null;
			var fullMax = slider ? parseFloat( slider.dataset.max ) : null;
			return ! ( priceFilter.min === fullMin && priceFilter.max === fullMax );
		}
		return ( filters[ field ] || [] ).length > 0;
	};

	/**
	 * Есть ли хотя бы один реально активный фильтр (среди всех групп).
	 *
	 * @param {Object} filters Результат collectFilters().
	 * @return {boolean}
	 */
	PFForm.prototype.hasActiveFilters = function ( filters ) {
		var self = this;
		return Object.keys( this.groups ).some( function ( field ) {
			return self.isFieldActive( field, filters );
		} );
	};

	/**
	 * Сбросить элементы управления ОДНОЙ группы (чекбоксы/радио/теги/range) —
	 * без запуска runFilter(). Общая часть для resetAllFilters() (сбрасывает
	 * все группы разом одним итоговым запросом) и resetGroupFilter()
	 * (сбрасывает ровно одну группу — используется чипом цены в активных
	 * фильтрах и кнопкой [pf-filter-remove] внутри самой группы).
	 */
	PFForm.prototype.resetGroupControls = function ( group ) {
		qsa( group.el, 'input[type="checkbox"], input[type="radio"]' ).forEach( function ( input ) {
			setChecked( input, false );
		} );
		qsa( group.el, '[data-pf-value].is-active' ).forEach( function ( row ) {
			row.classList.remove( 'is-active' );
		} );

		if ( 'range' === group.config.template ) {
			var slider = qs( group.el, '[pf-filter-range-slider]' );
			if ( slider ) {
				var rangeMin = parseFloat( slider.dataset.min );
				var rangeMax = parseFloat( slider.dataset.max );
				slider.dataset.currentMin = slider.dataset.min;
				slider.dataset.currentMax = slider.dataset.max;
				positionRangeHandle( qs( group.el, '[pf-filter-range-handle="min"]' ), rangeMin, rangeMin, rangeMax, false );
				positionRangeHandle( qs( group.el, '[pf-filter-range-handle="max"]' ), rangeMax, rangeMin, rangeMax, true );
				setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="min"]' ), slider.dataset.min );
				setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="max"]' ), slider.dataset.max );
			}
		}
	};

	PFForm.prototype.resetAllFilters = function () {
		// Пока сбрасываем контролы группа за группой, не даём каждому отдельному
		// change-событию запускать свой runFilter() — иначе на N чекбоксов будет
		// N лишних запросов вместо одного общего в конце.
		this._suppressAutoFilter = true;

		Object.keys( this.groups ).forEach( function ( field ) {
			this.resetGroupControls( this.groups[ field ] );
		}, this );

		this._suppressAutoFilter = false;
		this.runFilter( { resetPage: true, forceReplace: true } );
	};

	PFForm.prototype.updateActiveFilters = function () {
		var self = this;
		var filters = this.collectFilters();

		qsa( document, '[pf-active-reset]' ).forEach( function ( btn ) {
			btn.classList.toggle( 'pf-hidden', ! self.hasActiveFilters( filters ) );
		} );

		// [pf-filter-remove] — кнопка сброса ОДНОЙ группы, живёт внутри её
		// pf-template — показывается, только когда у этой конкретной группы
		// реально есть активный фильтр.
		Object.keys( this.groups ).forEach( function ( field ) {
			var group = self.groups[ field ];
			var active = self.isFieldActive( field, filters );
			qsa( group.el, '[pf-filter-remove]' ).forEach( function ( btn ) {
				btn.classList.toggle( 'pf-hidden', ! active );
			} );
		} );

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
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				onRemove();
			} );
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
				this._suppressAutoFilter = true;
				setChecked( input, false );
				this._suppressAutoFilter = false;
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
		// Подавляем автозапуск runFilter() по каждому отдельному снятому
		// чекбоксу/радио этой группы — иначе на группу с N значениями будет N
		// лишних запросов вместо одного итогового (см. resetAllFilters()).
		this._suppressAutoFilter = true;
		this.resetGroupControls( group );
		this._suppressAutoFilter = false;
		this.runFilter( { resetPage: true, forceReplace: true } );
	};

	// -----------------------------------------------------------------
	// URL и история браузера
	// -----------------------------------------------------------------

	PFForm.prototype.updateUrl = function () {
		if ( ! this.syncUrl ) {
			return;
		}

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
		// Номер страницы плагин всегда пишет только в query-строку (?paged=N) —
		// если текущий URL был открыт в каноничном для WordPress pretty-permalink
		// виде /page/N/ (см. getPagedFromPath), этот сегмент пути нужно убрать,
		// иначе получится дублирующее /page/3/?paged=2.
		var basePath = window.location.pathname.replace( /\/page\/\d+\/?$/, '/' );
		var newUrl = basePath + ( query ? '?' + query : '' );
		window.history.pushState( null, '', newUrl );
	};

	/**
	 * Номер страницы из пути вида /page/N/ (каноничный формат WordPress
	 * pretty-permalink пагинации, используется например ссылками паджинации
	 * самого WooCommerce/темы) — в отличие от ?paged=N, которую пишет сама
	 * форма (см. updateUrl), URLSearchParams(location.search) его не видит,
	 * т.к. он не в query-строке, а в самом пути.
	 *
	 * @return {number|null}
	 */
	PFForm.prototype.getPagedFromPath = function () {
		var match = window.location.pathname.match( /\/page\/(\d+)\/?$/ );
		if ( ! match ) {
			return null;
		}
		var paged = parseInt( match[ 1 ], 10 );
		return paged > 0 ? paged : null;
	};

	/** @return {boolean} true, если в URL были параметры фильтра и по ним запущен runFilter(). */
	PFForm.prototype.restoreFromUrl = function () {
		if ( ! this.syncUrl ) {
			return false;
		}

		var params = new URLSearchParams( window.location.search );
		var pathPaged = this.getPagedFromPath();
		if ( ! Array.from( params.keys() ).length && ! pathPaged ) {
			return false;
		}

		var self = this;
		var hasRelevantParams = false;

		if ( pathPaged ) {
			hasRelevantParams = true;
			this.state.paged = pathPaged;
		}

		// Восстанавливаем состояние контролов пачкой — не даём change-событиям
		// от каждого отдельного чекбокса запускать свой runFilter().
		this._suppressAutoFilter = true;

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
					setChecked( input, true );
				} else {
					row.classList.add( 'is-active' );
				}
			} );
		} );

		this._suppressAutoFilter = false;

		if ( hasRelevantParams ) {
			this.runFilter( { forceReplace: true } );
		}
		return hasRelevantParams;
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
