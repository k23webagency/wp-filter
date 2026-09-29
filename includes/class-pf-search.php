<?php
/**
 * Точка входа модуля поиска (атрибуты pfs-*). Модуль включается отдельно
 * в админке (Настройки → PF Search); выключенный — не регистрирует ни
 * одного хука индексации и REST-маршрута, фильтр работает как раньше.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search
 */
final class PF_Search {

	/**
	 * Подключить модуль (вызывается из PF_Plugin на plugins_loaded).
	 */
	public static function init() {
		if ( is_admin() ) {
			( new PF_Search_Admin() )->init();
		}

		if ( ! PF_Search_Config::is_enabled() ) {
			return;
		}

		PF_Search_Index::register_hooks();
		add_action( 'rest_api_init', array( 'PF_Search_REST', 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'switch_theme', array( 'PF_Search_Template', 'flush_files_cache' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'ensure_installed' ) );
		}
	}

	/**
	 * Фронтовый скрипт поиска. Подключается на всех страницах (блок поиска
	 * обычно в шапке) — небольшой, при отсутствии [pfs] на странице ничего
	 * не делает. Служебные классы is-hidden/pf-hidden — из pf-filter.css,
	 * который фильтр подключает на всём фронте.
	 */
	public static function enqueue_scripts() {
		wp_enqueue_script( 'pfs-search', PF_FILTER_URL . 'assets/js/pfs-search.js', array(), PF_FILTER_VERSION, true );
		wp_localize_script( 'pfs-search', 'pfsConfig', self::build_front_config() );
	}

	/**
	 * Конфигурация для фронта: всё, что нужно JS без отдельного запроса.
	 *
	 * @return array
	 */
	public static function build_front_config() {
		$profiles = array();
		$first    = '';

		foreach ( PF_Search_Config::get_profiles() as $id => $profile ) {
			if ( '' === $first ) {
				$first = (string) $id;
			}
			$types = array();
			foreach ( PF_Search_Config::get_profile_types( $profile ) as $type ) {
				$types[] = array(
					'slug'  => $type,
					'label' => PF_Search_Config::get_type_label( $profile, $type ),
				);
			}
			$profiles[ $id ] = array(
				'typeMode'    => $profile['type_mode'],
				'types'       => $types,
				'defaultType' => PF_Search_Config::resolve_type( $profile ),
				'limit'       => max( 1, (int) $profile['dropdown_limit'] ),
				'resultsUrl'  => $profile['results_page'] ? (string) get_permalink( (int) $profile['results_page'] ) : '',
			);
		}

		// Тип записей каждого профиля фильтра: поиск внутри [pf-profile]
		// ищет по типу этого блока фильтра.
		$filter_profiles = array();
		$first_filter    = '';
		foreach ( PF_Config::get_profiles() as $id => $profile ) {
			if ( '' === $first_filter ) {
				$first_filter = (string) $id;
			}
			$profile                  = wp_parse_args( $profile, PF_Config::get_defaults() );
			$filter_profiles[ $id ] = (string) $profile['post_type'];
		}

		return array(
			'restUrl'            => esc_url_raw( rest_url( 'pf/v1/search' ) ),
			'logUrl'             => PF_Search_Analytics::is_enabled() ? esc_url_raw( rest_url( 'pf/v1/search/log' ) ) : '',
			'homeUrl'            => esc_url_raw( home_url( '/' ) ),
			'minChars'           => PF_Search_Engine::MIN_QUERY_LENGTH,
			'debounce'           => 300,
			'profiles'           => $profiles,
			'firstProfile'       => $first,
			'filterProfiles'     => $filter_profiles,
			'firstFilterProfile' => $first_filter,
		);
	}

	/**
	 * Таблицы на месте и плановое обслуживание запланировано (после
	 * обновления плагина со сменой схемы, после деактивации/активации).
	 */
	public static function ensure_installed() {
		PF_Search_Index::maybe_install();
		if ( ! wp_next_scheduled( PF_Search_Index::CRON_MAINTENANCE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', PF_Search_Index::CRON_MAINTENANCE );
		}
		// После обновления плагина с новым форматом индекса (INDEX_FORMAT
		// входит в отпечаток) — переиндексация запускается сама.
		if ( 'running' !== PF_Search_Index::get_state()['status'] ) {
			PF_Search_Index::maybe_reindex_on_spec_change();
		}
	}

	/**
	 * Включить модуль: таблицы, профиль по умолчанию, полная индексация.
	 */
	public static function enable() {
		PF_Search_Index::maybe_install();
		PF_Search_Config::ensure_default_profile();
		PF_Search_Config::set_enabled( true );
		self::ensure_installed();
		PF_Search_Index::start_full_reindex();
	}

	/**
	 * Выключить модуль. Индекс остаётся в БД (включение обратно не
	 * требует ждать долгой первичной индексации — хватит догоняющей),
	 * удаляется только при удалении плагина.
	 */
	public static function disable() {
		PF_Search_Config::set_enabled( false );
		self::clear_cron();
	}

	/**
	 * Снять все расписания модуля.
	 */
	public static function clear_cron() {
		wp_clear_scheduled_hook( PF_Search_Index::CRON_BATCH );
		wp_clear_scheduled_hook( PF_Search_Index::CRON_MAINTENANCE );
	}
}
