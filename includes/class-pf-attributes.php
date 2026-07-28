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
	 * Кэш результата scan_custom_attributes() за запрос.
	 *
	 * @var array|null
	 */
	private $custom_attributes_cache = null;

	/**
	 * Собрать полный набор групп фильтров для ответа GET /config.
	 *
	 * Список включённых групп всегда один и тот же (глобальный, из настроек
	 * админки) — ручного переопределения по категориям больше нет. Вместо этого
	 * при указанной категории каждая группа автоматически показывается только
	 * если среди товаров этой категории есть хотя бы одно её значение; если нет —
	 * группа тихо пропускается.
	 *
	 * @param string $category_slug Slug категории (для авто-релевантности), может быть пустым.
	 * @return array Список групп в формате ответа REST API.
	 */
	public function get_groups( $category_slug = '' ) {
		$configs = PF_Config::get( 'groups', array() );

		if ( empty( $configs ) ) {
			$configs = $this->build_default_group_configs();
		}

		$category_product_ids = '' !== $category_slug ? $this->get_product_ids_in_category( $category_slug ) : null;

		$groups = array();

		foreach ( $configs as $config ) {
			if ( empty( $config['enabled'] ) && isset( $config['enabled'] ) ) {
				continue;
			}

			$group = $this->build_group( $config, $category_product_ids );
			if ( null !== $group ) {
				$groups[] = $group;
			}
		}

		return $groups;
	}

	/**
	 * Если в настройках ещё нет ни одной сконфигурированной группы — построить
	 * набор по умолчанию из всех зарегистрированных атрибутов WooCommerce
	 * (глобальных таксономий pa_* и кастомных/локальных атрибутов товаров),
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

		foreach ( array_keys( $this->scan_custom_attributes() ) as $raw_name ) {
			$configs[] = array(
				'field'    => $this->get_custom_attribute_field( $raw_name ),
				'label'    => $raw_name,
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
	 * @param array    $config                Конфигурация группы (field/label/template/...).
	 * @param int[]|null $category_product_ids ID товаров текущей категории для авто-релевантности
	 *                                         (null — без ограничения, глобально).
	 * @return array|null
	 */
	private function build_group( array $config, $category_product_ids = null ) {
		$field = isset( $config['field'] ) ? $config['field'] : '';

		if ( '' === $field ) {
			return null;
		}

		if ( 'price' === $field ) {
			return $this->build_price_group( $config, $category_product_ids );
		}

		if ( 'product_cat' === $field ) {
			return $this->build_category_tree_group( $config );
		}

		if ( 0 === strpos( $field, 'pa_' ) ) {
			return $this->build_taxonomy_group( $config, $category_product_ids );
		}

		if ( 0 === strpos( $field, 'custom_' ) ) {
			$raw_name = $this->resolve_custom_attribute_name( $field );
			if ( null === $raw_name ) {
				return null;
			}
			return $this->build_custom_attribute_group( $raw_name, $config, $category_product_ids );
		}

		return null;
	}

	/**
	 * Группа фильтра по товарному атрибуту WooCommerce (pa_*).
	 *
	 * @param array      $config               Конфигурация группы.
	 * @param int[]|null $category_product_ids ID товаров текущей категории, или null.
	 * @return array|null
	 */
	private function build_taxonomy_group( array $config, $category_product_ids = null ) {
		$taxonomy = $config['field'];

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		if ( null !== $category_product_ids && empty( $category_product_ids ) ) {
			return null; // в категории вообще нет товаров — группа не имеет смысла.
		}

		$term_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		);
		if ( null !== $category_product_ids ) {
			$term_args['object_ids'] = $category_product_ids;
		}

		$terms = get_terms( $term_args );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		// term->count у get_terms() всегда глобальный (по всему сайту), даже с
		// object_ids — для реального счётчика "сколько товаров этой категории"
		// нужен отдельный подсчёт, ограниченный этим же набором ID.
		$scoped_counts = null !== $category_product_ids
			? $this->get_term_counts_for_products( $taxonomy, $category_product_ids )
			: null;

		$colors = isset( $config['colors'] ) && is_array( $config['colors'] ) ? $config['colors'] : array();

		$values = array();
		foreach ( $terms as $term ) {
			$count = null !== $scoped_counts ? ( $scoped_counts[ $term->term_id ] ?? 0 ) : (int) $term->count;
			if ( $count < 1 ) {
				continue;
			}
			$values[] = array(
				'value' => $term->slug,
				'label' => $term->name,
				'color' => isset( $colors[ $term->slug ] ) ? $colors[ $term->slug ] : null,
				'count' => $count,
			);
		}

		if ( empty( $values ) ) {
			return null;
		}

		return array(
			'field'    => $taxonomy,
			'label'    => isset( $config['label'] ) ? $config['label'] : $taxonomy,
			'template' => isset( $config['template'] ) ? $config['template'] : 'checkbox',
			'logic'    => isset( $config['logic'] ) ? $config['logic'] : 'or',
			'search'   => ! array_key_exists( 'search', $config ) || false !== $config['search'],
			'values'   => $values,
		);
	}

	/**
	 * Подсчитать количество товаров (из заданного набора ID) для каждого термина таксономии.
	 * get_terms()'s object_ids фильтрует термины, но не пересчитывает их ->count — считаем сами.
	 *
	 * @param string $taxonomy Таксономия.
	 * @param array  $post_ids Ограничение по ID товаров.
	 * @return array term_id => count
	 */
	private function get_term_counts_for_products( $taxonomy, array $post_ids ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return array();
		}

		$ids_placeholder = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ids_placeholder из плейсхолдеров, значения через prepare().
		$sql = $wpdb->prepare(
			"SELECT tt.term_id, COUNT( DISTINCT tr.object_id ) AS cnt
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tt.taxonomy = %s AND tr.object_id IN ( {$ids_placeholder} )
			 GROUP BY tt.term_id",
			array_merge( array( $taxonomy ), $post_ids )
		);

		$rows   = $wpdb->get_results( $sql );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->term_id ] = (int) $row->cnt;
		}
		return $counts;
	}

	/**
	 * ID опубликованных товаров в заданной категории (для авто-релевантности групп).
	 *
	 * @param string $category_slug Slug категории товаров.
	 * @return int[]
	 */
	public function get_product_ids_in_category( $category_slug ) {
		$query = new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'slug',
						'terms'    => array( $category_slug ),
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Просканировать _product_attributes всех опубликованных товаров и собрать
	 * кастомные (не таксономические, "локальные") атрибуты: их сырые имена,
	 * сырые значения и ID товаров у каждого значения. Результат кэшируется на
	 * время запроса — сканирование одно на весь /config или /products вызов.
	 *
	 * @return array raw_name => array( raw_value => array( post_id, ... ) )
	 */
	private function scan_custom_attributes() {
		if ( null !== $this->custom_attributes_cache ) {
			return $this->custom_attributes_cache;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_product_attributes'
			   AND p.post_status = 'publish'
			   AND p.post_type = 'product'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- статичный SQL без пользовательского ввода.

		$attributes = array();

		foreach ( $rows as $row ) {
			$data = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $data ) ) {
				continue;
			}

			foreach ( $data as $attribute ) {
				if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) ) {
					continue; // таксономические (pa_*) атрибуты обрабатываются отдельно.
				}

				$raw_name = isset( $attribute['name'] ) ? trim( (string) $attribute['name'] ) : '';
				if ( '' === $raw_name ) {
					continue;
				}

				$raw_values = isset( $attribute['value'] ) ? explode( '|', (string) $attribute['value'] ) : array();

				foreach ( $raw_values as $raw_value ) {
					$raw_value = trim( $raw_value );
					if ( '' === $raw_value ) {
						continue;
					}

					if ( ! isset( $attributes[ $raw_name ][ $raw_value ] ) ) {
						$attributes[ $raw_name ][ $raw_value ] = array();
					}
					$attributes[ $raw_name ][ $raw_value ][] = (int) $row->post_id;
				}
			}
		}

		$this->custom_attributes_cache = $attributes;
		return $attributes;
	}

	/**
	 * Стабильный идентификатор поля для кастомного (локального) атрибута.
	 *
	 * @param string $raw_name Сырое (как в _product_attributes) имя атрибута.
	 * @return string
	 */
	public function get_custom_attribute_field( $raw_name ) {
		return 'custom_' . sanitize_title( $raw_name );
	}

	/**
	 * По field-идентификатору (custom_...) найти исходное сырое имя атрибута.
	 *
	 * @param string $field_slug Значение из group.field / фильтра.
	 * @return string|null
	 */
	public function resolve_custom_attribute_name( $field_slug ) {
		foreach ( array_keys( $this->scan_custom_attributes() ) as $raw_name ) {
			if ( $this->get_custom_attribute_field( $raw_name ) === $field_slug ) {
				return $raw_name;
			}
		}
		return null;
	}

	/**
	 * ID товаров, у которых кастомный атрибут $raw_name имеет хотя бы одно
	 * из запрошенных значений (значения передаются как slug — sanitize_title
	 * от сырого значения, как их видит JS).
	 *
	 * @param string $raw_name Сырое имя атрибута.
	 * @param array  $slugs    Запрошенные значения (slugs).
	 * @return int[]
	 */
	public function get_product_ids_for_custom_attribute_values( $raw_name, array $slugs ) {
		$attributes = $this->scan_custom_attributes();
		if ( ! isset( $attributes[ $raw_name ] ) ) {
			return array();
		}

		$matching = array();
		foreach ( $attributes[ $raw_name ] as $raw_value => $post_ids ) {
			if ( in_array( sanitize_title( $raw_value ), $slugs, true ) ) {
				$matching = array_merge( $matching, $post_ids );
			}
		}

		return array_values( array_unique( $matching ) );
	}

	/**
	 * Группа фильтра по кастомному (локальному, не-таксономическому) атрибуту товара.
	 *
	 * @param string     $raw_name             Сырое имя атрибута.
	 * @param array      $config               Конфигурация группы.
	 * @param int[]|null $category_product_ids ID товаров текущей категории, или null.
	 * @return array|null
	 */
	private function build_custom_attribute_group( $raw_name, array $config, $category_product_ids = null ) {
		$attributes = $this->scan_custom_attributes();
		if ( ! isset( $attributes[ $raw_name ] ) ) {
			return null;
		}

		$colors = isset( $config['colors'] ) && is_array( $config['colors'] ) ? $config['colors'] : array();

		$values = array();
		foreach ( $attributes[ $raw_name ] as $raw_value => $post_ids ) {
			$scoped_ids = null === $category_product_ids ? $post_ids : array_intersect( $post_ids, $category_product_ids );
			$count      = count( $scoped_ids );
			if ( $count < 1 ) {
				continue;
			}
			$slug     = sanitize_title( $raw_value );
			$values[] = array(
				'value' => $slug,
				'label' => $raw_value,
				'color' => isset( $colors[ $slug ] ) ? $colors[ $slug ] : null,
				'count' => $count,
			);
		}

		if ( empty( $values ) ) {
			return null;
		}

		return array(
			'field'    => $this->get_custom_attribute_field( $raw_name ),
			'label'    => isset( $config['label'] ) ? $config['label'] : $raw_name,
			'template' => isset( $config['template'] ) ? $config['template'] : 'checkbox',
			'logic'    => isset( $config['logic'] ) ? $config['logic'] : 'or',
			'search'   => ! array_key_exists( 'search', $config ) || false !== $config['search'],
			'values'   => $values,
		);
	}

	/**
	 * Группа фильтра по цене.
	 *
	 * @param array      $config               Конфигурация группы.
	 * @param int[]|null $category_product_ids ID товаров текущей категории, или null.
	 * @return array|null
	 */
	private function build_price_group( array $config, $category_product_ids = null ) {
		if ( null !== $category_product_ids && empty( $category_product_ids ) ) {
			return null;
		}

		$range = $this->get_price_range( $category_product_ids );

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
	 * Найти min/max цены среди опубликованных товаров (глобально, либо в заданном
	 * наборе ID — для авто-релевантности по категории).
	 *
	 * @param int[]|null $post_ids Ограничение по ID товаров, или null — без ограничения.
	 * @return array{min:float,max:float}
	 */
	public function get_price_range( $post_ids = null ) {
		global $wpdb;

		if ( null !== $post_ids ) {
			if ( empty( $post_ids ) ) {
				return array(
					'min' => 0,
					'max' => 0,
				);
			}

			$ids_placeholder = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ids_placeholder из плейсхолдеров, значения через prepare().
			$sql = $wpdb->prepare(
				"SELECT MIN( CAST( meta_value AS DECIMAL(10,2) ) ) AS min_price,
				        MAX( CAST( meta_value AS DECIMAL(10,2) ) ) AS max_price
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = '_price' AND meta_value != '' AND post_id IN ( {$ids_placeholder} )",
				$post_ids
			);
		} else {
			$sql = "
				SELECT MIN( CAST( meta_value AS DECIMAL(10,2) ) ) AS min_price,
				       MAX( CAST( meta_value AS DECIMAL(10,2) ) ) AS max_price
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_price'
				  AND pm.meta_value != ''
				  AND p.post_status = 'publish'
				  AND p.post_type IN ( 'product', 'product_variation' )
			"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- статичный SQL без пользовательского ввода.
		}

		$row = $wpdb->get_row( $sql );

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
			'search'     => ! array_key_exists( 'search', $config ) || false !== $config['search'],
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
	 * Список всех зарегистрированных на сайте атрибутов WooCommerce (для страницы админки) —
	 * и глобальных таксономий (pa_*), и кастомных/локальных атрибутов, найденных
	 * непосредственно у товаров.
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
		foreach ( array_keys( $this->scan_custom_attributes() ) as $raw_name ) {
			$out[] = array(
				'field' => $this->get_custom_attribute_field( $raw_name ),
				'label' => $raw_name,
			);
		}
		return $out;
	}
}
