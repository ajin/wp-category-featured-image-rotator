<?php
/**
 * Runtime checks for the project rules in CLAUDE.md.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator\Tests\Integration;

use CategoryFeaturedImageRotator\Tests\TestCase;

/**
 * Plugin-wide boundary tests.
 */
class PluginBoundariesTest extends TestCase {

	/**
	 * The plugin bootstrap file is loaded by the test suite.
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'CFIR_VERSION' ) );
	}

	/**
	 * Logged-out visitors get no AJAX or admin-post handlers.
	 */
	public function test_registers_no_unauthenticated_ajax_or_admin_post_handlers() {
		$nopriv = array_filter(
			array_keys( $GLOBALS['wp_filter'] ),
			static fn( $hook ) => str_starts_with( $hook, 'wp_ajax_nopriv_' ) || str_starts_with( $hook, 'admin_post_nopriv_' )
		);

		$this->assertSame( array(), array_values( $nopriv ) );
	}

	/**
	 * The plugin exposes no REST endpoints.
	 */
	public function test_registers_no_rest_routes() {
		$plugin_namespaces = array_filter(
			rest_get_server()->get_namespaces(),
			static fn( $ns ) => str_contains( $ns, 'cfir' ) || str_contains( $ns, 'featured-image-rotator' )
		);

		$this->assertSame( array(), array_values( $plugin_namespaces ) );
	}

	/**
	 * Saving a categorised post never reaches the network.
	 */
	public function test_creating_a_post_makes_no_external_requests() {
		$this->act_as( 'editor' );
		$category_id = self::factory()->category->create();

		$post_id = wp_insert_post(
			array(
				'post_title'    => 'Boundary check',
				'post_status'   => 'publish',
				'post_category' => array( $category_id ),
			)
		);

		$this->assertIsInt( $post_id );
		// The HTTP guard in TestCase::tear_down() asserts no requests were made.
	}

	/**
	 * Outside wp-admin the plugin hooks exactly one callback, which starts
	 * with a capability check: nothing else of the plugin can run.
	 */
	public function test_only_capability_gated_hooks_outside_admin() {
		$this->assertFalse( is_admin() );

		$hooked = array();
		foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && is_object( $function[0] ) && str_starts_with( get_class( $function[0] ), 'CategoryFeaturedImageRotator\\' ) && ! $function[0] instanceof TestCase ) {
						$hooked[] = $hook . ':' . $function[1];
					}
				}
			}
		}
		sort( $hooked );

		$this->assertSame( array( 'wp_after_insert_post:on_after_insert_post' ), $hooked );
	}

	/**
	 * The plugin adds nothing to REST: no registered meta, so post responses
	 * and the schema never mention it.
	 */
	public function test_adds_nothing_to_rest_responses() {
		$plugin_meta = array_filter(
			array_keys( get_registered_meta_keys( 'post', 'post' ) ),
			static fn( $key ) => str_contains( $key, 'cfir' )
		);
		$this->assertSame( array(), array_values( $plugin_meta ) );

		$post_id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$this->assertStringNotContainsString( 'cfir', (string) wp_json_encode( $response->get_data() ) );
	}
}
