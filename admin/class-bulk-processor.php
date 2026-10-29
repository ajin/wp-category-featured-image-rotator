<?php
/**
 * Assigns missing featured images to existing published posts.
 *
 * Runs only when an administrator starts it from the admin screen. The
 * browser requests one small batch at a time (authenticated admin-ajax), so
 * work happens inside the user's own requests and can be cancelled between
 * batches. Published posts are walked oldest first for a deterministic
 * rotation; posts that already have a featured image are left alone, so an
 * interrupted run can simply be started again.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk assignment.
 */
final class Bulk_Processor {

	const AJAX_ACTION = 'cfir_bulk_batch';
	const NONCE       = 'cfir_bulk';
	const CAPABILITY  = 'manage_options';

	/**
	 * Posts checked per "Check existing posts" request.
	 */
	const SCAN_CHUNK = 200;

	/**
	 * Posts listed under "Show which posts".
	 */
	const PREVIEW_LIMIT = 50;

	/**
	 * Image assigner.
	 *
	 * @var Image_Assigner
	 */
	private Image_Assigner $assigner;

	/**
	 * Category resolver.
	 *
	 * @var Category_Resolver
	 */
	private Category_Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param Image_Assigner    $assigner Image assigner.
	 * @param Category_Resolver $resolver Category resolver.
	 */
	public function __construct( Image_Assigner $assigner, Category_Resolver $resolver ) {
		$this->assigner = $assigner;
		$this->resolver = $resolver;
	}

	/**
	 * Register hooks. Logged-in users only: there is no nopriv handler.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
	}

	/**
	 * Admin-ajax handler for one batch.
	 */
	public function handle_ajax(): void {
		// Verified here before reading input, and again in run_batch(), which is the testable entry point.
		check_ajax_referer( self::NONCE, 'nonce' );
		$result = $this->run_batch(
			array(
				'nonce'  => isset( $_POST['nonce'] ) ? sanitize_key( wp_unslash( $_POST['nonce'] ) ) : '',
				'step'   => isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '',
				'offset' => isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0,
				'want'   => isset( $_POST['want'] ) ? absint( wp_unslash( $_POST['want'] ) ) : 0,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Process one batch of published posts, oldest first.
	 *
	 * Step "check" counts posts without a featured image and describes up to
	 * `want` of them; step "assign" gives them images. Each request handles a
	 * bounded number of posts, so even very large sites are processed in the
	 * administrator's own requests without timeouts.
	 *
	 * @param array $request { nonce: string, step: string, offset: int, want: int } Already unslashed.
	 * @return array|\WP_Error
	 */
	public function run_batch( array $request ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new \WP_Error( 'cfir_forbidden', __( 'You are not allowed to do this.', 'wp-category-featured-image-rotator' ) );
		}
		if ( empty( $request['nonce'] ) || ! wp_verify_nonce( $request['nonce'], self::NONCE ) ) {
			return new \WP_Error( 'cfir_bad_nonce', __( 'The link you followed has expired. Please reload the page.', 'wp-category-featured-image-rotator' ) );
		}

		$offset = isset( $request['offset'] ) ? absint( $request['offset'] ) : 0;
		if ( 'check' === ( $request['step'] ?? '' ) ) {
			return $this->check( $offset, min( self::PREVIEW_LIMIT, absint( $request['want'] ?? 0 ) ) );
		}
		return $this->assign_batch( $offset );
	}

	/**
	 * Count posts without a featured image in one chunk (and how many of them
	 * can be given one now), and describe some.
	 *
	 * @param int $offset Posts already checked.
	 * @param int $want   How many more posts to describe for the preview.
	 * @return array
	 */
	private function check( int $offset, int $want ): array {
		$post_ids = $this->published_ids( self::SCAN_CHUNK, $offset );
		$use_own  = Settings::get()['content_image'];
		$missing  = 0;
		$addable  = 0;
		$rows     = array();
		foreach ( $post_ids as $post_id ) {
			if ( Settings::featured_image_id( $post_id ) ) {
				continue;
			}
			++$missing;
			$own  = $use_own ? Image_Assigner::image_in_content( get_post( $post_id ) ) : 0;
			$pool = $own ? array(
				'term_id' => 0,
				'source'  => 'content',
			) : $this->resolver->resolve( $post_id );
			if ( $pool ) {
				++$addable;
			}
			if ( count( $rows ) < $want ) {
				$rows[] = array(
					'title'  => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
					'edit'   => (string) get_edit_post_link( $post_id, 'raw' ),
					'date'   => get_the_date( '', $post_id ),
					'source' => $pool ? Category_Resolver::source_label( $pool ) : __( 'None: its categories have no images', 'wp-category-featured-image-rotator' ),
				);
			}
		}
		return array(
			'missing'     => $missing,
			'addable'     => $addable,
			'rows'        => $rows,
			'next_offset' => $offset + count( $post_ids ),
			'total'       => $this->count_published(),
			'done'        => count( $post_ids ) < self::SCAN_CHUNK,
		);
	}

	/**
	 * Give images to the posts without one in one small batch.
	 *
	 * @param int $offset Posts already processed.
	 * @return array
	 */
	private function assign_batch( int $offset ): array {
		/**
		 * Number of posts processed per "Add featured images" request (1–100).
		 *
		 * @param int $size Batch size.
		 */
		$size     = min( 100, max( 1, (int) apply_filters( 'cfir_bulk_batch_size', 20 ) ) );
		$post_ids = $this->published_ids( $size, $offset );

		$assigned = 0;
		$skipped  = 0;
		foreach ( $post_ids as $post_id ) {
			if ( Settings::featured_image_id( $post_id ) ) {
				continue;
			}
			if ( is_wp_error( $this->assigner->assign( $post_id ) ) ) {
				++$skipped;
			} else {
				++$assigned;
			}
		}

		return array(
			'assigned'    => $assigned,
			'skipped'     => $skipped,
			'next_offset' => $offset + count( $post_ids ),
			'total'       => $this->count_published(),
			'done'        => count( $post_ids ) < $size,
		);
	}

	/**
	 * Published post IDs, oldest first.
	 *
	 * @param int $limit  Maximum posts.
	 * @param int $offset Posts to skip.
	 * @return int[]
	 */
	private function published_ids( int $limit, int $offset ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => Settings::POST_TYPE,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'orderby'                => array(
					'date' => 'ASC',
					'ID'   => 'ASC',
				),
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$ids   = array_map( 'intval', $query->posts );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		return $ids;
	}

	/**
	 * Number of published posts.
	 *
	 * @return int
	 */
	private function count_published(): int {
		$counts = wp_count_posts( Settings::POST_TYPE );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}
}
