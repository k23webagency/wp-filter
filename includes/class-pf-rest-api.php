<?php
/**
 * Регистрация REST-эндпоинтов плагина.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_REST_API
 */
class PF_REST_API {

	/**
	 * Namespace REST API.
	 *
	 * @var string
	 */
	const NAMESPACE_ = 'pf/v1';

	/**
	 * Экземпляр сканера атрибутов/таксономий.
	 *
	 * @var PF_Attributes
	 */
	private $attributes;

	/**
	 * Построитель WP_Query.
	 *
	 * @var PF_Query
	 */
	private $query_builder;

	/**
	 * Рендерер карточек и counts.
	 *
	 * @var PF_Renderer
	 */
	private $renderer;

	/**
	 * Конструктор.
	 */
	public function __construct() {
		$this->attributes    = new PF_Attributes();
		// Общий экземпляр PF_Attributes — сканирование кастомных атрибутов
		// (_product_attributes по всем товарам) кэшируется на нём за запрос,
		// незачем сканировать дважды в одном REST-вызове.
		$this->query_builder = new PF_Query( $this->attributes );
		$this->renderer      = new PF_Renderer();
	}

	/**
	 * Подключиться к rest_api_init.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Регистрация обоих эндпоинтов.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_config' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'category' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/products',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Проверка nonce из заголовка X-WP-Nonce.
	 *
	 * @param WP_REST_Request $request Текущий запрос.
	 * @return bool
	 */
	private function verify_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * GET /wp-json/pf/v1/config
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_config( WP_REST_Request $request ) {
		if ( ! $this->verify_nonce( $request ) ) {
			return new WP_Error( 'pf_invalid_nonce', __( 'Недействительный nonce.', 'pf-filter' ), array( 'status' => 403 ) );
		}

		$category = sanitize_text_field( (string) $request->get_param( 'category' ) );

		$settings = PF_Config::get_settings();

		$response = array(
			'groups'       => $this->attributes->get_groups( $category ),
			'sort_options' => $settings['sort_options'],
			'settings'     => array(
				'logic'               => $settings['logic'],
				'search_threshold'    => (int) $settings['search_threshold'],
				'show_counts'         => (bool) $settings['show_counts'],
				'sync_url'            => (bool) $settings['sync_url'],
				'pagination_strategy' => $settings['pagination_strategy'],
				'posts_per_page'      => (int) $settings['posts_per_page'],
			),
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * POST /wp-json/pf/v1/products
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_products( WP_REST_Request $request ) {
		if ( ! $this->verify_nonce( $request ) ) {
			return new WP_Error( 'pf_invalid_nonce', __( 'Недействительный nonce.', 'pf-filter' ), array( 'status' => 403 ) );
		}

		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$filters = $this->sanitize_filters( isset( $body['filters'] ) ? $body['filters'] : array() );

		$logic    = in_array( strtolower( (string) ( $body['logic'] ?? '' ) ), array( 'and', 'or' ), true )
			? strtolower( $body['logic'] )
			: PF_Config::get( 'logic', 'and' );
		$orderby  = sanitize_text_field( (string) ( $body['orderby'] ?? 'menu_order' ) );
		$order    = sanitize_text_field( (string) ( $body['order'] ?? 'ASC' ) );
		$paged    = absint( $body['paged'] ?? 1 );
		$per_page = absint( $body['posts_per_page'] ?? 12 );

		$paged    = $paged > 0 ? $paged : 1;
		$per_page = $per_page > 0 ? $per_page : 12;

		$query = $this->query_builder->build( $filters, $logic, $orderby, $order, $paged, $per_page );

		$page_url = isset( $body['page_url'] ) ? esc_url_raw( (string) $body['page_url'] ) : '';
		$html     = $this->render_in_page_context( $query, $page_url );

		// "Активная категория" (авто-сужение счётчиков/видимости остальных
		// групп) намеренно всё ещё захардкожена на product_cat — этот механизм
		// не обобщён на произвольную таксономию/тип записи (см. ROADMAP.md);
		// для не-WooCommerce настроек просто не активируется.
		$active_category = isset( $filters['product_cat'][0] ) ? $filters['product_cat'][0] : '';
		$groups          = $this->attributes->get_groups( $active_category );
		$counts          = $this->renderer->get_counts( $groups, $filters, $logic, $this->query_builder );

		$response = array(
			'html'         => $html,
			'count'        => (int) $query->found_posts,
			'pages'        => (int) $query->max_num_pages,
			'current_page' => $paged,
			'counts'       => $counts,
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Отрендерить карточки в контексте реального URL страницы каталога, а не URL
	 * REST-эндпоинта.
	 *
	 * Разметка карточки — ответственность темы, и часто она строит ссылки (например,
	 * «В корзину» через add_query_arg() без явного базового URL) на основе текущего
	 * REQUEST_URI. Внутри REST-запроса REQUEST_URI равен '/wp-json/pf/v1/products',
	 * поэтому такие ссылки ломаются. Подставляем на время рендера реальный URL
	 * страницы (который прислал клиент) и обязательно возвращаем исходный обратно.
	 *
	 * @param WP_Query $query    Выполненный запрос товаров.
	 * @param string   $page_url URL страницы каталога, на которой находится пользователь.
	 * @return string
	 */
	private function render_in_page_context( WP_Query $query, $page_url ) {
		$original_request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$replaced              = false;

		if ( $page_url ) {
			$parts     = wp_parse_url( $page_url );
			$site_host = wp_parse_url( home_url() )['host'] ?? '';

			if ( ! empty( $parts['host'] ) && ! empty( $site_host ) && strtolower( $parts['host'] ) === strtolower( $site_host ) ) {
				$path = $parts['path'] ?? '/';
				if ( ! empty( $parts['query'] ) ) {
					$path .= '?' . $parts['query'];
				}
				$_SERVER['REQUEST_URI'] = $path; // phpcs:ignore WordPress.VIP.SuperGlobalInputUsage.AccessDetected -- временно, только для контекста рендера, значение проверено на совпадение хоста.
				$replaced                = true;
			}
		}

		try {
			return $this->renderer->render( $query, $page_url );
		} finally {
			if ( $replaced ) {
				$_SERVER['REQUEST_URI'] = $original_request_uri;
			}
		}
	}

	/**
	 * Санировать пришедший объект filters из тела запроса.
	 *
	 * @param mixed $filters Сырые данные filters.
	 * @return array
	 */
	private function sanitize_filters( $filters ) {
		if ( ! is_array( $filters ) ) {
			return array();
		}

		$clean = array();

		foreach ( $filters as $field => $value ) {
			$field = sanitize_text_field( (string) $field );

			// Числовой диапазон (шаблон range — цена и любое другое такое поле,
			// см. PF_Attributes::resolve_range_meta_key()) отличается по форме
			// значения ({min,max}), а не по имени поля — раньше здесь был
			// спецкейс под буквальное имя 'price'.
			if ( is_array( $value ) && ( array_key_exists( 'min', $value ) || array_key_exists( 'max', $value ) ) ) {
				$clean[ $field ] = array(
					'min' => isset( $value['min'] ) ? floatval( $value['min'] ) : null,
					'max' => isset( $value['max'] ) ? floatval( $value['max'] ) : null,
				);
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $field ] = array_map( 'sanitize_text_field', $value );
			}
		}

		return $clean;
	}
}
