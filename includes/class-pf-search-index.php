<?php
/**
 * Поисковый индекс: собственные таблицы (словарь термов, постинги,
 * документы), индексация отдельной записи, очередь и полная фоновая
 * переиндексация пачками.
 *
 * Индекс общий на все профили поиска (см. PF_Search_Config::get_index_spec()):
 * профиль при запросе только сужает выборку по типу записи и полям.
 * Позиции слов не хранятся (раздули бы индекс в 2-3 раза) — точная фраза
 * проверяется по нормализованному заголовку в таблице документов.
 *
 * Любая ошибка индексации — тихий пропуск записи, никогда не фатальная
 * ошибка: индексация идёт на хуках сохранения записей и не должна мешать
 * ни админке, ни сайту.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Index
 */
class PF_Search_Index {

	/**
	 * Версия схемы таблиц — при изменении CREATE TABLE ниже увеличить,
	 * тогда maybe_install() прогонит dbDelta заново.
	 */
	const DB_VERSION = '2';

	/**
	 * Версия формата содержимого индекса (что и как пишется в постинги) —
	 * при изменении увеличить: входит в отпечаток спецификации индекса,
	 * поэтому смена сама запустит полную переиндексацию.
	 */
	const INDEX_FORMAT = '2';

	const DB_VERSION_OPTION = 'pf_search_db_version';
	const STATE_OPTION      = 'pf_search_reindex_state';
	const QUEUE_OPTION      = 'pf_search_queue';
	const STATS_OPTION      = 'pf_search_stats';
	const SIGNATURE_OPTION  = 'pf_search_index_signature';
	const FIELD_IDS_OPTION  = 'pf_search_acf_field_ids';
	const TYPE_IDS_OPTION   = 'pf_search_type_ids';
	const LOCK_OPTION       = 'pf_search_batch_lock';

	const CRON_BATCH       = 'pf_search_reindex_batch';
	const CRON_MAINTENANCE = 'pf_search_maintenance';

	/**
	 * Числовые ID встроенных полей в постингах. ACF-поля получают ID от
	 * ACF_FIELD_BASE и выше (см. get_acf_field_id()).
	 */
	const FIELD_TITLE    = 1;
	const FIELD_CONTENT  = 2;
	const FIELD_EXCERPT  = 3;
	const FIELD_SKU      = 4;
	const FIELD_TERMS    = 5;
	const ACF_FIELD_BASE = 100;

	/**
	 * Записей за одну пачку переиндексации.
	 */
	const BATCH_SIZE = 40;

	/**
	 * Сколько символов текста записи индексируется максимум (очень длинные
	 * статьи дают мало пользы поиску и много строк в индексе).
	 */
	const MAX_CONTENT_CHARS = 30000;

	/**
	 * Таксономии, которые не несут смысла для поиска.
	 */
	const SKIP_TAXONOMIES = array( 'product_type', 'product_visibility', 'product_shipping_class', 'post_format' );

	/**
	 * Записи, изменённые за текущий запрос — индексируются одним проходом
	 * на shutdown (одна запись за запрос может затронуть несколько хуков).
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Кэш term => id за запрос.
	 *
	 * @var array
	 */
	private static $term_ids = array();

	/* ---------------------------------------------------------------------
	 * Схема
	 * ------------------------------------------------------------------ */

	/**
	 * Имена таблиц.
	 *
	 * @return array{terms:string,postings:string,docs:string}
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'terms'    => $wpdb->prefix . 'pf_search_terms',
			'postings' => $wpdb->prefix . 'pf_search_postings',
			'docs'     => $wpdb->prefix . 'pf_search_docs',
			'words'    => $wpdb->prefix . 'pf_search_words',
		);
	}

	/**
	 * Создать/обновить таблицы, если версия схемы изменилась.
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();
		// Термы сравниваются побайтово: они уже нормализованы, а регистро-
		// и диакритико-независимая сортировка склеила бы разные термы в
		// один уникальный ключ.
		$bin = 'utf8mb4' === $wpdb->charset ? 'utf8mb4_bin' : ( 'utf8' === $wpdb->charset ? 'utf8_bin' : '' );
		$bin = $bin ? " COLLATE {$bin}" : '';

		dbDelta(
			"CREATE TABLE {$t['terms']} (
id int(10) unsigned NOT NULL AUTO_INCREMENT,
term varchar(64){$bin} NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY term (term)
) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$t['postings']} (
term_id int(10) unsigned NOT NULL,
post_id bigint(20) unsigned NOT NULL,
field smallint(5) unsigned NOT NULL,
type_id smallint(5) unsigned NOT NULL DEFAULT 0,
tf smallint(5) unsigned NOT NULL,
flen smallint(5) unsigned NOT NULL,
PRIMARY KEY  (term_id,post_id,field),
KEY post_field (post_id,field,flen)
) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$t['docs']} (
post_id bigint(20) unsigned NOT NULL,
post_type varchar(20) NOT NULL,
title varchar(255) NOT NULL DEFAULT '',
sku varchar(191) NOT NULL DEFAULT '',
in_stock tinyint(1) unsigned NOT NULL DEFAULT 1,
popularity int(10) unsigned NOT NULL DEFAULT 0,
gen int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (post_id),
KEY post_type (post_type)
) {$collate};"
		);

		// Словарь исходных (не стеммированных) слов заголовков, таксономий и
		// ACF-полей — для исправления опечаток и подсказки «Возможно, вы
		// искали…» читаемыми словами (стемы для подсказки не годятся).
		dbDelta(
			"CREATE TABLE {$t['words']} (
word varchar(64){$bin} NOT NULL,
len tinyint(3) unsigned NOT NULL,
freq int(10) unsigned NOT NULL DEFAULT 1,
PRIMARY KEY  (word),
KEY len (len)
) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Удалить таблицы и служебные опции индекса (uninstall).
	 */
	public static function drop_all() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( array( self::DB_VERSION_OPTION, self::STATE_OPTION, self::QUEUE_OPTION, self::STATS_OPTION, self::SIGNATURE_OPTION, self::FIELD_IDS_OPTION, self::TYPE_IDS_OPTION, self::LOCK_OPTION ) as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Таблицы существуют (модуль включали хотя бы раз).
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	/* ---------------------------------------------------------------------
	 * Хуки инкрементальной индексации
	 * ------------------------------------------------------------------ */

	/**
	 * Подключить хуки (только когда модуль включён).
	 */
	public static function register_hooks() {
		// wp_after_insert_post — после сохранения meta и термов записи
		// (save_post срабатывает раньше, чем часть meta успевает записаться).
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_changed' ), 20 );
		// ACF в блочном редакторе сохраняет поля отдельным запросом.
		add_action( 'acf/save_post', array( __CLASS__, 'on_post_changed' ), 20 );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_changed' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post_changed' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_deleted' ) );

		// WooCommerce CRUD (REST, импорт, быстрое редактирование) и остатки.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_post_changed' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_post_changed' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_post_changed' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_variation_changed' ) );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'on_variation_changed' ) );

		// Переименование термина меняет текст у всех его записей — в очередь.
		add_action( 'edited_term', array( __CLASS__, 'on_term_edited' ), 10, 3 );

		add_action( 'shutdown', array( __CLASS__, 'flush_pending' ) );
		add_action( self::CRON_BATCH, array( __CLASS__, 'run_batch' ) );
		add_action( self::CRON_MAINTENANCE, array( __CLASS__, 'maintenance' ) );
	}

	/**
	 * Запись изменилась — переиндексировать в конце запроса.
	 *
	 * @param int|string $post_id ID записи (acf/save_post может передать строку вида 'options').
	 */
	public static function on_post_changed( $post_id ) {
		if ( ! is_numeric( $post_id ) || (int) $post_id <= 0 ) {
			return;
		}
		// Ревизии, пункты меню, заказы и прочие типы, по которым не ищет
		// ни один профиль, не трогаем вовсе (записи, выпавшие из индекса
		// из-за смены настроек профиля, убирает полная переиндексация,
		// которую такая смена запускает сама).
		$type = get_post_type( (int) $post_id );
		if ( $type && isset( PF_Search_Config::get_index_spec()[ $type ] ) ) {
			self::$pending[ (int) $post_id ] = true;
		}
	}

	/**
	 * Изменилась вариация — переиндексировать родительский товар.
	 *
	 * @param int $variation_id ID вариации.
	 */
	public static function on_variation_changed( $variation_id ) {
		$parent = wp_get_post_parent_id( (int) $variation_id );
		if ( $parent ) {
			self::$pending[ (int) $parent ] = true;
		}
	}

	/**
	 * Запись удалена навсегда.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function on_post_deleted( $post_id ) {
		unset( self::$pending[ (int) $post_id ] );
		self::remove_post( (int) $post_id );
	}

	/**
	 * Термин отредактирован — его записи в фоновую очередь (их может быть
	 * много, индексировать всё прямо в запросе сохранения термина нельзя).
	 *
	 * @param int    $term_id  ID термина.
	 * @param int    $tt_id    ID term_taxonomy.
	 * @param string $taxonomy Таксономия.
	 */
	public static function on_term_edited( $term_id, $tt_id, $taxonomy ) {
		if ( in_array( $taxonomy, self::SKIP_TAXONOMIES, true ) ) {
			return;
		}
		$ids = get_objects_in_term( (int) $term_id, $taxonomy );
		if ( is_array( $ids ) && $ids ) {
			self::enqueue( $ids );
		}
	}

	/**
	 * Проиндексировать накопленные за запрос записи.
	 */
	public static function flush_pending() {
		if ( ! self::$pending || ! self::is_installed() ) {
			return;
		}
		$ids            = array_keys( self::$pending );
		self::$pending = array();

		// Массовые операции (импорт сотен записей за запрос) — в фон.
		if ( count( $ids ) > 20 ) {
			self::enqueue( $ids );
			return;
		}

		foreach ( $ids as $id ) {
			self::index_post( $id );
		}
	}

	/**
	 * Добавить записи в фоновую очередь и запланировать её обработку.
	 *
	 * @param array $ids ID записей.
	 */
	public static function enqueue( array $ids ) {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();
		foreach ( $ids as $id ) {
			$queue[ (int) $id ] = true;
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		self::schedule_batch();
	}

	/**
	 * Запланировать следующую пачку фоновой обработки.
	 */
	private static function schedule_batch() {
		if ( ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time(), self::CRON_BATCH );
		}
	}

	/* ---------------------------------------------------------------------
	 * Индексация одной записи
	 * ------------------------------------------------------------------ */

	/**
	 * Проиндексировать запись (или убрать её из индекса, если она не должна
	 * там быть: не опубликована, тип не используется профилями, скрыта из
	 * поиска WooCommerce).
	 *
	 * @param int $post_id ID записи.
	 * @return bool true — запись в индексе.
	 */
	public static function index_post( $post_id ) {
		try {
			return self::index_post_unsafe( (int) $post_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * См. index_post().
	 *
	 * @param int $post_id ID записи.
	 * @return bool
	 */
	private static function index_post_unsafe( $post_id ) {
		$post = get_post( $post_id );
		$spec = PF_Search_Config::get_index_spec();

		if ( ! $post || 'publish' !== $post->post_status || ! isset( $spec[ $post->post_type ] ) || self::is_hidden_from_search( $post ) ) {
			self::remove_post( $post_id );
			return false;
		}

		$fields = self::collect_fields( $post, $spec[ $post->post_type ] );

		// Термы по полям: field_id => [term => tf], длина поля.
		$postings  = array();
		$all_terms = array();
		foreach ( $fields as $field_id => $text ) {
			$terms = PF_Search_Tokenizer::terms( $text );
			if ( self::FIELD_SKU === $field_id ) {
				foreach ( preg_split( '/\s*\|\s*/u', $text ) as $sku ) {
					$compact = PF_Search_Tokenizer::compact( $sku );
					if ( '' !== $compact && mb_strlen( $compact ) <= PF_Search_Tokenizer::MAX_TERM_LENGTH ) {
						$terms[] = $compact;
					}
				}
			}
			if ( ! $terms ) {
				continue;
			}
			$tf   = array_count_values( $terms );
			$flen = min( 65535, count( $terms ) );
			foreach ( $tf as $term => $count ) {
				$postings[] = array( (string) $term, $field_id, min( 65535, $count ), $flen );
				$all_terms[ (string) $term ] = true;
			}
		}

		$term_ids = self::get_term_ids( array_keys( $all_terms ) );

		global $wpdb;
		$t = self::tables();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['postings']} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$type_id = self::get_type_id( $post->post_type );
		$rows    = array();
		foreach ( $postings as $p ) {
			if ( ! isset( $term_ids[ $p[0] ] ) ) {
				continue;
			}
			$rows[] = $wpdb->prepare( '(%d,%d,%d,%d,%d,%d)', $term_ids[ $p[0] ], $post_id, $p[1], $type_id, $p[2], $p[3] );
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query( "INSERT IGNORE INTO {$t['postings']} (term_id,post_id,field,type_id,tf,flen) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		self::add_words( $fields );

		$state = self::get_state();
		$wpdb->replace(
			$t['docs'],
			array(
				'post_id'    => $post_id,
				'post_type'  => $post->post_type,
				'title'      => mb_substr( PF_Search_Tokenizer::normalize( $post->post_title ), 0, 255 ),
				'sku'        => mb_substr( self::compact_skus( $fields[ self::FIELD_SKU ] ?? '' ), 0, 191 ),
				'in_stock'   => self::is_in_stock( $post ) ? 1 : 0,
				'popularity' => self::get_popularity( $post ),
				'gen'        => (int) $state['gen'],
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		return true;
	}

	/**
	 * Пополнить словарь исправлений словами заголовка, таксономий и
	 * ACF-полей записи (только буквенные слова от 3 символов, без
	 * стоп-слов). Частота — сколько раз слово попадало в индексируемые
	 * записи; удалённые записи её не уменьшают (при полной переиндексации
	 * словарь строится заново) — она нужна только как подсказка при выборе
	 * между равноудалёнными исправлениями, а само исправление применяется,
	 * только если по нему реально что-то находится.
	 *
	 * @param array $fields field_id => текст.
	 */
	private static function add_words( array $fields ) {
		$words = array();
		foreach ( $fields as $field_id => $text ) {
			if ( self::FIELD_TITLE !== $field_id && self::FIELD_TERMS !== $field_id && $field_id < self::ACF_FIELD_BASE ) {
				continue;
			}
			foreach ( PF_Search_Tokenizer::words( $text ) as $word ) {
				$len = mb_strlen( $word );
				if ( $len < 3 || $len > PF_Search_Tokenizer::MAX_TERM_LENGTH || ! preg_match( '/^\p{L}+$/u', $word ) || in_array( $word, PF_Search_Tokenizer::STOP_WORDS, true ) ) {
					continue;
				}
				$words[ $word ] = $len;
			}
		}
		if ( ! $words ) {
			return;
		}

		global $wpdb;
		$t    = self::tables();
		$rows = array();
		foreach ( $words as $word => $len ) {
			$rows[] = $wpdb->prepare( '(%s,%d,1)', $word, min( 255, $len ) );
		}
		foreach ( array_chunk( $rows, 300 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$t['words']} (word,len,freq) VALUES " . implode( ',', $chunk ) . ' ON DUPLICATE KEY UPDATE freq = freq + 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Убрать запись из индекса.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function remove_post( $post_id ) {
		if ( ! self::is_installed() ) {
			return;
		}
		global $wpdb;
		$t = self::tables();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['postings']} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['docs']} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Тексты полей записи для индекса: field_id => текст.
	 *
	 * @param WP_Post $post       Запись.
	 * @param array   $acf_fields Имена ACF-полей, выбранных для этого типа в профилях.
	 * @return array
	 */
	private static function collect_fields( $post, array $acf_fields ) {
		$fields = array(
			self::FIELD_TITLE => $post->post_title,
		);

		if ( '' === $post->post_password ) {
			$content = strip_shortcodes( (string) $post->post_content );
			// Комментарии блочного редактора не нужны в тексте.
			$content = preg_replace( '/<!--.*?-->/s', ' ', $content );
			$content = wp_strip_all_tags( (string) $content );

			$fields[ self::FIELD_CONTENT ] = mb_substr( $content, 0, self::MAX_CONTENT_CHARS );
			$fields[ self::FIELD_EXCERPT ] = $post->post_excerpt;
		}

		$sku = self::collect_skus( $post );
		if ( '' !== $sku ) {
			$fields[ self::FIELD_SKU ] = $sku;
		}

		$terms = self::collect_term_names( $post );
		if ( '' !== $terms ) {
			$fields[ self::FIELD_TERMS ] = $terms;
		}

		foreach ( $acf_fields as $name ) {
			$text = self::collect_acf_value( $post->ID, $name );
			if ( '' !== $text ) {
				$fields[ self::get_acf_field_id( $name ) ] = $text;
			}
		}

		return $fields;
	}

	/**
	 * Артикулы товара и его вариаций через « | ».
	 *
	 * @param WP_Post $post Запись.
	 * @return string
	 */
	private static function collect_skus( $post ) {
		if ( 'product' !== $post->post_type ) {
			return '';
		}

		$skus = array( (string) get_post_meta( $post->ID, '_sku', true ) );

		$children = get_children(
			array(
				'post_parent' => $post->ID,
				'post_type'   => 'product_variation',
				'fields'      => 'ids',
				'numberposts' => 200,
			)
		);
		foreach ( (array) $children as $child_id ) {
			$skus[] = (string) get_post_meta( $child_id, '_sku', true );
		}

		return implode( ' | ', array_unique( array_filter( array_map( 'trim', $skus ) ) ) );
	}

	/**
	 * Компактные артикулы через пробел — для точного совпадения артикула в
	 * таблице документов.
	 *
	 * @param string $skus Артикулы через « | ».
	 * @return string
	 */
	private static function compact_skus( $skus ) {
		if ( '' === $skus ) {
			return '';
		}
		$out = array();
		foreach ( preg_split( '/\s*\|\s*/u', $skus ) as $sku ) {
			$compact = PF_Search_Tokenizer::compact( $sku );
			if ( '' !== $compact ) {
				$out[] = $compact;
			}
		}
		return implode( ' ', array_unique( $out ) );
	}

	/**
	 * Названия всех терминов записи (категории, метки, атрибуты, свои
	 * таксономии) плюс значения локальных атрибутов товара WooCommerce.
	 *
	 * @param WP_Post $post Запись.
	 * @return string
	 */
	private static function collect_term_names( $post ) {
		$names = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( in_array( $taxonomy->name, self::SKIP_TAXONOMIES, true ) || ( ! $taxonomy->public && ! $taxonomy->show_ui ) ) {
				continue;
			}
			$terms = get_the_terms( $post->ID, $taxonomy->name );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$names[] = $term->name;
				}
			}
		}

		if ( 'product' === $post->post_type ) {
			$attributes = get_post_meta( $post->ID, '_product_attributes', true );
			if ( is_array( $attributes ) ) {
				foreach ( $attributes as $attribute ) {
					if ( empty( $attribute['is_taxonomy'] ) && ! empty( $attribute['value'] ) ) {
						$names[] = str_replace( '|', ' ', (string) $attribute['value'] );
					}
				}
			}
		}

		return implode( ' ', $names );
	}

	/**
	 * Текст значения ACF-поля (или обычного meta, если ACF нет). Для полей
	 * выбора берутся подписи вариантов, для вложенных полей (повторитель,
	 * группа, гибкое содержание) — все строковые значения внутри;
	 * медиа/связи/служебные типы не индексируются.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $name    Имя поля.
	 * @return string
	 */
	private static function collect_acf_value( $post_id, $name ) {
		if ( ! function_exists( 'get_field_object' ) ) {
			return self::flatten_text( get_post_meta( $post_id, $name, true ), false );
		}

		$field = get_field_object( $name, $post_id, false, true );
		if ( ! is_array( $field ) || ! isset( $field['value'] ) ) {
			return '';
		}

		$type  = $field['type'] ?? '';
		$value = $field['value'];

		$skip = array( 'image', 'file', 'gallery', 'relationship', 'post_object', 'page_link', 'user', 'true_false', 'color_picker', 'date_picker', 'date_time_picker', 'time_picker', 'google_map', 'link', 'oembed', 'password' );
		if ( in_array( $type, $skip, true ) ) {
			return '';
		}

		if ( in_array( $type, array( 'select', 'checkbox', 'radio', 'button_group' ), true ) && ! empty( $field['choices'] ) ) {
			$labels = array();
			foreach ( (array) $value as $v ) {
				if ( is_scalar( $v ) ) {
					$labels[] = $field['choices'][ $v ] ?? $v;
				}
			}
			return implode( ' ', $labels );
		}

		if ( 'taxonomy' === $type ) {
			$labels = array();
			foreach ( (array) $value as $term_id ) {
				$term = is_numeric( $term_id ) ? get_term( (int) $term_id ) : null;
				if ( $term && ! is_wp_error( $term ) ) {
					$labels[] = $term->name;
				}
			}
			return implode( ' ', $labels );
		}

		$nested = in_array( $type, array( 'repeater', 'group', 'flexible_content' ), true );
		return self::flatten_text( $value, $nested );
	}

	/**
	 * Все строковые значения массива (рекурсивно) через пробел.
	 *
	 * @param mixed $value  Значение.
	 * @param bool  $nested Внутри вложенного поля: чистые числа там почти
	 *                      всегда ID картинок/записей, а не текст — пропускаем.
	 * @return string
	 */
	private static function flatten_text( $value, $nested ) {
		if ( is_scalar( $value ) ) {
			$value = (string) $value;
			if ( $nested && ctype_digit( $value ) ) {
				return '';
			}
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$parts = array();
		foreach ( $value as $item ) {
			$text = self::flatten_text( $item, true );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * Скрыт ли товар из поиска настройками видимости WooCommerce.
	 *
	 * @param WP_Post $post Запись.
	 * @return bool
	 */
	private static function is_hidden_from_search( $post ) {
		return 'product' === $post->post_type
			&& taxonomy_exists( 'product_visibility' )
			&& has_term( 'exclude-from-search', 'product_visibility', $post );
	}

	/**
	 * В наличии ли (для любых записей, кроме товаров, — всегда да).
	 *
	 * @param WP_Post $post Запись.
	 * @return bool
	 */
	private static function is_in_stock( $post ) {
		if ( 'product' !== $post->post_type ) {
			return true;
		}
		return 'outofstock' !== get_post_meta( $post->ID, '_stock_status', true );
	}

	/**
	 * Популярность: число продаж товара WooCommerce, для остального — 0.
	 *
	 * @param WP_Post $post Запись.
	 * @return int
	 */
	private static function get_popularity( $post ) {
		if ( 'product' !== $post->post_type ) {
			return 0;
		}
		return max( 0, (int) get_post_meta( $post->ID, 'total_sales', true ) );
	}

	/**
	 * Числовой ID ACF-поля в постингах (сопоставление имя => ID хранится в
	 * опции и только растёт — ID не переиспользуются).
	 *
	 * @param string $name Имя поля.
	 * @return int
	 */
	public static function get_acf_field_id( $name ) {
		$map = get_option( self::FIELD_IDS_OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		if ( isset( $map[ $name ] ) ) {
			return (int) $map[ $name ];
		}
		$next         = $map ? max( $map ) + 1 : self::ACF_FIELD_BASE;
		$map[ $name ] = min( 65535, $next );
		update_option( self::FIELD_IDS_OPTION, $map, false );
		return (int) $map[ $name ];
	}

	/**
	 * Числовой ID типа записи в постингах: фильтр по типу прямо в таблице
	 * постингов, без JOIN с документами (на частых словах JOIN в разы
	 * медленнее самой выборки). Сопоставление в опции, только растёт.
	 *
	 * @param string $post_type Тип записи.
	 * @param bool   $create    Завести ID, если его ещё нет.
	 * @return int 0 — нет такого (при $create = false).
	 */
	public static function get_type_id( $post_type, $create = true ) {
		$map = get_option( self::TYPE_IDS_OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		if ( isset( $map[ $post_type ] ) ) {
			return (int) $map[ $post_type ];
		}
		if ( ! $create ) {
			return 0;
		}
		$map[ $post_type ] = $map ? max( $map ) + 1 : 1;
		update_option( self::TYPE_IDS_OPTION, $map, false );
		return (int) $map[ $post_type ];
	}

	/**
	 * ID полей, уже заведённых для ACF (без создания новых) — для запроса.
	 *
	 * @param array $names Имена полей.
	 * @return array name => id
	 */
	public static function get_existing_acf_field_ids( array $names ) {
		$map = get_option( self::FIELD_IDS_OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		return array_intersect_key( $map, array_flip( $names ) );
	}

	/**
	 * ID термов словаря, недостающие — создать.
	 *
	 * @param array $terms Термы.
	 * @return array term => id
	 */
	private static function get_term_ids( array $terms ) {
		global $wpdb;
		$t   = self::tables();
		$out = array();

		$missing = array();
		foreach ( $terms as $term ) {
			if ( isset( self::$term_ids[ $term ] ) ) {
				$out[ $term ] = self::$term_ids[ $term ];
			} else {
				$missing[] = $term;
			}
		}
		if ( ! $missing ) {
			return $out;
		}

		$found = self::lookup_terms( $missing );

		$to_insert = array_values( array_diff( $missing, array_keys( $found ) ) );
		if ( $to_insert ) {
			foreach ( array_chunk( $to_insert, 300 ) as $chunk ) {
				$values = implode( ',', array_fill( 0, count( $chunk ), '(%s)' ) );
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['terms']} (term) VALUES {$values}", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$found += self::lookup_terms( $to_insert );
		}

		if ( count( self::$term_ids ) > 50000 ) {
			self::$term_ids = array();
		}
		foreach ( $found as $term => $id ) {
			self::$term_ids[ $term ] = $id;
			$out[ $term ]            = $id;
		}

		return $out;
	}

	/**
	 * Найти ID существующих термов.
	 *
	 * @param array $terms Термы.
	 * @return array term => id
	 */
	public static function lookup_terms( array $terms ) {
		global $wpdb;
		$t   = self::tables();
		$out = array();
		foreach ( array_chunk( array_values( $terms ), 300 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, term FROM {$t['terms']} WHERE term IN ({$in})", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$out[ (string) $row->term ] = (int) $row->id;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Полная переиндексация и фоновая очередь
	 * ------------------------------------------------------------------ */

	/**
	 * Состояние переиндексации.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status'   => 'idle',
				'gen'      => 1,
				'last_id'  => 0,
				'done'     => 0,
				'total'    => 0,
				'started'  => 0,
				'finished' => 0,
			)
		);
	}

	/**
	 * Сохранить состояние переиндексации.
	 *
	 * @param array $state Состояние.
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Начать полную переиндексацию (в фоне, пачками). Поиск во время неё
	 * продолжает работать по старым данным — записи обновляются на месте,
	 * а в конце удаляется всё, что не было переиндексировано (новое
	 * поколение gen).
	 */
	public static function start_full_reindex() {
		self::maybe_install();

		$spec  = PF_Search_Config::get_index_spec();
		$state = self::get_state();

		$state['status']   = 'running';
		$state['gen']      = (int) $state['gen'] + 1;
		$state['last_id']  = 0;
		$state['done']     = 0;
		$state['total']    = self::count_indexable( array_keys( $spec ) );
		$state['started']  = time();
		$state['finished'] = 0;
		self::save_state( $state );

		global $wpdb;
		$t = self::tables();
		$wpdb->query( "TRUNCATE TABLE {$t['words']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( self::SIGNATURE_OPTION, PF_Search_Config::get_index_signature(), false );
		self::schedule_batch();
	}

	/**
	 * Если спецификация индекса изменилась (сменились типы записей или
	 * ACF-поля в профилях) — запустить полную переиндексацию.
	 *
	 * @return bool Запущена ли переиндексация.
	 */
	public static function maybe_reindex_on_spec_change() {
		if ( get_option( self::SIGNATURE_OPTION ) === PF_Search_Config::get_index_signature() ) {
			return false;
		}
		self::start_full_reindex();
		return true;
	}

	/**
	 * Сколько записей подлежит индексации.
	 *
	 * @param array $types Типы записей.
	 * @return int
	 */
	private static function count_indexable( array $types ) {
		if ( ! $types ) {
			return 0;
		}
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in})", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Обработать одну пачку: сначала фоновая очередь, потом полная
	 * переиндексация. Вызывается из WP-Cron и AJAX-ом со страницы настроек
	 * (пока она открыта — так быстрее и не зависит от посещаемости сайта).
	 *
	 * @param int $time_budget Сколько секунд можно крутить пачки подряд
	 *                         (из cron — побольше, из AJAX — поменьше, чтобы
	 *                         прогресс в админке обновлялся часто).
	 * @return array Состояние после обработки.
	 */
	public static function run_batch( $time_budget = 15 ) {
		if ( ! self::is_installed() || ! self::acquire_lock() ) {
			return self::get_state();
		}

		$started = microtime( true );
		$more    = false;

		try {
			do {
				$queue = get_option( self::QUEUE_OPTION, array() );
				$queue = is_array( $queue ) ? $queue : array();

				if ( $queue ) {
					$ids = array_slice( array_keys( $queue ), 0, self::BATCH_SIZE );
					foreach ( $ids as $id ) {
						unset( $queue[ $id ] );
					}
					update_option( self::QUEUE_OPTION, $queue, false );
					foreach ( $ids as $id ) {
						self::index_post( (int) $id );
					}
				} else {
					self::run_reindex_batch();
				}

				$more = $queue || 'running' === self::get_state()['status'];
			} while ( $more && microtime( true ) - $started < (float) $time_budget );

			if ( $more ) {
				wp_schedule_single_event( time(), self::CRON_BATCH );
			}
		} finally {
			self::release_lock();
		}

		return self::get_state();
	}

	/**
	 * Одна пачка полной переиндексации.
	 */
	private static function run_reindex_batch() {
		$state = self::get_state();
		if ( 'running' !== $state['status'] ) {
			return;
		}

		$types = array_keys( PF_Search_Config::get_index_spec() );
		$ids   = array();

		if ( $types ) {
			global $wpdb;
			$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$args = array_merge( $types, array( (int) $state['last_id'], self::BATCH_SIZE ) );
			$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND ID > %d ORDER BY ID ASC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $ids ) {
			self::finish_reindex( $state );
			return;
		}

		foreach ( $ids as $id ) {
			self::index_post( (int) $id );
		}

		$state['last_id'] = (int) end( $ids );
		$state['done']    = min( (int) $state['total'], (int) $state['done'] + count( $ids ) );
		self::save_state( $state );
	}

	/**
	 * Завершить переиндексацию: удалить документы прошлых поколений,
	 * осиротевшие термы, пересчитать статистику.
	 *
	 * @param array $state Состояние.
	 */
	private static function finish_reindex( array $state ) {
		global $wpdb;
		$t   = self::tables();
		$gen = (int) $state['gen'];

		$wpdb->query( $wpdb->prepare( "DELETE p FROM {$t['postings']} p INNER JOIN {$t['docs']} d ON d.post_id = p.post_id WHERE d.gen < %d", $gen ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['docs']} WHERE gen < %d", $gen ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Постинги без документа (запись удалили, пока хуки не работали).
		$wpdb->query( "DELETE p FROM {$t['postings']} p LEFT JOIN {$t['docs']} d ON d.post_id = p.post_id WHERE d.post_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::purge_orphan_terms();

		$state['status']   = 'idle';
		$state['done']     = (int) $state['total'];
		$state['finished'] = time();
		self::save_state( $state );

		self::refresh_stats();
	}

	/**
	 * Удалить термы словаря, на которые не ссылается ни один постинг.
	 */
	private static function purge_orphan_terms() {
		global $wpdb;
		$t = self::tables();
		$wpdb->query( "DELETE t FROM {$t['terms']} t LEFT JOIN {$t['postings']} p ON p.term_id = t.id WHERE p.term_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$term_ids = array();
	}

	/**
	 * Плановое обслуживание (дважды в сутки): статистика и страховка от
	 * расхождения отпечатка спецификации.
	 */
	public static function maintenance() {
		if ( ! self::is_installed() ) {
			return;
		}
		self::refresh_stats();
		if ( 'running' !== self::get_state()['status'] ) {
			self::maybe_reindex_on_spec_change();
		}
	}

	/**
	 * Попытаться захватить блокировку пачки (cron и AJAX не должны
	 * обрабатывать одну и ту же пачку одновременно). add_option атомарна —
	 * уникальный ключ option_name.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK_OPTION );
		if ( $since && time() - $since > 120 ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, time(), '', 'no' );
		}
		return false;
	}

	/**
	 * Снять блокировку пачки.
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/* ---------------------------------------------------------------------
	 * Статистика для ранжирования
	 * ------------------------------------------------------------------ */

	/**
	 * Пересчитать статистику: число документов и средние длины полей по
	 * типам записей (нужны BM25F).
	 *
	 * @return array
	 */
	public static function refresh_stats() {
		global $wpdb;
		$t     = self::tables();
		$stats = array(
			'types'   => array(),
			'updated' => time(),
		);

		$counts = $wpdb->get_results( "SELECT post_type, COUNT(*) AS n FROM {$t['docs']} GROUP BY post_type" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $counts as $row ) {
			$stats['types'][ $row->post_type ] = array(
				'docs' => (int) $row->n,
				'avg'  => array(),
			);
		}

		$avgs = $wpdb->get_results( "SELECT d.post_type, x.field, AVG(x.flen) AS avg_len FROM (SELECT post_id, field, MAX(flen) AS flen FROM {$t['postings']} GROUP BY post_id, field) x INNER JOIN {$t['docs']} d ON d.post_id = x.post_id GROUP BY d.post_type, x.field" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $avgs as $row ) {
			if ( isset( $stats['types'][ $row->post_type ] ) ) {
				$stats['types'][ $row->post_type ]['avg'][ (int) $row->field ] = max( 1.0, (float) $row->avg_len );
			}
		}

		update_option( self::STATS_OPTION, $stats, false );
		return $stats;
	}

	/**
	 * Статистика (посчитать, если её ещё нет).
	 *
	 * @return array
	 */
	public static function get_stats() {
		$stats = get_option( self::STATS_OPTION );
		if ( ! is_array( $stats ) || empty( $stats['types'] ) ) {
			$stats = self::refresh_stats();
		}
		return $stats;
	}

	/**
	 * Сводка для админки: документов, термов, постингов.
	 *
	 * @return array
	 */
	public static function get_summary() {
		if ( ! self::is_installed() ) {
			return array(
				'docs'     => 0,
				'terms'    => 0,
				'postings' => 0,
				'by_type'  => array(),
			);
		}
		global $wpdb;
		$t       = self::tables();
		$by_type = array();
		foreach ( (array) $wpdb->get_results( "SELECT post_type, COUNT(*) AS n FROM {$t['docs']} GROUP BY post_type" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$by_type[ $row->post_type ] = (int) $row->n;
		}
		return array(
			'docs'     => array_sum( $by_type ),
			'terms'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['terms']}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'postings' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['postings']}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'by_type'  => $by_type,
		);
	}
}
