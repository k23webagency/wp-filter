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
	 * Разрешённые значения orderby.
	 *
	 * @var array
	 */
	const ORDERBY_WHITELIST = array( 'menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc' );

	/**
	 * Сканер атрибутов — нужен для разрешения кастомных (не таксономических) полей.
	 *
	 * @var PF_Attributes
	 */
	private $attributes;

	/**
	 * Конструктор.
	 *
	 * @param PF_Attributes|null $attributes Опционально — для переиспользования уже созданного экземпляра.
	 */
	public function __construct( PF_Attributes $attributes = null ) {
		$this->attributes = $attributes ?: new PF_Attributes();
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
	 * @return WP_Query
	 */
	public function build( array $filters, $logic, $orderby, $order, $paged, $per_page ) {
		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'paged'               => max( 1, absint( $paged ) ),
			'posts_per_page'      => max( 1, absint( $per_page ) ),
			'ignore_sticky_posts' => true,
		);

		$tax_clauses    = array();
		$price_meta     = null;
		$custom_filters = array();

		foreach ( $filters as $field => $value ) {
			if ( 'price' === $field ) {
				$price_meta = $this->build_price_meta_query( $value );
				continue;
			}

			if ( 'product_cat' === $field || 'product_tag' === $field ) {
				$tax_clauses[] = $this->build_tax_clause( $field, $value );
				continue;
			}

			if ( 0 === strpos( $field, 'pa_' ) && taxonomy_exists( $field ) ) {
				$tax_clauses[] = $this->build_tax_clause( $field, $value );
				continue;
			}

			if ( 0 === strpos( $field, 'custom_' ) && is_array( $value ) ) {
				$custom_filters[ $field ] = array_map( 'sanitize_title', $value );
			}
		}

		$logic_upper = ( 'or' === strtolower( (string) $logic ) ) ? 'OR' : 'AND';

		if ( empty( $custom_filters ) ) {
			$this->apply_tax_and_price( $args, $tax_clauses, $price_meta, $logic_upper );
		} elseif ( 'OR' === $logic_upper ) {
			// OR между группами: результат — объединение ID, подходящих ХОТЯ БЫ
			// под одно условие (обычный tax_query с relation=OR не умеет "ИЛИ" с
			// произвольным post__in, поэтому объединяем ID вручную).
			$union = array();
			foreach ( $tax_clauses as $clause ) {
				$union = array_merge( $union, $this->get_ids_matching( array( 'tax_query' => array( $clause ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}
			if ( $price_meta ) {
				$union = array_merge( $union, $this->get_ids_matching( array( 'meta_query' => array( $price_meta ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}
			foreach ( $custom_filters as $field => $slugs ) {
				$union = array_merge( $union, $this->match_custom_filter( $field, $slugs ) );
			}
			$args['post__in'] = empty( $union ) ? array( 0 ) : array_values( array_unique( $union ) );
		} else {
			// AND между группами: tax_query/meta_query уже отфильтровывают через
			// обычный SQL, кастомные атрибуты дополнительно пересекаются через post__in.
			$this->apply_tax_and_price( $args, $tax_clauses, $price_meta, $logic_upper );

			$post_in = null;
			foreach ( $custom_filters as $field => $slugs ) {
				$ids     = $this->match_custom_filter( $field, $slugs );
				$post_in = ( null === $post_in ) ? $ids : array_intersect( $post_in, $ids );
			}
			$args['post__in'] = empty( $post_in ) ? array( 0 ) : array_values( $post_in );
		}

		$this->apply_orderby( $args, $orderby, $order );

		return new WP_Query( $args );
	}

	/**
	 * Применить накопленные tax_query/meta_query к аргументам запроса.
	 *
	 * @param array      $args        Аргументы WP_Query (по ссылке).
	 * @param array      $tax_clauses Ветки tax_query.
	 * @param array|null $price_meta  Ветка meta_query для цены, или null.
	 * @param string     $logic_upper 'AND' | 'OR'.
	 */
	private function apply_tax_and_price( array &$args, array $tax_clauses, $price_meta, $logic_upper ) {
		if ( ! empty( $tax_clauses ) ) {
			if ( count( $tax_clauses ) > 1 ) {
				$tax_clauses['relation'] = $logic_upper;
			}
			$args['tax_query'] = $tax_clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- фасетная фильтрация, ожидаемо.
		}
		if ( $price_meta ) {
			$args['meta_query'] = array( $price_meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- фильтр по цене.
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
					'post_type'           => 'product',
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
	 * Собрать meta_query для цены.
	 *
	 * @param array $value Массив с ключами min/max.
	 * @return array|null
	 */
	private function build_price_meta_query( $value ) {
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
				'key'     => '_price',
				'value'   => array( $min, $max ),
				'type'    => 'NUMERIC',
				'compare' => 'BETWEEN',
			);
		}

		if ( null !== $min ) {
			return array(
				'key'     => '_price',
				'value'   => $min,
				'type'    => 'NUMERIC',
				'compare' => '>=',
			);
		}

		return array(
			'key'     => '_price',
			'value'   => $max,
			'type'    => 'NUMERIC',
			'compare' => '<=',
		);
	}

	/**
	 * Применить orderby/order к аргументам запроса с проверкой по whitelist.
	 *
	 * @param array  $args    Аргументы WP_Query (по ссылке).
	 * @param string $orderby Запрошенное поле сортировки.
	 * @param string $order   Запрошенное направление.
	 */
	private function apply_orderby( array &$args, $orderby, $order ) {
		$orderby = in_array( $orderby, self::ORDERBY_WHITELIST, true ) ? $orderby : 'menu_order';

		if ( 'price-desc' === $orderby ) {
			$orderby = 'price';
			$order   = 'DESC';
		}

		$order = ( 'DESC' === strtoupper( (string) $order ) ) ? 'DESC' : 'ASC';

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
}
