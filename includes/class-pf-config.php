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
			'logic'              => 'and',
			'search_threshold'   => 7,
			'show_counts'        => true,
			'tree_depth'         => 4,
			'scan_url'           => '',
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
			'category_overrides' => array(),
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
	 * Получить переопределение групп для конкретной категории, если оно задано.
	 *
	 * @param string $category_slug Slug категории товаров.
	 * @return array|null Массив групп или null если переопределения нет.
	 */
	public static function get_category_override( $category_slug ) {
		if ( empty( $category_slug ) ) {
			return null;
		}

		$overrides = self::get( 'category_overrides', array() );

		if ( isset( $overrides[ $category_slug ]['groups'] ) && ! empty( $overrides[ $category_slug ]['groups'] ) ) {
			return $overrides[ $category_slug ]['groups'];
		}

		return null;
	}

	/**
	 * Сбросить внутренний кэш (используется после сохранения из другого контекста).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
