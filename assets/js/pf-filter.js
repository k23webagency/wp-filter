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
 *
 * [pf-profile] — не атрибут конкретно формы, а маркер ближайшего общего
 * контейнера ОДНОГО блока фильтрации (форма + список + pf-loading/pf-empty/
 * pf-count/pf-active-filters/pf-pagination-.../pf-sort внутри одной обёртки).
 * Находится через formEl.closest('[pf-profile]') — значит атрибут можно
 * поставить и прямо на саму форму (вырожденный случай), и на любого её
 * предка. Все перечисленные выше pf-* элементы ищутся ВНУТРИ этого
 * контейнера, а не по всему document — это и разруливает, какой профиль
 * им соответствует, и заодно даёт двум независимым блокам фильтрации на
 * одной странице не путать чужие pf-count/pf-loading и т.п. Если на
 * странице такого контейнера нет вообще (простая тема с одним блоком
 * фильтра) — всё ищется по всему document, как было исторически.
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

	/**
	 * Записать отображаемое значение min/max диапазона.
	 *
	 * @param {boolean} isTouched Тронута ли эта сторона реальным действием
	 *   пользователя (drag/клавиши/число), см. data-user-min/data-user-max в
	 *   buildRangeGroup(). Имеет значение только для <input>: тронутая сторона
	 *   показывается как настоящее value (можно редактировать/увидеть, что
	 *   реально выбрано); НЕ тронутая — value остаётся пустым, а текущая
	 *   граница трека показывается через placeholder (подсказка "вот текущий
	 *   предел", а не подставленный за пользователя выбор). Для элементов,
	 *   не являющихся <input> (например, просто текст), разницы нет.
	 */
	function setRangeDisplayValue( el, value, isTouched ) {
		if ( ! el ) {
			return;
		}
		if ( el.tagName === 'INPUT' ) {
			if ( isTouched ) {
				el.value = value;
			} else {
				el.value = '';
				el.placeholder = value;
			}
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

	/**
	 * Спозиционировать [pf-filter-range-track] — полоску подсветки между
	 * двумя бегунками — инлайновыми left/width в процентах, теми же
	 * границами, что и у самих бегунков (percentForRange()), так что она
	 * всегда точно совпадает с текущим выделенным диапазоном.
	 *
	 * @param {Element|null} track Элемент [pf-filter-range-track] (может отсутствовать в разметке — тогда no-op).
	 * @param {number} curMin Текущее значение min.
	 * @param {number} curMax Текущее значение max.
	 * @param {number} min    Левая граница трека.
	 * @param {number} max    Правая граница трека.
	 */
	function positionRangeTrack( track, curMin, curMax, min, max ) {
		if ( ! track ) {
			return;
		}
		var leftPercent = percentForRange( curMin, min, max, false );
		var rightPercent = percentForRange( curMax, min, max, true );
		track.style.left = leftPercent + '%';
		track.style.width = Math.max( 0, rightPercent - leftPercent ) + '%';
	}

	// ---------------------------------------------------------------------
	// PFForm — один экземпляр на каждую найденную [pf-form]
	// ---------------------------------------------------------------------

	function PFForm( formEl ) {
		this.formEl = formEl;
		this.scopeRoot = document; // ближайший [pf-profile]-контейнер, либо document — см. init().
		this.listEl = null;
		this.outputEl = null;
		this.templatesEl = null;
		this.loadingEl = null;
		this.emptyEl = null;
		this.sortEl = null;
		this.paginationMode = null;

		this.config = null;
		this.profileId = ''; // из [pf-profile]-контейнера; уточняется резолвом сервера в fetchConfig() (см. init()).
		this.explicitProfileId = ''; // копия исходного значения ДО резолва сервером — для предупреждения о неоднозначности (см. init()).
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
	 * Найти [pf-list] для этой формы в границах this.scopeRoot (см. init()).
	 * pf-target нигде не обязателен:
	 * - если задан — используется как CSS-селектор от всего document (явная
	 *   связь, побеждает любую автоматику);
	 * - если внутри scopeRoot ровно один [pf-list] — используется он,
	 *   независимо от того, сколько внутри форм (несколько форм на один и
	 *   тот же список — обычный случай, например мобильная и десктопная
	 *   версии одной и той же формы фильтра — не требуют pf-target);
	 * - если внутри scopeRoot несколько [pf-list] и pf-target не задан —
	 *   неоднозначность: console.warn, best-effort — первый найденный список.
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

		var scopedLists = qsa( this.scopeRoot, '[pf-list]' );
		var scopeLabel = this.scopeRoot === document ? 'на странице' : 'внутри её [pf-profile]-блока';

		if ( 1 === scopedLists.length ) {
			return scopedLists[ 0 ];
		}

		if ( ! scopedLists.length ) {
			console.error( 'PF Filter: [pf-list] не найден ' + scopeLabel + ', форма не инициализирована.' );
			return null;
		}

		console.warn( 'PF Filter: атрибут pf-target не указан, а ' + scopeLabel + ' несколько [pf-list] — однозначно связать нельзя. Добавьте pf-target="#selector" на форму. Пробую связать с первым найденным списком.' );
		return scopedLists[ 0 ];
	};

	PFForm.prototype.init = function () {
		if ( ! this.formEl.hasAttribute( 'pf-form' ) ) {
			// На случай если элемент передан ошибочно.
			return;
		}

		// [pf-profile] определяет и профиль, и границу блока для всех
		// остальных pf-* элементов ниже (pf-loading/pf-count/pf-active-filters/
		// pf-pagination-.../pf-sort) — см. пояснение в шапке файла. closest()
		// включает саму форму, если атрибут стоит прямо на ней.
		var profileContainer = this.formEl.closest( '[pf-profile]' );
		this.scopeRoot = profileContainer || document;
		this.profileId = profileContainer ? ( profileContainer.getAttribute( 'pf-profile' ) || '' ) : '';
		this.explicitProfileId = this.profileId;

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

		this.loadingEl = qs( this.scopeRoot, '[pf-loading]' );
		this.emptyEl = qs( this.scopeRoot, '[pf-empty]' );
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

		// this.profileId уже установлен выше (из [pf-profile]-контейнера, если
		// есть). Если контейнера/атрибута нет — не обязателен, по аналогии с
		// pf-target: сервер сам разрешает профиль, однозначно, если он на
		// сайте ровно один, иначе неоднозначно (тогда ответ /config несёт
		// ambiguous_profile — предупреждаем ниже).

		var self = this;
		this.fetchConfig()
			.then( function ( config ) {
				self.config = config;
				if ( config.profile ) {
					// Профиль, реально разрешённый сервером — используется дальше во
					// всех /products-запросах этой формы, чтобы не полагаться на то,
					// что серверный дефолт (первый профиль по порядку) не изменится
					// между запросами.
					self.profileId = config.profile;
				}
				if ( config.ambiguous_profile && ! self.explicitProfileId ) {
					console.warn( 'PF Filter: pf-profile не указан ни на форме, ни на её общем [pf-profile]-контейнере, а на сайте несколько профилей фильтра — однозначно выбрать нельзя. Добавьте pf-profile="..." на форму или на её общую обёртку. Использован профиль "' + config.profile + '".' );
				}
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
					// Первая загрузка страницы без фильтров в URL — обычно [pf-list]
					// трогать не нужно (уже отрендерен обычным циклом WordPress с
					// правильным posts_per_page, см. PF_Plugin::limit_main_query_posts_per_page()),
					// [pf-count]/счётчики групп/пагинация просто досчитываются тихим
					// запросом. Но этот хук ограничивает только ГЛАВНЫЙ запрос
					// страницы-архива — если [pf-form]/[pf-list] встроены НЕ на архив
					// своего типа записи (например, маленький виджет на главной
					// странице сайта), список изначально рисуется отдельным запросом
					// темы, который плагин не ограничивает и не может ограничить на
					// PHP-стороне. Проверяем по факту: если уже отрендерено больше
					// карточек, чем настроено на странице — значит исходный рендер не
					// был ограничен, и без реальной (не silent) замены пагинация не
					// заработает до первого действия пользователя.
					var initialCount   = self.listEl ? self.listEl.children.length : 0;
					var alreadyLimited = ! self.perPage || initialCount <= self.perPage;
					// forceReplace: true обязателен для режимов "load-more"/"both"/"infinite" —
					// без него handleResponse() ДОБАВИЛ бы правильно ограниченную первую
					// страницу поверх уже отрендеренного нелимитированного списка вместо
					// того, чтобы его заменить.
					self.runFilter( { silent: alreadyLimited, forceReplace: ! alreadyLimited } );
				}
			} )
			.catch( function ( err ) {
				console.error( 'PF Filter: не удалось загрузить /config.', err );
			} );
	};

	/** GET /wp-json/pf/v1/config, опционально с ?category=&profile=. */
	PFForm.prototype.fetchConfig = function ( category ) {
		var params = [];
		if ( category ) {
			params.push( 'category=' + encodeURIComponent( category ) );
		}
		if ( this.profileId ) {
			params.push( 'profile=' + encodeURIComponent( this.profileId ) );
		}
		var url = window.pfConfig.restUrl + 'config' + ( params.length ? '?' + params.join( '&' ) : '' );
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
				// background-color ставится напрямую инлайн-стилем — он всегда
				// сильнее любого правила CSS темы (в т.ч. цвета, зашитого прямо в
				// дизайн-шаблон группы в [pf-templates]), никакого дополнительного
				// CSS от темы для этого не требуется.
				swatchEl.style.backgroundColor = value.color;
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
		// [pf-filter-range-track] — необязательная полоска подсветки между
		// бегунками, см. positionRangeTrack(). Нет предупреждения при
		// отсутствии — атрибут новый и не обязателен, чисто визуальное дополнение.
		var trackEl = qs( clone, '[pf-filter-range-track]' );

		if ( ! minHandle || ! maxHandle ) {
			console.warn( 'PF Filter: [pf-filter-range-handle] не найден для группы "' + groupConfig.field + '", drag недоступен.' );
		}
		if ( ! minValueEl || ! maxValueEl ) {
			console.warn( 'PF Filter: [pf-filter-range-value] не найден для группы "' + groupConfig.field + '", значения не отображаются.' );
		}

		// В момент построения группы ничего ещё не тронуто пользователем —
		// оба [pf-filter-range-value] (если это <input>) показываются как
		// placeholder текущей границы, а не как готовое value.
		setRangeDisplayValue( minValueEl, min, false );
		setRangeDisplayValue( maxValueEl, max, false );

		positionRangeTrack( trackEl, min, max, min, max );

		positionRangeHandle( minHandle, min, min, max, false );
		positionRangeHandle( maxHandle, max, min, max, true );

		// min/max/step ниже читаются из slider.dataset заново при каждом
		// взаимодействии, а не захватываются в замыкание один раз — границы
		// диапазона могут поменяться после первой сборки: см.
		// PFForm.prototype.updateRangeBounds (диапазон подстраивается под
		// остальные активные фильтры так же, как счётчики других групп).
		// Если бы drag/клавиши/числовые поля продолжали клэмпиться по старым
		// min/max, перетаскивание после такого обновления работало бы неверно.
		//
		// slider.dataset.userMin/userMax ставятся в drag/клавишах/числовом
		// поле ниже — только реальным действием пользователя, НЕЗАВИСИМО
		// друг от друга (тронут может быть только один бегунок). Это
		// "замороженный" абсолютный выбор пользователя, отдельно от
		// currentMin/currentMax — те могут многократно перезаписываться
		// автоподстройкой (updateRangeBounds) под другие активные фильтры.
		// collectFilters() отправляет эту группу как активный фильтр только
		// если хотя бы один из них задан. updateRangeBounds() при каждом
		// обновлении границ клэмпит именно userMin/userMax (а не текущее,
		// уже возможно суженное currentMin/currentMax) в новые границы —
		// иначе после каскада из нескольких сужений (например, ещё один
		// активный фильтр временно оставил один подходящий товар) диапазон
		// терял исходный выбор пользователя и не мог "расшириться назад"
		// при снятии того, другого, фильтра — оставался стянутым в точку
		// даже когда границы трека уже честно раздвинулись.

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
				slider.dataset.userMin = value;
				positionRangeHandle( minHandle, value, rangeMin, rangeMax, false );
				setRangeDisplayValue( minValueEl, value, true );
			} else {
				value = clamp( value, currentMin, rangeMax );
				slider.dataset.currentMax = value;
				slider.dataset.userMax = value;
				positionRangeHandle( maxHandle, value, rangeMin, rangeMax, true );
				setRangeDisplayValue( maxValueEl, value, true );
			}
			positionRangeTrack( trackEl, parseFloat( slider.dataset.currentMin ), parseFloat( slider.dataset.currentMax ), rangeMin, rangeMax );
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
					slider.dataset.userMin = newMin;
					positionRangeHandle( minHandle, newMin, rangeMin, rangeMax, false );
					setRangeDisplayValue( minValueEl, newMin, true );
				} else {
					var newMax = clamp( currentMax + delta, currentMin, rangeMax );
					slider.dataset.currentMax = newMax;
					slider.dataset.userMax = newMax;
					positionRangeHandle( maxHandle, newMax, rangeMin, rangeMax, true );
					setRangeDisplayValue( maxValueEl, newMax, true );
				}
				positionRangeTrack( trackEl, parseFloat( slider.dataset.currentMin ), parseFloat( slider.dataset.currentMax ), rangeMin, rangeMax );
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

				// Поле очищено вручную — пользователь "отпускает" именно эту
				// сторону обратно к границе трека (та же логика, что и у
				// resetGroupControls(), но только для одной стороны, не для
				// всей группы): поле снова показывает placeholder вместо value.
				if ( '' === el.value.trim() ) {
					if ( 'min' === type ) {
						delete slider.dataset.userMin;
						slider.dataset.currentMin = rangeMin;
						positionRangeHandle( minHandle, rangeMin, rangeMin, rangeMax, false );
						setRangeDisplayValue( minValueEl, rangeMin, false );
					} else {
						delete slider.dataset.userMax;
						slider.dataset.currentMax = rangeMax;
						positionRangeHandle( maxHandle, rangeMax, rangeMin, rangeMax, true );
						setRangeDisplayValue( maxValueEl, rangeMax, false );
					}
					positionRangeTrack( trackEl, parseFloat( slider.dataset.currentMin ), parseFloat( slider.dataset.currentMax ), rangeMin, rangeMax );
					self.rangeDebounced( { resetPage: true, forceReplace: true } );
					return;
				}

				var value = parseFloat( el.value );
				if ( isNaN( value ) ) {
					return;
				}
				if ( 'min' === type ) {
					value = clamp( value, rangeMin, currentMax );
					slider.dataset.currentMin = value;
					slider.dataset.userMin = value;
					positionRangeHandle( minHandle, value, rangeMin, rangeMax, false );
				} else {
					value = clamp( value, currentMin, rangeMax );
					slider.dataset.currentMax = value;
					slider.dataset.userMax = value;
					positionRangeHandle( maxHandle, value, rangeMin, rangeMax, true );
				}
				setRangeDisplayValue( el, value, true );
				positionRangeTrack( trackEl, parseFloat( slider.dataset.currentMin ), parseFloat( slider.dataset.currentMax ), rangeMin, rangeMax );
				self.rangeDebounced( { resetPage: true, forceReplace: true } );
			} );
		}

		bindNumberInput( minValueEl, 'min' );
		bindNumberInput( maxValueEl, 'max' );

		return clone;
	};

	/**
	 * Подстроить границы (не только текущий выбор, а сам диапазон трека)
	 * range-группы (цена и любое другое такое поле) под остальные активные
	 * фильтры — точно так же, как уже подстраиваются счётчики у остальных
	 * групп (data.counts на каждый /products-ответ). Сервер уже считает
	 * min/max среди записей, подходящих под остальные фильтры (см.
	 * PF_Renderer::count_meta_range_bounds).
	 *
	 * Если пользователь ещё не двигал бегунок (min или max — независимо друг
	 * от друга) — он "едет" вместе с новыми границами, оставаясь на краю
	 * диапазона. Если бегунок реально трогали — берётся ИМЕННО его
	 * "замороженное" абсолютное значение (data-user-min/data-user-max,
	 * записанное в момент того самого взаимодействия), обрезанное по новым
	 * min/max, а не текущее отображаемое currentMin/currentMax.
	 *
	 * Это важно: currentMin/currentMax может быть много раз перезаписан
	 * ЭТИМ ЖЕ методом на предыдущих ответах сервера (подстройка под ДРУГИЕ
	 * фильтры). Если бы клэмп брал currentMin/currentMax вместо
	 * "замороженного" пользовательского значения, после каскада из
	 * нескольких сужений (например, ещё один активный фильтр временно
	 * оставил один подходящий товар — границы схлопнулись в точку) диапазон
	 * терял исходный выбор пользователя без возможности вернуться: при
	 * снятии того, другого, фильтра границы трека честно раздвигались
	 * обратно, а выделение оставалось стянутым в ту схлопнувшуюся точку —
	 * выглядело как самопроизвольное "залипание" фильтра, путало
	 * isFieldActive()/чипы (сравнивают выбор с data-min/data-max).
	 *
	 * @param {{el: Element}} group  Группа из this.groups (see collectFilters).
	 * @param {{min: number, max: number}} bounds Новые границы из data.counts[field].
	 */
	PFForm.prototype.updateRangeBounds = function ( group, bounds ) {
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

		var hasUserMin = undefined !== slider.dataset.userMin;
		var hasUserMax = undefined !== slider.dataset.userMax;

		slider.dataset.min = newMin;
		slider.dataset.max = newMax;

		var newCurMin = hasUserMin ? clamp( parseFloat( slider.dataset.userMin ), newMin, newMax ) : newMin;
		var newCurMax = hasUserMax ? clamp( parseFloat( slider.dataset.userMax ), newMin, newMax ) : newMax;
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
		setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="min"]' ), newCurMin, hasUserMin );
		setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="max"]' ), newCurMax, hasUserMax );
		positionRangeTrack( qs( group.el, '[pf-filter-range-track]' ), newCurMin, newCurMax, newMin, newMax );
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
				var hasUserMin = slider && undefined !== slider.dataset.userMin;
				var hasUserMax = slider && undefined !== slider.dataset.userMax;
				// Отправляется "замороженное" абсолютное значение выбора
				// пользователя (data-user-min/data-user-max — только реальным
				// действием, см. buildRangeGroup()), а НЕ отображаемое
				// currentMin/currentMax. Это принципиально: currentMin/currentMax —
				// чисто визуальное отображение, которое updateRangeBounds() постоянно
				// подстраивает под ДРУГИЕ активные фильтры (в т.ч. схлопывает в 0,
				// если с ними совпадений вообще нет) — если бы туда же уходил и
				// реальный фильтр, снятая с одной группы констрейнта (например, "0..0"
				// из-за третьего фильтра) навсегда протекала бы в запрос ДРУГОЙ
				// range-группы через её же currentMin/currentMax, и группы взаимно
				// блокировали друг друга по кругу — сброс одного фильтра не помогал,
				// потому что другой продолжал слать чужое схлопнувшееся значение как
				// будто это его собственный осознанный выбор. Не тронутая пользователем
				// сторона (min либо max) отправляется как null — без ограничения,
				// сервер это понимает (см. PF_Query::build_meta_range_query()).
				if ( hasUserMin || hasUserMax ) {
					filters[ field ] = {
						min: hasUserMin ? parseFloat( slider.dataset.userMin ) : null,
						max: hasUserMax ? parseFloat( slider.dataset.userMax ) : null,
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
			profile: this.profileId || '',
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
		qsa( this.scopeRoot, '[pf-count]' ).forEach( function ( el ) {
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
			if ( 'range' === self.groups[ field ].config.template ) {
				return; // range-группа (цена и т.п.) всегда видна, релевантность по счётчикам к ней не применяется.
			}
			self.groups[ field ].el.classList.toggle( 'pf-hidden', ! ( field in counts ) );
		} );

		Object.keys( counts ).forEach( function ( field ) {
			var group = self.groups[ field ];
			if ( ! group ) {
				return;
			}

			if ( 'range' === group.config.template ) {
				// В отличие от остальных групп — не список значений со счётчиком
				// у каждого, а min/max среди записей, подходящих под остальные
				// активные фильтры. Ползунок подстраивается под них так же, как
				// подстраиваются счётчики остальных групп.
				self.updateRangeBounds( group, counts[ field ] );
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
		this.sortEl = qs( this.scopeRoot, '[pf-sort]' );
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

	/**
	 * Разобрать значение опции сортировки в orderby+order. Суффикс "-desc"
	 * обозначает убывающее направление для ЛЮБОГО поля сортировки, не только
	 * "price-desc" — то же соглашение для сгенерированных на сервере
	 * meta_<поле>-desc/attr_<таксономия>-desc (см. PF_Attributes::get_sortable_field_options()).
	 */
	PFForm.prototype.applyOrderby = function ( value ) {
		if ( /-desc$/.test( value ) ) {
			this.state.orderby = value.replace( /-desc$/, '' );
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
		this._pagesContainer  = qs( this.scopeRoot, '[pf-pagination-pages]' );
		this._pageItemTpl     = qs( this.scopeRoot, '[pf-page-item]' );
		this._prevBtn         = qs( this.scopeRoot, '[pf-page-prev]' );
		this._nextBtn         = qs( this.scopeRoot, '[pf-page-next]' );
		this._loadMoreBtn     = qs( this.scopeRoot, '[pf-load-more]' );
		this._infiniteTrigger = qs( this.scopeRoot, '[pf-infinite-trigger]' );

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
		qsa( this.scopeRoot, '[pf-active-chip]' ).forEach( function ( chipTpl ) {
			chipTpl.classList.add( 'pf-hidden' );
		} );

		// Кнопка сброса по умолчанию скрыта — показывается только когда есть
		// хоть один реально активный фильтр (см. updateActiveFilters()).
		qsa( this.scopeRoot, '[pf-active-reset]' ).forEach( function ( btn ) {
			btn.classList.add( 'pf-hidden' );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.resetAllFilters();
			} );
		} );
	};

	/**
	 * Активен ли фильтр конкретно этого поля. Для range-группы — «активен»,
	 * если пользователь реально тронул min либо max (т.е. поле вообще есть
	 * в filters — collectFilters() кладёт его туда только в этом случае).
	 *
	 * Раньше здесь ещё сравнивалось значение фильтра с текущими "полными"
	 * границами трека (data-min/data-max) — считалось неактивным, если они
	 * совпадали. Убрано: границы трека — величина плавающая, подстраивается
	 * под ДРУГИЕ активные фильтры (см. updateRangeBounds()), и могла
	 * случайно совпасть с осознанным выбором пользователя просто потому что
	 * ДРУГОЙ фильтр в моменте сузил доступные значения до того же диапазона
	 * — тогда чип этой группы мог мигать/пропадать при изменении СОСЕДНЕЙ
	 * группы, никак не связанной с этой напрямую.
	 *
	 * @param {string} field   Поле группы.
	 * @param {Object} filters Результат collectFilters().
	 * @return {boolean}
	 */
	PFForm.prototype.isFieldActive = function ( field, filters ) {
		var group = this.groups[ field ];
		if ( group && 'range' === group.config.template ) {
			return !! filters[ field ];
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
				// Сброс — это тоже способ "перестать быть тронутым пользователем":
				// иначе после ручного выбора и последующего сброса цена продолжала
				// бы слаться как активный фильтр (полный диапазон, но formально
				// "тронутый"), пока сервер не подтвердит это следующим ответом.
				delete slider.dataset.userMin;
				delete slider.dataset.userMax;
				positionRangeHandle( qs( group.el, '[pf-filter-range-handle="min"]' ), rangeMin, rangeMin, rangeMax, false );
				positionRangeHandle( qs( group.el, '[pf-filter-range-handle="max"]' ), rangeMax, rangeMin, rangeMax, true );
				setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="min"]' ), slider.dataset.min, false );
				setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="max"]' ), slider.dataset.max, false );
				positionRangeTrack( qs( group.el, '[pf-filter-range-track]' ), rangeMin, rangeMax, rangeMin, rangeMax );
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

		qsa( this.scopeRoot, '[pf-active-reset]' ).forEach( function ( btn ) {
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

		qsa( this.scopeRoot, '[pf-active-filters]' ).forEach( function ( container ) {
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

				if ( group && 'range' === group.config.template ) {
					// Пришло из collectFilters() — уже "замороженный" выбор
					// пользователя (min/max по отдельности, либо null если ту
					// сторону не трогали), см. там же — почему не текущее
					// отображаемое значение.
					var rangeFilter = filters[ field ];
					var rangeText;
					if ( null !== rangeFilter.min && null !== rangeFilter.max ) {
						rangeText = rangeFilter.min + '–' + rangeFilter.max;
					} else if ( null !== rangeFilter.min ) {
						rangeText = 'от ' + rangeFilter.min;
					} else {
						rangeText = 'до ' + rangeFilter.max;
					}
					self.appendChip( container, chipTpl, groupLabel + ': ' + rangeText, function () {
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

		var self = this;
		var params = new URLSearchParams();
		var filters = this.collectFilters();

		Object.keys( filters ).forEach( function ( field ) {
			var group = self.groups[ field ];
			if ( group && 'range' === group.config.template ) {
				var rangeFilter = filters[ field ];
				if ( null !== rangeFilter.min && ! isNaN( rangeFilter.min ) ) {
					params.set( field + '_min', rangeFilter.min );
				}
				if ( null !== rangeFilter.max && ! isNaN( rangeFilter.max ) ) {
					params.set( field + '_max', rangeFilter.max );
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
		// Номер страницы пишется в URL только на настоящих архивных страницах
		// (см. pfConfig.isArchivePage, PF_Plugin::limit_main_query_posts_per_page()).
		// На встроенном (не архивном) блоке плагин не ограничивает и не
		// пагинирует исходный WordPress-запрос темы вообще — /page/N или
		// ?paged=N там не соответствует ничему реальному, и при перезагрузке
		// такой страницы запрос темы неожиданно может вернуть 0 записей
		// (WordPress к тому же канонизирует ?paged=N в путь /page/N/ при
		// следующей загрузке — тот же эффект). Проще не писать номер
		// страницы в URL вообще, чем чинить последствия у каждой темы.
		if ( this.state.paged > 1 && window.pfConfig && window.pfConfig.isArchivePage ) {
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
	 * На НЕ архивной странице (см. pfConfig.isArchivePage, updateUrl()) этот
	 * сегмент пути не может означать ничего надёжного — WordPress-запрос
	 * темы на такой странице никем не пагинирован, поэтому здесь он
	 * игнорируется симметрично тому, что updateUrl() его на таких страницах
	 * никогда не пишет.
	 *
	 * @return {number|null}
	 */
	PFForm.prototype.getPagedFromPath = function () {
		if ( ! window.pfConfig || ! window.pfConfig.isArchivePage ) {
			return null;
		}
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
			// ?paged=N в query-строке — то же самое соображение, что и у
			// getPagedFromPath() для пути /page/N/: на не архивной странице
			// (см. pfConfig.isArchivePage) номер страницы не может означать
			// ничего надёжного, updateUrl() сам такого не пишет.
			if ( 'paged' === key && window.pfConfig && window.pfConfig.isArchivePage ) {
				hasRelevantParams = true;
				self.state.paged = parseInt( rawValue, 10 ) || 1;
				return;
			}
			var rangeMatch = key.match( /^(.+)_(min|max)$/ );
			if ( rangeMatch ) {
				var rangeGroup = self.groups[ rangeMatch[ 1 ] ];
				if ( rangeGroup && 'range' === rangeGroup.config.template ) {
					hasRelevantParams = true;
					self.restoreRangeFromUrl( rangeGroup, rangeMatch[ 2 ], rawValue );
					return;
				}
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

	/**
	 * Восстановить одну границу (min или max) range-группы из URL-параметра
	 * вида <field>_min/<field>_max (см. updateUrl()).
	 *
	 * @param {{el: Element}} group  Группа из this.groups.
	 * @param {string} minOrMax      'min' | 'max'.
	 * @param {string} rawValue      Значение параметра из URL.
	 */
	PFForm.prototype.restoreRangeFromUrl = function ( group, minOrMax, rawValue ) {
		var slider = qs( group.el, '[pf-filter-range-slider]' );
		if ( ! slider ) {
			return;
		}
		var value = parseFloat( rawValue );
		if ( isNaN( value ) ) {
			return;
		}
		// Помечаем именно эту границу "тронутой пользователем" (data-user-min/
		// data-user-max — независимо друг от друга, см. buildRangeGroup()) —
		// без этого collectFilters() не включил бы восстановленное значение в
		// исходящий запрос, и после перезагрузки страницы с диапазоном в URL
		// ползунок визуально стоял бы в нужном месте, но реальная фильтрация
		// продолжала бы игнорировать эту границу.
		if ( 'min' === minOrMax ) {
			slider.dataset.currentMin = value;
			slider.dataset.userMin = value;
			setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="min"]' ), value, true );
		} else {
			slider.dataset.currentMax = value;
			slider.dataset.userMax = value;
			setRangeDisplayValue( qs( group.el, '[pf-filter-range-value="max"]' ), value, true );
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
