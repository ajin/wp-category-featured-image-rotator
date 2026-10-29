<?php
/**
 * Round-robin and shuffle rotation, persistence and concurrency.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator\Tests\Integration;

use CategoryFeaturedImageRotator\Rotation;
use CategoryFeaturedImageRotator\Settings;
use CategoryFeaturedImageRotator\Tests\TestCase;

/**
 * Rotation behaviour.
 */
class RotationTest extends TestCase {

	/**
	 * Log in as an editor for every test.
	 */
	public function set_up() {
		parent::set_up();
		$this->act_as( 'editor' );
	}

	/**
	 * Publish posts in a category and return their featured images.
	 *
	 * @param int $term_id Category ID.
	 * @param int $count   Number of posts.
	 * @return int[]
	 */
	private function publish_many( int $term_id, int $count ): array {
		$images = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$images[] = $this->thumbnail( $this->create_post( array( $term_id ) ) );
		}
		return $images;
	}

	/**
	 * AC-01 / AC-02: four images assigned A, B, C, D, A.
	 */
	public function test_round_robin_assigns_images_in_order_and_wraps() {
		list( $a, $b, $c, $d ) = $this->create_images( 4, 'chess' );
		$chess                 = $this->create_pool( 'Chess', array( $a, $b, $c, $d ) );

		$this->assertSame( array( $a, $b, $c, $d ), Settings::get_images( $chess ) );
		$this->assertSame( array( $a, $b, $c, $d, $a ), $this->publish_many( $chess, 5 ) );
	}

	/**
	 * Assigning from one category never moves another category's rotation.
	 */
	public function test_each_category_rotates_independently() {
		$shared   = $this->create_image( 'shared.jpg' );
		$tech_img = $this->create_images( 2, 'tech' );
		$sec_img  = $this->create_images( 2, 'sec' );
		$tech     = $this->create_pool( 'Technology', array( $shared, $tech_img[0], $tech_img[1] ) );
		$security = $this->create_pool( 'Security', array( $shared, $sec_img[0], $sec_img[1] ) );

		$this->assertSame( array( $shared, $sec_img[0] ), $this->publish_many( $security, 2 ) );
		$this->assertSame( array( $shared, $tech_img[0] ), $this->publish_many( $tech, 2 ) );
		$this->assertSame( array( $sec_img[1] ), $this->publish_many( $security, 1 ) );
	}

	/**
	 * A pool with one image always reuses it.
	 */
	public function test_single_image_is_reused() {
		$only = $this->create_image();
		$cat  = $this->create_pool( 'Solo', array( $only ) );

		$this->assertSame( array( $only, $only, $only ), $this->publish_many( $cat, 3 ) );
	}

	/**
	 * AC-07 / AC-08: removing images keeps the rotation going and leaves posts alone.
	 */
	public function test_removing_images_continues_rotation_and_keeps_assigned_images() {
		list( $a, $b, $c, $d ) = $this->create_images( 4 );
		$cat                   = $this->create_pool( 'Cat', array( $a, $b, $c, $d ) );

		$this->create_post( array( $cat ) );
		$post_b = $this->create_post( array( $cat ) );
		$this->assertSame( $b, $this->thumbnail( $post_b ) );

		// Remove the last-used image: the image that took its place is next.
		Settings::update_images( $cat, array( $a, $c, $d ) );
		$post_c = $this->create_post( array( $cat ) );
		$this->assertSame( $c, $this->thumbnail( $post_c ) );
		$this->assertSame( $b, $this->thumbnail( $post_b ), 'Removing an image from a pool must not touch posts using it.' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $b ), 'Removing an image from a pool must not delete the attachment.' );

		Settings::update_images( $cat, array( $a, $d ) );
		$this->assertSame( array( $d, $a ), $this->publish_many( $cat, 2 ) );
		$this->assertSame( $c, $this->thumbnail( $post_c ) );
	}

	/**
	 * Reordering continues after the last used image.
	 */
	public function test_reordering_continues_after_last_used_image() {
		list( $a, $b, $c, $d ) = $this->create_images( 4 );
		$cat                   = $this->create_pool( 'Cat', array( $a, $b, $c, $d ) );
		$this->publish_many( $cat, 1 );

		Settings::update_images( $cat, array( $c, $a, $d, $b ) );

		$this->assertSame( array( $d, $b, $c ), $this->publish_many( $cat, 3 ) );
	}

	/**
	 * Adding images does not reset the rotation or change existing posts.
	 */
	public function test_adding_images_keeps_rotation_and_existing_posts() {
		list( $a, $b, $c ) = $this->create_images( 3 );
		$cat               = $this->create_pool( 'Cat', array( $a, $b ) );
		$first             = $this->create_post( array( $cat ) );

		Settings::update_images( $cat, array( $a, $b, $c ) );

		$this->assertSame( array( $b, $c, $a ), $this->publish_many( $cat, 3 ) );
		$this->assertSame( $a, $this->thumbnail( $first ) );
	}

	/**
	 * Deleted attachments in a pool are skipped.
	 */
	public function test_deleted_attachment_is_skipped() {
		list( $a, $b, $c ) = $this->create_images( 3 );
		$cat               = $this->create_pool( 'Cat', array( $a, $b, $c ) );
		wp_delete_attachment( $b, true );

		$this->assertSame( array( $a, $c, $a ), $this->publish_many( $cat, 3 ) );
	}

	/**
	 * A failed assignment does not consume a rotation position.
	 */
	public function test_failed_assignment_does_not_advance_rotation() {
		list( $a, $b ) = $this->create_images( 2 );
		$cat           = $this->create_pool( 'Cat', array( $a, $b ) );

		$block = static fn( $check, $object_id, $meta_key ) => '_thumbnail_id' === $meta_key ? false : $check;
		add_filter( 'update_post_metadata', $block, 10, 3 );
		add_filter( 'add_post_metadata', $block, 10, 3 );
		$failed = $this->create_post( array( $cat ) );
		remove_filter( 'update_post_metadata', $block, 10 );
		remove_filter( 'add_post_metadata', $block, 10 );

		$this->assertSame( 0, $this->thumbnail( $failed ) );
		$this->assertSame( array( $a, $b ), $this->publish_many( $cat, 2 ) );
	}

	/**
	 * AC-12: a post published while another is mid-assignment gets a different
	 * image, and the rotation stays consistent.
	 */
	public function test_concurrent_publish_assigns_distinct_images() {
		list( $a, $b, $c ) = $this->create_images( 3 );
		$cat               = $this->create_pool( 'Cat', array( $a, $b, $c ) );

		$concurrent = 0;
		$interrupt  = function () use ( &$concurrent, &$interrupt, $cat ) {
			// Simulate another request publishing a post in the same category
			// after this one picked its image but before it advanced the rotation.
			remove_action( 'cfir_before_rotation_commit', $interrupt );
			$concurrent = $this->create_post( array( $cat ) );
		};
		add_action( 'cfir_before_rotation_commit', $interrupt );

		$outer = $this->create_post( array( $cat ) );

		$this->assertSame( $a, $this->thumbnail( $concurrent ) );
		$this->assertSame( $b, $this->thumbnail( $outer ) );
		$this->assertSame( array( $c, $a ), $this->publish_many( $cat, 2 ) );
	}

	/**
	 * If the image cannot be set on a retry, the image set by the earlier
	 * attempt is removed rather than left on the post untracked.
	 */
	public function test_failed_retry_leaves_no_untracked_image() {
		list( $a, $b ) = $this->create_images( 2 );
		$cat           = $this->create_pool( 'Cat', array( $a, $b ) );

		$block     = static fn( $check, $object_id, $meta_key ) => '_thumbnail_id' === $meta_key ? false : $check;
		$interrupt = function () use ( &$interrupt, $block, $cat ) {
			remove_action( 'cfir_before_rotation_commit', $interrupt );
			$this->create_post( array( $cat ) );
			add_filter( 'update_post_metadata', $block, 10, 3 );
		};
		add_action( 'cfir_before_rotation_commit', $interrupt );

		$outer = $this->create_post( array( $cat ) );
		remove_filter( 'update_post_metadata', $block, 10 );

		$this->assertSame( 0, $this->thumbnail( $outer ) );
		$this->assertSame( array( $b ), $this->publish_many( $cat, 1 ), 'The failed post did not consume a position.' );
	}

	/**
	 * Rotation state persists in term meta.
	 */
	public function test_rotation_state_is_stored_in_term_meta() {
		list( $a, $b ) = $this->create_images( 2 );
		$cat           = $this->create_pool( 'Cat', array( $a, $b ) );
		$this->publish_many( $cat, 1 );

		list( , $state ) = Rotation::read( $cat );
		$this->assertSame( $a, $state['l'] );
		$this->assertSame( 0, $state['p'] );
		$this->assertSame( 1, $state['s'] );
	}

	/**
	 * Resetting restarts from the first image without touching posts.
	 */
	public function test_reset_rotation_restarts_from_first_image() {
		list( $a, $b, $c ) = $this->create_images( 3 );
		$cat               = $this->create_pool( 'Cat', array( $a, $b, $c ) );
		$posts             = array( $this->create_post( array( $cat ) ), $this->create_post( array( $cat ) ) );

		$this->assertTrue( Rotation::reset( $cat ) );

		$this->assertSame( array( $a ), $this->publish_many( $cat, 1 ) );
		$this->assertSame( $b, $this->thumbnail( $posts[1] ) );
	}

	/**
	 * Shuffle mode uses every image once per cycle and never repeats back to back.
	 */
	public function test_shuffle_uses_each_image_once_per_cycle() {
		Settings::update( array( 'rotation_mode' => 'shuffle' ) );
		$images = $this->create_images( 4 );
		$cat    = $this->create_pool( 'Cat', $images );

		$assigned = $this->publish_many( $cat, 12 );

		foreach ( array_chunk( $assigned, 4 ) as $cycle ) {
			$this->assertEqualsCanonicalizing( $images, $cycle );
		}
		foreach ( array_slice( $assigned, 1 ) as $i => $image ) {
			$this->assertNotSame( $assigned[ $i ], $image, 'Consecutive posts got the same image.' );
		}
	}
}
