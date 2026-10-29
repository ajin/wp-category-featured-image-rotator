<?php
/**
 * Base test case for every plugin test.
 *
 * Guards that apply to every test automatically:
 * - Any outbound HTTP request fails the test (rule: no external calls).
 * - WP_UnitTestCase already fails on unexpected _deprecated_*() and
 *   _doing_it_wrong() notices, which catches core API drift on WP upgrades.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator\Tests;

/**
 * Base test case.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * URLs of HTTP requests attempted during the current test.
	 *
	 * @var string[]
	 */
	private array $http_requests = array();

	/**
	 * Install the HTTP guard.
	 */
	public function set_up() {
		parent::set_up();
		$this->http_requests = array();
		add_filter( 'pre_http_request', array( $this, 'block_http_request' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Fail the test if anything tried to reach the network.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'block_http_request' ), PHP_INT_MAX );
		$requests = $this->http_requests;
		parent::tear_down();
		$this->assertSame( array(), $requests, 'Outbound HTTP requests are not allowed.' );
	}

	/**
	 * Short-circuit and record any HTTP request.
	 *
	 * @param false|array|\WP_Error $response Pre-empted response.
	 * @param array                 $args     Request arguments.
	 * @param string                $url      Request URL.
	 * @return \WP_Error
	 */
	public function block_http_request( $response, $args, $url ) {
		$this->http_requests[] = $url;
		return new \WP_Error( 'cfir_http_blocked', 'Outbound HTTP blocked in tests.' );
	}

	/**
	 * Log in as a fresh user with the given role and return the user ID.
	 *
	 * @param string $role Role name.
	 * @return int
	 */
	protected function act_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Create an image attachment.
	 *
	 * @param string $name File name.
	 * @return int Attachment ID.
	 */
	protected function create_image( string $name = 'image.jpg' ): int {
		return self::factory()->attachment->create_object(
			array(
				'file'           => $name,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $name,
			)
		);
	}

	/**
	 * Create several image attachments.
	 *
	 * @param int    $count  How many.
	 * @param string $prefix File name prefix.
	 * @return int[]
	 */
	protected function create_images( int $count, string $prefix = 'image' ): array {
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->create_image( sprintf( '%s-%02d.jpg', $prefix, $i ) );
		}
		return $ids;
	}

	/**
	 * Create a category.
	 *
	 * @param string $name   Name.
	 * @param int    $parent_id Parent category ID.
	 * @return int
	 */
	protected function create_category( string $name, int $parent_id = 0 ): int {
		return self::factory()->category->create(
			array(
				'name'   => $name,
				'parent' => $parent_id,
			)
		);
	}

	/**
	 * Create a category with an image pool.
	 *
	 * @param string $name   Name.
	 * @param int[]  $images Attachment IDs.
	 * @param int    $parent_id Parent category ID.
	 * @return int
	 */
	protected function create_pool( string $name, array $images, int $parent_id = 0 ): int {
		$term_id = $this->create_category( $name, $parent_id );
		\CategoryFeaturedImageRotator\Settings::update_images( $term_id, $images );
		return $term_id;
	}

	/**
	 * Insert a post as the current user.
	 *
	 * @param int[] $categories Category IDs.
	 * @param array $args       Extra wp_insert_post() args.
	 * @return int
	 */
	protected function create_post( array $categories, array $args = array() ): int {
		return (int) wp_insert_post(
			array_merge(
				array(
					'post_title'    => 'Post',
					'post_status'   => 'publish',
					'post_category' => $categories,
				),
				$args
			),
			true
		);
	}

	/**
	 * Featured image of a post (0 when none).
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	protected function thumbnail( int $post_id ): int {
		return (int) get_post_thumbnail_id( $post_id );
	}
}
