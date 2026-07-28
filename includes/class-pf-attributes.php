<?php
/**
 * Сканирование таксономий и атрибутов WooCommerce, построение данных для /config.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Attributes
 */
class PF_Attributes {

	/**
	 * Собрать полный набор групп фильтров для ответа GET /config.
	 *
	 * @param string $category_slug Slug категории (для контекстного переопределения), может быть пустым.
	 * @return array Список групп в формате ответа REST API.
	 */
	public function get_groups( $category_slug = '' ) {
		$override = PF_Config::get_category_override( $category_slug );
		$configs  = $override ? $override : PF_Config::get( 'groups', array() );

		if ( empty( $configs ) ) {
			$configs = $this->build_default_group_configs();
		}

		$groups = array();

		foreach ( $configs as $config ) {
			if ( empty( $config['enabled'] ) && isset( $config['enabled'] ) ) {
				continue;
			}

			$group = $this->build_group( $config );
			if ( null !== $group ) {
				$groups[] = $group;
			}
		}

		return $groups;
	}

	/**
	 * Если в настройках ещё нет ни одной сконфигурированной группы — построить
	 * набор по умолчанию из всех зарегистрированных атрибутов WooCommerce
	 * плюс цена и дерево категорий.
	 *
	 * @return array
	 */
	private function build_default_group_configs() {
		$configs = array();

		foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
			$configs[] = array(
				'field'    => 'pa_' . $taxonomy->attribute_name,
				'label'    => $taxonomy->attribute_label,
				'template' => 'checkbox',
				'logic'    => 'or',
				'enabled'  => true,
			);
		}

		$configs[] = array(
			'field'    => 'price',
			'label'    => 'Цена',
			'template' => 'range',
			'enabled'  => true,
		);

		$configs[] = array(
			'field'      => 'product_cat',
			'label'      => 'Категории',
			'template'   => 'category-tree',
			'logic'      => 'or',
			'enabled'    => true,
			'tree_depth' => (int) PF_Config::get( 'tree_depth', 4 ),
		);

		return $configs;
	}

	/**
	 * Построить одну группу по конфигу.
	 *
	 * @param array $config Конфигурация группы (field/label/template/...).
	 * @return array|null
	 */
	private function build_group( array $config ) {
		$field = isset( $config['field'] ) ? $config['field'] : '';

		if ( '' === $field ) {
			return null;
		}

		if ( 'price' === $field ) {
			return $this->build_price_group( $config );
		}

		if ( 'product_cat' === $field ) {
			return $this->build_category_tree_group( $config );
		}

		if ( 0 === strpos( $field, 'pa_' ) ) {
			return $this->build_taxonomy_group( $config );
		}

		return null;
	}

	/**
	 * Группа фильтра по товарному атрибуту WooCommerce (pa_*).
	 *
	 * @param array $config Конфигурация группы.
	 * @return array|null
	 */
	private function build_taxonomy_group( array $config ) {
		$taxonomy = $config['field'];

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$colors = isset( $config['colors'] ) && is_array( $config['colors'] ) ? $config['colors'] : array();

		$values = array();
		foreach ( $terms as $term ) {
			$values[] = array(
				'value' => $term->slug,
				'label' => $term->name,
				'color' => isset( $colors[ $term->slug ] ) ? $colors[ $term->slug ] : null,
				'count' => (int) $term->count,
			);
		}

		return array(
			'field'    => $taxonomy,
			'label'    => isset( $config['label'] ) ? $config['label'] : $taxonomy,
			'template' => isset( $config['template'] ) ? $config['template'] : 'checkbox',
			'logic'    => isset( $config['logic'] ) ? $config['logic'] : 'or',
			'values'   => $values,
		);
	}

	/**
	 * Группа фильтра по цене.
	 *
	 * @param array $config Конфигурация группы.
	 * @return array
	 */
	private function build_price_group( array $config ) {
		$range = $this->get_price_range();

		return array(
			'field'    => 'price',
			'label'    => isset( $config['label'] ) ? $config['label'] : 'Цена',
			'template' => 'range',
			'min'      => $range['min'],
			'max'      => $range['max'],
			'step'     => isset( $config['step'] ) ? (float) $config['step'] : 500,
			'unit'     => isset( $config['unit'] ) ? $config['unit'] : '₽',
		);
	}

	/**
	 * Найти глобальные min/max цены среди опубликованных товаров.
	 *
	 * @return array{min:float,max:float}
	 */
	public function get_price_range() {
		global $wpdb;

		$sql = "
			SELECT MIN( CAST( meta_value AS DECIMAL(10,2) ) ) AS min_price,
			       MAX( CAST( meta_value AS DECIMAL(10,2) ) ) AS max_price
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_price'
			  AND pm.meta_value != ''
			  AND p.post_status = 'publish'
			  AND p.post_type IN ( 'product', 'product_variation' )
		";

		$row = $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- статичный SQL без пользовательского ввода.

		return array(
			'min' => $row && null !== $row->min_price ? (float) $row->min_price : 0,
			'max' => $row && null !== $row->max_price ? (float) $row->max_price : 0,
		);
	}

	/**
	 * Группа фильтра — дерево категорий товаров.
	 *
	 * @param array $config Конфигурация группы.
	 * @return array
	 */
	private function build_category_tree_group( array $config ) {
		$depth = isset( $config['tree_depth'] ) ? (int) $config['tree_depth'] : (int) PF_Config::get( 'tree_depth', 4 );

		return array(
			'field'      => 'product_cat',
			'label'      => isset( $config['label'] ) ? $config['label'] : 'Категории',
			'template'   => 'category-tree',
			'logic'      => isset( $config['logic'] ) ? $config['logic'] : 'or',
			'tree_depth' => $depth,
			'values'     => $this->get_category_tree( 0, $depth, 1 ),
		);
	}

	/**
	 * Рекурсивно построить дерево категорий товаров начиная с parent_id.
	 *
	 * @param int $parent_id ID родительского термина (0 — корень).
	 * @param int $max_depth Максимальная глубина из настроек шаблона.
	 * @param int $level     Текущий уровень (с 1).
	 * @return array
	 */
	public function get_category_tree( $parent_id = 0, $max_depth = 4, $level = 1 ) {
		if ( $level > $max_depth ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => $parent_id,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$tree = array();
		foreach ( $terms as $term ) {
			$tree[] = array(
				'value'    => $term->slug,
				'label'    => $term->name,
				'count'    => (int) $term->count,
				'children' => $this->get_category_tree( $term->term_id, $max_depth, $level + 1 ),
			);
		}

		return $tree;
	}

	/**
	 * Реальная максимальная глубина дерева категорий товаров (без учёта ограничения шаблона).
	 * Используется для предупреждения в админке.
	 *
	 * @return int
	 */
	public function get_real_category_depth() {
		return $this->measure_depth( 0, 1 );
	}

	/**
	 * Вспомогательный рекурсивный подсчёт реальной глубины без ограничения по $max_depth.
	 *
	 * @param int $parent_id ID родителя.
	 * @param int $level     Текущий уровень.
	 * @return int
	 */
	private function measure_depth( $parent_id, $level ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => $parent_id,
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $level - 1;
		}

		$deepest = $level;
		foreach ( $terms as $term_id ) {
			$deepest = max( $deepest, $this->measure_depth( $term_id, $level + 1 ) );
		}

		return $deepest;
	}

	/**
	 * Список всех зарегистрированных на сайте атрибутов WooCommerce (для страницы админки).
	 *
	 * @return array
	 */
	public function get_all_attribute_taxonomies() {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
			$out[] = array(
				'field' => 'pa_' . $taxonomy->attribute_name,
				'label' => $taxonomy->attribute_label,
			);
		}
		return $out;
	}
}
