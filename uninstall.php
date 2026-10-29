<?php
/**
 * Uninstall: remove the plugin's settings, history, category images and
 * rotation state.
 *
 * Featured images (_thumbnail_id) are never touched: they belong to the posts.
 * Media Library attachments are never deleted.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-settings.php';

/**
 * Remove the plugin's data from the current site.
 */
function uninstall_site(): void {
	delete_option( Settings::OPTION );
	delete_option( Settings::HISTORY_OPTION );

	foreach ( array( Settings::META_IMAGES, Settings::META_ENABLED, Settings::META_ROTATION ) as $meta_key ) {
		delete_metadata( 'term', 0, $meta_key, '', true );
	}
	// Only the plugin's marker: the featured images themselves stay.
	delete_metadata( 'post', 0, Settings::POST_META_ADDED, '', true );

	Settings::ensure_pool_taxonomy();
	$pool_terms = get_terms(
		array(
			'taxonomy'   => Settings::POOL_TAXONOMY,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	foreach ( is_array( $pool_terms ) ? $pool_terms : array() as $term_id ) {
		wp_delete_term( (int) $term_id, Settings::POOL_TAXONOMY );
	}
}

// On multisite, clean up every site, not just the current one.
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $cfir_site_id ) {
		switch_to_blog( (int) $cfir_site_id );
		uninstall_site();
		restore_current_blog();
	}
} else {
	uninstall_site();
}
