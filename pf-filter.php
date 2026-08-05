<?php
/**
 * Plugin Name:       PF Filter
 * Plugin URI:        https://example.com/pf-filter
 * Description:       Движок AJAX-фильтрации каталога, который читает HTML-атрибуты pf-* прямо в разметке темы: товары WooCommerce, любой тип записи, произвольное число независимых профилей и блоков фильтра на одной странице. Разметка и стилизация карточек, сетки и фильтров — полностью в руках темы; плагин отвечает за данные, состояние и AJAX-обновления.
 * Version:           2.2.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Kirill Andreev
 * Author URI:        https://t.me/lucius_wd
 * Text Domain:       pf-filter
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

// Константы плагина.
define( 'PF_FILTER_VERSION', '2.2.2' );
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
 * созданных профилей), плюс завести расписание проактивного обновления
 * персистентного кэша PF_Attributes::scan_custom_attributes() (см. её
 * комментарий про CUSTOM_ATTRIBUTES_TRANSIENT).
 */
function pf_filter_activate() {
	if ( false === get_option( PF_Config::PROFILES_OPTION_KEY ) && false === get_option( 'pf_filter_settings' ) ) {
		add_option( PF_Config::PROFILES_OPTION_KEY, array( 'default' => PF_Config::get_defaults() ) );
	}

	if ( ! wp_next_scheduled( 'pf_filter_refresh_custom_attributes_cache' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'pf_filter_refresh_custom_attributes_cache' );
	}
}
register_activation_hook( __FILE__, 'pf_filter_activate' );

/**
 * Деактивация плагина — снять расписание из pf_filter_activate(), иначе
 * WP-Cron продолжал бы пытаться его выполнять (обработчик отвязан вместе с
 * остальными хуками плагина) до следующей активации.
 */
function pf_filter_deactivate() {
	wp_clear_scheduled_hook( 'pf_filter_refresh_custom_attributes_cache' );
}
register_deactivation_hook( __FILE__, 'pf_filter_deactivate' );
