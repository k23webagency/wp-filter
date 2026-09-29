<?php
/**
 * Страница настроек модуля поиска (Настройки → PF Search): включение
 * модуля, профили поиска, статус индекса, пробный поиск.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Admin
 */
class PF_Search_Admin {

	/**
	 * Slug страницы настроек.
	 */
	const PAGE_SLUG = 'pf-search-settings';

	/**
	 * Максимум лимита выпадашки, который можно сохранить.
	 */
	const MAX_DROPDOWN_LIMIT = 50;

	/**
	 * Регистрация хуков.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_pfs_toggle_module', array( $this, 'handle_toggle_module' ) );
		add_action( 'admin_post_pfs_save_profile', array( $this, 'handle_save_profile' ) );
		add_action( 'admin_post_pfs_create_profile', array( $this, 'handle_create_profile' ) );
		add_action( 'admin_post_pfs_duplicate_profile', array( $this, 'handle_duplicate_profile' ) );
		add_action( 'admin_post_pfs_delete_profile', array( $this, 'handle_delete_profile' ) );
		add_action( 'admin_post_pfs_reindex', array( $this, 'handle_reindex' ) );

		add_action( 'wp_ajax_pfs_run_batch', array( $this, 'ajax_run_batch' ) );
		add_action( 'wp_ajax_pfs_test_search', array( $this, 'ajax_test_search' ) );
	}

	/**
	 * Пункт меню Настройки → PF Search.
	 */
	public function register_page() {
		add_options_page(
			__( 'PF Search', 'pf-filter' ),
			__( 'PF Search', 'pf-filter' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * JS/CSS только на странице модуля.
	 *
	 * @param string $hook Текущий admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'pf-admin', PF_FILTER_URL . 'assets/css/pf-admin.css', array(), PF_FILTER_VERSION );
		wp_enqueue_script( 'pfs-admin', PF_FILTER_URL . 'assets/js/pfs-admin.js', array(), PF_FILTER_VERSION, true );

		wp_localize_script(
			'pfs-admin',
			'pfsAdminConfig',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'pfs_admin' ),
				'limitWarn' => PF_Search_Config::DROPDOWN_LIMIT_WARN,
				'state'     => PF_Search_Index::get_state(),
				'i18n'      => array(
					'running'   => __( 'Индексация: %1$d из %2$d', 'pf-filter' ),
					'done'      => __( 'Индексация завершена.', 'pf-filter' ),
					'searching' => __( 'Поиск…', 'pf-filter' ),
					'nothing'   => __( 'Ничего не найдено.', 'pf-filter' ),
					'tooShort'  => __( 'Минимум 3 символа.', 'pf-filter' ),
					'error'     => __( 'Ошибка запроса.', 'pf-filter' ),
					'partial'   => __( 'Все слова сразу не нашлись — показаны частичные совпадения.', 'pf-filter' ),
				),
			)
		);
	}

	/**
	 * ID активного профиля страницы (?profile=).
	 *
	 * @return string
	 */
	private function get_requested_profile_id() {
		$requested = isset( $_REQUEST['profile'] ) ? sanitize_key( wp_unslash( $_REQUEST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только выбор профиля для просмотра.
		$resolved  = PF_Search_Config::resolve_profile( $requested );
		return $resolved ? $resolved['id'] : '';
	}

	/**
	 * Отрисовать страницу.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled        = PF_Search_Config::is_enabled();
		$environment_ok = PF_Search_Config::environment_ok();
		$profiles       = PF_Search_Config::get_profiles();
		$profile_id     = $this->get_requested_profile_id();
		$profile        = $profile_id ? $profiles[ $profile_id ] : PF_Search_Config::get_defaults();
		$post_types     = PF_Search_Config::get_searchable_post_types();
		$acf_by_type    = $this->get_acf_fields_by_type( array_keys( $post_types ) );
		$state          = PF_Search_Index::get_state();
		$summary        = PF_Search_Index::get_summary();
		$has_wc         = class_exists( 'WooCommerce' );

		require PF_FILTER_PATH . 'includes/views/search-admin-page.php';
	}

	/**
	 * ACF-поля записей по типам (только типы, у которых поля есть).
	 *
	 * @param array $types Слаги типов.
	 * @return array type => [{name, label}]
	 */
	private function get_acf_fields_by_type( array $types ) {
		$attributes = new PF_Attributes();
		$out        = array();
		foreach ( $types as $type ) {
			$fields = $attributes->get_acf_post_fields( $type );
			if ( $fields ) {
				$out[ $type ] = $fields;
			}
		}
		return $out;
	}

	/**
	 * Включить/выключить модуль.
	 */
	public function handle_toggle_module() {
		$this->require_manage_options( 'pfs_toggle_module' );

		$enable = ! empty( $_POST['enable'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверено в require_manage_options().

		if ( $enable && PF_Search_Config::environment_ok() ) {
			PF_Search::enable();
			$this->redirect( '', array( 'enabled' => 1 ) );
		}

		PF_Search::disable();
		$this->redirect( '', array( 'disabled' => 1 ) );
	}

	/**
	 * Сохранить профиль.
	 */
	public function handle_save_profile() {
		$this->require_manage_options( 'pfs_save_profile' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- проверено в require_manage_options().
		$id = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		if ( ! PF_Search_Config::get_profile( $id ) ) {
			$this->redirect( $id, array( 'error' => 'not_found' ) );
		}

		$input = isset( $_POST['pfs'] ) && is_array( $_POST['pfs'] ) ? wp_unslash( $_POST['pfs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- санируется в sanitize_profile().

		$requested_id = isset( $_POST['profile_id'] ) ? sanitize_title( wp_unslash( $_POST['profile_id'] ) ) : '';
		// phpcs:enable

		$final_id  = $id;
		$collision = false;
		if ( $requested_id && $requested_id !== $id ) {
			if ( PF_Search_Config::rename_profile( $id, $requested_id ) ) {
				$final_id = $requested_id;
			} else {
				$collision = true;
			}
		}

		$current = PF_Search_Config::get_profile( $final_id );
		PF_Search_Config::save_profile( $final_id, $this->sanitize_profile( $input, $current ? $current : array() ) );
		PF_Search_Config::flush_cache();

		$args = $collision ? array( 'error' => 'id_taken' ) : array( 'updated' => 1 );
		if ( PF_Search_Config::is_enabled() && PF_Search_Index::maybe_reindex_on_spec_change() ) {
			$args['reindex'] = 1;
		}

		$this->redirect( $final_id, $args );
	}

	/**
	 * Создать профиль.
	 */
	public function handle_create_profile() {
		$this->require_manage_options( 'pfs_create_profile' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id   = PF_Search_Config::create_profile( '' !== $name ? $name : __( 'Новый поиск', 'pf-filter' ) );

		$this->redirect( $id, array( 'created' => 1 ) );
	}

	/**
	 * Дублировать профиль.
	 */
	public function handle_duplicate_profile() {
		$this->require_manage_options( 'pfs_duplicate_profile' );

		$id      = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$profile = PF_Search_Config::get_profile( $id );
		if ( ! $profile ) {
			$this->redirect( $id, array( 'error' => 'not_found' ) );
		}

		/* translators: %s: название профиля */
		$new_id = PF_Search_Config::create_profile( sprintf( __( '%s (копия)', 'pf-filter' ), $profile['name'] ), $profile );
		$this->redirect( $new_id, array( 'duplicated' => 1 ) );
	}

	/**
	 * Удалить профиль.
	 */
	public function handle_delete_profile() {
		$this->require_manage_options( 'pfs_delete_profile' );

		$id = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! PF_Search_Config::delete_profile( $id ) ) {
			$this->redirect( $id, array( 'error' => 'last_profile' ) );
		}

		if ( PF_Search_Config::is_enabled() ) {
			PF_Search_Index::maybe_reindex_on_spec_change();
		}
		$this->redirect( '', array( 'deleted' => 1 ) );
	}

	/**
	 * Кнопка «Переиндексировать».
	 */
	public function handle_reindex() {
		$this->require_manage_options( 'pfs_reindex' );

		$id = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( PF_Search_Config::is_enabled() ) {
			PF_Search_Index::start_full_reindex();
		}
		$this->redirect( $id, array( 'reindex' => 1 ) );
	}

	/**
	 * AJAX: обработать порцию фоновой индексации (пока страница открыта —
	 * так быстрее и не зависит от WP-Cron) и вернуть состояние.
	 */
	public function ajax_run_batch() {
		check_ajax_referer( 'pfs_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$state = PF_Search_Config::is_enabled() ? PF_Search_Index::run_batch( 5 ) : PF_Search_Index::get_state();
		wp_send_json_success(
			array(
				'state'   => $state,
				'summary' => PF_Search_Index::get_summary(),
			)
		);
	}

	/**
	 * AJAX: пробный поиск по сохранённому профилю.
	 */
	public function ajax_test_search() {
		check_ajax_referer( 'pfs_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$profile_id = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		$query      = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$type       = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		$profile = PF_Search_Config::get_profile( $profile_id );
		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => __( 'Профиль не найден.', 'pf-filter' ) ) );
		}

		$type   = PF_Search_Config::resolve_type( $profile, $type );
		$result = PF_Search_Engine::search(
			$profile,
			$query,
			$type,
			array(
				'per_page' => 20,
				'debug'    => true,
			)
		);

		$field_labels = $this->get_field_labels( $profile, $type );
		$items        = array();
		foreach ( $result['ids'] as $id ) {
			$debug  = $result['debug'][ $id ] ?? array();
			$fields = array();
			foreach ( array_unique( (array) ( $debug['fields'] ?? array() ) ) as $field_id ) {
				$fields[] = $field_labels[ $field_id ] ?? (string) $field_id;
			}
			$items[] = array(
				'id'     => $id,
				'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'    => get_permalink( $id ),
				'score'  => round( (float) ( $debug['score'] ?? 0 ), 3 ),
				'fields' => $fields,
			);
		}

		wp_send_json_success(
			array(
				'items'   => $items,
				'total'   => $result['total'],
				'took_ms' => $result['took_ms'],
				'mode'    => $result['mode'],
				'type'    => $type,
			)
		);
	}

	/**
	 * Подписи полей для отладочной таблицы пробного поиска.
	 *
	 * @param array  $profile Профиль.
	 * @param string $type    Тип записей.
	 * @return array field_id => подпись
	 */
	private function get_field_labels( array $profile, $type ) {
		$labels = array(
			PF_Search_Index::FIELD_TITLE   => __( 'заголовок', 'pf-filter' ),
			PF_Search_Index::FIELD_CONTENT => __( 'текст', 'pf-filter' ),
			PF_Search_Index::FIELD_EXCERPT => __( 'отрывок', 'pf-filter' ),
			PF_Search_Index::FIELD_SKU     => __( 'артикул', 'pf-filter' ),
			PF_Search_Index::FIELD_TERMS   => __( 'рубрики/атрибуты', 'pf-filter' ),
		);
		$names = (array) ( $profile['acf_fields'][ $type ] ?? array() );
		foreach ( PF_Search_Index::get_existing_acf_field_ids( $names ) as $name => $field_id ) {
			$labels[ (int) $field_id ] = 'ACF: ' . $name;
		}
		return $labels;
	}

	/**
	 * Санировать настройки профиля из формы.
	 *
	 * @param array $input   Сырые данные формы.
	 * @param array $current Текущие настройки (для полей, которых нет в форме).
	 * @return array
	 */
	public function sanitize_profile( array $input, array $current ) {
		$defaults   = PF_Search_Config::get_defaults();
		$searchable = PF_Search_Config::get_searchable_post_types();
		$clean      = $defaults;

		$clean['name'] = isset( $input['name'] ) && '' !== trim( (string) $input['name'] )
			? sanitize_text_field( $input['name'] )
			: ( $current['name'] ?? $defaults['name'] );

		$clean['type_mode'] = in_array( $input['type_mode'] ?? '', PF_Search_Config::TYPE_MODES, true ) ? $input['type_mode'] : 'fixed';

		$post_type          = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		$clean['post_type'] = isset( $searchable[ $post_type ] ) ? $post_type : $defaults['post_type'];

		// Разрешённые посетителю типы: [slug => {enabled, label, order}].
		$rows = array();
		foreach ( (array) ( $input['types'] ?? array() ) as $slug => $row ) {
			$slug = sanitize_key( (string) $slug );
			if ( ! isset( $searchable[ $slug ] ) || ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' !== $label ) {
				$clean['type_labels'][ $slug ] = $label;
			}
			if ( ! empty( $row['enabled'] ) ) {
				$rows[ $slug ] = (int) ( $row['order'] ?? 0 );
			}
		}
		asort( $rows, SORT_NUMERIC );
		$clean['allowed_types'] = array_keys( $rows );

		$default_type          = sanitize_key( (string) ( $input['default_type'] ?? '' ) );
		$clean['default_type'] = in_array( $default_type, $clean['allowed_types'], true )
			? $default_type
			: ( $clean['allowed_types'][0] ?? '' );

		foreach ( PF_Search_Config::BUILTIN_FIELDS as $field ) {
			$clean['fields'][ $field ] = ! empty( $input['fields'][ $field ] );
		}

		// ACF-поля: только реально существующие у типа записи.
		$attributes          = new PF_Attributes();
		$clean['acf_fields'] = array();
		foreach ( (array) ( $input['acf_fields'] ?? array() ) as $type => $names ) {
			$type = sanitize_key( (string) $type );
			if ( ! isset( $searchable[ $type ] ) || ! is_array( $names ) ) {
				continue;
			}
			$valid = wp_list_pluck( $attributes->get_acf_post_fields( $type ), 'name' );
			$names = array_values( array_intersect( array_map( 'sanitize_text_field', $names ), $valid ) );
			if ( $names ) {
				$clean['acf_fields'][ $type ] = $names;
			}
		}

		foreach ( PF_Search_Config::get_default_weights() as $key => $default ) {
			$value                    = isset( $input['weights'][ $key ] ) ? (float) str_replace( ',', '.', (string) $input['weights'][ $key ] ) : $default;
			$clean['weights'][ $key ] = max( 0.0, min( 20.0, $value ) );
		}

		$limit                   = isset( $input['dropdown_limit'] ) ? absint( $input['dropdown_limit'] ) : $defaults['dropdown_limit'];
		$clean['dropdown_limit'] = max( 1, min( self::MAX_DROPDOWN_LIMIT, $limit ) );

		$page                  = isset( $input['results_page'] ) ? absint( $input['results_page'] ) : 0;
		$clean['results_page'] = ( $page && 'page' === get_post_type( $page ) ) ? $page : 0;

		// Настраивается на следующем этапе (вёрстка выпадашки) — не теряем.
		$clean['group_variants'] = is_array( $current['group_variants'] ?? null ) ? $current['group_variants'] : array();

		return $clean;
	}

	/**
	 * nonce действия + право manage_options.
	 *
	 * @param string $nonce_action Действие nonce.
	 */
	private function require_manage_options( $nonce_action ) {
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'pf-filter' ) );
		}
	}

	/**
	 * Редирект на страницу настроек и выход.
	 *
	 * @param string $profile_id ID профиля (пусто — первый).
	 * @param array  $args       Доп. параметры (флаги уведомлений).
	 */
	private function redirect( $profile_id, array $args = array() ) {
		$base = array( 'page' => self::PAGE_SLUG );
		if ( $profile_id ) {
			$base['profile'] = $profile_id;
		}
		wp_safe_redirect( add_query_arg( array_merge( $base, $args ), admin_url( 'options-general.php' ) ) );
		exit;
	}
}
