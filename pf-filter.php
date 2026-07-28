<?php
/**
 * Plugin Name:       PF Filter
 * Plugin URI:        https://example.com/pf-filter
 * Description:       Движок фильтрации каталога WooCommerce, работающий через HTML-атрибуты pf-* в разметке темы. Не диктует внешний вид карточек и сетки.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * Author:            PF Filter
 * Text Domain:       pf-filter
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

// Константы плагина.
define( 'PF_FILTER_VERSION', '1.3.0' );
define( 'PF_FILTER_FILE', __FILE__ );
define( 'PF_FILTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'PF_FILTER_URL', plugin_dir_url( __FILE__ ) );
define( 'PF_FILTER_BASENAME', plugin_basename( __FILE__ ) );

// Источник автообновлений — репозиторий GitHub.
define( 'PF_GITHUB_OWNER', 'k23webagency' );
define( 'PF_GITHUB_REPO', 'wp-filter' );

/**
 * Автозагрузка классов плагина.
 *
 * Ожидаемый формат имени файла: class-pf-{name}.php внутри includes/.
 * Ожидаемый формат имени класса: PF_{Name}.
 *
 * @param string $class_name Имя класса.
 */
function pf_filter_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'PF_' ) ) {
		return;
	}

	$file_slug = strtolower( str_replace( '_', '-', $class_name ) );
	$file_path = PF_FILTER_PATH . 'includes/class-' . $file_slug . '.php';

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}
spl_autoload_register( 'pf_filter_autoload' );

// Автообновление из релизов GitHub (Plugin Update Checker v5).
require_once PF_FILTER_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

$pf_filter_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/' . PF_GITHUB_OWNER . '/' . PF_GITHUB_REPO . '/',
	__FILE__,
	'pf-filter'
);

$pf_filter_update_checker->setBranch( 'main' );
// Метод называется getVcsApi() (а не getVaultProvider() — такого метода в библиотеке нет).
$pf_filter_update_checker->getVcsApi()->enableReleaseAssets();

/**
 * Проверка что WooCommerce активен, инициализация плагина.
 */
function pf_filter_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'pf_filter_woocommerce_missing_notice' );
		return;
	}

	PF_Plugin::instance();
}
add_action( 'plugins_loaded', 'pf_filter_init' );

/**
 * Уведомление в админке если WooCommerce не активен.
 */
function pf_filter_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'PF Filter требует активный и установленный плагин WooCommerce.', 'pf-filter' ); ?></p>
	</div>
	<?php
}

/**
 * Активация плагина — регистрация настроек по умолчанию.
 */
function pf_filter_activate() {
	if ( false === get_option( 'pf_filter_settings' ) ) {
		add_option( 'pf_filter_settings', PF_Config::get_defaults() );
	}
}
register_activation_hook( __FILE__, 'pf_filter_activate' );
