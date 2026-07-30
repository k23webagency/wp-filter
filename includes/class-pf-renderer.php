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
	 * Сканер/извлекатель шаблона карточки из PHP-файла реальной страницы.
	 *
	 * @var PF_Card_Template
	 */
	private $card_template;

	/**
	 * Сканер атрибутов — нужен для подсчёта facet-счётчиков одним групповым
	 * SQL-запросом вместо отдельного запроса на каждое значение группы.
	 *
	 * @var PF_Attributes
	 */
	private $attributes;

	/**
	 * Конструктор.
	 *
	 * @param PF_Attributes|null $attributes Опционально — для переиспользования
	 *                                       уже созданного экземпляра (общий кэш
	 *                                       scan_custom_attributes() за запрос).
	 */
	public function __construct( PF_Attributes $attributes = null ) {
		$this->card_template = new PF_Card_Template();
		$this->attributes    = $attributes ?: new PF_Attributes();
	}

	/**
	 * Рендерит карточки товаров результата запроса.
	 *
	 * Приоритет — автоматически извлечённый из PHP-файла страницы шаблон
	 * (см. PF_Card_Template): он воспроизводит карточку темы один в один,
	 * включая условные блоки, без ручного создания content-product.php.
	 * Если извлечь не удалось (или извлечённый код не выполнился без ошибок) —
	 * откат на стандартный wc_get_template_part('content','product').
	 *
	 * @param WP_Query $query    Выполненный запрос товаров.
	 * @param string   $page_url URL страницы каталога (для поиска её PHP-шаблона), может быть пустым.
	 * @return string HTML карточек.
	 */
	public function render( WP_Query $query, $page_url = '' ) {
		if ( $page_url ) {
			$cache_file = $this->card_template->get_cached_template_file( $page_url );
			if ( $cache_file ) {
				try {
					return $this->render_with_extracted_template( $query, $cache_file );
				} catch ( \Throwable $e ) {
					// Извлечённый шаблон скомпилировался, но упал во время выполнения
					// (например, вызвал функцию темы, которой в этом контексте нет) —
					// тихо откатываемся на стандартный рендер ниже.
					$query->rewind_posts();
				}
			}
		}

		return $this->render_default( $query );
	}

	/**
	 * Рендер через кусок PHP, извлечённый из настоящего шаблона страницы:
	 * include один раз на товар, с setup_postdata() перед каждым (через
	 * $query->the_post()) — так же, как это делает сама тема в своём цикле.
	 *
	 * @param WP_Query $query      Выполненный запрос товаров.
	 * @param string   $cache_file Путь к закэшированному извлечённому шаблону.
	 * @return string
	 */
	private function render_with_extracted_template( WP_Query $query, $cache_file ) {
		ob_start();

		try {
			while ( $query->have_posts() ) {
				$query->the_post();
				include $cache_file;
			}
		} catch ( \Throwable $e ) {
			ob_end_clean();
			wp_reset_postdata();
			throw $e;
		}

		$html = ob_get_clean();
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Стандартный рендер — используется если извлечь шаблон страницы не
	 * удалось (или он не заработал). Для товаров WooCommerce — через
	 * стандартный WooCommerce loop (тема может переопределить карточку через
	 * свой woocommerce/content-product.php). Для любого другого типа записи
	 * WooCommerce не участвует вообще — карточка ищется по обычной
	 * WordPress-конвенции content-{post_type}.php/content.php, либо, если
	 * тема и такого не предоставляет, рендерится минимальный бейзлайн, чтобы
	 * AJAX-ответ не оставался пустым.
	 *
	 * @param WP_Query $query Выполненный запрос.
	 * @return string
	 */
	private function render_default( WP_Query $query ) {
		ob_start();

		$post_type            = (string) $query->get( 'post_type' );
		$use_woocommerce_loop = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_template_part' ) && 'product' === $post_type;

		if ( $use_woocommerce_loop && function_exists( 'wc_set_loop_prop' ) ) {
			wc_set_loop_prop( 'total', $query->found_posts );
			wc_set_loop_prop( 'total_pages', $query->max_num_pages );
			wc_set_loop_prop( 'current_page', max( 1, (int) $query->get( 'paged' ) ) );
			wc_set_loop_prop( 'is_shortcode', false );
		}

		while ( $query->have_posts() ) {
			$query->the_post();

			if ( $use_woocommerce_loop ) {
				wc_get_template_part( 'content', 'product' );
				continue;
			}

			$this->render_default_card( get_post_type() );
		}

		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * Карточка записи не-WooCommerce типа: сначала — обычная WordPress-
	 * конвенция шаблона темы (content-{post_type}.php, затем content.php),
	 * если ни того ни другого в теме нет — минимальный бейзлайн (заголовок
	 * со ссылкой + эксцерпт).
	 *
	 * @param string $post_type Тип текущей (setup_postdata) записи.
	 */
	private function render_default_card( $post_type ) {
		$located = locate_template( array( "content-{$post_type}.php", 'content.php' ) );

		if ( $located ) {
			get_template_part( 'content', $post_type );
			return;
		}
		?>
		<div class="pf-card">
			<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
			<div class="pf-card-excerpt"><?php the_excerpt(); ?></div>
		</div>
		<?php
	}

	/**
	 * Подсчёт количества товаров по каждому значению каждой активной группы
	 * при текущих фильтрах, не учитывая фильтр самой этой группы (facet UX).
	 *
	 * @param array    $groups        Группы из /config (с полем field/values, либо field/min/max для range).
	 * @param array    $filters       Текущие активные фильтры (санированные).
	 * @param string   $logic         Логика между группами.
	 * @param PF_Query $query_builder Построитель запроса.
	 * @return array field => (value => count) либо field => {min,max} для range-групп.
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

			// Range-группа (числовой диапазон — цена и любое другое такое поле)
			// отличается по форме своего ответа ({min,max}, без values), а не по
			// имени поля — раньше здесь был спецкейс под буквальное имя 'price'.
			if ( array_key_exists( 'min', $group ) && array_key_exists( 'max', $group ) ) {
				$counts[ $field ] = $this->count_meta_range_bounds( $field, $filters_without_group, $logic, $query_builder );
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
	 * ID записей, подходящих под заданный набор фильтров — без ограничения
	 * по количеству. Общий первый шаг для facet-счётчиков любого типа группы:
	 * дальше по этому фиксированному набору ID считается либо число значений
	 * таксономии/custom-атрибута (count_taxonomy_values()), либо min/max
	 * числового поля (count_meta_range_bounds()) — одним групповым запросом
	 * вместо отдельного WP_Query на каждое значение/группу.
	 *
	 * @param array    $filters Активные фильтры.
	 * @param string   $logic   Логика между группами.
	 * @param PF_Query $builder Построитель запроса.
	 * @return int[]
	 */
	private function matching_post_ids( array $filters, $logic, PF_Query $builder ) {
		$query = $builder->build( $filters, $logic, 'menu_order', 'ASC', 1, -1 );
		// $query->posts содержит объекты WP_Post — берём только ID для дальнейшего подсчёта.
		$ids = wp_list_pluck( $query->posts, 'ID' );
		wp_reset_postdata();
		return $ids;
	}

	/**
	 * Посчитать количество товаров для каждого значения таксономии/custom-
	 * атрибута при остальных активных фильтрах.
	 *
	 * Одним групповым запросом (через PF_Attributes) по фиксированному набору
	 * ID, подходящих под остальные фильтры — а не отдельным WP_Query на
	 * каждое значение группы (для дерева категорий на несколько уровней или
	 * таксономии с десятками термов это была реальная N+1-проблема: до
	 * нескольких десятков лишних запросов на один-единственный /products-ответ).
	 *
	 * @param string   $field   Таксономия или custom_* атрибут.
	 * @param array    $values  Список slug'ов значений.
	 * @param array    $filters Остальные активные фильтры.
	 * @param string   $logic   Логика между группами.
	 * @param PF_Query $builder Построитель запроса.
	 * @return array value => count
	 */
	private function count_taxonomy_values( $field, array $values, array $filters, $logic, PF_Query $builder ) {
		$ids = $this->matching_post_ids( $filters, $logic, $builder );

		if ( empty( $ids ) ) {
			return array_fill_keys( $values, 0 );
		}

		if ( taxonomy_exists( $field ) ) {
			$counts = $this->attributes->get_term_slug_counts_for_products( $field, $ids );
		} elseif ( 0 === strpos( $field, 'custom_' ) ) {
			$raw_name = $this->attributes->resolve_custom_attribute_name( $field );
			$counts   = null !== $raw_name ? $this->attributes->count_custom_attribute_values( $raw_name, $ids ) : array();
		} else {
			$counts = array();
		}

		$result = array();
		foreach ( $values as $value ) {
			$result[ $value ] = isset( $counts[ $value ] ) ? (int) $counts[ $value ] : 0;
		}

		return $result;
	}

	/**
	 * Посчитать min/max числового meta-поля среди записей, соответствующих
	 * остальным активным фильтрам.
	 *
	 * @param string   $field   Field-идентификатор range-группы (см. PF_Attributes::resolve_range_meta_key()).
	 * @param array    $filters Остальные активные фильтры (без этой группы).
	 * @param string   $logic   Логика между группами.
	 * @param PF_Query $builder Построитель запроса.
	 * @return array{min:int,max:int}
	 */
	private function count_meta_range_bounds( $field, array $filters, $logic, PF_Query $builder ) {
		global $wpdb;

		$ids = $this->matching_post_ids( $filters, $logic, $builder );

		if ( empty( $ids ) ) {
			return array(
				'min' => 0,
				'max' => 0,
			);
		}

		$meta_key        = PF_Attributes::resolve_range_meta_key( $field );
		$ids_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ids_placeholder построен из плейсхолдеров, значения подставляются через prepare.
		$sql = $wpdb->prepare(
			"SELECT MIN( CAST( meta_value AS DECIMAL(10,2) ) ) AS min_price,
			        MAX( CAST( meta_value AS DECIMAL(10,2) ) ) AS max_price
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND post_id IN ( {$ids_placeholder} )",
			array_merge( array( $meta_key ), $ids )
		);

		$row = $wpdb->get_row( $sql );

		return array(
			'min' => $row && null !== $row->min_price ? (float) $row->min_price : 0,
			'max' => $row && null !== $row->max_price ? (float) $row->max_price : 0,
		);
	}
}
