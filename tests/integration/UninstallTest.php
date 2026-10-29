<?php
/**
 * Assigned images survive deactivation and uninstall.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator\Tests\Integration;

use CategoryFeaturedImageRotator\Plugin;
use CategoryFeaturedImageRotator\Settings;
use CategoryFeaturedImageRotator\Tests\TestCase;

/**
 * Permanence of assignments.
 */
class UninstallTest extends TestCase {

	/**
	 * AC-09: with the plugin's hooks gone, posts keep their native featured image.
	 */
	public function test_images_remain_when_plugin_stops_running() {
		$this->act_as( 'editor' );
		$image = $this->create_image();
		$post  = $this->create_post( array( $this->create_pool( 'Chess', array( $image ) ) ) );

		remove_action( 'wp_after_insert_post', array( Plugin::instance()->assigner(), 'on_after_insert_post' ), 10 );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Edited while inactive',
			)
		);
		Plugin::instance()->assigner()->register();

		$this->assertSame( $image, $this->thumbnail( $post ) );
		$this->assertTrue( has_post_thumbnail( $post ) );
	}

	/**
	 * AC-10: uninstall removes plugin data but never featured images or attachments.
	 */
	public function test_uninstall_removes_plugin_data_and_keeps_featured_images() {
		$this->act_as( 'administrator' );
		$images = $this->create_images( 2 );
		$cat    = $this->create_pool( 'Chess', $images );
		Settings::set_pool_enabled( $cat, true );
		Settings::update( array( 'parent_fallback' => false ) );
		Settings::update( array( 'precedence' => array( $cat ) ) );
		Settings::global_pool_id( true );
		$post     = $this->create_post( array( $cat ) );
		$manual   = $this->create_image( 'manual.jpg' );
		$by_hand  = $this->create_post( array( $cat ), array( 'meta_input' => array( '_thumbnail_id' => $manual ) ) );
		$assigned = $this->thumbnail( $post );

		// On multisite, another site's data must be removed too.
		$other_site = is_multisite() ? self::factory()->blog->create() : 0;
		if ( $other_site ) {
			switch_to_blog( $other_site );
			Settings::update( array( 'enabled' => false ) );
			restore_current_blog();
		}

		// Run the real uninstall entry point, as WordPress does.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-category-featured-image-rotator/wp-category-featured-image-rotator.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		wp_cache_flush();

		$this->assertSame( $images[0], $assigned );
		$this->assertSame( $assigned, $this->thumbnail( $post ) );
		$this->assertSame( $manual, $this->thumbnail( $by_hand ) );
		foreach ( $images as $image ) {
			$this->assertInstanceOf( \WP_Post::class, get_post( $image ) );
		}

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Settings::HISTORY_OPTION ) );
		foreach ( array( Settings::META_IMAGES, Settings::META_ENABLED, Settings::META_ROTATION ) as $key ) {
			$this->assertFalse( metadata_exists( 'term', $cat, $key ), $key );
		}
		$this->assertSame( 0, Settings::global_pool_id() );
		$this->assertFalse( metadata_exists( 'post', $post, Settings::POST_META_ADDED ), 'The marker goes; the image stays.' );
		$this->assertInstanceOf( \WP_Term::class, get_term( $cat, 'category' ), 'Categories themselves are untouched.' );

		if ( $other_site ) {
			switch_to_blog( $other_site );
			$this->assertFalse( get_option( Settings::OPTION ), 'Other sites in the network are cleaned up.' );
			restore_current_blog();
		}
	}
}
