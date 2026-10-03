<?php
/**
 * Построение WP_Query из санированных параметров фильтра.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Query
 */
class PF_Query {

	/**
	 * Сканер атрибутов — нужен для разрешения кастомных (не таксономических) полей.
	 *
	 * @var PF_Attributes
	 */
	private $attributes;

	/**
	 * Ограничение выборки результатами поиска модуля PF Search: ID в
	 * порядке релевантности, либо null — поиска нет. Действует на ВСЕ
	 * запросы этого построителя за REST-вызов — и на список, и на
	 * facet-счётчики (PF_Renderer::matching_post_ids() строит свою выборку
	 * тем же build()), поэтому фасеты считаются по найденному.
	 *
	 * @var int[]|null
	 */
	private $search_ids = null;

	/**
	 * Конструктор.
	 *
	 * @param PF_Attributes|null $attributes Опционально — для переиспользования уже созданного экземпляра.
	 */
	public function __construct( ?PF_Attributes $attributes = null ) {
		$this->attributes = $attributes ?: new PF_Attributes();
	}

	/**
	 * Задать (или снять — null) ограничение выборки результатами поиска.
	 *
	 * @param int[]|null $ids ID в порядке релевантности.
	 */
	public function set_search_restriction( $ids ) {
		$this->search_ids = null === $ids ? null : array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Отпечаток текущего ограничения поиска — для ключей кэша, построенных
	 * поверх build() (см. PF_Renderer::matching_post_ids()).
	 *
	 * @return string
	 */
	public function get_search_restriction_key() {
		return null === $this->search_ids ? '' : md5( implode( ',', $this->search_ids ) );
	}

	/**
	 * Построить WP_Query по параметрам фильтра.
	 *
	 * Кастомные (не таксономические, "локальные") атрибуты товаров не
	 * поддерживаются через tax_query — WooCommerce хранит их одной сериализованной
	 * строкой в _product_attributes. Поэтому подходящие ID товаров ищутся отдельно
	 * (PF_Attributes::get_product_ids_for_custom_attribute_values()) и подставляются
	 * через post__in, комбинируясь с остальными условиями по той же логике and/or.
	 *
	 * @param array  $filters  Ассоциативный массив field => значения.
	 * @param string $logic    'and' | 'or' — логика между группами.
	 * @param string $orderby  Поле сортировки.
	 * @param string $order    'ASC' | 'DESC'.
	 * @param int    $paged    Номер страницы (с 1).
	 * @param int    $per_page Количество товаров на странице.
	 * @param bool   $ids_only Только ID подходящих записей, без сортировки
	 *                         и без загрузки объектов/метаданных/термов —
	 *                         для facet-счётчиков (PF_Renderer::matching_post_ids()).
	 *                         На каталогах в тысячи записей полная загрузка
	 *                         всех WP_Post занимала секунды на каждый ответ.
	 * @return WP_Query
	 */
	public function build( array $filters, $logic, $orderby, $order, $paged, $per_page, $ids_only = false ) {
		// -1 — конвенция WordPress "без ограничения" (используется, например,
		// count_meta_range_bounds() в PF_Renderer, чтобы получить ВСЕ подходящие
		// товары для подсчёта реального min/max числового поля). absint(-1)
		// отбрасывает знак и даёт 1 — без явного исключения запрос тихо
		// ограничивался одним товаром, и границы диапазона схлопывались в
		// значение этого одного товара.
		$per_page = (int) $per_page;
		$args     = array(
			'post_type'           => PF_Config::get_post_type(),
			'post_status'         => 'publish',
			'paged'               => max( 1, absint( $paged ) ),
			'posts_per_page'      => -1 === $per_page ? -1 : max( 1, absint( $per_page ) ),
			'ignore_sticky_posts' => true,
		);

		$tax_clauses    = array();
		$meta_clauses   = array();
		$custom_filters = array();

		foreach ( $filters as $field => $value ) {
			// Числовой диапазон (шаблон range — цена, вес и т.п.) отличается по
			// форме значения ({min,max}), а не по имени поля — раньше здесь был
			// спецкейс под буквальное имя 'price', теперь диапазоном может быть
			// любое поле (см. PF_Attributes::resolve_range_meta_key()).
			if ( is_array( $value ) && ( array_key_exists( 'min', $value ) || array_key_exists( 'max', $value ) ) ) {
				$clause = $this->build_meta_range_query( PF_Attributes::resolve_range_meta_key( $field ), $value );
				if ( $clause ) {
					$meta_clauses[] = $clause;
				}
				continue;
			}

			// Наличие товара (WooCommerce _stock_status) — фиксированный набор
			// значений, а не таксономия/custom_-атрибут, но по форме ({значения})
			// собирается в meta_query точно так же, как диапазон выше.
			if ( 'stock_status' === $field && is_array( $value ) ) {
				$meta_clauses[] = $this->build_stock_status_clause( $value );
				continue;
			}

			// Любая реальная таксономия обрабатывается одинаково, независимо от
			// её имени — раньше здесь были спецкейсы под product_cat/product_tag/pa_*.
			if ( taxonomy_exists( $field ) ) {
				$tax_clauses[] = $this->build_tax_clause( $field, $value );
				continue;
			}

			if ( 0 === strpos( $field, 'custom_' ) && is_array( $value ) ) {
				$custom_filters[ $field ] = array_map( 'sanitize_title', $value );
			}
		}

		$logic_upper = ( 'or' === strtolower( (string) $logic ) ) ? 'OR' : 'AND';

		if ( empty( $custom_filters ) ) {
			$this->apply_tax_and_meta( $args, $tax_clauses, $meta_clauses, $logic_upper );
		} elseif ( 'OR' === $logic_upper ) {
			// OR между группами: результат — объединение ID, подходящих ХОТЯ БЫ
			// под одно условие (обычный tax_query с relation=OR не умеет "ИЛИ" с
			// произвольным post__in, поэтому объединяем ID вручную).
			$union = array();
			foreach ( $tax_clauses as $clause ) {
				$union = array_merge( $union, $this->get_ids_matching( array( 'tax_query' => array( $clause ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}
			foreach ( $meta_clauses as $clause ) {
				$union = array_merge( $union, $this->get_ids_matching( array( 'meta_query' => array( $clause ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}
			foreach ( $custom_filters as $field => $slugs ) {
				$union = array_merge( $union, $this->match_custom_filter( $field, $slugs ) );
			}
			$args['post__in'] = empty( $union ) ? array( 0 ) : array_values( array_unique( $union ) );
		} else {
			// AND между группами: tax_query/meta_query уже отфильтровывают через
			// обычный SQL, кастомные атрибуты дополнительно пересекаются через post__in.
			$this->apply_tax_and_meta( $args, $tax_clauses, $meta_clauses, $logic_upper );

			$post_in = null;
			foreach ( $custom_filters as $field => $slugs ) {
				$ids     = $this->match_custom_filter( $field, $slugs );
				$post_in = ( null === $post_in ) ? $ids : array_intersect( $post_in, $ids );
			}
			$args['post__in'] = empty( $post_in ) ? array( 0 ) : array_values( $post_in );
		}

		// Поиск всегда сужает выборку (AND с фильтрами при любой логике между
		// группами) — порядок ID сохраняется для сортировки по релевантности.
		if ( null !== $this->search_ids ) {
			$restricted = $this->search_ids;
			if ( isset( $args['post__in'] ) ) {
				$allowed    = array_flip( array_map( 'intval', (array) $args['post__in'] ) );
				$restricted = array_values(
					array_filter(
						$restricted,
						static function ( $id ) use ( $allowed ) {
							return isset( $allowed[ $id ] );
						}
					)
				);
			}
			$args['post__in'] = $restricted ? $restricted : array( 0 );
		}

		if ( $ids_only ) {
			$args['fields']                 = 'ids';
			$args['orderby']                = 'none';
			$args['no_found_rows']          = true;
			$args['update_post_meta_cache'] = false;
			$args['update_post_term_cache'] = false;
			$args['cache_results']          = false;
			return new WP_Query( $args );
		}

		$this->apply_orderby( $args, $orderby, $order );

		// Сортировка по названию термина таксономии (apply_taxonomy_orderby())
		// и по размеру скидки (apply_discount_orderby()) не поддерживаются
		// WP_Query нативно — нужен JOIN, добавляемый через posts_clauses
		// ТОЛЬКО на время этого конкретного запроса (фильтр — глобальный
		// хук, задел бы вообще все запросы сайта, если не снять сразу
		// после). Оба обработчика сами проверяют orderby именно этого
		// запроса, поэтому даже случайный побочный запрос в этом окне
		// (например, прогрев кэша метаданных) их не заденет.
		add_filter( 'posts_clauses', array( $this, 'filter_taxonomy_orderby_clauses' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'filter_discount_orderby_clauses' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'filter_lookup_orderby_clauses' ), 10, 2 );
		// Регистрируется ПОСЛЕДНИМ (см. filter_out_of_stock_last_clauses()) —
		// должен видеть уже полностью собранный $clauses['orderby'] от двух
		// фильтров выше и просто добавить свой ключ сортировки первым, не
		// отменяя остальные.
		add_filter( 'posts_clauses', array( $this, 'filter_out_of_stock_last_clauses' ), 10, 2 );
		$query = new WP_Query( $args );
		remove_filter( 'posts_clauses', array( $this, 'filter_taxonomy_orderby_clauses' ), 10 );
		remove_filter( 'posts_clauses', array( $this, 'filter_discount_orderby_clauses' ), 10 );
		remove_filter( 'posts_clauses', array( $this, 'filter_lookup_orderby_clauses' ), 10 );
		remove_filter( 'posts_clauses', array( $this, 'filter_out_of_stock_last_clauses' ), 10 );

		return $query;
	}

	/**
	 * Применить накопленные tax_query/meta_query к аргументам запроса.
	 *
	 * @param array  $args         Аргументы WP_Query (по ссылке).
	 * @param array  $tax_clauses  Ветки tax_query.
	 * @param array  $meta_clauses Ветки meta_query (числовые диапазоны).
	 * @param string $logic_upper  'AND' | 'OR'.
	 */
	private function apply_tax_and_meta( array &$args, array $tax_clauses, array $meta_clauses, $logic_upper ) {
		if ( ! empty( $tax_clauses ) ) {
			if ( count( $tax_clauses ) > 1 ) {
				$tax_clauses['relation'] = $logic_upper;
			}
			$args['tax_query'] = $tax_clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- фасетная фильтрация, ожидаемо.
		}
		if ( ! empty( $meta_clauses ) ) {
			if ( count( $meta_clauses ) > 1 ) {
				$meta_clauses['relation'] = $logic_upper;
			}
			$args['meta_query'] = $meta_clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- фильтр по числовому диапазону.
		}
	}

	/**
	 * ID товаров, подходящих под кастомный фильтр (значения уже санированы как slugs).
	 *
	 * @param string $field Field-идентификатор (custom_...).
	 * @param array  $slugs Запрошенные значения.
	 * @return int[]
	 */
	private function match_custom_filter( $field, array $slugs ) {
		$raw_name = $this->attributes->resolve_custom_attribute_name( $field );
		if ( null === $raw_name ) {
			return array();
		}
		return $this->attributes->get_product_ids_for_custom_attribute_values( $raw_name, $slugs );
	}

	/**
	 * Быстрый вспомогательный запрос: ID товаров, подходящих под один набор условий.
	 * Используется только для ручного построения OR между разнородными условиями.
	 *
	 * @param array $extra_args Дополнительные аргументы WP_Query (tax_query/meta_query).
	 * @return int[]
	 */
	private function get_ids_matching( array $extra_args ) {
		$query = new WP_Query(
			array_merge(
				array(
					'post_type'           => PF_Config::get_post_type(),
					'post_status'         => 'publish',
					'posts_per_page'      => -1,
					'fields'              => 'ids',
					'ignore_sticky_posts' => true,
				),
				$extra_args
			)
		);
		return $query->posts;
	}

	/**
	 * Собрать одну ветку tax_query для указанной таксономии.
	 *
	 * @param string $taxonomy Слаг таксономии.
	 * @param array  $values   Значения (slugs термов).
	 * @return array
	 */
	private function build_tax_clause( $taxonomy, $values ) {
		$values = array_map( 'sanitize_text_field', (array) $values );

		return array(
			'taxonomy' => $taxonomy,
			'field'    => 'slug',
			'terms'    => $values,
			'operator' => 'IN',
		);
	}

	/**
	 * Собрать meta_query для числового диапазона.
	 *
	 * @param string $meta_key Ключ postmeta (см. PF_Attributes::resolve_range_meta_key()).
	 * @param array  $value    Массив с ключами min/max.
	 * @return array|null
	 */
	private function build_meta_range_query( $meta_key, $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$min = isset( $value['min'] ) ? floatval( $value['min'] ) : null;
		$max = isset( $value['max'] ) ? floatval( $value['max'] ) : null;

		if ( null === $min && null === $max ) {
			return null;
		}

		if ( null !== $min && null !== $max ) {
			return array(
				'key'     => $meta_key,
				'value'   => array( $min, $max ),
				'type'    => 'NUMERIC',
				'compare' => 'BETWEEN',
			);
		}

		if ( null !== $min ) {
			return array(
				'key'     => $meta_key,
				'value'   => $min,
				'type'    => 'NUMERIC',
				'compare' => '>=',
			);
		}

		return array(
			'key'     => $meta_key,
			'value'   => $max,
			'type'    => 'NUMERIC',
			'compare' => '<=',
		);
	}

	/**
	 * Собрать meta_query для наличия товара (WooCommerce _stock_status).
	 *
	 * @param array $values Запрошенные значения (instock/outofstock/onbackorder).
	 * @return array
	 */
	private function build_stock_status_clause( array $values ) {
		return array(
			'key'     => '_stock_status',
			'value'   => array_map( 'sanitize_key', $values ),
			'compare' => 'IN',
		);
	}

	/**
	 * Применить orderby/order к аргументам запроса.
	 *
	 * Значения не сверяются с фиксированным whitelist-константой (раньше
	 * была ORDERBY_WHITELIST) — они формируются динамически под настроенный
	 * тип записи профиля (см. PF_Attributes::get_sortable_field_options()),
	 * поэтому здесь вместо этого структурная валидация: `meta_<имя>` только
	 * если такое ACF-поле реально существует у настроенного типа записи
	 * (иначе можно было бы отсортировать по ЛЮБОМУ чужому postmeta —
	 * небольшая, но реальная утечка через порядок сортировки), `attr_<таксономия>`
	 * только если такая таксономия реально существует, `price`/`popularity`/
	 * `rating` — только для настоящих товаров (WooCommerce). Всё
	 * не прошедшее проверку — тихий откат на menu_order, как и раньше при
	 * недопустимом значении.
	 *
	 * @param array  $args    Аргументы WP_Query (по ссылке).
	 * @param string $orderby Запрошенное поле сортировки.
	 * @param string $order   Запрошенное направление.
	 */
	private function apply_orderby( array &$args, $orderby, $order ) {
		$orderby = (string) $orderby;

		// По релевантности — порядок, в котором движок поиска вернул ID
		// (post__in уже выставлен в build()). Без поиска — как по умолчанию.
		if ( 'relevance' === $orderby ) {
			if ( null !== $this->search_ids ) {
				$args['orderby'] = 'post__in';
				return;
			}
			$orderby = 'menu_order';
		}

		// JS уже разделяет "-desc"-суффикс на orderby+order отдельно (см.
		// PFForm.prototype.applyOrderby()) до отправки запроса — эта проверка
		// чисто оборонительная, на случай прямого вызова REST API с
		// orderby="...-desc" целиком.
		if ( preg_match( '/^(.+)-desc$/', $orderby, $matches ) ) {
			$orderby = $matches[1];
			$order   = 'DESC';
		}

		$order = ( 'DESC' === strtoupper( (string) $order ) ) ? 'DESC' : 'ASC';

		if ( in_array( $orderby, array( 'price', 'popularity', 'rating' ), true ) && 'product' !== PF_Config::get_post_type() ) {
			$orderby = 'menu_order';
		}

		if ( 0 === strpos( $orderby, 'meta_' ) ) {
			$meta_key = substr( $orderby, 5 );
			if ( '' !== $meta_key && $this->is_sortable_meta_field( $meta_key ) ) {
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = $meta_key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ключ проверен против реальных ACF-полей настроенного типа записи выше.
				$args['order']    = $order;
				return;
			}
			$orderby = 'menu_order';
		}

		if ( 0 === strpos( $orderby, 'attr_' ) ) {
			$taxonomy = substr( $orderby, 5 );
			if ( '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
				$this->apply_taxonomy_orderby( $args, $taxonomy, $order );
				return;
			}
			$orderby = 'menu_order';
		}

		if ( 'discount' === $orderby ) {
			if ( 'product' === PF_Config::get_post_type() ) {
				$this->apply_discount_orderby( $args, $order );
				return;
			}
			$orderby = 'menu_order';
		}

		// Цена, популярность, рейтинг — через индексированную таблицу
		// WooCommerce wc_product_meta_lookup (как сортирует сам WooCommerce),
		// а не через postmeta: у вариативных товаров _price — несколько строк
		// на товар, и сортировка тысяч товаров по CAST(meta_value) занимала
		// секунды. Нет таблицы — прежний путь через meta_value_num ниже.
		$lookup_columns = array(
			'price'      => 'DESC' === $order ? 'max_price' : 'min_price',
			'popularity' => 'total_sales',
			'rating'     => 'average_rating',
		);
		if ( isset( $lookup_columns[ $orderby ] ) && 'product' === PF_Config::get_post_type() && self::has_lookup_table() ) {
			$args['orderby']           = 'pf_lookup';
			$args['order']             = $order;
			$args['pf_lookup_column']  = $lookup_columns[ $orderby ];
			return;
		}

		switch ( $orderby ) {
			case 'price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- сортировка по цене, стандартно для WooCommerce.
				$args['order']    = $order;
				break;

			case 'popularity':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['order']    = $order;
				break;

			case 'rating':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['order']    = $order;
				break;

			case 'date':
				$args['orderby'] = 'date';
				$args['order']   = $order;
				break;

			case 'menu_order':
			default:
				$args['orderby'] = 'menu_order title';
				$args['order']   = $order;
				break;
		}
	}

	/**
	 * Реально ли настроенный тип записи (PF_Config::get_post_type()) имеет
	 * ACF-поле с таким именем — защита от сортировки по произвольному чужому
	 * postmeta-ключу (см. apply_orderby()).
	 *
	 * @param string $meta_key Имя ACF-поля.
	 * @return bool
	 */
	private function is_sortable_meta_field( $meta_key ) {
		foreach ( $this->attributes->get_acf_post_fields( PF_Config::get_post_type() ) as $field ) {
			if ( $field['name'] === $meta_key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Сортировка по названию термина таксономии («атрибут» в терминах
	 * плагина) — WP_Query нативно такого не умеет, значение orderby
	 * подставляется маркером, а сам JOIN/ORDER BY добавляется через
	 * posts_clauses (см. filter_taxonomy_orderby_clauses(), подключается на
	 * время запроса в build()).
	 *
	 * @param array  $args     Аргументы WP_Query (по ссылке).
	 * @param string $taxonomy Слаг таксономии.
	 * @param string $order    'ASC' | 'DESC'.
	 */
	private function apply_taxonomy_orderby( array &$args, $taxonomy, $order ) {
		$args['orderby']             = 'pf_taxonomy_name';
		$args['order']               = $order;
		$args['pf_orderby_taxonomy'] = $taxonomy;
	}

	/**
	 * posts_clauses-фильтр, добавляющий JOIN на wp_terms для сортировки по
	 * apply_taxonomy_orderby(). Многозначная таксономия у одной записи даёт
	 * несколько строк JOIN — GROUP BY + MIN(term.name) сводит к одной строке
	 * на запись и берёт первый по алфавиту термин как ключ сортировки.
	 * Записи без термина в этой таксономии (LEFT JOIN → NULL) сортируются
	 * последними независимо от направления — `MIN(...) IS NULL` первым
	 * ключом сортировки гарантирует это для ASC и DESC одинаково.
	 *
	 * Подключается ТОЛЬКО на время одного конкретного запроса (см. build()) —
	 * сама проверяет, что $query->get('orderby') относится именно к этому
	 * механизму, поэтому даже если случайно останется подключённым дольше,
	 * на чужие запросы не повлияет.
	 *
	 * @param array    $clauses Части SQL-запроса (join/groupby/orderby/...).
	 * @param WP_Query $query   Текущий запрос.
	 * @return array
	 */
	public function filter_taxonomy_orderby_clauses( $clauses, $query ) {
		if ( 'pf_taxonomy_name' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}

		$taxonomy = $query->get( 'pf_orderby_taxonomy' );
		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return $clauses;
		}

		global $wpdb;

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->term_relationships} pf_sort_tr ON pf_sort_tr.object_id = {$wpdb->posts}.ID
			  LEFT JOIN {$wpdb->term_taxonomy} pf_sort_tt ON pf_sort_tt.term_taxonomy_id = pf_sort_tr.term_taxonomy_id AND pf_sort_tt.taxonomy = %s
			  LEFT JOIN {$wpdb->terms} pf_sort_t ON pf_sort_t.term_id = pf_sort_tt.term_id",
			$taxonomy
		);

		if ( false === strpos( $clauses['groupby'], "{$wpdb->posts}.ID" ) ) {
			$clauses['groupby'] = "{$wpdb->posts}.ID" . ( $clauses['groupby'] ? ', ' . $clauses['groupby'] : '' );
		}

		$direction         = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$clauses['orderby'] = "MIN(pf_sort_t.name) IS NULL, MIN(pf_sort_t.name) {$direction}"
			. ( $clauses['orderby'] ? ', ' . $clauses['orderby'] : '' );

		return $clauses;
	}

	/**
	 * Сортировка товаров по размеру скидки (разница _regular_price минус
	 * _price — фактическая цена, действующая сейчас: равна _regular_price,
	 * если товар не по акции, тогда разница честно 0). Тоже не умеет
	 * WP_Query нативно — маркер + posts_clauses, тот же приём, что и
	 * apply_taxonomy_orderby()/filter_taxonomy_orderby_clauses().
	 *
	 * Для вариативных товаров использует meta самого родительского товара,
	 * не вариаций — то же ограничение, что и у обычной сортировки по цене
	 * (`price`) в этом же классе, вариации отдельно не учитываются.
	 *
	 * @param array  $args  Аргументы WP_Query (по ссылке).
	 * @param string $order 'ASC' | 'DESC'.
	 */
	/**
	 * Есть ли таблица WooCommerce wc_product_meta_lookup (WooCommerce 3.6+).
	 * Проверяется один раз за запрос.
	 *
	 * @return bool
	 */
	public static function has_lookup_table() {
		static $has = null;
		if ( null === $has ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wc_product_meta_lookup';
			$has   = class_exists( 'WooCommerce' )
				&& $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $has;
	}

	/**
	 * posts_clauses-фильтр для сортировки по колонке wc_product_meta_lookup
	 * (цена/популярность/рейтинг, см. apply_orderby()). Таблица — одна
	 * строка на товар, поэтому JOIN не размножает строки; товар без строки
	 * (NULL) уходит в конец при любом направлении. Вторым ключом — ID, как
	 * у самого WooCommerce, чтобы порядок между страницами был стабильным.
	 *
	 * @param array    $clauses Части SQL-запроса (join/orderby/...).
	 * @param WP_Query $query   Текущий запрос.
	 * @return array
	 */
	public function filter_lookup_orderby_clauses( $clauses, $query ) {
		if ( 'pf_lookup' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}
		$column = (string) $query->get( 'pf_lookup_column' );
		if ( ! in_array( $column, array( 'min_price', 'max_price', 'total_sales', 'average_rating' ), true ) ) {
			return $clauses;
		}

		global $wpdb;

		$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup pf_lookup ON pf_lookup.product_id = {$wpdb->posts}.ID";

		$direction = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$expr      = ! empty( $clauses['groupby'] ) ? "MIN( pf_lookup.{$column} )" : "pf_lookup.{$column}";

		$clauses['orderby'] = "{$expr} IS NULL, {$expr} {$direction}, {$wpdb->posts}.ID {$direction}";

		return $clauses;
	}

	private function apply_discount_orderby( array &$args, $order ) {
		$args['orderby'] = 'pf_discount';
		$args['order']   = $order;
	}

	/**
	 * posts_clauses-фильтр для apply_discount_orderby() — JOIN на postmeta
	 * дважды (_regular_price и _price), сортировка по их разнице.
	 * NULLIF(...,'') + CAST(...AS DECIMAL) — на случай пустого/отсутствующего
	 * значения (тогда разница NULL, товар уходит в конец списка независимо
	 * от направления, тем же приёмом IS NULL первым ключом, что и у
	 * сортировки по таксономии).
	 *
	 * @param array    $clauses Части SQL-запроса (join/orderby/...).
	 * @param WP_Query $query   Текущий запрос.
	 * @return array
	 */
	public function filter_discount_orderby_clauses( $clauses, $query ) {
		if ( 'pf_discount' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}

		global $wpdb;

		$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} pf_sort_regular ON pf_sort_regular.post_id = {$wpdb->posts}.ID AND pf_sort_regular.meta_key = '_regular_price'"
			. " LEFT JOIN {$wpdb->postmeta} pf_sort_price ON pf_sort_price.post_id = {$wpdb->posts}.ID AND pf_sort_price.meta_key = '_price'";

		$discount = "( CAST( NULLIF( pf_sort_regular.meta_value, '' ) AS DECIMAL(10,2) ) - CAST( NULLIF( pf_sort_price.meta_value, '' ) AS DECIMAL(10,2) ) )";
		$direction = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';

		$clauses['orderby'] = "{$discount} IS NULL, {$discount} {$direction}"
			. ( $clauses['orderby'] ? ', ' . $clauses['orderby'] : '' );

		return $clauses;
	}

	/**
	 * Товары не в наличии (WooCommerce _stock_status = outofstock) — всегда в
	 * конец списка, независимо от выбранной сортировки (по цене, дате,
	 * популярности, названию атрибута и т.д.). Настройка профиля
	 * out_of_stock_last (чекбокс в админке, включён по умолчанию — в том
	 * числе для профилей, сохранённых до появления настройки, через
	 * wp_parse_args() с get_defaults()).
	 *
	 * Работает добавлением ключа сортировки ПЕРЕД уже собранным orderby, а не
	 * заменой — регистрируется последним из posts_clauses-фильтров build()
	 * (см. её комментарий), поэтому не отменяет apply_taxonomy_orderby()/
	 * apply_discount_orderby() выше, только предшествует им. Только для
	 * настоящих товаров (post_type=product) — у любого другого типа записи
	 * такого meta-поля нет вообще.
	 *
	 * @param array    $clauses Части SQL-запроса (join/orderby/...).
	 * @param WP_Query $query   Текущий запрос.
	 * @return array
	 */
	public function filter_out_of_stock_last_clauses( $clauses, $query ) {
		if ( 'product' !== PF_Config::get_post_type() || ! PF_Config::get( 'out_of_stock_last', true ) ) {
			return $clauses;
		}

		global $wpdb;

		// Наличие — из wc_product_meta_lookup (индексированная, одна строка
		// на товар), если таблица есть; иначе из postmeta _stock_status.
		if ( self::has_lookup_table() ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup pf_sort_stock_l ON pf_sort_stock_l.product_id = {$wpdb->posts}.ID";
			$stock_col        = 'pf_sort_stock_l.stock_status';
		} else {
			$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} pf_sort_stock ON pf_sort_stock.post_id = {$wpdb->posts}.ID AND pf_sort_stock.meta_key = '_stock_status'";
			$stock_col        = 'pf_sort_stock.meta_value';
		}

		// Если этот же запрос уже сгруппирован по wp_posts.ID (сортировка по
		// названию термина таксономии — см. filter_taxonomy_orderby_clauses(),
		// она регистрируется раньше и сама добавляет эту GROUP BY из-за
		// потенциально многозначного JOIN на термины) — колонку из другой,
		// негруппированной таблицы нужно завернуть в агрегатную функцию,
		// иначе в режиме ONLY_FULL_GROUP_BY MySQL выдаст ошибку. Без GROUP BY
		// заворачивать нельзя — тогда MIN() схлопнул бы весь результат в одну
		// строку. _stock_status у товара однозначен (не многозначная meta),
		// поэтому MIN() по группе из одного значения просто возвращает его же.
		$expr = ! empty( $clauses['groupby'] )
			? "MIN( {$stock_col} = 'outofstock' )"
			: "( {$stock_col} = 'outofstock' )";

		$clauses['orderby'] = "{$expr} ASC"
			. ( $clauses['orderby'] ? ', ' . $clauses['orderby'] : '' );

		return $clauses;
	}
}
