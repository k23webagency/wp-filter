<?php
/**
 * Чтение и запись настроек плагина из wp_options.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Config
 */
class PF_Config {

	/**
	 * Ключ опции в wp_options.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'pf_filter_settings';

	/**
	 * Допустимые значения стратегии пагинации.
	 *
	 * @var array
	 */
	const PAGINATION_STRATEGIES = array( 'load-more', 'pages', 'both', 'infinite' );

	/**
	 * Кэш настроек за запрос.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Настройки по умолчанию.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'post_type'          => class_exists( 'WooCommerce' ) ? 'product' : 'post',
			'logic'              => 'and',
			'search_threshold'   => 7,
			'show_counts'        => true,
			'tree_depth'         => 4,
			'sync_url'           => true,
			'pagination_strategy' => 'pages',
			'posts_per_page'     => 12,
			'groups'             => array(),
			'sort_options'       => array(
				array(
					'value' => 'menu_order',
					'label' => 'По умолчанию',
				),
				array(
					'value' => 'popularity',
					'label' => 'По популярности',
				),
				array(
					'value' => 'price',
					'label' => 'Цена ↑',
				),
				array(
					'value' => 'price-desc',
					'label' => 'Цена ↓',
				),
			),
		);
	}

	/**
	 * Получить все настройки плагина, смёрженные с умолчаниями.
	 *
	 * @return array
	 */
	public static function get_settings() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = wp_parse_args( $stored, self::get_defaults() );

		return self::$cache;
	}

	/**
	 * Получить одну настройку по ключу.
	 *
	 * @param string $key     Ключ настройки.
	 * @param mixed  $default Значение по умолчанию если ключ не найден.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Тип записи, который фильтрует плагин — настройка в админке («Общие
	 * настройки» → «Тип записи»), сегодня одна глобальная на весь сайт
	 * (станет настройкой профиля на этапе мультипрофильности). По умолчанию
	 * — 'product', если активен WooCommerce, иначе 'post'.
	 *
	 * @return string
	 */
	public static function get_post_type() {
		$default = class_exists( 'WooCommerce' ) ? 'product' : 'post';
		return (string) self::get( 'post_type', $default );
	}

	/**
	 * Сохранить настройки (полная перезапись со слиянием умолчаний).
	 *
	 * @param array $data Новые данные настроек.
	 * @return bool
	 */
	public static function save_settings( array $data ) {
		$merged      = wp_parse_args( $data, self::get_defaults() );
		self::$cache = null;
		return update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * Сбросить внутренний кэш (используется после сохранения из другого контекста).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
