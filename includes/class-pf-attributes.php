<?php
/**
 * Сканирование таксономий настроенного типа записи (плюс кастомные/локальные
 * атрибуты товаров — WooCommerce-специфика) и построение данных для /config.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Attributes
 */
class PF_Attributes {

	/**
	 * Допустимые режимы сортировки значений внутри группы (настройка группы
	 * value_sort). Применяется единообразно к любому типу группы со списком
	 * значений (таксономия/кастомный атрибут/плоские категории/дерево
	 * категорий) — не имеет смысла для price (там нет списка значений).
	 *
	 * @var string[]
	 */
	const VALUE_SORT_OPTIONS = array( 'name_asc', 'name_desc', 'count_desc', 'count_asc' );

	/**
	 * Глубина дерева категорий, если группа с шаблоном category-tree (или
	 * депth-ограничение для плоского шаблона на иерархической таксономии, см.
	 * build_taxonomy_group()) не задала свою явно. Раньше была отдельной
	 * глобальной настройкой в админке ("Общие настройки") — убрана как
	 * избыточная: у каждой такой группы уже есть собственное поле «Дерево:
	 * глубина», отдельный глобальный дефолт только путал.
	 *
	 * @var int
	 */
	const DEFAULT_TREE_DEPTH = 4;

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
	 * если среди записей этой категории есть хотя бы одно её значение; если нет —
	 * группа тихо пропускается.
	 *
	 * @param string $category_taxonomy Таксономия «категории» (см.
	 *                                  get_configured_category_tree_taxonomy()),
	 *                                  может быть пустой — тогда авто-релевантность
	 *                                  не применяется вообще.
	 * @param string $category_slug     Slug выбранного значения этой таксономии,
	 *                                  может быть пустым.
	 * @return array Список групп в формате ответа REST API.
	 */
	public function get_groups( $category_taxonomy = '', $category_slug = '' ) {
		$configs = PF_Config::get( 'groups', array() );

		if ( empty( $configs ) ) {
			$configs = $this->build_default_group_configs();
		}

		$category_product_ids = ( '' !== $category_taxonomy && '' !== $category_slug )
			? $this->get_ids_in_category( $category_taxonomy, $category_slug )
			: null;

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
	 * набор по умолчанию из всех подходящих таксономий настроенного типа
	 * записи (см. PF_Config::get_post_type()), числовых ACF-полей записи
	 * (кандидатов на range-группу, см. get_acf_post_fields()) плюс —
	 * только для товаров — кастомные/локальные атрибуты и цену
	 * (WooCommerce-специфика, см. scan_custom_attributes()).
	 *
	 * @return array
	 */
	private function build_default_group_configs() {
		$post_type = PF_Config::get_post_type();
		$configs   = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( ! $this->is_taxonomy_offerable( $taxonomy ) ) {
				continue;
			}
			$configs[] = array(
				'field'    => $taxonomy->name,
				'label'    => $taxonomy->label,
				'template' => $taxonomy->hierarchical ? 'category-tree' : 'checkbox',
				'logic'    => 'or',
				'enabled'  => true,
			);
		}

		// Кастомные/локальные атрибуты (_product_attributes) и цена (_price) —
		// целиком WooCommerce-специфика (сканирование всегда по post_type=product
		// в SQL/postmeta), имеет смысл только когда сам плагин настроен именно
		// на товары — иначе в список утекали бы чужие WC-атрибуты/цена товаров
		// даже для типа записи "услуги" или блога.
		if ( 'product' === $post_type ) {
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
		}

		foreach ( $this->get_acf_post_fields( $post_type ) as $af ) {
			$configs[] = array(
				'field'    => $af['name'],
				'label'    => $af['label'],
				'template' => 'range',
				'enabled'  => true,
			);
		}

		return $configs;
	}

	/**
	 * Стоит ли предлагать таксономию как поле группы фильтра. Обычно —
	 * `$taxonomy->public`, НО у WooCommerce-атрибутов (`pa_*`) этот флаг
	 * отражает настройку "Enable Archives?" в Товары → Атрибуты, которую
	 * почти никто не включает — при жёстком требовании `public` реальные,
	 * рабочие атрибуты (например, «Цвет») пропадали бы из списка. Поэтому
	 * `pa_*`-таксономии допускаются независимо от этого флага.
	 *
	 * @param WP_Taxonomy $taxonomy Объект таксономии.
	 * @return bool
	 */
	private function is_taxonomy_offerable( $taxonomy ) {
		return $taxonomy->public || 0 === strpos( $taxonomy->name, 'pa_' );
	}

	/**
	 * Проверить и вернуть шаблон группы, которым отвечает /config — сверяется
	 * с get_compatible_templates(), а не слепо возвращает сохранённый
	 * $config['template']. Нужно для самовосстановления уже сохранённых на
	 * сайте настроек: например, до 1.8.1 в админке можно было выбрать (и
	 * сохранить) шаблон range для таксономии/custom_-атрибута — комбинация,
	 * которую build_group() никогда не умел строить как настоящий диапазон
	 * (у терминов/атрибутов нет numeric postmeta, откуда взять min/max), из-за
	 * чего ползунок показывал 0..0. Без этой проверки такая уже сохранённая
	 * группа продолжала бы отдавать template:"range" в ответе /config, пока
	 * админ вручную не откроет настройки и не пересохранит группу.
	 *
	 * @param string $field   Field-идентификатор группы.
	 * @param array  $config  Конфигурация группы.
	 * @param string $default Шаблон по умолчанию, если сохранённый недопустим.
	 * @return string
	 */
	private function resolve_group_template( $field, array $config, $default ) {
		$requested  = isset( $config['template'] ) ? $config['template'] : $default;
		$compatible = $this->get_compatible_templates( $field );
		return in_array( $requested, $compatible, true ) ? $requested : $default;
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

		// Наличие товара (WooCommerce _stock_status) — синтетическое поле по
		// аналогии с 'price': не таксономия и не ACF-поле, фиксированный
		// небольшой набор значений. Имеет смысл только для настоящих товаров.
		if ( 'stock_status' === $field ) {
			return 'product' === PF_Config::get_post_type() ? $this->build_stock_status_group( $config, $category_product_ids ) : null;
		}

		// Любая реальная таксономия обрабатывается одинаково, независимо от
		// её имени (раньше здесь были спецкейсы под product_cat/product_tag/pa_*).
		// category-tree — только когда шаблон явно выбран таким (или не задан
		// вовсе для иерархической таксономии) И таксономия действительно
		// иерархическая; для плоской таксономии дерево не имеет смысла.
		if ( taxonomy_exists( $field ) ) {
			$hierarchical = is_taxonomy_hierarchical( $field );
			$template     = isset( $config['template'] ) ? $config['template'] : ( $hierarchical ? 'category-tree' : 'checkbox' );
			if ( 'category-tree' === $template && $hierarchical ) {
				return $this->build_category_tree_group( $config );
			}
			return $this->build_taxonomy_group( $config, $category_product_ids );
		}

		if ( 0 === strpos( $field, 'custom_' ) ) {
			$raw_name = $this->resolve_custom_attribute_name( $field );
			if ( null === $raw_name ) {
				return null;
			}
			return $this->build_custom_attribute_group( $raw_name, $config, $category_product_ids );
		}

		// Всё остальное — числовое meta/ACF-поле записи (диапазон): 'price'
		// (обратная совместимость с группами, сохранёнными до 1.8.0 — meta-ключ
		// _price) либо произвольное ACF-поле, обнаруженное get_acf_post_fields().
		return $this->build_range_group( $config, $category_product_ids );
	}

	/**
	 * Группа фильтра по значениям обычной (плоской или иерархической — тогда
	 * это плоский список без вложенности, см. build_category_tree_group())
	 * таксономии.
	 *
	 * Если у группы на иерархической таксономии задана tree_depth (обычно
	 * настраивается для шаблона category-tree, но применяется независимо от
	 * шаблона) — термины глубже этого уровня в список не попадают вообще,
	 * даже при плоском шаблоне (checkbox/radio/tags/dropdown-*).
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

		if ( is_taxonomy_hierarchical( $taxonomy ) && isset( $config['tree_depth'] ) && '' !== $config['tree_depth'] ) {
			$allowed_ids = $this->collect_term_ids_within_depth( $taxonomy, 0, (int) $config['tree_depth'], 1 );
			$terms       = array_filter(
				$terms,
				function ( $term ) use ( $allowed_ids ) {
					return in_array( $term->term_id, $allowed_ids, true );
				}
			);
		}

		// term->count у get_terms() всегда глобальный (по всему сайту), даже с
		// object_ids — для реального счётчика "сколько товаров этой категории"
		// нужен отдельный подсчёт, ограниченный этим же набором ID.
		$scoped_counts = null !== $category_product_ids
			? $this->get_term_counts_for_products( $taxonomy, $category_product_ids )
			: null;

		$color_meta_key = isset( $config['color_meta_key'] ) ? trim( (string) $config['color_meta_key'] ) : '';

		$values = array();
		foreach ( $terms as $term ) {
			$count = null !== $scoped_counts ? ( $scoped_counts[ $term->term_id ] ?? 0 ) : (int) $term->count;
			if ( $count < 1 ) {
				continue;
			}
			$values[] = array(
				'value' => $term->slug,
				'label' => $term->name,
				'color' => $this->resolve_term_color( $term, $color_meta_key ),
				'count' => $count,
			);
		}

		if ( empty( $values ) ) {
			return null;
		}

		$values = $this->sort_values( $values, $config['value_sort'] ?? 'name_asc' );

		return array(
			'field'    => $taxonomy,
			'label'    => isset( $config['label'] ) ? $config['label'] : $taxonomy,
			'template' => $this->resolve_group_template( $taxonomy, $config, 'checkbox' ),
			'logic'    => isset( $config['logic'] ) ? $config['logic'] : 'or',
			'search'   => ! array_key_exists( 'search', $config ) || false !== $config['search'],
			'values'   => $values,
		);
	}

	/**
	 * Цвет значения таксономии из term meta по заданному ключу (ACF или любой
	 * другой плагин, хранящий значение поля термина как обычный term meta под
	 * именем поля — так делают простые типы полей ACF вроде Color
	 * Picker/Text). Без прямой зависимости от функций ACF — читается через
	 * стандартный get_term_meta().
	 *
	 * @param WP_Term $term           Термин таксономии.
	 * @param string  $color_meta_key Имя term meta / ACF-поля (может быть пустым — тогда цвета нет).
	 * @return string|null
	 */
	private function resolve_term_color( $term, $color_meta_key ) {
		if ( '' === $color_meta_key ) {
			return null;
		}

		$value = get_term_meta( $term->term_id, $color_meta_key, true );
		$value = is_string( $value ) ? sanitize_text_field( $value ) : '';
		return '' !== $value ? $value : null;
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
	 * То же самое, что get_term_counts_for_products(), но результат сразу
	 * keyed по slug'у термина (не term_id) — то, что нужно facet-счётчикам
	 * (PF_Renderer::count_taxonomy_values()): одним групповым SQL-запросом
	 * на всю группу вместо отдельного WP_Query на каждое её значение.
	 *
	 * @param string $taxonomy Таксономия.
	 * @param array  $post_ids Ограничение по ID записей.
	 * @return array slug => count
	 */
	public function get_term_slug_counts_for_products( $taxonomy, array $post_ids ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return array();
		}

		$ids_placeholder = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ids_placeholder из плейсхолдеров, значения через prepare().
		$sql = $wpdb->prepare(
			"SELECT t.slug, COUNT( DISTINCT tr.object_id ) AS cnt
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = %s AND tr.object_id IN ( {$ids_placeholder} )
			 GROUP BY t.term_id",
			array_merge( array( $taxonomy ), $post_ids )
		);

		$rows   = $wpdb->get_results( $sql );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->slug ] = (int) $row->cnt;
		}
		return $counts;
	}

	/**
	 * ID опубликованных записей настроенного типа записи (см.
	 * PF_Config::get_post_type()) в заданном значении заданной таксономии
	 * (для авто-релевантности групп — см. get_groups()). Работает для любой
	 * таксономии/типа записи — раньше было жёстко захардкожено на
	 * product_cat/post_type=product.
	 *
	 * @param string $taxonomy      Слаг таксономии.
	 * @param string $category_slug Slug значения этой таксономии.
	 * @return int[]
	 */
	public function get_ids_in_category( $taxonomy, $category_slug ) {
		$query = new WP_Query(
			array(
				'post_type'           => PF_Config::get_post_type(),
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $taxonomy,
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
	 * Посчитать количество записей (из заданного набора ID) для каждого
	 * значения кастомного/локального атрибута — чистый PHP по уже
	 * просканированным (и закэшированным на время запроса, см.
	 * scan_custom_attributes()) спискам post_id на значение, без
	 * дополнительных SQL-запросов. Используется facet-счётчиками
	 * (PF_Renderer::count_taxonomy_values()) одним вызовом на всю группу
	 * вместо отдельного WP_Query на каждое её значение.
	 *
	 * @param string $raw_name Сырое имя атрибута.
	 * @param array  $post_ids Ограничение по ID записей.
	 * @return array slug => count
	 */
	public function count_custom_attribute_values( $raw_name, array $post_ids ) {
		$attributes = $this->scan_custom_attributes();
		if ( ! isset( $attributes[ $raw_name ] ) || empty( $post_ids ) ) {
			return array();
		}

		$post_ids_flip = array_flip( $post_ids );

		$counts = array();
		foreach ( $attributes[ $raw_name ] as $raw_value => $value_post_ids ) {
			$count = 0;
			foreach ( $value_post_ids as $post_id ) {
				if ( isset( $post_ids_flip[ $post_id ] ) ) {
					++$count;
				}
			}
			if ( $count > 0 ) {
				$counts[ sanitize_title( $raw_value ) ] = $count;
			}
		}

		return $counts;
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

		$values = $this->sort_values( $values, $config['value_sort'] ?? 'name_asc' );

		$field = $this->get_custom_attribute_field( $raw_name );

		return array(
			'field'    => $field,
			'label'    => isset( $config['label'] ) ? $config['label'] : $raw_name,
			'template' => $this->resolve_group_template( $field, $config, 'checkbox' ),
			'logic'    => isset( $config['logic'] ) ? $config['logic'] : 'or',
			'search'   => ! array_key_exists( 'search', $config ) || false !== $config['search'],
			'values'   => $values,
		);
	}

	/**
	 * Meta-ключ, из которого берутся значения range-группы. 'price' — синоним
	 * '_price' для обратной совместимости с группами, сохранёнными до 1.8.0
	 * (когда цена была единственной захардкоженной range-сущностью); для
	 * любого другого field — сам field и есть meta-ключ (так же, как field
	 * таксономической группы — это и есть слаг таксономии).
	 *
	 * @param string $field Field-идентификатор range-группы.
	 * @return string
	 */
	public static function resolve_range_meta_key( $field ) {
		return 'price' === $field ? '_price' : $field;
	}

	/**
	 * Группа фильтра — числовой диапазон (шаблон range) по meta-полю записи.
	 * Раньше это была отдельная захардкоженная сущность «цена»; теперь любая
	 * range-группа устроена одинаково — см. resolve_range_meta_key().
	 *
	 * @param array      $config               Конфигурация группы.
	 * @param int[]|null $category_product_ids ID записей текущей категории, или null.
	 * @return array|null
	 */
	private function build_range_group( array $config, $category_product_ids = null ) {
		$field = isset( $config['field'] ) ? $config['field'] : '';
		if ( '' === $field ) {
			return null;
		}

		if ( null !== $category_product_ids && empty( $category_product_ids ) ) {
			return null;
		}

		$meta_key = self::resolve_range_meta_key( $field );
		$range    = $this->get_meta_numeric_range( $meta_key, $category_product_ids );

		return array(
			'field'    => $field,
			'label'    => isset( $config['label'] ) ? $config['label'] : ( 'price' === $field ? 'Цена' : $field ),
			'template' => 'range',
			'min'      => $range['min'],
			'max'      => $range['max'],
			'step'     => isset( $config['step'] ) ? (float) $config['step'] : 500,
			'unit'     => isset( $config['unit'] ) ? $config['unit'] : ( 'price' === $field ? '₽' : '' ),
		);
	}

	/**
	 * Группа фильтра — наличие товара (WooCommerce _stock_status). Синтетическое
	 * поле по аналогии с 'price': не таксономия (термов не существует) и не
	 * ACF-поле, а фиксированный небольшой набор значений
	 * (instock/outofstock/onbackorder, см. get_stock_status_labels()) —
	 * устроена как обычная values-группа (совместима с любым шаблоном списка
	 * значений — checkbox/radio/tags/dropdown-*, но не с category-tree/range,
	 * см. get_compatible_templates()).
	 *
	 * @param array      $config               Конфигурация группы.
	 * @param int[]|null $category_product_ids ID товаров текущей категории, или null.
	 * @return array|null
	 */
	private function build_stock_status_group( array $config, $category_product_ids = null ) {
		if ( null !== $category_product_ids && empty( $category_product_ids ) ) {
			return null;
		}

		$counts = $this->count_stock_status_values( $category_product_ids );
		$labels = $this->get_stock_status_labels();

		$values = array();
		foreach ( $labels as $status => $label ) {
			$count = isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;
			if ( $count < 1 ) {
				continue;
			}
			$values[] = array(
				'value' => $status,
				'label' => $label,
				'color' => null,
				'count' => $count,
			);
		}

		if ( empty( $values ) ) {
			return null;
		}

		$values = $this->sort_values( $values, $config['value_sort'] ?? 'name_asc' );

		return array(
			'field'    => 'stock_status',
			'label'    => isset( $config['label'] ) ? $config['label'] : __( 'Наличие', 'pf-filter' ),
			'template' => $this->resolve_group_template( 'stock_status', $config, 'checkbox' ),
			'logic'    => isset( $config['logic'] ) ? $config['logic'] : 'or',
			'search'   => ! array_key_exists( 'search', $config ) || false !== $config['search'],
			'values'   => $values,
		);
	}

	/**
	 * Подписи статусов наличия — берутся из самого WooCommerce
	 * (wc_get_product_stock_status_options(), те же, что в редакторе товара),
	 * свой список — только запасной вариант, если функция вдруг недоступна.
	 *
	 * @return array status => label
	 */
	public function get_stock_status_labels() {
		if ( function_exists( 'wc_get_product_stock_status_options' ) ) {
			return wc_get_product_stock_status_options();
		}

		return array(
			'instock'     => __( 'В наличии', 'pf-filter' ),
			'outofstock'  => __( 'Нет в наличии', 'pf-filter' ),
			'onbackorder' => __( 'Под заказ', 'pf-filter' ),
		);
	}

	/**
	 * Посчитать количество товаров для каждого значения _stock_status —
	 * глобально (без ограничения по ID) либо в заданном наборе (авто-
	 * релевантность по категории/facet-счётчики, см. PF_Renderer).
	 *
	 * @param int[]|null $post_ids Ограничение по ID товаров, или null — без ограничения.
	 * @return array status => count
	 */
	public function count_stock_status_values( $post_ids = null ) {
		global $wpdb;

		if ( null !== $post_ids ) {
			if ( empty( $post_ids ) ) {
				return array();
			}

			$ids_placeholder = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ids_placeholder из плейсхолдеров, значения через prepare().
			$sql = $wpdb->prepare(
				"SELECT meta_value, COUNT(*) AS cnt
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = '_stock_status' AND post_id IN ( {$ids_placeholder} )
				 GROUP BY meta_value",
				$post_ids
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- нет пользовательского ввода, только литералы.
			$sql = "SELECT pm.meta_value, COUNT(*) AS cnt
			        FROM {$wpdb->postmeta} pm
			        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			        WHERE pm.meta_key = '_stock_status'
			          AND p.post_status = 'publish'
			          AND p.post_type = 'product'
			        GROUP BY pm.meta_value";
		}

		$rows   = $wpdb->get_results( $sql );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->meta_value ] = (int) $row->cnt;
		}

		return $counts;
	}

	/**
	 * Пост-типы, среди записей которых искать значения meta-поля для
	 * глобального (без ограничения по ID) диапазона. Для WooCommerce-товаров
	 * дополнительно учитываются вариации (у них своя собственная _price) —
	 * иначе только сам настроенный тип записи.
	 *
	 * @return string[]
	 */
	private function range_query_post_types() {
		$post_type = PF_Config::get_post_type();
		return 'product' === $post_type ? array( 'product', 'product_variation' ) : array( $post_type );
	}

	/**
	 * Найти min/max значения числового meta-поля среди опубликованных записей
	 * (глобально, либо в заданном наборе ID — для авто-релевантности по категории).
	 *
	 * @param string     $meta_key Ключ postmeta (см. resolve_range_meta_key()).
	 * @param int[]|null $post_ids Ограничение по ID записей, или null — без ограничения.
	 * @return array{min:float,max:float}
	 */
	public function get_meta_numeric_range( $meta_key, $post_ids = null ) {
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
				 WHERE meta_key = %s AND meta_value != '' AND post_id IN ( {$ids_placeholder} )",
				array_merge( array( $meta_key ), $post_ids )
			);
		} else {
			$post_types   = $this->range_query_post_types();
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $placeholders из плейсхолдеров, значения через prepare().
			$sql = $wpdb->prepare(
				"SELECT MIN( CAST( meta_value AS DECIMAL(10,2) ) ) AS min_price,
				        MAX( CAST( meta_value AS DECIMAL(10,2) ) ) AS max_price
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value != ''
				   AND p.post_status = 'publish'
				   AND p.post_type IN ( {$placeholders} )",
				array_merge( array( $meta_key ), $post_types )
			);
		}

		$row = $wpdb->get_row( $sql );

		return array(
			'min' => $row && null !== $row->min_price ? (float) $row->min_price : 0,
			'max' => $row && null !== $row->max_price ? (float) $row->max_price : 0,
		);
	}

	/**
	 * Группа фильтра — дерево значений иерархической таксономии (для
	 * WooCommerce-товаров это обычно product_cat, для блога — category,
	 * либо любая другая иерархическая таксономия выбранного типа записи).
	 *
	 * @param array $config Конфигурация группы (field — таксономия).
	 * @return array|null
	 */
	private function build_category_tree_group( array $config ) {
		$taxonomy = isset( $config['field'] ) ? $config['field'] : '';
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$depth = isset( $config['tree_depth'] ) && '' !== $config['tree_depth'] ? (int) $config['tree_depth'] : self::DEFAULT_TREE_DEPTH;
		$tree  = $this->get_category_tree( $taxonomy, 0, $depth, 1 );
		$tree  = $this->sort_values( $tree, $config['value_sort'] ?? 'name_asc' );

		return array(
			'field'      => $taxonomy,
			'label'      => isset( $config['label'] ) ? $config['label'] : $taxonomy,
			'template'   => 'category-tree',
			'logic'      => isset( $config['logic'] ) ? $config['logic'] : 'or',
			'search'     => ! array_key_exists( 'search', $config ) || false !== $config['search'],
			'tree_depth' => $depth,
			'values'     => $tree,
		);
	}

	/**
	 * Отсортировать список значений группы согласно настройке value_sort.
	 * Работает единообразно для любого типа группы со списком значений —
	 * обычная таксономия, кастомный атрибут, плоские категории или дерево
	 * категорий (для дерева сортировка применяется на каждом уровне отдельно,
	 * рекурсивно по children, без изменения самой структуры дерева).
	 *
	 * @param array  $values Список значений (или узлов дерева).
	 * @param string $mode   Один из self::VALUE_SORT_OPTIONS; любое другое значение — без изменений.
	 * @return array
	 */
	private function sort_values( array $values, $mode ) {
		if ( ! in_array( $mode, self::VALUE_SORT_OPTIONS, true ) ) {
			return $values;
		}

		usort(
			$values,
			function ( $a, $b ) use ( $mode ) {
				switch ( $mode ) {
					case 'name_desc':
						return strnatcasecmp( $b['label'], $a['label'] );
					case 'count_desc':
						return $b['count'] <=> $a['count'];
					case 'count_asc':
						return $a['count'] <=> $b['count'];
					default: // name_asc.
						return strnatcasecmp( $a['label'], $b['label'] );
				}
			}
		);

		foreach ( $values as &$value ) {
			if ( ! empty( $value['children'] ) ) {
				$value['children'] = $this->sort_values( $value['children'], $mode );
			}
		}
		unset( $value );

		return $values;
	}

	/**
	 * Рекурсивно построить дерево значений таксономии начиная с parent_id.
	 *
	 * @param string $taxonomy  Слаг иерархической таксономии.
	 * @param int    $parent_id ID родительского термина (0 — корень).
	 * @param int    $max_depth Максимальная глубина из настроек шаблона.
	 * @param int    $level     Текущий уровень (с 1).
	 * @return array
	 */
	public function get_category_tree( $taxonomy, $parent_id = 0, $max_depth = 4, $level = 1 ) {
		if ( $level > $max_depth ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
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
				'children' => $this->get_category_tree( $taxonomy, $term->term_id, $max_depth, $level + 1 ),
			);
		}

		return $tree;
	}

	/**
	 * ID терминов иерархической таксономии не глубже $max_depth уровней (корень
	 * — уровень 1) — используется build_taxonomy_group(), чтобы ограничивать
	 * глубину показанных значений у ПЛОСКОГО шаблона (checkbox/radio/tags/
	 * dropdown-*) на иерархической таксономии так же, как tree_depth уже
	 * ограничивает глубину настоящего дерева (category-tree). hide_empty
	 * специально false — это чисто структурный обход, "пустой" по прямым
	 * товарам родитель всё равно должен считаться существующим уровнем.
	 *
	 * @param string $taxonomy  Слаг иерархической таксономии.
	 * @param int    $parent_id ID родителя (0 — корень).
	 * @param int    $max_depth Максимальная глубина.
	 * @param int    $level     Текущий уровень (с 1).
	 * @return int[]
	 */
	private function collect_term_ids_within_depth( $taxonomy, $parent_id, $max_depth, $level ) {
		if ( $level > $max_depth ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'parent'     => $parent_id,
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$ids = $terms;
		foreach ( $terms as $term_id ) {
			$ids = array_merge( $ids, $this->collect_term_ids_within_depth( $taxonomy, $term_id, $max_depth, $level + 1 ) );
		}

		return $ids;
	}

	/**
	 * Реальная максимальная глубина дерева значений таксономии (без учёта
	 * ограничения шаблона). Используется для предупреждения в админке у
	 * группы с шаблоном category-tree.
	 *
	 * @param string $taxonomy Слаг иерархической таксономии.
	 * @return int
	 */
	public function get_real_category_depth( $taxonomy ) {
		return $this->measure_depth( $taxonomy, 0, 1 );
	}

	/**
	 * Таксономия для проверки диагностикой «Глубина дерева категорий» — берётся
	 * из первой сконфигурированной группы с шаблоном category-tree, а если
	 * группы ещё не настроены (свежая установка) — первая иерархическая
	 * публичная таксономия настроенного типа записи (см. PF_Config::get_post_type()).
	 *
	 * @return string Слаг таксономии, либо '' если подходящей не нашлось.
	 */
	public function get_configured_category_tree_taxonomy() {
		foreach ( PF_Config::get( 'groups', array() ) as $group ) {
			$field = $group['field'] ?? '';
			if ( 'category-tree' === ( $group['template'] ?? '' ) && '' !== $field && taxonomy_exists( $field ) ) {
				return $field;
			}
		}

		$post_type = PF_Config::get_post_type();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( $taxonomy->public && $taxonomy->hierarchical ) {
				return $taxonomy->name;
			}
		}

		return '';
	}

	/**
	 * Вспомогательный рекурсивный подсчёт реальной глубины без ограничения по $max_depth.
	 *
	 * @param string $taxonomy  Слаг иерархической таксономии.
	 * @param int    $parent_id ID родителя.
	 * @param int    $level     Текущий уровень.
	 * @return int
	 */
	private function measure_depth( $taxonomy, $parent_id, $level ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
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
			$deepest = max( $deepest, $this->measure_depth( $taxonomy, $term_id, $level + 1 ) );
		}

		return $deepest;
	}

	/**
	 * Список полей, доступных для группы фильтра на странице админки: все
	 * подходящие (см. is_taxonomy_offerable()) таксономии настроенного типа
	 * записи (PF_Config::get_post_type()), плюс — только если этот тип записи
	 * product — кастомные/локальные атрибуты товаров (WooCommerce-специфика,
	 * см. scan_custom_attributes()); для другого типа записи их не показываем
	 * вообще, иначе в список утекали бы чужие WC-атрибуты товаров.
	 *
	 * @return array
	 */
	public function get_available_taxonomy_fields() {
		$post_type = PF_Config::get_post_type();
		$out       = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( ! $this->is_taxonomy_offerable( $taxonomy ) ) {
				continue;
			}
			$out[] = array(
				'field' => $taxonomy->name,
				'label' => $taxonomy->label,
			);
		}

		if ( 'product' === $post_type ) {
			foreach ( array_keys( $this->scan_custom_attributes() ) as $raw_name ) {
				$out[] = array(
					'field' => $this->get_custom_attribute_field( $raw_name ),
					'label' => $raw_name,
				);
			}
		}
		return $out;
	}

	/**
	 * Список опций сортировки, доступных для настройки на вкладке
	 * «Сортировка» админки — динамический, под настроенный тип записи
	 * активного профиля (PF_Config::get_post_type()), а не фиксированный
	 * список WooCommerce-значений на все профили сразу:
	 * - `menu_order`/`date` — доступны всегда;
	 * - `price`/`price-desc`/`popularity`/`rating` — только для product
	 *   (сортировка по _price/total_sales/_wc_average_rating, см.
	 *   PF_Query::apply_orderby());
	 * - `meta_<имя>`/`meta_<имя>-desc` — по любому ACF-полю записи (те же
	 *   кандидаты, что и у range-групп, см. get_acf_post_fields());
	 * - `attr_<таксономия>`/`attr_<таксономия>-desc` — по названию термина
	 *   любой таксономии настроенного типа записи (только настоящие
	 *   таксономии — не custom_-атрибуты, у тех нет отдельной таблицы термов
	 *   для сортировки), см. PF_Query::apply_taxonomy_orderby().
	 *
	 * @return array [{value, label}, ...]
	 */
	public function get_sortable_field_options() {
		$post_type = PF_Config::get_post_type();

		$options = array(
			array(
				'value' => 'menu_order',
				'label' => __( 'Порядок (по умолчанию)', 'pf-filter' ),
			),
			array(
				'value' => 'date',
				'label' => __( 'Дата', 'pf-filter' ),
			),
		);

		if ( 'product' === $post_type ) {
			$options[] = array(
				'value' => 'price',
				'label' => __( 'Цена ↑', 'pf-filter' ),
			);
			$options[] = array(
				'value' => 'price-desc',
				'label' => __( 'Цена ↓', 'pf-filter' ),
			);
			$options[] = array(
				'value' => 'popularity',
				'label' => __( 'Популярность', 'pf-filter' ),
			);
			$options[] = array(
				'value' => 'rating',
				'label' => __( 'Рейтинг', 'pf-filter' ),
			);
		}

		foreach ( $this->get_acf_post_fields( $post_type ) as $af ) {
			$options[] = array(
				'value' => 'meta_' . $af['name'],
				'label' => $af['label'] . ' ↑',
			);
			$options[] = array(
				'value' => 'meta_' . $af['name'] . '-desc',
				'label' => $af['label'] . ' ↓',
			);
		}

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( ! $this->is_taxonomy_offerable( $taxonomy ) ) {
				continue;
			}
			$options[] = array(
				'value' => 'attr_' . $taxonomy->name,
				'label' => $taxonomy->label . ' (А→Я)',
			);
			$options[] = array(
				'value' => 'attr_' . $taxonomy->name . '-desc',
				'label' => $taxonomy->label . ' (Я→А)',
			);
		}

		return $options;
	}

	/**
	 * Поля ACF, привязанные (через правила локации) к термину указанной
	 * таксономии — чтобы в админке можно было ВЫБРАТЬ поле с цветом термина
	 * (например, ACF-поле "cvet" у pa_color), а не вписывать его имя руками.
	 * Список не ограничивается типом поля (Color Picker) — на практике поле
	 * может быть обычным Text с hex-строкой, как в этом случае, — поэтому
	 * возвращаются вообще все поля, привязанные к таксономии, выбор
	 * "какое из них — цвет" остаётся за админом.
	 *
	 * @param string $taxonomy Слаг таксономии.
	 * @return array [{name, label}, ...] — пусто, если ACF не активен или полей нет.
	 */
	public function get_acf_term_fields( $taxonomy ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$groups = acf_get_field_groups( array( 'taxonomy' => $taxonomy ) );
		$fields = array();

		foreach ( $groups as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( empty( $field['name'] ) ) {
					continue;
				}
				$fields[] = array(
					'name'  => $field['name'],
					'label' => $field['label'] ?? $field['name'],
				);
			}
		}

		return $fields;
	}

	/**
	 * Поля ACF, привязанные (через правила локации) к самой записи указанного
	 * типа — кандидаты на «числовое meta-поле» range-группы (аналог
	 * get_acf_term_fields(), но на уровне записи, не термина). Список не
	 * ограничивается числовыми типами полей ACF — выбор подходящего поля
	 * остаётся за админом, как и для color_meta_key.
	 *
	 * @param string $post_type Слаг типа записи.
	 * @return array [{name, label}, ...] — пусто, если ACF не активен или полей нет.
	 */
	public function get_acf_post_fields( $post_type ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		$fields = array();

		foreach ( $groups as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( empty( $field['name'] ) ) {
					continue;
				}
				$fields[] = array(
					'name'  => $field['name'],
					'label' => $field['label'] ?? $field['name'],
				);
			}
		}

		return $fields;
	}

	/**
	 * Список шаблонов, совместимых с данным полем (для страницы админки —
	 * не даёт назначить бессмысленную комбинацию).
	 *
	 * range умышленно НЕ предлагается для таксономий/custom_-атрибутов, даже
	 * если все их значения по факту числовые (например, товарный атрибут
	 * "Длина" со значениями терминов "100"/"200"/"300") — build_group()
	 * всё равно строит для них обычную values-группу (checkbox-подобную), а
	 * не числовой диапазон: у терминов/атрибутов нет постоянного numeric
	 * postmeta, откуда range мог бы взять min/max. Раньше эта комбинация
	 * была выбираемой в админке, но не реализованной — при выборе range
	 * ползунок показывал 0..0 вместо реальных границ.
	 *
	 * @param string $field Field-идентификатор (имя таксономии, custom_*, любое другое — числовое meta-поле).
	 * @return array
	 */
	public function get_compatible_templates( $field ) {
		if ( taxonomy_exists( $field ) && is_taxonomy_hierarchical( $field ) ) {
			return array( 'category-tree', 'checkbox', 'radio', 'tags', 'dropdown-checkbox', 'dropdown-radio' );
		}

		if ( taxonomy_exists( $field ) || 0 === strpos( $field, 'custom_' ) || 'stock_status' === $field ) {
			return array( 'checkbox', 'radio', 'tags', 'dropdown-checkbox', 'dropdown-radio' );
		}

		// Всё остальное (в т.ч. 'price') — числовое meta/ACF-поле записи: у
		// него нет списка дискретных значений, единственный осмысленный
		// шаблон — range.
		return array( 'range' );
	}
}
