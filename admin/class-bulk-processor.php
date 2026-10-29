<?php
/**
 * Assigns missing featured images to existing published posts.
 *
 * Runs only when an administrator starts it from the admin screen. The
 * browser requests one small batch at a time (authenticated admin-ajax), so
 * work happens inside the user's own requests and can be stopped between
 * batches. Posts can be narrowed down by category and publish date, and then
 * either the selected posts or all matching posts get an image. Posts are
 * handled oldest first for a predictable order; posts that already have a
 * featured image are left alone, so an interrupted run can simply be started
 * again.
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
	 * Posts checked per "Find posts" request.
	 */
	const SCAN_CHUNK = 200;

	/**
	 * Most posts listed for selection.
	 */
	const PREVIEW_LIMIT = 100;

	/**
	 * Most selected posts handled per request.
	 */
	const SELECTED_PER_REQUEST = 20;

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
		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$result = $this->run_batch(
			array(
				'nonce'    => isset( $_POST['nonce'] ) ? sanitize_key( wp_unslash( $_POST['nonce'] ) ) : '',
				'step'     => isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '',
				'offset'   => isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0,
				'want'     => isset( $_POST['want'] ) ? absint( wp_unslash( $_POST['want'] ) ) : 0,
				'category' => isset( $_POST['category'] ) ? absint( wp_unslash( $_POST['category'] ) ) : 0,
				'from'     => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '',
				'to'       => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '',
				'ids'      => $ids,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Process one batch.
	 *
	 * Steps:
	 * - "check": count matching posts without a featured image (and how many
	 *   can be given one now), and describe up to `want` of them;
	 * - "assign": give images to all matching posts, one batch at a time;
	 * - "assign_selected": give images to the posts in `ids`.
	 *
	 * @param array $request {
	 *     Already unslashed.
	 *
	 *     @type string $nonce    Nonce.
	 *     @type string $step     "check", "assign" or "assign_selected".
	 *     @type int    $offset   Posts already handled.
	 *     @type int    $want     Posts to describe (check step).
	 *     @type int    $category Category ID; 0 for all. Includes subcategories.
	 *     @type string $from     Earliest publish date, Y-m-d; '' for none.
	 *     @type string $to       Latest publish date, Y-m-d; '' for none.
	 *     @type int[]  $ids      Selected post IDs (assign_selected step).
	 * }
	 * @return array|\WP_Error
	 */
	public function run_batch( array $request ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new \WP_Error( 'cfir_forbidden', __( 'You are not allowed to do this.', 'wp-category-featured-image-rotator' ) );
		}
		if ( empty( $request['nonce'] ) || ! wp_verify_nonce( $request['nonce'], self::NONCE ) ) {
			return new \WP_Error( 'cfir_bad_nonce', __( 'The link you followed has expired. Please reload the page.', 'wp-category-featured-image-rotator' ) );
		}

		$filters = self::filters( $request );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		$offset = isset( $request['offset'] ) ? absint( $request['offset'] ) : 0;

		switch ( $request['step'] ?? '' ) {
			case 'check':
				return $this->check( $offset, min( self::PREVIEW_LIMIT, absint( $request['want'] ?? 0 ) ), $filters );
			case 'assign_selected':
				return $this->assign_selected( array_slice( array_unique( array_map( 'absint', (array) ( $request['ids'] ?? array() ) ) ), 0, self::SELECTED_PER_REQUEST ) );
			default:
				return $this->assign_batch( $offset, $filters );
		}
	}

	/**
	 * Validate the category and date filters.
	 *
	 * @param array $request Request data.
	 * @return array|\WP_Error { category: int, from: string, to: string }
	 */
	private static function filters( array $request ) {
		$filters = array(
			'category' => absint( $request['category'] ?? 0 ),
			'from'     => (string) ( $request['from'] ?? '' ),
			'to'       => (string) ( $request['to'] ?? '' ),
		);
		if ( $filters['category'] && ! get_term( $filters['category'], Settings::TAXONOMY ) instanceof \WP_Term ) {
			return new \WP_Error( 'cfir_bad_category', __( 'That category does not exist.', 'wp-category-featured-image-rotator' ) );
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			if ( '' !== $filters[ $key ] && ! self::is_date( $filters[ $key ] ) ) {
				return new \WP_Error( 'cfir_bad_date', __( 'Please enter dates as year-month-day.', 'wp-category-featured-image-rotator' ) );
			}
		}
		if ( '' !== $filters['from'] && '' !== $filters['to'] && $filters['from'] > $filters['to'] ) {
			return new \WP_Error( 'cfir_bad_range', __( 'The start date is after the end date.', 'wp-category-featured-image-rotator' ) );
		}
		return $filters;
	}

	/**
	 * Whether a string is a real calendar date in Y-m-d form.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts )
			&& checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * Count matching posts without a featured image in one chunk (and how many
	 * of them can be given one now), and describe some.
	 *
	 * @param int   $offset  Posts already checked.
	 * @param int   $want    How many more posts to describe.
	 * @param array $filters Category and date filters.
	 * @return array
	 */
	private function check( int $offset, int $want, array $filters ): array {
		$query    = $this->posts( self::SCAN_CHUNK, $offset, $filters );
		$post_ids = $query['ids'];
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
					'id'      => $post_id,
					'title'   => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
					'edit'    => (string) get_edit_post_link( $post_id, 'raw' ),
					'date'    => get_the_date( '', $post_id ),
					'addable' => (bool) $pool,
					'source'  => $pool ? Category_Resolver::source_label( $pool ) : __( 'None: its categories have no images', 'wp-category-featured-image-rotator' ),
				);
			}
		}
		return array(
			'missing'     => $missing,
			'addable'     => $addable,
			'rows'        => $rows,
			'next_offset' => $offset + count( $post_ids ),
			'total'       => $query['total'],
			'done'        => count( $post_ids ) < self::SCAN_CHUNK,
		);
	}

	/**
	 * Give images to all matching posts without one, one small batch.
	 *
	 * @param int   $offset  Posts already processed.
	 * @param array $filters Category and date filters.
	 * @return array
	 */
	private function assign_batch( int $offset, array $filters ): array {
		/**
		 * Number of posts processed per "Add featured images" request (1–100).
		 *
		 * @param int $size Batch size.
		 */
		$size     = min( 100, max( 1, (int) apply_filters( 'cfir_bulk_batch_size', 20 ) ) );
		$query    = $this->posts( $size, $offset, $filters );
		$post_ids = $query['ids'];

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
			'total'       => $query['total'],
			'done'        => count( $post_ids ) < $size,
		);
	}

	/**
	 * Give images to specific posts, and report what happened to each.
	 *
	 * Each must be a published post without a featured image; assign() also
	 * checks that the user can edit it.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array
	 */
	private function assign_selected( array $post_ids ): array {
		$results  = array();
		$assigned = 0;
		$skipped  = 0;
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || Settings::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
				$result = new \WP_Error( 'cfir_unsupported', __( 'Not a published post.', 'wp-category-featured-image-rotator' ) );
			} else {
				$result = $this->assigner->assign( $post_id );
			}
			if ( is_wp_error( $result ) ) {
				++$skipped;
				$results[] = array(
					'id'      => $post_id,
					'added'   => false,
					'message' => $result->get_error_message(),
				);
			} else {
				++$assigned;
				$results[] = array(
					'id'      => $post_id,
					'added'   => true,
					/* translators: %s: where the image came from, e.g. a category name. */
					'message' => sprintf( __( 'Added, from %s', 'wp-category-featured-image-rotator' ), Category_Resolver::source_label( $result ) ),
				);
			}
		}
		return array(
			'assigned' => $assigned,
			'skipped'  => $skipped,
			'results'  => $results,
		);
	}

	/**
	 * Matching published post IDs, oldest first, and how many match in total.
	 *
	 * @param int   $limit   Maximum posts.
	 * @param int   $offset  Posts to skip.
	 * @param array $filters Category and date filters.
	 * @return array{ids: int[], total: int}
	 */
	private function posts( int $limit, int $offset, array $filters ): array {
		$args = array(
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
			'update_post_term_cache' => false,
		);
		if ( $filters['category'] ) {
			$args['cat'] = $filters['category']; // Includes subcategories, like the category archive.
		}
		$dates = array_filter(
			array(
				'after'  => $filters['from'],
				'before' => $filters['to'],
			)
		);
		if ( $dates ) {
			$args['date_query'] = array( $dates + array( 'inclusive' => true ) );
		}
		$query = new \WP_Query( $args );
		$ids   = array_map( 'intval', $query->posts );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		return array(
			'ids'   => $ids,
			'total' => (int) $query->found_posts,
		);
	}
}
