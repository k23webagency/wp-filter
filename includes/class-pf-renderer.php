<?php
/**
 * Рендер HTML карточек товаров через стандартный WooCommerce loop
 * и подсчёт counts для фасетной фильтрации.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Renderer
 */
class PF_Renderer {

	/**
	 * Рендерит карточки товаров результата запроса через тему.
	 * Плагин не знает и не должен знать как выглядит карточка —
	 * это template part `content-product.php` темы/WooCommerce.
	 *
	 * @param WP_Query $query Выполненный запрос товаров.
	 * @return string HTML карточек.
	 */
	public function render( WP_Query $query ) {
		ob_start();

		if ( function_exists( 'wc_set_loop_prop' ) ) {
			wc_set_loop_prop( 'total', $query->found_posts );
			wc_set_loop_prop( 'total_pages', $query->max_num_pages );
			wc_set_loop_prop( 'current_page', max( 1, (int) $query->get( 'paged' ) ) );
			wc_set_loop_prop( 'is_shortcode', false );
		}

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				wc_get_template_part( 'content', 'product' );
			}
		}

		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * Подсчёт количества товаров по каждому значению каждой активной группы
	 * при текущих фильтрах, не учитывая фильтр самой этой группы (facet UX).
	 *
	 * @param array    $groups        Группы из /config (с полем field/values).
	 * @param array    $filters       Текущие активные фильтры (санированные).
	 * @param string   $logic         Логика между группами.
	 * @param PF_Query $query_builder Построитель запроса.
	 * @return array field => (value => count) либо field => {min,max} для price.
	 */
	public function get_counts( array $groups, array $filters, $logic, PF_Query $query_builder ) {
		$counts = array();

		foreach ( $groups as $group ) {
			$field = isset( $group['field'] ) ? $group['field'] : '';
			if ( '' === $field ) {
				continue;
			}

			$filters_without_group = $filters;
			unset( $filters_without_group[ $field ] );

			if ( 'price' === $field ) {
				$counts['price'] = $this->count_price_bounds( $filters_without_group, $logic, $query_builder );
				continue;
			}

			$values = isset( $group['values'] ) ? $this->flatten_values( $group['values'] ) : array();
			if ( empty( $values ) ) {
				continue;
			}

			$counts[ $field ] = $this->count_taxonomy_values( $field, $values, $filters_without_group, $logic, $query_builder );
		}

		return $counts;
	}

	/**
	 * Свести дерево значений (в т.ч. вложенное дерево категорий) в плоский список slug'ов.
	 *
	 * @param array $values Значения группы (могут иметь children).
	 * @return array
	 */
	private function flatten_values( array $values ) {
		$flat = array();
		foreach ( $values as $value ) {
			$flat[] = $value['value'];
			if ( ! empty( $value['children'] ) ) {
				$flat = array_merge( $flat, $this->flatten_values( $value['children'] ) );
			}
		}
		return $flat;
	}

	/**
	 * Посчитать количество товаров для каждого значения таксономии
	 * при остальных активных фильтрах.
	 *
	 * @param string   $field   Таксономия (pa_* или product_cat).
	 * @param array    $values  Список slug'ов значений.
	 * @param array    $filters Остальные активные фильтры.
	 * @param string   $logic   Логика между группами.
	 * @param PF_Query $builder Построитель запроса.
	 * @return array value => count
	 */
	private function count_taxonomy_values( $field, array $values, array $filters, $logic, PF_Query $builder ) {
		$result = array();

		foreach ( $values as $value ) {
			$test_filters          = $filters;
			$test_filters[ $field ] = array( $value );

			$query = $builder->build( $test_filters, $logic, 'menu_order', 'ASC', 1, 1 );
			$result[ $value ] = (int) $query->found_posts;
			wp_reset_postdata();
		}

		return $result;
	}

	/**
	 * Посчитать min/max цены среди товаров, соответствующих остальным активным фильтрам.
	 *
	 * @param array    $filters Остальные активные фильтры (без price).
	 * @param string   $logic   Логика между группами.
	 * @param PF_Query $builder Построитель запроса.
	 * @return array{min:int,max:int}
	 */
	private function count_price_bounds( array $filters, $logic, PF_Query $builder ) {
		global $wpdb;

		$query = $builder->build( $filters, $logic, 'menu_order', 'ASC', 1, -1 );
		// $query->posts содержит объекты WP_Post — берём только ID для запроса к postmeta.
		$ids = wp_list_pluck( $query->posts, 'ID' );
		wp_reset_postdata();

		if ( empty( $ids ) ) {
			return array(
				'min' => 0,
				'max' => 0,
			);
		}

		$ids_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ids_placeholder построен из плейсхолдеров, значения подставляются через prepare.
		$sql = $wpdb->prepare(
			"SELECT MIN( CAST( meta_value AS DECIMAL(10,2) ) ) AS min_price,
			        MAX( CAST( meta_value AS DECIMAL(10,2) ) ) AS max_price
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_price' AND post_id IN ( {$ids_placeholder} )",
			$ids
		);

		$row = $wpdb->get_row( $sql );

		return array(
			'min' => $row && null !== $row->min_price ? (float) $row->min_price : 0,
			'max' => $row && null !== $row->max_price ? (float) $row->max_price : 0,
		);
	}
}
