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
		$this->query_builder = new PF_Query();
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
				'logic'            => $settings['logic'],
				'search_threshold' => (int) $settings['search_threshold'],
				'show_counts'      => (bool) $settings['show_counts'],
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

		$html = $this->renderer->render( $query );

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

			if ( 'price' === $field && is_array( $value ) ) {
				$clean['price'] = array(
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
