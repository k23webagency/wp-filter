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
