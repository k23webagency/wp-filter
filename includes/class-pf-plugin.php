<?php
/**
 * Главный класс плагина — инициализация, подключение скриптов.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Plugin
 */
final class PF_Plugin {

	/**
	 * Единственный экземпляр (singleton).
	 *
	 * @var PF_Plugin|null
	 */
	private static $instance = null;

	/**
	 * REST API контроллер.
	 *
	 * @var PF_REST_API
	 */
	private $rest_api;

	/**
	 * Страница настроек в админке.
	 *
	 * @var PF_Admin
	 */
	private $admin;

	/**
	 * Получить единственный экземпляр плагина.
	 *
	 * @return PF_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор — регистрирует хуки.
	 */
	private function __construct() {
		$this->rest_api = new PF_REST_API();
		$this->rest_api->init();

		if ( is_admin() ) {
			$this->admin = new PF_Admin();
			$this->admin->init();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'pre_get_posts', array( $this, 'limit_main_query_posts_per_page' ) );
	}

	/**
	 * Ограничивает posts_per_page ГЛАВНОГО запроса страницы каталога (архив
	 * настроенного типа записи, его таксономии, либо блог) тем же значением
	 * из настроек плагина, что использует AJAX-эндпоинт /pf/v1/products. Без
	 * этого первая (не через AJAX) загрузка каталога показывала все записи
	 * сразу — тема сама ничего не ограничивает, плагин ограничивал только
	 * свой REST-запрос.
	 *
	 * Кроме визуального расхождения с AJAX-страницами это ломает саму
	 * пагинацию на уровне WordPress: если главный запрос не постраничный,
	 * max_num_pages = 1, и WordPress считает любой ?paged=2+ несуществующей
	 * страницей и отдаёт 404 — в том числе при обычной перезагрузке уже
	 * открытой пользователем страницы.
	 *
	 * @param WP_Query $query Запрос (хук общий, поэтому фильтруем сами).
	 */
	public function limit_main_query_posts_per_page( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = PF_Config::get_post_type();

		$is_target_archive = $query->is_post_type_archive( $post_type );

		if ( ! $is_target_archive ) {
			$taxonomies = ( 'product' === $post_type && function_exists( 'wc_get_object_taxonomies' ) )
				? wc_get_object_taxonomies( 'product' )
				: get_object_taxonomies( $post_type );

			$is_target_archive = ! empty( $taxonomies ) && $query->is_tax( $taxonomies );
		}

		if ( ! $is_target_archive && 'post' === $post_type ) {
			// У типа записи 'post' нет отдельного «архива» в смысле
			// is_post_type_archive() — его каталог это блог (главная лента).
			$is_target_archive = $query->is_home();
		}

		if ( ! $is_target_archive ) {
			return;
		}

		$query->set( 'posts_per_page', (int) PF_Config::get( 'posts_per_page', 12 ) );
	}

	/**
	 * Подключение JS/CSS на фронтенде и передача конфигурации в window.pfConfig.
	 *
	 * Плагин работает через HTML-атрибуты в разметке темы, поэтому не пытается
	 * угадать на какой именно странице есть [pf-form] — подключается на всём фронтенде.
	 */
	public function enqueue_scripts() {
		wp_enqueue_style(
			'pf-filter',
			PF_FILTER_URL . 'assets/css/pf-filter.css',
			array(),
			PF_FILTER_VERSION
		);

		wp_enqueue_script(
			'pf-filter',
			PF_FILTER_URL . 'assets/js/pf-filter.js',
			array(),
			PF_FILTER_VERSION,
			true
		);

		wp_localize_script(
			'pf-filter',
			'pfConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'pf/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}
}
