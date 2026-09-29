<?php
/**
 * Необязательная аналитика запросов модуля поиска (по умолчанию выключена).
 *
 * Хранится только агрегат «запрос → сколько раз искали, сколько нашлось в
 * последний раз» (таблица pf_search_log), без сырого лога каждого нажатия
 * клавиши. Логируются итоговые запросы: полная выдача (/products — Enter,
 * страница результатов, выдача в блоке фильтра) и живой поиск, на котором
 * посетитель остановился (окно результатов простояло без нового ввода,
 * или клик по карточке) — это решает фронт и шлёт один beacon.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Analytics
 */
class PF_Search_Analytics {

	/**
	 * Потолок числа строк агрегата (обслуживание удаляет самые редкие).
	 */
	const MAX_ROWS = 20000;

	/**
	 * Включена ли аналитика (и модуль).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( PF_Search_Config::SETTINGS_OPTION, array() );
		return ! empty( $settings['analytics'] ) && PF_Search_Config::is_enabled();
	}

	/**
	 * Включить/выключить аналитику.
	 *
	 * @param bool $enabled Новое состояние.
	 */
	public static function set_enabled( $enabled ) {
		$settings              = get_option( PF_Search_Config::SETTINGS_OPTION, array() );
		$settings              = is_array( $settings ) ? $settings : array();
		$settings['analytics'] = (bool) $enabled;
		update_option( PF_Search_Config::SETTINGS_OPTION, $settings );
	}

	/**
	 * Таблица агрегата.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pf_search_log';
	}

	/**
	 * Учесть итоговый запрос.
	 *
	 * @param string $query      Запрос как ввёл посетитель.
	 * @param string $profile_id ID профиля поиска.
	 * @param string $type       Тип записей.
	 * @param int    $total      Сколько найдено.
	 */
	public static function log( $query, $profile_id, $type, $total ) {
		if ( ! self::is_enabled() || ! PF_Search_Index::is_installed() ) {
			return;
		}

		$normalized = PF_Search_Tokenizer::normalize( $query );
		$len        = mb_strlen( $normalized );
		if ( $len < PF_Search_Engine::MIN_QUERY_LENGTH || $len > 150 ) {
			return;
		}

		global $wpdb;
		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (qhash, query, profile, post_type, hits, last_total, last_at) VALUES (%s, %s, %s, %s, 1, %d, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_total = VALUES(last_total), last_at = VALUES(last_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				md5( $normalized . '|' . $profile_id . '|' . $type ),
				$normalized,
				substr( (string) $profile_id, 0, 64 ),
				substr( (string) $type, 0, 20 ),
				max( 0, (int) $total ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Частые запросы.
	 *
	 * @param int  $limit     Сколько строк.
	 * @param bool $zero_only Только запросы без результатов.
	 * @return array
	 */
	public static function top( $limit = 50, $zero_only = false ) {
		if ( ! PF_Search_Index::is_installed() ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$where = $zero_only ? 'WHERE last_total = 0' : '';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT query, profile, post_type, hits, last_total, last_at FROM {$table} {$where} ORDER BY hits DESC, last_at DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Очистить статистику.
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Держать агрегат в пределах MAX_ROWS (плановое обслуживание): удалить
	 * самые редкие и давние запросы сверх потолка.
	 */
	public static function prune() {
		if ( ! PF_Search_Index::is_installed() ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count <= self::MAX_ROWS ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY hits ASC, last_at ASC LIMIT %d", $count - self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
