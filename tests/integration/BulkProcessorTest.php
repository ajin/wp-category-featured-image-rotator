<?php
/**
 * Bulk assignment for existing posts.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator\Tests\Integration;

use CategoryFeaturedImageRotator\Bulk_Processor;
use CategoryFeaturedImageRotator\Plugin;
use CategoryFeaturedImageRotator\Settings;
use CategoryFeaturedImageRotator\Tests\TestCase;

/**
 * Bulk processor.
 */
class BulkProcessorTest extends TestCase {

	/**
	 * Pool images.
	 *
	 * @var int[]
	 */
	private array $images = array();

	/**
	 * Category with a pool.
	 *
	 * @var int
	 */
	private int $cat = 0;

	/**
	 * Create a pool and log in as an administrator.
	 */
	public function set_up() {
		parent::set_up();
		$this->act_as( 'administrator' );
		$this->images = $this->create_images( 3 );
		$this->cat    = $this->create_pool( 'Chess', $this->images );
	}

	/**
	 * Create published posts without images (automation off), oldest first.
	 *
	 * @param int   $count      Number of posts.
	 * @param int[] $categories Categories.
	 * @return int[]
	 */
	private function create_existing_posts( int $count, array $categories ): array {
		Settings::update( array( 'enabled' => false ) );
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->create_post(
				$categories,
				array( 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '2020-01-01' ) + $i * HOUR_IN_SECONDS ) )
			);
		}
		Settings::update( array( 'enabled' => true ) );
		return $ids;
	}

	/**
	 * One request, as the admin screen sends it.
	 *
	 * @param string $step   "check" or "assign".
	 * @param int    $offset Posts already handled.
	 * @param int    $want   Posts to describe (check step).
	 * @return array
	 */
	private function batch( string $step, int $offset = 0, int $want = 0 ): array {
		$result = Plugin::instance()->bulk()->run_batch(
			array(
				'nonce'  => wp_create_nonce( Bulk_Processor::NONCE ),
				'step'   => $step,
				'offset' => $offset,
				'want'   => $want,
			)
		);
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * Run a step's batches until done, summing the counts.
	 *
	 * @param string $step "check" or "assign".
	 * @param int    $want Posts to describe (check step).
	 * @return array Totals: missing + rows (check) or assigned + skipped (assign).
	 */
	private function run_all( string $step = 'assign', int $want = 0 ): array {
		$totals = array(
			'missing'  => 0,
			'addable'  => 0,
			'rows'     => array(),
			'assigned' => 0,
			'skipped'  => 0,
		);
		$offset = 0;
		do {
			$result = $this->batch( $step, $offset, $want - count( $totals['rows'] ) );
			foreach ( array( 'missing', 'addable', 'assigned', 'skipped' ) as $key ) {
				$totals[ $key ] += $result[ $key ] ?? 0;
			}
			$totals['rows'] = array_merge( $totals['rows'], $result['rows'] ?? array() );
			$offset         = $result['next_offset'];
		} while ( ! $result['done'] );
		return $totals;
	}

	/**
	 * AC-11: 100 existing posts all receive images, oldest first, in rotation.
	 */
	public function test_assigns_images_to_existing_posts_oldest_first() {
		$posts = $this->create_existing_posts( 100, array( $this->cat ) );
		$this->assertSame( 100, $this->run_all( 'check' )['missing'] );

		$this->assertSame( 100, $this->run_all()['assigned'] );

		foreach ( $posts as $i => $post_id ) {
			$this->assertSame( $this->images[ $i % 3 ], $this->thumbnail( $post_id ), "Post #{$i}" );
		}
		$this->assertSame( 0, $this->run_all( 'check' )['missing'] );
	}

	/**
	 * Existing images are kept, posts without a pool are skipped and reported.
	 */
	public function test_preserves_existing_images_and_reports_skipped_posts() {
		$manual     = $this->create_image( 'manual.jpg' );
		$empty      = $this->create_category( 'Empty' );
		$with_image = $this->create_existing_posts( 1, array( $this->cat ) )[0];
		$no_pool    = $this->create_existing_posts( 1, array( $empty ) )[0];
		$eligible   = $this->create_existing_posts( 1, array( $this->cat ) )[0];
		set_post_thumbnail( $with_image, $manual );

		$check = $this->run_all( 'check', 5 );
		$this->assertSame( 2, $check['missing'] );
		$this->assertSame( 1, $check['addable'], 'The post whose category has no images is known up front.' );
		$this->assertSame( 'None: its categories have no images', $check['rows'][0]['source'] );

		$result = $this->run_all();

		$this->assertSame( $manual, $this->thumbnail( $with_image ) );
		$this->assertSame( 0, $this->thumbnail( $no_pool ) );
		$this->assertSame( $this->images[0], $this->thumbnail( $eligible ) );
		$this->assertSame( 1, $result['assigned'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * An interrupted run can be started again without reprocessing or skipping posts.
	 */
	public function test_interrupted_run_resumes_safely() {
		add_filter( 'cfir_bulk_batch_size', static fn() => 4 );
		$posts = $this->create_existing_posts( 10, array( $this->cat ) );

		$this->batch( 'assign' );
		$after_first = array_map( array( $this, 'thumbnail' ), $posts );
		$this->assertSame( 4, count( array_filter( $after_first ) ) );

		// Cancelled; the administrator starts again from the beginning.
		$this->run_all();

		$assigned = array_map( array( $this, 'thumbnail' ), $posts );
		$this->assertSame( array_slice( $after_first, 0, 4 ), array_slice( $assigned, 0, 4 ) );
		foreach ( $posts as $i => $post_id ) {
			$this->assertSame( $this->images[ $i % 3 ], $assigned[ $i ] );
		}
	}

	/**
	 * Checking counts posts without an image and describes the oldest ones,
	 * without changing anything.
	 */
	public function test_check_counts_and_describes_posts_without_changing_them() {
		$posts = $this->create_existing_posts( 3, array( $this->cat ) );
		wp_update_post(
			array(
				'ID'         => $posts[0],
				'post_title' => 'Rock & Roll',
			)
		);

		$result = $this->run_all( 'check', 2 );

		$this->assertSame( 3, $result['missing'] );
		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( 'Rock & Roll', $result['rows'][0]['title'], 'Plain text for the script to insert safely.' );
		$this->assertSame( 'Chess', $result['rows'][0]['source'] );
		$this->assertStringContainsString( 'post=' . $posts[0], $result['rows'][0]['edit'] );
		$this->assertSame( 0, $this->thumbnail( $posts[0] ) );
	}

	/**
	 * Checking only reads a bounded number of posts per request.
	 */
	public function test_check_is_batched() {
		$this->create_existing_posts( 3, array( $this->cat ) );

		$first = $this->batch( 'check', 0, 50 );

		$this->assertTrue( $first['done'] );
		$this->assertSame( 3, $first['next_offset'] );

		add_filter( 'cfir_bulk_batch_size', static fn() => 100000 );
		$this->create_existing_posts( 120, array( $this->cat ) );
		$this->assertSame( 100, $this->batch( 'assign' )['next_offset'], 'Batch size is capped at 100.' );
	}

	/**
	 * Only administrators with a valid nonce can run batches.
	 */
	public function test_requires_capability_and_nonce() {
		$posts = $this->create_existing_posts( 1, array( $this->cat ) );
		$bulk  = Plugin::instance()->bulk();

		$this->assertSame( 'cfir_bad_nonce', $bulk->run_batch( array( 'offset' => 0 ) )->get_error_code() );

		$this->act_as( 'editor' );
		$result = $bulk->run_batch(
			array(
				'nonce'  => wp_create_nonce( Bulk_Processor::NONCE ),
				'offset' => 0,
			)
		);
		$this->assertSame( 'cfir_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->thumbnail( $posts[0] ) );
	}

	/**
	 * Bulk assignment and its preview use a post's own image when that
	 * setting is on.
	 */
	public function test_uses_image_in_post_for_existing_posts() {
		$inline       = $this->create_image( 'inline.jpg' );
		list( $post ) = $this->create_existing_posts( 1, array( $this->cat ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => '<p><img class="wp-image-' . $inline . '" src="' . wp_get_attachment_url( $inline ) . '" /></p>',
			)
		);
		Settings::update( array( 'content_image' => true ) );

		$this->assertSame( 'Image in the post', $this->run_all( 'check', 1 )['rows'][0]['source'] );

		$this->run_all();
		$this->assertSame( $inline, $this->thumbnail( $post ) );
	}
}
