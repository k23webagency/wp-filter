/**
 * PF Search — живой поиск по атрибутам pf-search / pf-search="…" в разметке темы.
 *
 * Контракт разметки — pfs-search-docs.html (справочник для верстальщика)
 * и REFERENCE.md. Работает то, что есть в вёрстке: нет элемента — нет
 * функции, без ошибок. Обязателен только [pf-search="input"].
 */
( function () {
	'use strict';

	var cfg = window.pfsConfig;
	if ( ! cfg || ! cfg.profiles ) {
		return;
	}

	var MIN_CHARS = cfg.minChars || 3;
	var DEBOUNCE = cfg.debounce || 300;
	// Пауза перед применением группы поиска к списку фильтра.
	var GROUP_DELAY = 500;

	// Кэш ответов на страницу: ключ — профиль|тип|запрос.
	var responseCache = {};
	// Аналитика (если включена): какие запросы уже учтены на этой странице.
	var loggedQueries = {};
	var LOG_IDLE = 2500;

	/**
	 * Учесть итоговый запрос живого поиска (один beacon на запрос за
	 * страницу). Выключенная аналитика — logUrl пуст, ничего не шлём.
	 */
	function logQuery( profileId, type, q, total ) {
		if ( ! cfg.logUrl || ! q ) {
			return;
		}
		var key = profileId + '|' + type + '|' + q.toLowerCase();
		if ( loggedQueries[ key ] ) {
			return;
		}
		loggedQueries[ key ] = true;
		var body = new FormData();
		body.append( 'q', q );
		body.append( 'profile', profileId );
		body.append( 'type', type );
		body.append( 'total', String( total ) );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.logUrl, body );
		} else {
			fetch( cfg.logUrl, { method: 'POST', body: body, keepalive: true } ).catch( function () {} );
		}
	}
	var uid = 0;

	function hide( el ) {
		if ( el ) {
			el.classList.add( 'is-hidden' );
		}
	}

	function show( el ) {
		if ( el ) {
			el.classList.remove( 'is-hidden' );
		}
	}

	function toArray( list ) {
		return Array.prototype.slice.call( list || [] );
	}

	function buildUrl( base, params ) {
		var query = Object.keys( params ).filter( function ( k ) {
			return '' !== params[ k ] && null != params[ k ];
		} ).map( function ( k ) {
			return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
		} ).join( '&' );
		if ( ! query ) {
			return base;
		}
		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
	}

	/**
	 * Точечная переинициализация Webflow после вставки карточек — как
	 * PFForm.prototype.reinitWebflow() фильтра (дропдауны + interactions,
	 * без общего Webflow.destroy()).
	 */
	function reinitWebflow() {
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
			console.warn( 'PF Search: не удалось переинициализировать Webflow.', err );
		}
	}

	/**
	 * Подсветка слов, начинающихся с одного из terms, внутри el (<mark>).
	 */
	function highlight( el, terms ) {
		if ( ! terms || ! terms.length ) {
			return;
		}
		var parts = terms.map( function ( t ) {
			return t.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ).replace( /е/g, '[её]' );
		} );
		var re;
		try {
			re = new RegExp( '(^|[^\\p{L}\\p{N}])((?:' + parts.join( '|' ) + ')[\\p{L}\\p{N}]*)', 'giu' );
		} catch ( e ) {
			return; // браузер без Unicode property escapes — просто без подсветки.
		}

		var walker = document.createTreeWalker( el, NodeFilter.SHOW_TEXT, null );
		var nodes = [];
		while ( walker.nextNode() ) {
			if ( walker.currentNode.parentNode && 'MARK' !== walker.currentNode.parentNode.nodeName ) {
				nodes.push( walker.currentNode );
			}
		}

		nodes.forEach( function ( node ) {
			var text = node.nodeValue;
			re.lastIndex = 0;
			if ( ! re.test( text ) ) {
				return;
			}
			re.lastIndex = 0;
			var frag = document.createDocumentFragment();
			var last = 0;
			var m;
			while ( ( m = re.exec( text ) ) !== null ) {
				var start = m.index + m[ 1 ].length;
				frag.appendChild( document.createTextNode( text.slice( last, start ) ) );
				var mark = document.createElement( 'mark' );
				mark.textContent = m[ 2 ];
				frag.appendChild( mark );
				last = start + m[ 2 ].length;
				if ( m[ 0 ].length === 0 ) {
					re.lastIndex++;
				}
			}
			frag.appendChild( document.createTextNode( text.slice( last ) ) );
			node.parentNode.replaceChild( frag, node );
		} );
	}

	// -----------------------------------------------------------------
	// Блок поиска
	// -----------------------------------------------------------------

	function PFSearch( root, options ) {
		this.root = root;
		// Режим группы фильтра (pf-template="search"): options.onInput /
		// options.onSubmit / options.lockedType передаёт pf-filter.js.
		this.options = options || null;
		this.groupTimer = null;
		this.id = ++uid;
		this.timer = null;
		this.controller = null;
		this.currentQuery = '';
		this.lastData = null;
		this.isOpen = false;
		this.lastToggleAt = 0;
		this.live = false;
		this.escPressed = false;
	}

	PFSearch.prototype.init = function () {
		var root = this.root;

		this.profileId = root.getAttribute( 'pf-search-profile' ) || cfg.firstProfile;
		this.profile = cfg.profiles[ this.profileId ];
		if ( ! this.profile ) {
			console.error( 'PF Search: профиль поиска «' + this.profileId + '» не найден (Настройки → PF Search).', root );
			return false;
		}

		this.input = root.querySelector( '[pf-search="input"]' );
		if ( ! this.input ) {
			console.error( 'PF Search: внутри [pf-search=""] нет поля [pf-search="input"] — блок поиска не запущен.', root );
			return false;
		}

		this.input.setAttribute( 'autocomplete', 'off' );
		this.input.setAttribute( 'role', 'combobox' );
		this.input.setAttribute( 'aria-autocomplete', 'list' );
		this.input.setAttribute( 'aria-expanded', 'false' );

		// Внутри блока фильтра тип задан самим блоком.
		this.filterBlock = this.options ? null : root.closest( '[pf-profile]' );
		this.lockedType = this.options ? ( this.options.lockedType || '' ) : '';
		if ( this.filterBlock ) {
			var filterProfile = this.filterBlock.getAttribute( 'pf-profile' ) || cfg.firstFilterProfile;
			this.lockedType = cfg.filterProfiles[ filterProfile ] || '';
		}
		this.type = this.lockedType || this.profile.defaultType;

		this.list = root.querySelector( '[pf-search="dropdown"]' );
		this.toggle = root.querySelector( '[pf-search="toggle"]' );
		if ( this.toggle && this.toggle.contains( this.input ) ) {
			console.warn( 'PF Search: поле [pf-search="input"] лежит внутри [pf-search="toggle"] — так делать не нужно, переключатель должен быть отдельным элементом.', root );
		}

		this.loading = toArray( root.querySelectorAll( '[pf-search="loading"]' ) );
		this.clearBtns = toArray( root.querySelectorAll( '[pf-search="clear"]' ) );
		this.submitBtns = toArray( root.querySelectorAll( '[pf-search="submit"]' ) );
		this.empties = toArray( root.querySelectorAll( '[pf-search="empty"]' ) );
		this.suggests = toArray( root.querySelectorAll( '[pf-search="suggest"]' ) );
		this.alls = toArray( root.querySelectorAll( '[pf-search="all"]' ) );
		this.counts = toArray( root.querySelectorAll( '[pf-search="count"]' ) );
		this.queries = toArray( root.querySelectorAll( '[pf-search="query"]' ) );
		this.groups = this.list ? toArray( this.list.querySelectorAll( '[pf-search-group]' ) ) : [];

		this.loading.forEach( hide );
		this.empties.forEach( hide );
		this.suggests.forEach( hide );
		this.alls.forEach( hide );

		this.initLive();
		this.initTypes();
		this.bind();
		this.prefillFromUrl();
		this.updateClear();

		return true;
	};

	/**
	 * ?q= в адресе: поле внутри блока фильтра (его список уже показывает
	 * этот запрос) и поле на странице результатов своего профиля (в т.ч. в
	 * шапке) заполняются запросом; на странице результатов — ещё и тип.
	 */
	PFSearch.prototype.prefillFromUrl = function () {
		if ( this.options ) {
			return; // Поле группы заполняет pf-filter.js (syncSearchInputs()).
		}
		var params;
		try {
			params = new URLSearchParams( window.location.search );
		} catch ( e ) {
			return;
		}
		var q = ( params.get( 'q' ) || '' ).trim();
		if ( ! q || this.input.value ) {
			return;
		}

		var onResultsPage = false;
		if ( this.profile.resultsUrl ) {
			try {
				onResultsPage = new URL( this.profile.resultsUrl, window.location.href ).pathname.replace( /\/+$/, '' ) === window.location.pathname.replace( /\/+$/, '' );
			} catch ( e ) {
				onResultsPage = false;
			}
		}
		if ( ! this.filterBlock && ! onResultsPage ) {
			return;
		}

		this.input.value = q;
		var type = params.get( 'q_type' );
		if ( onResultsPage && ! this.lockedType && type && this.typeButtons ) {
			var allowed = this.profile.types.some( function ( t ) {
				return t.slug === type;
			} );
			if ( allowed ) {
				this.type = type;
				this.markType();
			}
		}
	};

	/**
	 * Живой поиск возможен, если есть окно и в нём [pf-search="results"].
	 */
	PFSearch.prototype.initLive = function () {
		if ( ! this.list ) {
			return; // Окна нет — живой поиск не нужен, это не ошибка.
		}

		var containers = toArray( this.list.querySelectorAll( '[pf-search="results"]' ) );
		if ( ! containers.length ) {
			console.warn( 'PF Search: в [pf-search="dropdown"] нет [pf-search="results"] — выпадающее окно отключено, поиск работает по Enter.', this.root );
			return;
		}

		// Стартовое содержимое цикла темы убираем: окно показывает только
		// результаты поиска.
		containers.forEach( function ( c ) {
			c.innerHTML = '';
		} );

		this.list.setAttribute( 'id', this.list.id || 'pfs-list-' + this.id );
		this.input.setAttribute( 'aria-controls', this.list.id );

		if ( ! this.toggle ) {
			hide( this.list );
		}
		this.live = true;
	};

	/**
	 * Кнопки выбора типа: одна кнопка-образец → по кнопке на тип профиля.
	 */
	PFSearch.prototype.initTypes = function () {
		var self = this;
		var sample = this.root.querySelector( '[pf-search="type"]' );
		if ( ! sample ) {
			return;
		}

		if ( this.options ) {
			sample.classList.add( 'pf-hidden' );
			return; // В группе фильтра тип задан профилем фильтра.
		}
		if ( this.lockedType ) {
			sample.classList.add( 'pf-hidden' );
			console.warn( 'PF Search: кнопка [pf-search="type"] внутри блока фильтра [pf-profile] не нужна — тип записей задан блоком фильтра. Кнопка скрыта.', sample );
			return;
		}
		if ( 'visitor' !== this.profile.typeMode || ! this.profile.types.length ) {
			sample.classList.add( 'pf-hidden' );
			return;
		}

		this.typeButtons = [];
		var prev = sample;
		var radioName = 'pfs-type-' + this.id;

		this.profile.types.forEach( function ( type, i ) {
			var btn = 0 === i ? sample : sample.cloneNode( true );
			btn.setAttribute( 'data-pf-type', type.slug );

			var label = btn.querySelector( '[pf-search="type-label"]' ) || btn;
			label.textContent = type.label;

			var radio = btn.querySelector( 'input[type="radio"]' );
			if ( radio ) {
				var radioId = radioName + '-' + type.slug;
				var labelFor = btn.querySelector( 'label[for]' );
				radio.name = radioName;
				radio.value = type.slug;
				radio.id = radioId;
				if ( labelFor ) {
					labelFor.setAttribute( 'for', radioId );
				}
			}

			if ( i > 0 ) {
				prev.parentNode.insertBefore( btn, prev.nextSibling );
			}
			prev = btn;

			btn.addEventListener( 'click', function ( e ) {
				if ( 'A' === btn.tagName || 'BUTTON' === btn.tagName ) {
					e.preventDefault();
				}
				self.selectType( type.slug );
			} );
			self.typeButtons.push( btn );
		} );

		this.markType();
	};

	PFSearch.prototype.markType = function () {
		var type = this.type;
		( this.typeButtons || [] ).forEach( function ( btn ) {
			var active = btn.getAttribute( 'data-pf-type' ) === type;
			btn.classList.toggle( 'is-active', active );
			var radio = btn.querySelector( 'input[type="radio"]' );
			if ( radio ) {
				radio.checked = active;
			}
		} );
	};

	PFSearch.prototype.selectType = function ( slug ) {
		if ( slug === this.type ) {
			return;
		}
		this.type = slug;
		this.markType();
		if ( this.query().length >= MIN_CHARS ) {
			this.search( true );
		}
	};

	PFSearch.prototype.query = function () {
		return ( this.input.value || '' ).trim();
	};

	PFSearch.prototype.bind = function () {
		var self = this;
		var input = this.input;

		input.addEventListener( 'input', function () {
			self.escPressed = false;
			self.updateClear();
			self.schedule();
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key ) {
				if ( self.focusCard( 0 ) ) {
					e.preventDefault();
				}
			} else if ( 'Escape' === e.key ) {
				e.preventDefault();
				if ( self.isOpenNow() ) {
					self.close();
				} else if ( self.escPressed || ! self.isOpenNow() ) {
					self.clear( false );
				}
				self.escPressed = true;
			} else if ( 'Enter' === e.key ) {
				e.preventDefault();
				self.fullSearch();
			}
		} );

		var reopen = function () {
			// Уже есть ответ на текущий запрос — открыть без нового запроса.
			if ( self.live && self.lastData && self.lastData.query === self.query() && self.hasContent( self.lastData ) ) {
				self.open();
			}
		};
		input.addEventListener( 'focus', reopen );
		// Webflow Dropdown закрывается по mouseup в любом месте вне себя, а
		// поле лежит вне дропдауна — клик в поле при открытом окне не должен
		// его закрывать (иначе окно мигает: закрылось и открылось снова).
		input.addEventListener( 'mouseup', function ( e ) {
			if ( self.isWebflowToggle() && self.isOpenNow() ) {
				e.stopPropagation();
			}
		} );
		input.addEventListener( 'pf-search:synced', function () {
			self.updateClear();
		} );
		// Компонент Webflow Dropdown сам закрывается по клику вне себя, а
		// поле лежит вне дропдауна — клик в поле его закрыл бы. Проверяем
		// после того, как отработают обработчики клика темы.
		input.addEventListener( 'click', function () {
			setTimeout( reopen, 0 );
		} );

		if ( 'FORM' === this.root.tagName ) {
			this.root.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				self.fullSearch();
			} );
		}

		this.submitBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.fullSearch();
			} );
		} );

		this.clearBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.clear( true );
			} );
		} );

		this.alls.forEach( function ( el ) {
			el.addEventListener( 'click', function ( e ) {
				// Ссылка вне блока фильтра — обычный переход по href (можно
				// открыть в новой вкладке); всё остальное — полная выдача.
				if ( 'A' === el.tagName && ! self.filterBlock && ! self.options && el.getAttribute( 'href' ) && '#' !== el.getAttribute( 'href' ) ) {
					return;
				}
				e.preventDefault();
				self.fullSearch();
			} );
		} );

		this.suggests.forEach( function ( box ) {
			toArray( box.querySelectorAll( '[pf-search="suggest-query"]' ) ).forEach( function ( el ) {
				el.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var text = el.textContent.trim();
					if ( text ) {
						self.input.value = text;
						self.updateClear();
						self.input.focus();
						self.search( true );
					}
				} );
			} );
		} );

		if ( this.list ) {
			// Клик по карточке — запрос точно итоговый.
			this.list.addEventListener( 'mousedown', function ( e ) {
				if ( self.lastData && e.target.closest && e.target.closest( '[pf-search="results"] a[href]' ) ) {
					logQuery( self.profileId, self.lastData.type, self.lastData.query, self.lastData.total );
				}
			} );

			this.list.addEventListener( 'keydown', function ( e ) {
				var links = self.cardLinks();
				var index = links.indexOf( document.activeElement );
				if ( -1 === index ) {
					return;
				}
				if ( 'ArrowDown' === e.key ) {
					e.preventDefault();
					if ( index < links.length - 1 ) {
						links[ index + 1 ].focus();
					}
				} else if ( 'ArrowUp' === e.key ) {
					e.preventDefault();
					if ( index > 0 ) {
						links[ index - 1 ].focus();
					} else {
						self.input.focus();
					}
				} else if ( 'Escape' === e.key ) {
					e.preventDefault();
					self.close();
					self.input.focus();
				}
			} );
		}

		document.addEventListener( 'click', function ( e ) {
			if ( ! self.root.contains( e.target ) ) {
				self.close();
			}
		} );
	};

	PFSearch.prototype.updateClear = function () {
		var empty = '' === ( this.input.value || '' );
		this.clearBtns.forEach( function ( btn ) {
			btn.classList.toggle( 'is-hidden', empty );
		} );
	};

	PFSearch.prototype.schedule = function () {
		var self = this;
		clearTimeout( this.timer );

		// Группа фильтра: применить к списку после паузы в наборе (как
		// изменение любого фильтра). Меньше минимума символов — снять поиск.
		if ( this.options && this.options.onInput ) {
			clearTimeout( this.groupTimer );
			this.groupTimer = setTimeout( function () {
				var q = self.query();
				self.options.onInput( q.length >= MIN_CHARS ? q : '' );
			}, GROUP_DELAY );
		}

		if ( ! this.live ) {
			return; // Нет окна — нет запросов при наборе.
		}

		if ( this.query().length < MIN_CHARS ) {
			this.abort();
			this.setLoading( false );
			this.close();
			this.lastData = null;
			return;
		}

		this.timer = setTimeout( function () {
			self.search( false );
		}, DEBOUNCE );
	};

	PFSearch.prototype.abort = function () {
		if ( this.controller ) {
			this.controller.abort();
			this.controller = null;
		}
	};

	PFSearch.prototype.setLoading = function ( on ) {
		this.root.classList.toggle( 'is-loading', on );
		this.loading.forEach( on ? show : hide );
	};

	PFSearch.prototype.search = function ( force ) {
		var self = this;
		var q = this.query();

		if ( ! this.live || q.length < MIN_CHARS ) {
			return;
		}
		if ( ! force && this.lastData && this.lastData.query === q && this.lastData.type === this.type ) {
			return;
		}

		var key = this.profileId + '|' + this.type + '|' + q;
		this.currentQuery = q;

		if ( responseCache[ key ] ) {
			this.abort();
			this.setLoading( false );
			this.render( responseCache[ key ] );
			return;
		}

		this.abort();
		var controller = window.AbortController ? new AbortController() : null;
		this.controller = controller;
		this.setLoading( true );

		var url = buildUrl( cfg.restUrl, {
			q: q,
			profile: this.profileId,
			type: this.type,
			per_page: this.profile.limit,
			render: 1,
		} );

		fetch( url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'HTTP ' + r.status );
				}
				return r.json();
			} )
			.then( function ( data ) {
				data.query = q;
				responseCache[ key ] = data;
				if ( self.controller === controller ) {
					self.controller = null;
					self.setLoading( false );
				}
				// Пока шёл запрос, посетитель мог напечатать дальше.
				if ( self.query() === q && self.type === data.type ) {
					self.render( data );
				}
			} )
			.catch( function ( err ) {
				if ( err && 'AbortError' === err.name ) {
					return;
				}
				if ( self.controller === controller ) {
					self.controller = null;
					self.setLoading( false );
				}
				console.warn( 'PF Search: запрос не удался.', err );
			} );
	};

	PFSearch.prototype.hasContent = function ( data ) {
		return data.total > 0 || this.empties.length > 0 || ( data.suggest && this.suggests.length > 0 );
	};

	/**
	 * Контейнер карточек под вариант, который вернул сервер: группа с этим
	 * именем, иначе первая группа, иначе единственный [pf-search="results"].
	 */
	PFSearch.prototype.resultsTarget = function ( groupName ) {
		if ( ! this.groups.length ) {
			return this.list.querySelector( '[pf-search="results"]' );
		}
		var target = null;
		this.groups.forEach( function ( g ) {
			if ( ! target && groupName && g.getAttribute( 'pf-search-group' ) === groupName ) {
				target = g;
			}
		} );
		target = target || this.groups[ 0 ];
		this.groups.forEach( function ( g ) {
			g.classList.toggle( 'is-hidden', g !== target );
		} );
		return target.querySelector( '[pf-search="results"]' );
	};

	PFSearch.prototype.render = function ( data ) {
		var self = this;
		this.lastData = data;

		// Посетитель остановился на этом запросе — учесть его в аналитике.
		clearTimeout( this.logTimer );
		if ( cfg.logUrl ) {
			this.logTimer = setTimeout( function () {
				if ( self.lastData === data && self.query() === data.query ) {
					logQuery( self.profileId, data.type, data.query, data.total );
				}
			}, LOG_IDLE );
		}

		if ( false === data.template ) {
			if ( ! this.templateWarned ) {
				this.templateWarned = true;
				console.warn( 'PF Search: не найден цикл карточек внутри [pf-search="results"] в PHP-файлах темы для профиля «' + this.profileId + '» — выпадающее окно отключено, поиск работает по Enter.', this.root );
			}
			this.live = false;
			this.close();
			return;
		}

		var container = this.resultsTarget( data.group );
		if ( container ) {
			container.innerHTML = data.html || '';
			toArray( container.querySelectorAll( '[pf-search="highlight"]' ) ).forEach( function ( el ) {
				highlight( el, data.highlight );
			} );
			reinitWebflow();
			container.dispatchEvent( new CustomEvent( 'pf-search:results-updated', {
				bubbles: true,
				detail: { results: container, query: data.query, type: data.type, total: data.total },
			} ) );
		}
		this.container = container;

		this.counts.forEach( function ( el ) {
			el.textContent = String( data.total );
		} );
		this.queries.forEach( function ( el ) {
			el.textContent = data.query;
		} );

		var found = data.total > 0;
		this.empties.forEach( found ? hide : show );
		this.alls.forEach( function ( el ) {
			if ( found ) {
				show( el );
				if ( 'A' === el.tagName ) {
					el.setAttribute( 'href', self.fullSearchUrl( data.query ) );
				}
			} else {
				hide( el );
			}
		} );

		this.suggests.forEach( function ( box ) {
			if ( data.suggest ) {
				toArray( box.querySelectorAll( '[pf-search="suggest-query"]' ) ).forEach( function ( el ) {
					el.textContent = data.suggest;
				} );
				show( box );
			} else {
				hide( box );
			}
		} );

		if ( this.hasContent( data ) && document.activeElement === this.input ) {
			this.open();
		} else if ( ! this.hasContent( data ) ) {
			this.close();
		}
	};

	PFSearch.prototype.cardLinks = function () {
		if ( ! this.container ) {
			return [];
		}
		var links = [];
		toArray( this.container.children ).forEach( function ( card ) {
			var link = card.matches( 'a[href]' ) ? card : card.querySelector( 'a[href]' );
			if ( link ) {
				links.push( link );
			}
		} );
		return links;
	};

	PFSearch.prototype.focusCard = function ( index ) {
		if ( ! this.isOpenNow() ) {
			return false;
		}
		var links = this.cardLinks();
		if ( links[ index ] ) {
			links[ index ].focus();
			return true;
		}
		return false;
	};

	// -----------------------------------------------------------------
	// Открытие/закрытие окна: переключатель темы или is-hidden
	// -----------------------------------------------------------------

	PFSearch.prototype.isListVisible = function () {
		var list = this.list;
		if ( ! list || ! list.getClientRects().length ) {
			return false;
		}
		var style = window.getComputedStyle( list );
		return 'hidden' !== style.visibility && '0' !== style.opacity;
	};

	PFSearch.prototype.isOpenNow = function () {
		if ( ! this.list ) {
			return false;
		}
		if ( ! this.toggle ) {
			return ! this.list.classList.contains( 'is-hidden' );
		}
		// Компонент Webflow Dropdown: состояние синхронно в классе w--open.
		if ( this.isWebflowToggle() ) {
			return this.toggle.classList.contains( 'w--open' );
		}
		// Только что нажимали переключатель — анимация ещё идёт, видимость
		// ненадёжна: верим своему состоянию.
		if ( Date.now() - this.lastToggleAt < 700 ) {
			return this.isOpen;
		}
		return this.isListVisible();
	};

	PFSearch.prototype.pressToggle = function () {
		this.lastToggleAt = Date.now();
		// Полная последовательность настоящего клика мышью: компонент Webflow
		// Dropdown открывается/закрывается по mouseup, Webflow Interactions
		// («Mouse click», анимация открытия) и свой JS темы — по click.
		// Одного click() недостаточно (Dropdown его не видит), одного
		// mouseup — тоже (анимация Interactions не проигрывается).
		var toggle = this.toggle;
		[ 'mousedown', 'mouseup', 'click' ].forEach( function ( type ) {
			toggle.dispatchEvent( new MouseEvent( type, { bubbles: true, cancelable: true, view: window } ) );
		} );
	};

	PFSearch.prototype.isWebflowToggle = function () {
		return !! this.toggle && this.toggle.classList.contains( 'w-dropdown-toggle' );
	};

	PFSearch.prototype.open = function () {
		if ( ! this.live ) {
			return;
		}
		if ( ! this.isOpenNow() ) {
			if ( this.toggle ) {
				this.pressToggle();
			} else {
				show( this.list );
			}
		}
		this.isOpen = true;
		this.input.setAttribute( 'aria-expanded', 'true' );
	};

	PFSearch.prototype.close = function () {
		if ( ! this.list ) {
			return;
		}
		if ( this.isOpenNow() ) {
			if ( this.toggle ) {
				this.pressToggle();
			} else {
				hide( this.list );
			}
		}
		this.isOpen = false;
		this.input.setAttribute( 'aria-expanded', 'false' );
	};

	PFSearch.prototype.clear = function ( focus ) {
		clearTimeout( this.timer );
		this.abort();
		this.setLoading( false );
		this.input.value = '';
		this.lastData = null;
		this.updateClear();
		this.close();
		clearTimeout( this.groupTimer );
		if ( this.options && this.options.onSubmit ) {
			this.options.onSubmit( '' );
		} else if ( this.filterBlock ) {
			this.submitToBlock( '' );
		}
		if ( focus ) {
			this.input.focus();
		}
	};

	// -----------------------------------------------------------------
	// Полная выдача
	// -----------------------------------------------------------------

	PFSearch.prototype.fullSearchUrl = function ( q ) {
		if ( this.profile.resultsUrl ) {
			return buildUrl( this.profile.resultsUrl, { q: q, q_type: this.type } );
		}
		return buildUrl( cfg.homeUrl, { s: q, post_type: this.type } );
	};

	/**
	 * Передать запрос блоку фильтра (pf-filter.js). Пустой запрос — снять
	 * поиск со списка.
	 *
	 * @return {boolean} true — блок фильтра обработал запрос.
	 */
	PFSearch.prototype.submitToBlock = function ( q ) {
		return ! this.filterBlock.dispatchEvent( new CustomEvent( 'pf-search:submit', {
			bubbles: false,
			cancelable: true,
			detail: { query: q, type: this.type, profile: this.profileId, search: this },
		} ) );
	};

	PFSearch.prototype.fullSearch = function () {
		var q = this.query();

		// Группа фильтра: Enter/«Найти»/«Показать все» — применить сразу.
		if ( this.options && this.options.onSubmit ) {
			clearTimeout( this.groupTimer );
			this.close();
			this.options.onSubmit( q.length >= MIN_CHARS ? q : '' );
			return;
		}

		// Внутри блока фильтра — выдача в его [pf-list=""] (обработчик ставит
		// pf-filter.js; если его нет — переход, как вне блока). Пустое поле +
		// Enter — показать список без поиска.
		if ( this.filterBlock ) {
			if ( ! q || q.length >= MIN_CHARS ) {
				if ( this.submitToBlock( q ) ) {
					this.close();
					return;
				}
			}
		}

		if ( q.length < MIN_CHARS ) {
			return;
		}

		window.location.href = this.fullSearchUrl( q );
	};

	// -----------------------------------------------------------------
	// Запуск
	// -----------------------------------------------------------------

	/**
	 * [pf-search="query"] вне любого [pf-search=""] — запрос из адреса страницы (заголовок
	 * страницы результатов).
	 */
	function fillPageQuery() {
		var q;
		try {
			q = new URLSearchParams( window.location.search ).get( 'q' );
		} catch ( e ) {
			return;
		}
		if ( ! q ) {
			return;
		}
		toArray( document.querySelectorAll( '[pf-search="query"]' ) ).forEach( function ( el ) {
			if ( ! el.closest( '[pf-search=""]' ) ) {
				el.textContent = q;
			}
		} );
	}

	function init() {
		window.pfsInstances = window.pfsInstances || [];
		toArray( document.querySelectorAll( '[pf-search=""]' ) ).forEach( function ( root ) {
			// Шаблоны групп фильтра — это не живые блоки: их запускает
			// pf-filter.js после построения группы (window.pfsSearchInit).
			if ( root.pfsInstance || root.closest( '[pf-template]' ) ) {
				return;
			}
			var instance = new PFSearch( root );
			if ( instance.init() ) {
				root.pfsInstance = instance;
				window.pfsInstances.push( instance );
			}
		} );
		fillPageQuery();
	}

	/**
	 * Запустить блок поиска на корне, построенном позже загрузки страницы
	 * (группа фильтра pf-template="search", см. pf-filter.js).
	 *
	 * @param {Element} root    Корень [pf-search=""].
	 * @param {Object}  options onInput(query), onSubmit(query), lockedType.
	 * @return {PFSearch|null}
	 */
	window.pfsSearchInit = function ( root, options ) {
		if ( root.pfsInstance ) {
			return root.pfsInstance;
		}
		var instance = new PFSearch( root, options );
		if ( ! instance.init() ) {
			return null;
		}
		root.pfsInstance = instance;
		window.pfsInstances = window.pfsInstances || [];
		window.pfsInstances.push( instance );
		return instance;
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
