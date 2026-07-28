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
	 * Построить WP_Query по параметрам фильтра.
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
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'paged'          => max( 1, absint( $paged ) ),
			'posts_per_page' => max( 1, absint( $per_page ) ),
			'ignore_sticky_posts' => true,
		);

		$tax_query  = array();
		$meta_query = array();

		foreach ( $filters as $field => $value ) {
			if ( 'price' === $field ) {
				$price_meta = $this->build_price_meta_query( $value );
				if ( $price_meta ) {
					$meta_query[] = $price_meta;
				}
				continue;
			}

			if ( 'product_cat' === $field ) {
				$tax_query[] = $this->build_tax_clause( 'product_cat', $value );
				continue;
			}

			if ( 0 === strpos( $field, 'pa_' ) && taxonomy_exists( $field ) ) {
				$tax_query[] = $this->build_tax_clause( $field, $value );
			}
		}

		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = ( 'or' === strtolower( (string) $logic ) ) ? 'OR' : 'AND';
			}
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- фасетная фильтрация, ожидаемо.
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- фильтр по цене.
		}

		$this->apply_orderby( $args, $orderby, $order );

		return new WP_Query( $args );
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
