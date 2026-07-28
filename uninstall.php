<?php
/**
 * Uninstall PF Filter — очистка wp_options при удалении плагина.
 *
 * @package PF_Filter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pf_filter_settings' );

// На случай мультисайта — то же для каждого сайта в сети.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'pf_filter_settings' );
		restore_current_blog();
	}
}
