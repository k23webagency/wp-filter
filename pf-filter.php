<?php
/**
 * Plugin Name:       PF Filter
 * Plugin URI:        https://example.com/pf-filter
 * Description:       Движок AJAX-фильтрации каталога (товары WooCommerce, любой другой тип записи или блог), работающий через HTML-атрибуты pf-* в разметке темы. Не диктует внешний вид карточек и сетки.
 * Version:           1.12.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            PF Filter
 * Text Domain:       pf-filter
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

// Константы плагина.
define( 'PF_FILTER_VERSION', '1.12.0' );
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
 * Инициализация плагина. WooCommerce не обязателен — плагин умеет
 * фильтровать любой тип записи; там, где код специфичен для WooCommerce,
 * он сам проверяет function_exists()/class_exists() и деградирует.
 */
function pf_filter_init() {
	PF_Plugin::instance();
}
add_action( 'plugins_loaded', 'pf_filter_init' );

/**
 * Активация плагина — завести профиль по умолчанию, если профилей ещё нет
 * (ни домультипрофильной опции 'pf_filter_settings' для миграции, ни уже
 * созданных профилей).
 */
function pf_filter_activate() {
	if ( false === get_option( PF_Config::PROFILES_OPTION_KEY ) && false === get_option( 'pf_filter_settings' ) ) {
		add_option( PF_Config::PROFILES_OPTION_KEY, array( 'default' => PF_Config::get_defaults() ) );
	}
}
register_activation_hook( __FILE__, 'pf_filter_activate' );
