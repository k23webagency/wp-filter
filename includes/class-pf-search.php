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

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'ensure_installed' ) );
		}
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
