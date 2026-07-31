<?php
/**
 * Страница настроек плагина в админке WordPress.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Admin
 */
class PF_Admin {

	/**
	 * Slug страницы настроек.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'pf-filter-settings';

	/**
	 * Сканер таксономий/атрибутов.
	 *
	 * @var PF_Attributes
	 */
	private $attributes;

	/**
	 * Диагностика окружения/разметки/REST API.
	 *
	 * @var PF_Diagnostics
	 */
	private $diagnostics;

	/**
	 * Конструктор.
	 */
	public function __construct() {
		$this->attributes  = new PF_Attributes();
		$this->diagnostics = new PF_Diagnostics();
	}

	/**
	 * Регистрация хуков админки.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		// Резолв активного профиля из $_REQUEST['profile'] должен произойти до
		// любого чтения PF_Config на странице настроек и до обработчиков
		// admin-post.php ниже — admin_init гарантированно фирится раньше обоих
		// (admin-post.php тоже полностью бутстрапит wp-admin/admin.php).
		add_action( 'admin_init', array( $this, 'resolve_active_profile' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_pf_run_diagnostics', array( $this, 'ajax_run_diagnostics' ) );

		add_action( 'admin_post_pf_save_profile', array( $this, 'handle_save_profile' ) );
		add_action( 'admin_post_pf_create_profile', array( $this, 'handle_create_profile' ) );
		add_action( 'admin_post_pf_duplicate_profile', array( $this, 'handle_duplicate_profile' ) );
		add_action( 'admin_post_pf_delete_profile', array( $this, 'handle_delete_profile' ) );
	}

	/**
	 * Регистрация пункта меню Настройки → PF Filter.
	 */
	public function register_page() {
		add_options_page(
			__( 'PF Filter', 'pf-filter' ),
			__( 'PF Filter', 'pf-filter' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Резолвит активный профиль на весь остаток текущего admin-запроса из
	 * ?profile=/$_POST[profile] (страница настроек, admin-post.php обработчики
	 * ниже), не требуя от каждого из них делать это самостоятельно. Без
	 * явного запроса — первый профиль по порядку (см. PF_Config::resolve_profile_id()).
	 *
	 * admin_init фирится на КАЖДОЙ странице wp-admin, не только на нашей —
	 * поэтому сперва проверяем, что запрос вообще относится к плагину
	 * (иначе на каждой странице админки был бы лишний get_option()).
	 */
	public function resolve_active_profile() {
		$is_our_page   = isset( $_REQUEST['page'] ) && self::PAGE_SLUG === $_REQUEST['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- определяем контекст, не мутируем; мутирующие действия ниже проверяют nonce отдельно.
		$is_our_action = isset( $_REQUEST['action'] ) && 0 === strpos( (string) $_REQUEST['action'], 'pf_' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $is_our_page && ! $is_our_action ) {
			return;
		}

		$requested = isset( $_REQUEST['profile'] ) ? sanitize_key( wp_unslash( $_REQUEST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- само переключение профиля для просмотра read-only, мутирующие действия проверяют nonce отдельно в своих обработчиках.
		$resolved  = PF_Config::resolve_profile_id( $requested );
		PF_Config::use_profile( $resolved['id'] );
	}

	/**
	 * Сохранить настройки текущего профиля (обработчик формы вкладок
	 * «Общие настройки»/«Группы фильтров»/«Сортировка», admin-post.php вместо
	 * Settings API — при мультипрофильности он писал бы поверх ключа
	 * PF_Config::OPTION_KEY целиком, а не в конкретный слот в списке профилей).
	 */
	public function handle_save_profile() {
		$this->require_manage_options( 'pf_save_profile' );

		$id = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';

		if ( ! isset( PF_Config::get_profiles()[ $id ] ) ) {
			wp_die( esc_html__( 'Профиль не найден.', 'pf-filter' ) );
		}

		// ID профиля (значение pf-profile в разметке темы) — редактируемое
		// поле формы, отдельное от pf_filter_settings[...] (это не настройка
		// профиля, а ключ, под которым он хранится в списке). Пустое значение
		// (поле оставили пустым) тихо игнорируется — id остаётся прежним,
		// а не обнуляется.
		$requested_id = isset( $_POST['profile_id'] ) ? sanitize_title( wp_unslash( $_POST['profile_id'] ) ) : '';
		$final_id     = $id;
		$id_collision = false;

		if ( $requested_id && $requested_id !== $id ) {
			if ( PF_Config::rename_profile_id( $id, $requested_id ) ) {
				$final_id = $requested_id;
			} else {
				$id_collision = true;
			}
		}

		$input = isset( $_POST['pf_filter_settings'] ) && is_array( $_POST['pf_filter_settings'] )
			? wp_unslash( $_POST['pf_filter_settings'] )
			: array();

		PF_Config::save_profile( $final_id, $this->sanitize( $input ) );

		wp_safe_redirect( $this->profile_url( $final_id, $id_collision ? array( 'error' => 'id_taken' ) : array( 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * Создать новый профиль (кнопка «+ Новый профиль»).
	 */
	public function handle_create_profile() {
		$this->require_manage_options( 'pf_create_profile' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			$name = __( 'Новый профиль', 'pf-filter' );
		}

		$requested_post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$post_type            = array_key_exists( $requested_post_type, $this->get_public_post_types() )
			? $requested_post_type
			: ( class_exists( 'WooCommerce' ) ? 'product' : 'post' );

		$id = PF_Config::create_profile( $name, $post_type );

		wp_safe_redirect( $this->profile_url( $id, array( 'created' => 1 ) ) );
		exit;
	}

	/**
	 * Дублировать текущий профиль целиком (кнопка «Дублировать профиль»).
	 */
	public function handle_duplicate_profile() {
		$this->require_manage_options( 'pf_duplicate_profile' );

		$id     = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		$new_id = PF_Config::duplicate_profile( $id );

		wp_safe_redirect( $this->profile_url( $new_id ?: $id, $new_id ? array( 'duplicated' => 1 ) : array( 'error' => 'not_found' ) ) );
		exit;
	}

	/**
	 * Удалить текущий профиль (кнопка «Удалить профиль») — отказывает, если
	 * это последний оставшийся, см. PF_Config::delete_profile().
	 */
	public function handle_delete_profile() {
		$this->require_manage_options( 'pf_delete_profile' );

		$id      = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		$deleted = PF_Config::delete_profile( $id );

		if ( $deleted ) {
			$remaining = array_keys( PF_Config::get_profiles() );
			wp_safe_redirect( $this->profile_url( $remaining[0] ?? '', array( 'deleted' => 1 ) ) );
		} else {
			wp_safe_redirect( $this->profile_url( $id, array( 'error' => 'last_profile' ) ) );
		}
		exit;
	}

	/**
	 * Общая проверка для всех admin-post.php-обработчиков CRUD профилей
	 * выше: nonce конкретного действия + право manage_options. Останавливает
	 * выполнение (wp_die()) сама, ничего не возвращает — вызывающему коду
	 * достаточно вызвать её первой строкой обработчика.
	 *
	 * @param string $nonce_action Значение nonce-действия (см. wp_nonce_field() в admin-page.php).
	 */
	private function require_manage_options( $nonce_action ) {
		check_admin_referer( $nonce_action );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'pf-filter' ) );
		}
	}

	/**
	 * URL страницы настроек, привязанный к профилю, с дополнительными
	 * query-параметрами (обычно флагом уведомления вида updated=1).
	 *
	 * @param string $profile_id ID профиля.
	 * @param array  $extra_args Дополнительные query-параметры.
	 * @return string
	 */
	private function profile_url( $profile_id, array $extra_args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page'    => self::PAGE_SLUG,
					'profile' => $profile_id,
				),
				$extra_args
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Подключить JS/CSS только на странице настроек плагина.
	 *
	 * @param string $hook Текущий admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'pf-admin', PF_FILTER_URL . 'assets/css/pf-admin.css', array(), PF_FILTER_VERSION );
		wp_enqueue_script( 'pf-admin', PF_FILTER_URL . 'assets/js/pf-admin.js', array(), PF_FILTER_VERSION, true );
		wp_enqueue_script( 'pf-diagnostics', PF_FILTER_URL . 'assets/js/pf-diagnostics.js', array(), PF_FILTER_VERSION, true );

		wp_localize_script(
			'pf-admin',
			'pfAdminConfig',
			array(
				'fieldCompatibleTemplates' => $this->get_field_compatible_templates_map(),
				'acfFieldsByField'         => $this->get_acf_fields_map(),
			)
		);

		wp_localize_script(
			'pf-diagnostics',
			'pfDiagnosticsConfig',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'pf_diagnostics' ),
				'defaultUrl' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
				// Профиль резолвится один раз на весь admin-запрос (см.
				// resolve_active_profile()) — та же диагностика должна
				// проверять именно тот профиль, что сейчас открыт на странице.
				'profile'    => PF_Config::get_active_profile_id(),
			)
		);
	}

	/**
	 * AJAX-обработчик кнопки «Запустить проверку» на вкладке «Диагностика».
	 * Результаты никогда не кешируются — каждый вызов делает свежие проверки.
	 */
	public function ajax_run_diagnostics() {
		check_ajax_referer( 'pf_diagnostics', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'pf-filter' ) ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		wp_send_json_success(
			array(
				'environment' => $this->diagnostics->check_environment(),
				'markup'      => $url ? $this->diagnostics->analyze_markup( $url ) : null,
				'rest_api'    => $this->diagnostics->test_rest_api(),
			)
		);
	}

	/**
	 * Санировать сохраняемые настройки перед записью в wp_options.
	 *
	 * @param array $input Сырые данные из $_POST (уже собранные Settings API).
	 * @return array
	 */
	public function sanitize( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return PF_Config::get_settings();
		}

		$input = is_array( $input ) ? $input : array();
		$clean = PF_Config::get_defaults();

		$clean['name']                = isset( $input['name'] ) && '' !== trim( (string) $input['name'] )
			? sanitize_text_field( $input['name'] )
			: $clean['name'];
		$clean['post_type']          = array_key_exists( $input['post_type'] ?? '', $this->get_public_post_types() )
			? $input['post_type']
			: $clean['post_type'];
		$clean['logic']              = in_array( $input['logic'] ?? '', array( 'and', 'or' ), true ) ? $input['logic'] : 'and';
		$clean['search_threshold']   = isset( $input['search_threshold'] ) ? absint( $input['search_threshold'] ) : 7;
		$clean['show_counts']        = ! empty( $input['show_counts'] );
		$clean['sync_url']           = ! empty( $input['sync_url'] );
		$clean['pagination_strategy'] = in_array( $input['pagination_strategy'] ?? '', PF_Config::PAGINATION_STRATEGIES, true )
			? $input['pagination_strategy']
			: 'pages';
		$clean['posts_per_page']    = isset( $input['posts_per_page'] ) ? max( 1, absint( $input['posts_per_page'] ) ) : 12;

		$clean['groups']       = $this->sanitize_groups( $input['groups'] ?? array() );
		$clean['sort_options'] = $this->sanitize_sort_options( $input['sort_options'] ?? array() );

		return $clean;
	}

	/**
	 * Санировать список групп фильтров.
	 *
	 * @param array $groups Сырые данные групп.
	 * @return array
	 */
	private function sanitize_groups( $groups ) {
		if ( ! is_array( $groups ) ) {
			return array();
		}

		$clean = array();

		foreach ( $groups as $group ) {
			if ( empty( $group['field'] ) ) {
				continue;
			}

			$row = array(
				'field'      => sanitize_text_field( $group['field'] ),
				'label'      => sanitize_text_field( $group['label'] ?? '' ),
				'template'   => sanitize_key( $group['template'] ?? 'checkbox' ),
				'logic'      => in_array( $group['logic'] ?? 'or', array( 'and', 'or' ), true ) ? $group['logic'] : 'or',
				'enabled'    => ! empty( $group['enabled'] ),
				'search'     => ! empty( $group['search'] ),
				'value_sort' => in_array( $group['value_sort'] ?? '', PF_Attributes::VALUE_SORT_OPTIONS, true ) ? $group['value_sort'] : 'name_asc',
			);

			if ( isset( $group['step'] ) && '' !== $group['step'] ) {
				$row['step'] = floatval( $group['step'] );
			}
			if ( isset( $group['unit'] ) ) {
				$row['unit'] = sanitize_text_field( $group['unit'] );
			}
			if ( isset( $group['tree_depth'] ) && '' !== $group['tree_depth'] ) {
				$row['tree_depth'] = absint( $group['tree_depth'] );
			}

			if ( isset( $group['color_meta_key'] ) && '' !== trim( (string) $group['color_meta_key'] ) ) {
				$row['color_meta_key'] = sanitize_key( $group['color_meta_key'] );
			}

			$clean[] = $row;
		}

		return $clean;
	}

	/**
	 * Санировать список опций сортировки.
	 *
	 * @param array $options Сырые данные опций.
	 * @return array
	 */
	private function sanitize_sort_options( $options ) {
		if ( ! is_array( $options ) ) {
			return array();
		}

		$whitelist = wp_list_pluck( $this->attributes->get_sortable_field_options(), 'value' );
		$clean     = array();

		foreach ( $options as $option ) {
			if ( empty( $option['value'] ) || ! in_array( $option['value'], $whitelist, true ) ) {
				continue;
			}
			$clean[] = array(
				'value' => $option['value'],
				'label' => sanitize_text_field( $option['label'] ?? $option['value'] ),
			);
		}

		return $clean;
	}

	/**
	 * Список шаблонов групп по умолчанию (используется если сканирование разметки
	 * не задано или не нашло ни одного pf-template в разметке).
	 *
	 * @var array
	 */
	const DEFAULT_TEMPLATES = array( 'checkbox', 'radio', 'tags', 'dropdown-checkbox', 'dropdown-radio', 'range', 'category-tree' );

	/**
	 * Русская подпись шаблона для выпадашки "Шаблон" в таблице групп — реальное
	 * значение (используется как pf-template в разметке темы и хранится в
	 * настройках) не меняется, подписывается только текст option.
	 *
	 * @param string $slug Значение pf-template (checkbox/radio/tags/...).
	 * @return string
	 */
	private function template_label( $slug ) {
		$labels = array(
			'checkbox'          => __( 'Чекбоксы', 'pf-filter' ),
			'radio'             => __( 'Радиокнопки', 'pf-filter' ),
			'tags'              => __( 'Теги/чипы', 'pf-filter' ),
			'dropdown-checkbox' => __( 'Дропдаун (чекбоксы)', 'pf-filter' ),
			'dropdown-radio'    => __( 'Дропдаун (радиокнопки)', 'pf-filter' ),
			'range'             => __( 'Диапазон (слайдер)', 'pf-filter' ),
			'category-tree'     => __( 'Дерево категорий', 'pf-filter' ),
		);

		return $labels[ $slug ] ?? $slug;
	}

	/**
	 * Типы записи, не имеющие смысла как «каталог с фильтром» — не показываются
	 * в выпадашке «Тип записи», даже будучи технически public=true.
	 *
	 * @var string[]
	 */
	const NON_CATALOG_POST_TYPES = array( 'attachment', 'page' );

	/**
	 * Публичные типы записи сайта (в т.ч. кастомные), пригодные для фильтра-
	 * каталога — источник для выпадашки «Тип записи» в общих настройках.
	 * Медиафайлы и страницы исключены (см. NON_CATALOG_POST_TYPES) — WordPress
	 * регистрирует их public=true, но списком/каталогом с фильтром их не
	 * листают. Ключ — слаг типа записи, значение — его подпись (labels->name).
	 *
	 * @return array slug => label
	 */
	private function get_public_post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( in_array( $post_type->name, self::NON_CATALOG_POST_TYPES, true ) ) {
				continue;
			}
			$out[ $post_type->name ] = $post_type->labels->name ?? $post_type->name;
		}
		return $out;
	}

	/**
	 * Дополнительные (не-таксономические) поля, которые стоит предложить в
	 * списке доступных полей группы, сверх get_available_taxonomy_fields():
	 * «Цена» и «Наличие» (обе — WooCommerce-специфика, предлагаются только
	 * когда настроенный тип записи product) плюс числовые ACF-поля записи-
	 * кандидаты на range-группу.
	 *
	 * «Цена» — meta-ключ _price жёстко зашит в
	 * PF_Attributes::resolve_range_meta_key(), иначе создавала бы заведомо
	 * нерабочую (всегда 0..0) группу для другого типа записи. «Наличие» —
	 * фиксированный набор значений _stock_status, см.
	 * PF_Attributes::build_stock_status_group(). Остальные кандидаты — любые
	 * ACF-поля, зарегистрированные на настроенный тип записи (см.
	 * get_acf_post_fields()) — выбор, какое из них численное и подходит для
	 * диапазона, остаётся за админом (тот же принцип, что и у color_meta_key).
	 *
	 * @return array
	 */
	private function get_extra_fields() {
		$post_type = PF_Config::get_post_type();
		$fields    = array();

		if ( 'product' === $post_type ) {
			$fields[] = array(
				'field' => 'price',
				'label' => __( 'Цена', 'pf-filter' ),
			);
			$fields[] = array(
				'field' => 'stock_status',
				'label' => __( 'Наличие', 'pf-filter' ),
			);
		}

		foreach ( $this->attributes->get_acf_post_fields( $post_type ) as $af ) {
			$fields[] = array(
				'field' => $af['name'],
				'label' => $af['label'],
			);
		}

		return $fields;
	}

	/**
	 * Отрендерить страницу настроек.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// resolve_active_profile() (admin_init, отработавший раньше этого метода)
		// уже выставил активный профиль из ?profile= — здесь только читаем.
		$active_profile_id = PF_Config::get_active_profile_id();
		$profiles           = PF_Config::get_profiles();
		$settings         = PF_Config::get_settings();
		$post_types       = $this->get_public_post_types();
		$available_fields = array_merge( $this->attributes->get_available_taxonomy_fields(), $this->get_extra_fields() );
		$sortable_options = $this->attributes->get_sortable_field_options();
		// Страница магазина используется как образец разметки для двух вспомогательных
		// проверок ниже — списка доступных pf-template и доступности стратегий
		// пагинации. Раньше для этого нужно было вручную вписывать URL в отдельное
		// поле настроек; теперь берём тот же URL, что уже используется как дефолт
		// для ручной диагностики разметки.
		$diagnostics_default_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$available_templates     = $this->get_available_templates( $diagnostics_default_url );
		// Один скан образца разметки на всю страницу настроек — переиспользуется
		// и для доступности стратегий пагинации, и для точечных предупреждений
		// "для этой настройки в вёрстке нет нужного элемента" (см. ниже
		// PF_Diagnostics::has_attribute()/has_text_input() в самом виде).
		$scan_dom              = $this->diagnostics->fetch_dom( $diagnostics_default_url );
		$scan_xpath            = $scan_dom ? new DOMXPath( $scan_dom ) : null;
		$available_pagination  = $scan_xpath ? PF_Diagnostics::detect_pagination_availability( $scan_xpath ) : null;

		require PF_FILTER_PATH . 'includes/views/admin-page.php';
	}

	/**
	 * Отрендерить одну строку группы фильтра (используется и для существующих
	 * групп, и как HTML-шаблон для JS при добавлении новой строки).
	 *
	 * @param string $name_prefix         Префикс имени поля, напр. "pf_filter_settings[groups]".
	 * @param string $index               Индекс строки (число либо "__INDEX__" для JS-шаблона).
	 * @param array  $group               Данные группы (field/label/template/logic/enabled/...).
	 * @param array         $available_fields    Список доступных полей [ [field,label], ... ].
	 * @param array         $available_templates Список доступных значений pf-template.
	 * @param DOMXPath|null $scan_xpath          XPath образца разметки (см. PF_Admin::render_page()) для точечных
	 *                                           предупреждений "нет нужного элемента"; null — сканирование недоступно.
	 */
	public function render_group_row( $name_prefix, $index, array $group, array $available_fields, array $available_templates, $scan_xpath = null ) {
		$n = $name_prefix . '[' . $index . ']';
		$field    = $group['field'] ?? '';
		$label    = $group['label'] ?? '';
		$template = $group['template'] ?? 'checkbox';
		$logic    = $group['logic'] ?? 'or';
		$enabled  = ! empty( $group['enabled'] );
		// По умолчанию (для новых групп и уже существующих без явной настройки)
		// поиск включён — как и было раньше, до появления этого переключателя.
		$search   = ! isset( $group['search'] ) || ! empty( $group['search'] );
		$step     = $group['step'] ?? '';
		$unit     = $group['unit'] ?? '';
		$tree_d   = $group['tree_depth'] ?? '';
		$value_sort = $group['value_sort'] ?? 'name_asc';
		$color_meta_key = $group['color_meta_key'] ?? '';
		// Поля ACF термина ЭТОГО поля группы (если оно вообще таксономия) —
		// список для выпадашки «поле с цветом». У кастомных атрибутов и цены
		// термов не существует, для них всегда пусто.
		$acf_fields = taxonomy_exists( $field ) ? $this->attributes->get_acf_term_fields( $field ) : array();
		// null — сканирование недоступно (тогда ничего не предупреждаем, как и
		// в остальных местах, завязанных на скан образца разметки).
		$search_available = $scan_xpath ? PF_Diagnostics::has_text_input( $scan_xpath, $template ) : null;
		$colors_available = $scan_xpath ? PF_Diagnostics::has_attribute( $scan_xpath, 'pf-filter-swatch', $template ) : null;
		// Реальная глубина дерева этой таксономии vs эффективно настроенная.
		// Для category-tree депth всегда эффективна (своя, иначе дефолт
		// PF_Attributes::DEFAULT_TREE_DEPTH, см. build_category_tree_group()).
		// Для плоских шаблонов (checkbox/radio/tags/dropdown-*) депth
		// ограничивает показанные значения ТОЛЬКО если явно задана на самой
		// группе (см. build_taxonomy_group()) — без неё предупреждать не о чем.
		$tree_depth_warning = null;
		if ( taxonomy_exists( $field ) && is_taxonomy_hierarchical( $field ) && ( 'category-tree' === $template || '' !== $tree_d ) ) {
			$effective_depth = '' !== $tree_d ? (int) $tree_d : PF_Attributes::DEFAULT_TREE_DEPTH;
			$real_depth      = $this->attributes->get_real_category_depth( $field );
			if ( $real_depth > $effective_depth ) {
				$tree_depth_warning = sprintf(
					/* translators: 1: реальная глубина дерева, 2: настроенная глубина */
					__( 'реальная глубина — %1$d, показывается %2$d', 'pf-filter' ),
					$real_depth,
					$effective_depth
				);
			}
		}
		?>
		<tr class="pf-group-row" draggable="true" data-index="<?php echo esc_attr( $index ); ?>">
			<td class="pf-drag-handle" title="<?php esc_attr_e( 'Перетащить для изменения порядка', 'pf-filter' ); ?>">☰</td>
			<td>
				<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $enabled ); ?> />
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[field]" class="pf-field-select">
					<?php foreach ( $available_fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f['field'] ); ?>" <?php selected( $field, $f['field'] ); ?>><?php echo esc_html( $f['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Название', 'pf-filter' ); ?>" />
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[template]" class="pf-template-select">
					<?php foreach ( $available_templates as $tpl ) : ?>
						<option value="<?php echo esc_attr( $tpl ); ?>" <?php selected( $template, $tpl ); ?>><?php echo esc_html( $this->template_label( $tpl ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[logic]">
					<option value="or" <?php selected( $logic, 'or' ); ?>><?php esc_html_e( 'OR', 'pf-filter' ); ?></option>
					<option value="and" <?php selected( $logic, 'and' ); ?>><?php esc_html_e( 'AND', 'pf-filter' ); ?></option>
				</select>
			</td>
			<td class="pf-extra-search">
				<label title="<?php esc_attr_e( 'Если выключено — поле поиска в этой группе не показывается никогда, независимо от порога', 'pf-filter' ); ?>">
					<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[search]" value="1" <?php checked( $search ); ?> />
					<?php esc_html_e( 'Поиск', 'pf-filter' ); ?>
				</label>
				<?php if ( $search && false === $search_available ) : ?>
					<br /><span style="color:#b32d2e;font-size:11px;" title="<?php esc_attr_e( 'В шаблоне pf-template этой группы не найден input[type=text] — поле поиска показывать будет негде.', 'pf-filter' ); ?>">⚠ <?php esc_html_e( 'нет поля в шаблоне', 'pf-filter' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="pf-extra-range">
				<input type="number" step="any" name="<?php echo esc_attr( $n ); ?>[step]" value="<?php echo esc_attr( $step ); ?>" placeholder="<?php esc_attr_e( 'Шаг', 'pf-filter' ); ?>" style="width:70px" />
				<input type="text" name="<?php echo esc_attr( $n ); ?>[unit]" value="<?php echo esc_attr( $unit ); ?>" placeholder="<?php esc_attr_e( 'Ед.', 'pf-filter' ); ?>" style="width:50px" />
			</td>
			<td class="pf-extra-tree">
				<input type="number" min="1" name="<?php echo esc_attr( $n ); ?>[tree_depth]" value="<?php echo esc_attr( $tree_d ); ?>" placeholder="<?php esc_attr_e( 'Глубина', 'pf-filter' ); ?>" style="width:70px" />
				<?php if ( $tree_depth_warning ) : ?>
					<br /><span style="color:#b32d2e;font-size:11px;" title="<?php esc_attr_e( 'Значения глубже настроенного уровня не будут показаны в этой группе.', 'pf-filter' ); ?>">⚠ <?php echo esc_html( $tree_depth_warning ); ?></span>
				<?php endif; ?>
			</td>
			<td class="pf-extra-colors">
				<select name="<?php echo esc_attr( $n ); ?>[color_meta_key]" class="pf-color-meta-select" title="<?php esc_attr_e( 'Поле ACF/term meta термина, где хранится его цвет.', 'pf-filter' ); ?>">
					<option value=""><?php esc_html_e( '— нет —', 'pf-filter' ); ?></option>
					<?php foreach ( $acf_fields as $af ) : ?>
						<option value="<?php echo esc_attr( $af['name'] ); ?>" <?php selected( $color_meta_key, $af['name'] ); ?>><?php echo esc_html( $af['label'] . ' (' . $af['name'] . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $color_meta_key && false === $colors_available ) : ?>
					<p style="color:#b32d2e;font-size:11px;margin:4px 0 0;" title="<?php esc_attr_e( 'В шаблоне pf-template этой группы не найден [pf-filter-swatch] — цвета показывать будет негде.', 'pf-filter' ); ?>">⚠ <?php esc_html_e( 'нет pf-filter-swatch в шаблоне', 'pf-filter' ); ?></p>
				<?php endif; ?>
			</td>
			<td class="pf-extra-value-sort">
				<select name="<?php echo esc_attr( $n ); ?>[value_sort]">
					<option value="name_asc" <?php selected( $value_sort, 'name_asc' ); ?>><?php esc_html_e( 'По алфавиту (А→Я)', 'pf-filter' ); ?></option>
					<option value="name_desc" <?php selected( $value_sort, 'name_desc' ); ?>><?php esc_html_e( 'По алфавиту (Я→А)', 'pf-filter' ); ?></option>
					<option value="count_desc" <?php selected( $value_sort, 'count_desc' ); ?>><?php esc_html_e( 'По кол-ву (сначала больше)', 'pf-filter' ); ?></option>
					<option value="count_asc" <?php selected( $value_sort, 'count_asc' ); ?>><?php esc_html_e( 'По кол-ву (сначала меньше)', 'pf-filter' ); ?></option>
				</select>
			</td>
			<td>
				<button type="button" class="button-link-delete pf-remove-row"><?php esc_html_e( 'Удалить', 'pf-filter' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Получить список доступных значений pf-template — либо просканировав разметку
	 * по указанному URL, либо (по умолчанию) статичный список всех типов шаблонов.
	 *
	 * @param string $scan_url URL страницы для сканирования (может быть пустым).
	 * @return array
	 */
	private function get_available_templates( $scan_url ) {
		if ( empty( $scan_url ) ) {
			return self::DEFAULT_TEMPLATES;
		}

		$response = wp_remote_get( $scan_url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			return self::DEFAULT_TEMPLATES;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return self::DEFAULT_TEMPLATES;
		}

		preg_match_all( '/pf-template=["\']([a-z-]+)["\']/i', $body, $matches );
		$found = array_unique( array_map( 'strtolower', $matches[1] ?? array() ) );

		return empty( $found ) ? self::DEFAULT_TEMPLATES : array_values( $found );
	}

	/**
	 * Карта field => совместимые шаблоны, для JS страницы настроек — чтобы
	 * при выборе поля список шаблонов сужался до применимых (например, range
	 * только для полей с исключительно числовыми значениями).
	 *
	 * @return array
	 */
	private function get_field_compatible_templates_map() {
		$fields = array_merge( $this->attributes->get_available_taxonomy_fields(), $this->get_extra_fields() );

		$map = array();
		foreach ( $fields as $f ) {
			$map[ $f['field'] ] = $this->attributes->get_compatible_templates( $f['field'] );
		}

		return $map;
	}

	/**
	 * Карта field => поля ACF термина этой таксономии, для JS страницы
	 * настроек — выпадашка «поле с цветом» у группы сужается под выбранное
	 * поле. Только для реальных таксономий — у кастомных/локальных атрибутов
	 * термов не существует.
	 *
	 * @return array
	 */
	private function get_acf_fields_map() {
		$map = array();
		foreach ( $this->attributes->get_available_taxonomy_fields() as $f ) {
			if ( taxonomy_exists( $f['field'] ) ) {
				$map[ $f['field'] ] = $this->attributes->get_acf_term_fields( $f['field'] );
			}
		}

		return $map;
	}
}
