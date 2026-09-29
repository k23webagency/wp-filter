<?php
/**
 * Uninstall PF Filter — очистка wp_options при удалении плагина.
 *
 * @package PF_Filter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Удалить данные плагина на текущем сайте: опции фильтра и модуля поиска,
 * таблицы поискового индекса. Плагин при удалении не загружен (классов
 * нет), поэтому имена опций/таблиц здесь продублированы из
 * PF_Search_Config / PF_Search_Index.
 */
function pf_filter_uninstall_site() {
	global $wpdb;

	$options = array(
		'pf_filter_settings',
		'pf_filter_profiles',
		'pf_search_settings',
		'pf_search_profiles',
		'pf_search_db_version',
		'pf_search_reindex_state',
		'pf_search_queue',
		'pf_search_stats',
		'pf_search_index_signature',
		'pf_search_acf_field_ids',
		'pf_search_type_ids',
		'pf_search_batch_lock',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	foreach ( array( 'pf_search_terms', 'pf_search_postings', 'pf_search_docs' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	wp_clear_scheduled_hook( 'pf_search_reindex_batch' );
	wp_clear_scheduled_hook( 'pf_search_maintenance' );
}

pf_filter_uninstall_site();

// На случай мультисайта — то же для каждого сайта в сети.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		pf_filter_uninstall_site();
		restore_current_blog();
	}
}
