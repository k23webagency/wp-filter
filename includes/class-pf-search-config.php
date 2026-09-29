<?php
/**
 * Настройки модуля поиска: глобальный переключатель модуля и профили
 * поиска (независимые от профилей фильтра, хранятся в своей опции).
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Config
 */
class PF_Search_Config {

	/**
	 * Глобальные настройки модуля (enabled).
	 */
	const SETTINGS_OPTION = 'pf_search_settings';

	/**
	 * Профили поиска: id => настройки.
	 */
	const PROFILES_OPTION = 'pf_search_profiles';

	/**
	 * Режимы выбора типа записей профиля: 'fixed' — один тип задан в
	 * админке; 'visitor' — посетитель выбирает один из разрешённых типов
	 * кнопками [pfs-type].
	 */
	const TYPE_MODES = array( 'fixed', 'visitor' );

	/**
	 * Встроенные поля записи, по которым может идти поиск (ACF — отдельно).
	 */
	const BUILTIN_FIELDS = array( 'title', 'content', 'excerpt', 'sku', 'terms' );

	/**
	 * Порог лимита выпадашки, выше которого админка предупреждает о нагрузке.
	 */
	const DROPDOWN_LIMIT_WARN = 6;

	/**
	 * Типы записей, которые не предлагаются для поиска вообще.
	 */
	const EXCLUDED_POST_TYPES = array( 'attachment' );

	/**
	 * Кэш профилей за запрос.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Включён ли модуль поиска.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		return ! empty( $settings['enabled'] ) && self::environment_ok();
	}

	/**
	 * Есть ли всё, без чего модуль работать не может (mbstring). Без него
	 * модуль просто не включается, сайт и фильтр работают как раньше.
	 *
	 * @return bool
	 */
	public static function environment_ok() {
		return function_exists( 'mb_strtolower' ) && function_exists( 'mb_strlen' );
	}

	/**
	 * Включить/выключить модуль.
	 *
	 * @param bool $enabled Новое состояние.
	 */
	public static function set_enabled( $enabled ) {
		$settings            = get_option( self::SETTINGS_OPTION, array() );
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = (bool) $enabled;
		update_option( self::SETTINGS_OPTION, $settings );
	}

	/**
	 * Настройки профиля по умолчанию.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'name'           => __( 'Поиск', 'pf-filter' ),
			'type_mode'      => 'fixed',
			'post_type'      => class_exists( 'WooCommerce' ) ? 'product' : 'post',
			'allowed_types'  => array(),
			'type_labels'    => array(),
			'default_type'   => '',
			'fields'         => array(
				'title'   => true,
				'content' => true,
				'excerpt' => true,
				'sku'     => true,
				'terms'   => true,
			),
			'acf_fields'     => array(),
			'weights'        => self::get_default_weights(),
			'dropdown_limit' => 6,
			'results_page'   => 0,
			'group_variants' => array(),
		);
	}

	/**
	 * Веса полей по умолчанию (BM25F).
	 *
	 * @return array
	 */
	public static function get_default_weights() {
		return array(
			'title'   => 5.0,
			'sku'     => 4.0,
			'terms'   => 2.0,
			'excerpt' => 1.5,
			'acf'     => 1.5,
			'content' => 1.0,
		);
	}

	/**
	 * Все профили (id => настройки, смёрженные с умолчаниями).
	 *
	 * @return array
	 */
	public static function get_profiles() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$raw = get_option( self::PROFILES_OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$out = array();
		foreach ( $raw as $id => $profile ) {
			$out[ $id ] = self::with_defaults( is_array( $profile ) ? $profile : array() );
		}

		self::$cache = $out;
		return $out;
	}

	/**
	 * Профиль по id (null — нет такого).
	 *
	 * @param string $id ID профиля.
	 * @return array|null
	 */
	public static function get_profile( $id ) {
		$profiles = self::get_profiles();
		return $profiles[ $id ] ?? null;
	}

	/**
	 * Профиль по id, а если такого нет — первый по порядку (null — профилей нет).
	 *
	 * @param string $id ID профиля.
	 * @return array{id:string,profile:array}|null
	 */
	public static function resolve_profile( $id ) {
		$profiles = self::get_profiles();
		if ( isset( $profiles[ $id ] ) ) {
			return array(
				'id'      => $id,
				'profile' => $profiles[ $id ],
			);
		}
		foreach ( $profiles as $pid => $profile ) {
			return array(
				'id'      => (string) $pid,
				'profile' => $profile,
			);
		}
		return null;
	}

	/**
	 * Смёржить профиль с умолчаниями (вложенные массивы — тоже).
	 *
	 * @param array $profile Сохранённые настройки.
	 * @return array
	 */
	private static function with_defaults( array $profile ) {
		$defaults = self::get_defaults();
		$merged   = wp_parse_args( $profile, $defaults );

		$merged['fields']  = wp_parse_args( is_array( $merged['fields'] ) ? $merged['fields'] : array(), $defaults['fields'] );
		$merged['weights'] = wp_parse_args( is_array( $merged['weights'] ) ? $merged['weights'] : array(), $defaults['weights'] );

		foreach ( array( 'allowed_types', 'type_labels', 'acf_fields', 'group_variants' ) as $key ) {
			if ( ! is_array( $merged[ $key ] ) ) {
				$merged[ $key ] = array();
			}
		}

		return $merged;
	}

	/**
	 * Сохранить профиль.
	 *
	 * @param string $id   ID профиля.
	 * @param array  $data Уже санированные настройки.
	 */
	public static function save_profile( $id, array $data ) {
		$profiles        = get_option( self::PROFILES_OPTION, array() );
		$profiles        = is_array( $profiles ) ? $profiles : array();
		$profiles[ $id ] = $data;
		update_option( self::PROFILES_OPTION, $profiles );
		self::$cache = null;
	}

	/**
	 * Переименовать ID профиля с сохранением порядка. false — ID занят/не найден.
	 *
	 * @param string $old_id Старый ID.
	 * @param string $new_id Новый ID.
	 * @return bool
	 */
	public static function rename_profile( $old_id, $new_id ) {
		$profiles = get_option( self::PROFILES_OPTION, array() );
		if ( ! isset( $profiles[ $old_id ] ) || isset( $profiles[ $new_id ] ) || '' === $new_id ) {
			return false;
		}

		$reordered = array();
		foreach ( $profiles as $pid => $profile ) {
			$reordered[ $pid === $old_id ? $new_id : $pid ] = $profile;
		}
		update_option( self::PROFILES_OPTION, $reordered );
		self::$cache = null;
		return true;
	}

	/**
	 * Создать профиль, вернуть его ID.
	 *
	 * @param string $name Название.
	 * @param array  $base Настройки-основа (для дублирования), иначе умолчания.
	 * @return string
	 */
	public static function create_profile( $name, array $base = array() ) {
		$profiles = get_option( self::PROFILES_OPTION, array() );
		$profiles = is_array( $profiles ) ? $profiles : array();

		$base         = $base ? $base : self::get_defaults();
		$base['name'] = $name;

		$slug = sanitize_title( $name );
		$slug = '' === $slug ? 'search' : $slug;
		$id   = $slug;
		$n    = 2;
		while ( isset( $profiles[ $id ] ) ) {
			$id = $slug . '-' . $n;
			++$n;
		}

		$profiles[ $id ] = $base;
		update_option( self::PROFILES_OPTION, $profiles );
		self::$cache = null;

		return $id;
	}

	/**
	 * Удалить профиль. Последний оставшийся не удаляется.
	 *
	 * @param string $id ID профиля.
	 * @return bool
	 */
	public static function delete_profile( $id ) {
		$profiles = get_option( self::PROFILES_OPTION, array() );
		if ( ! isset( $profiles[ $id ] ) || count( $profiles ) < 2 ) {
			return false;
		}
		unset( $profiles[ $id ] );
		update_option( self::PROFILES_OPTION, $profiles );
		self::$cache = null;
		return true;
	}

	/**
	 * Завести профиль по умолчанию, если профилей ещё нет.
	 */
	public static function ensure_default_profile() {
		if ( ! self::get_profiles() ) {
			$profiles = array( 'search' => self::get_defaults() );
			update_option( self::PROFILES_OPTION, $profiles );
			self::$cache = null;
		}
	}

	/**
	 * Типы записей, которые можно выбрать для поиска: все публичные, кроме
	 * вложений. Ключ — слаг, значение — подпись.
	 *
	 * @return array
	 */
	public static function get_searchable_post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( in_array( $post_type->name, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}
			$out[ $post_type->name ] = $post_type->labels->name ?? $post_type->name;
		}
		return $out;
	}

	/**
	 * Типы записей, по которым может искать профиль (в порядке кнопок).
	 *
	 * @param array $profile Профиль.
	 * @return array
	 */
	public static function get_profile_types( array $profile ) {
		$searchable = self::get_searchable_post_types();

		if ( 'visitor' === $profile['type_mode'] ) {
			$types = array_values( array_filter( (array) $profile['allowed_types'], static function ( $type ) use ( $searchable ) {
				return isset( $searchable[ $type ] );
			} ) );
			if ( $types ) {
				return $types;
			}
		}

		return isset( $searchable[ $profile['post_type'] ] ) ? array( $profile['post_type'] ) : array();
	}

	/**
	 * Тип записей, по которому идёт конкретный поиск: запрошенный, если он
	 * разрешён профилем, иначе тип по умолчанию.
	 *
	 * @param array  $profile   Профиль.
	 * @param string $requested Запрошенный тип (может быть пустым).
	 * @return string Пустая строка — профилю не по чему искать.
	 */
	public static function resolve_type( array $profile, $requested = '' ) {
		$types = self::get_profile_types( $profile );
		if ( ! $types ) {
			return '';
		}
		if ( $requested && in_array( $requested, $types, true ) ) {
			return $requested;
		}
		if ( 'visitor' === $profile['type_mode'] && in_array( $profile['default_type'], $types, true ) ) {
			return $profile['default_type'];
		}
		return $types[0];
	}

	/**
	 * Подпись типа для кнопки [pfs-type]: своя из профиля или название
	 * типа записи в WordPress.
	 *
	 * @param array  $profile Профиль.
	 * @param string $type    Слаг типа.
	 * @return string
	 */
	public static function get_type_label( array $profile, $type ) {
		if ( ! empty( $profile['type_labels'][ $type ] ) ) {
			return (string) $profile['type_labels'][ $type ];
		}
		$searchable = self::get_searchable_post_types();
		return $searchable[ $type ] ?? $type;
	}

	/**
	 * Что должно быть в индексе, чтобы обслужить все профили: типы записей
	 * и ACF-поля по каждому типу. Индекс общий на все профили — профиль
	 * только сужает выборку при запросе.
	 *
	 * @return array type => [acf field names]
	 */
	public static function get_index_spec() {
		$spec = array();
		foreach ( self::get_profiles() as $profile ) {
			foreach ( self::get_profile_types( $profile ) as $type ) {
				if ( ! isset( $spec[ $type ] ) ) {
					$spec[ $type ] = array();
				}
				$acf = $profile['acf_fields'][ $type ] ?? array();
				foreach ( (array) $acf as $name ) {
					$spec[ $type ][ $name ] = true;
				}
			}
		}

		ksort( $spec );
		foreach ( $spec as $type => $names ) {
			$names = array_keys( $names );
			sort( $names );
			$spec[ $type ] = $names;
		}

		return $spec;
	}

	/**
	 * Отпечаток спецификации индекса — если после сохранения профиля он
	 * изменился, индекс нужно перестроить.
	 *
	 * @return string
	 */
	public static function get_index_signature() {
		return md5( wp_json_encode( self::get_index_spec() ) . '|' . PF_Search_Index::INDEX_FORMAT );
	}

	/**
	 * Сбросить кэш профилей за запрос.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
