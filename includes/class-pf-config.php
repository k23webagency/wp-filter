<?php
/**
 * Чтение и запись настроек плагина — профили фильтра, хранящиеся в wp_options.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Config
 *
 * До этапа мультипрофильности все настройки жили в одной опции
 * OPTION_KEY ('pf_filter_settings'). Теперь настройки — список профилей в
 * PROFILES_OPTION_KEY, каждый профиль — та же структура, что раньше была
 * единственной. OPTION_KEY больше не пишется плагином, но и не удаляется —
 * это единственный источник данных для одноразовой миграции
 * migrate_to_profiles() при первом обращении к get_profiles() после
 * обновления с домультипрофильной версии.
 *
 * Весь остальной код плагина (PF_Attributes, PF_Query, PF_Plugin, ...)
 * продолжает читать настройки через get_settings()/get()/get_post_type() —
 * сигнатуры этих методов не изменились, они читают активный профиль
 * (get_active_profile_id()). Код, работающий в контексте конкретного
 * профиля (REST API, админка), обязан явно вызвать use_profile( $id ) до
 * первого обращения к настройкам за текущий запрос — иначе используется
 * первый профиль по порядку (полезно как раз для фронтового pre_get_posts
 * в PF_Plugin, у которого нет понятия "текущий профиль", и который должен
 * молча работать с единственным/первым профилем, пока страница не размечена
 * несколькими блоками фильтра, — см. Этап 5 ROADMAP.md).
 */
class PF_Config {

	/**
	 * Ключ опции в wp_options, использовавшийся до мультипрофильности.
	 * Читается только для одноразовой миграции, плагин больше в неё не пишет.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'pf_filter_settings';

	/**
	 * Ключ опции в wp_options со списком профилей: id => настройки профиля.
	 *
	 * @var string
	 */
	const PROFILES_OPTION_KEY = 'pf_filter_profiles';

	/**
	 * Допустимые значения стратегии пагинации.
	 *
	 * @var array
	 */
	const PAGINATION_STRATEGIES = array( 'load-more', 'pages', 'both', 'infinite' );

	/**
	 * Кэш списка профилей за запрос.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * ID активного профиля за запрос — null, пока use_profile() не вызван
	 * ни разу (тогда get_active_profile_id() резолвит его лениво).
	 *
	 * @var string|null
	 */
	private static $active_profile_id = null;

	/**
	 * Настройки профиля по умолчанию (используется и как база для
	 * get_settings()/wp_parse_args(), и как стартовые данные create_profile()).
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'name'                => __( 'Основной', 'pf-filter' ),
			'post_type'           => class_exists( 'WooCommerce' ) ? 'product' : 'post',
			'logic'               => 'and',
			'search_threshold'    => 7,
			'show_counts'         => true,
			'sync_url'            => true,
			'pagination_strategy' => 'pages',
			'posts_per_page'      => 12,
			'groups'              => array(),
			'sort_options'        => array(
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
	 * Получить все профили (id => настройки профиля), из кэша запроса либо
	 * из wp_options; при первом обращении после обновления с
	 * домультипрофильной версии — лениво мигрирует.
	 *
	 * @return array
	 */
	public static function get_profiles() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$profiles = get_option( self::PROFILES_OPTION_KEY, null );

		if ( ! is_array( $profiles ) || empty( $profiles ) ) {
			$profiles = self::migrate_to_profiles();
		}

		self::$cache = $profiles;

		return self::$cache;
	}

	/**
	 * Одноразовая миграция: если есть домультипрофильная опция
	 * OPTION_KEY — завести из неё единственный профиль 'default', иначе —
	 * профиль из чистых умолчаний. Старая опция не удаляется и не
	 * изменяется, только читается.
	 *
	 * @return array
	 */
	private static function migrate_to_profiles() {
		$legacy = get_option( self::OPTION_KEY, null );

		if ( is_array( $legacy ) ) {
			$profile         = wp_parse_args( $legacy, self::get_defaults() );
			$profile['name'] = ! empty( $profile['name'] ) ? $profile['name'] : __( 'Основной', 'pf-filter' );
			$profiles        = array( 'default' => $profile );
		} else {
			$profiles = array( 'default' => self::get_defaults() );
		}

		update_option( self::PROFILES_OPTION_KEY, $profiles );

		return $profiles;
	}

	/**
	 * Настройки одного профиля, смёрженные с умолчаниями. Несуществующий
	 * id тихо деградирует на первый существующий профиль (тот же принцип,
	 * что и у get_active_profile_id()) — на случай если сохранённая где-то
	 * ссылка на профиль (pf-profile в разметке, ?profile= в админке)
	 * устарела.
	 *
	 * @param string $id ID профиля.
	 * @return array
	 */
	public static function get_profile( $id ) {
		$profiles = self::get_profiles();

		if ( isset( $profiles[ $id ] ) ) {
			return wp_parse_args( $profiles[ $id ], self::get_defaults() );
		}

		$first = reset( $profiles );

		return $first ? wp_parse_args( $first, self::get_defaults() ) : self::get_defaults();
	}

	/**
	 * Задать активный профиль на текущий запрос. Обязателен явный вызов
	 * из REST API (по параметру profile запроса) и из админки (по admin_init,
	 * см. PF_Admin::resolve_active_profile()) до первого чтения настроек.
	 *
	 * @param string $id ID профиля.
	 */
	public static function use_profile( $id ) {
		self::$active_profile_id = $id;
	}

	/**
	 * ID активного профиля за запрос. Если use_profile() ещё не вызывался —
	 * лениво резолвится в первый профиль по порядку (тем самым
	 * PF_Plugin::pre_get_posts и другой фронтовый код, не знающий о
	 * профилях, продолжает молча работать с единственным/первым профилем).
	 *
	 * @return string
	 */
	public static function get_active_profile_id() {
		if ( null === self::$active_profile_id ) {
			$ids                      = array_keys( self::get_profiles() );
			self::$active_profile_id = $ids ? $ids[0] : 'default';
		}

		return self::$active_profile_id;
	}

	/**
	 * Разрешить запрошенный id профиля в реальный, по аналогии с
	 * авторезолвом pf-target: если запрошенный id существует — используем
	 * его; если профиль на сайте ровно один — используем его без вопросов;
	 * иначе берём первый по порядку, но сигнализируем неоднозначность
	 * (ambiguous => true), чтобы вызывающий код (REST API) мог сообщить об
	 * этом клиенту, а JS — сделать console.warn.
	 *
	 * @param string $requested Запрошенный id профиля (может быть пустым).
	 * @return array{id: string, ambiguous: bool}
	 */
	public static function resolve_profile_id( $requested ) {
		$profiles = self::get_profiles();

		if ( $requested && isset( $profiles[ $requested ] ) ) {
			return array(
				'id'        => $requested,
				'ambiguous' => false,
			);
		}

		$ids = array_keys( $profiles );

		if ( 1 === count( $ids ) ) {
			return array(
				'id'        => $ids[0],
				'ambiguous' => false,
			);
		}

		return array(
			'id'        => $ids ? $ids[0] : 'default',
			'ambiguous' => true,
		);
	}

	/**
	 * Получить все настройки активного профиля, смёрженные с умолчаниями.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return self::get_profile( self::get_active_profile_id() );
	}

	/**
	 * Получить одну настройку активного профиля по ключу.
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
	 * Тип записи, который фильтрует активный профиль.
	 *
	 * @return string
	 */
	public static function get_post_type() {
		$default = class_exists( 'WooCommerce' ) ? 'product' : 'post';
		return (string) self::get( 'post_type', $default );
	}

	/**
	 * Сохранить настройки одного профиля (полная перезапись со слиянием
	 * умолчаний), не трогая остальные профили.
	 *
	 * @param string $id   ID профиля.
	 * @param array  $data Новые данные настроек профиля.
	 * @return bool
	 */
	public static function save_profile( $id, array $data ) {
		$profiles         = self::get_profiles();
		$profiles[ $id ]  = wp_parse_args( $data, self::get_defaults() );
		$saved            = update_option( self::PROFILES_OPTION_KEY, $profiles );
		self::flush_cache();
		return $saved;
	}

	/**
	 * Переименовать id профиля (то самое значение, которое пишется в
	 * pf-profile="..." в разметке темы) — отдельно от имени (name), которое
	 * просто подпись в админке. Позиция профиля в списке (важна для
	 * авторезолва "первый по порядку", см. get_active_profile_id()/
	 * PF_Plugin::limit_main_query_posts_per_page()) сохраняется — id
	 * меняется на месте, весь список не пересобирается в порядке вставки.
	 *
	 * @param string $old_id Текущий id профиля.
	 * @param string $new_id Запрошенный новый id (санируется через sanitize_title()).
	 * @return bool true — переименовано (или новый id совпал со старым, no-op);
	 *              false — старый id не найден, новый id пуст после санации,
	 *              либо уже занят ДРУГИМ профилем.
	 */
	public static function rename_profile_id( $old_id, $new_id ) {
		$profiles = self::get_profiles();

		if ( ! isset( $profiles[ $old_id ] ) ) {
			return false;
		}

		$new_id = sanitize_title( $new_id );

		if ( '' === $new_id ) {
			return false;
		}

		if ( $new_id === $old_id ) {
			return true;
		}

		if ( isset( $profiles[ $new_id ] ) ) {
			return false;
		}

		$reordered = array();
		foreach ( $profiles as $id => $profile ) {
			if ( $id === $old_id ) {
				$reordered[ $new_id ] = $profile;
			} else {
				$reordered[ $id ] = $profile;
			}
		}

		update_option( self::PROFILES_OPTION_KEY, $reordered );
		self::flush_cache();

		return true;
	}

	/**
	 * Создать новый профиль с настройками по умолчанию.
	 *
	 * @param string $name      Имя профиля.
	 * @param string $post_type Тип записи профиля.
	 * @return string ID нового профиля.
	 */
	public static function create_profile( $name, $post_type = 'product' ) {
		$profiles              = self::get_profiles();
		$id                    = self::generate_profile_id( $name, $profiles );
		$profile               = self::get_defaults();
		$profile['name']       = $name;
		$profile['post_type']  = $post_type;

		// Дефолтные опции сортировки get_defaults() — WooCommerce-специфичные
		// (цена/популярность) и на не-товарном типе записи были бы тихо
		// отброшены при первом же сохранении (см. PF_Admin::sanitize_sort_options(),
		// PF_Attributes::get_sortable_field_options()) — для нового профиля
		// сразу подставляем нейтральные, применимые к любому типу записи.
		if ( 'product' !== $post_type ) {
			$profile['sort_options'] = array(
				array(
					'value' => 'menu_order',
					'label' => __( 'По умолчанию', 'pf-filter' ),
				),
				array(
					'value' => 'date-desc',
					'label' => __( 'Сначала новые', 'pf-filter' ),
				),
			);
		}

		$profiles[ $id ]       = $profile;

		update_option( self::PROFILES_OPTION_KEY, $profiles );
		self::flush_cache();

		return $id;
	}

	/**
	 * Дублировать профиль целиком (все настройки и группы), новому имени
	 * добавляется суффикс "(копия)".
	 *
	 * @param string $id ID профиля-источника.
	 * @return string|null ID нового профиля, либо null если исходный не найден.
	 */
	public static function duplicate_profile( $id ) {
		$profiles = self::get_profiles();

		if ( ! isset( $profiles[ $id ] ) ) {
			return null;
		}

		$source   = $profiles[ $id ];
		$new_name = sprintf(
			/* translators: %s: имя исходного профиля */
			__( '%s (копия)', 'pf-filter' ),
			$source['name'] ?? ''
		);
		$new_id   = self::generate_profile_id( $new_name, $profiles );

		$profiles[ $new_id ] = array_merge( $source, array( 'name' => $new_name ) );

		update_option( self::PROFILES_OPTION_KEY, $profiles );
		self::flush_cache();

		return $new_id;
	}

	/**
	 * Удалить профиль. Отказывает, если это последний оставшийся профиль —
	 * пустой список профилей ломает и админку, и фронтовый pre_get_posts.
	 *
	 * @param string $id ID профиля.
	 * @return bool
	 */
	public static function delete_profile( $id ) {
		$profiles = self::get_profiles();

		if ( count( $profiles ) <= 1 || ! isset( $profiles[ $id ] ) ) {
			return false;
		}

		unset( $profiles[ $id ] );

		update_option( self::PROFILES_OPTION_KEY, $profiles );
		self::flush_cache();

		return true;
	}

	/**
	 * Сгенерировать уникальный id профиля из его имени (slug + числовой
	 * суффикс при коллизии) — по аналогии с тем, как WordPress разруливает
	 * коллизии post_name.
	 *
	 * @param string $name     Имя профиля.
	 * @param array  $existing Существующие профили (id => ...) для проверки коллизий.
	 * @return string
	 */
	private static function generate_profile_id( $name, array $existing ) {
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'profile';
		}

		$id = $base;
		$i  = 2;
		while ( isset( $existing[ $id ] ) ) {
			$id = $base . '-' . $i;
			++$i;
		}

		return $id;
	}

	/**
	 * Сбросить внутренний кэш (используется после сохранения из другого
	 * контекста и при смене активного профиля).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
