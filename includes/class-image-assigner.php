<?php
/**
 * Assigns native featured images (_thumbnail_id) to posts.
 *
 * Hooks wp_after_insert_post, which fires once a post's terms and meta are
 * saved for every way of saving a post: block editor (REST), classic editor,
 * XML-RPC and wp_insert_post(). This is the plugin's only hook outside
 * wp-admin; its first step is a capability check, so nothing happens for
 * visitors or anyone who cannot edit the post.
 *
 * Images are assigned only when a post is first published (or scheduled).
 * Updates never assign, so an editor who removes the image from a published
 * post keeps it removed: that is the per-post opt-out.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

/**
 * Featured image assignment.
 */
final class Image_Assigner {

	/**
	 * Statuses that count as published. Scheduling a post counts as publishing
	 * it, so the image is in place before WordPress publishes it on schedule.
	 */
	const LIVE_STATUSES = array( 'publish', 'future' );

	/**
	 * How many images in a post's content are examined, at most.
	 */
	const CONTENT_IMAGES_CHECKED = 10;

	/**
	 * Category resolver.
	 *
	 * @var Category_Resolver
	 */
	private Category_Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param Category_Resolver $resolver Category resolver.
	 */
	public function __construct( Category_Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'on_after_insert_post' ), 10, 4 );
	}

	/**
	 * On the public site, hide images this plugin added from the top of the
	 * post's own page. Only registered when that setting is on, so otherwise
	 * no plugin code runs for visitors at all.
	 */
	public function register_display(): void {
		add_filter( 'post_thumbnail_html', array( $this, 'filter_thumbnail_html' ), 10, 2 );
		add_filter( 'has_post_thumbnail', array( $this, 'filter_has_thumbnail' ), 10, 2 );
	}

	/**
	 * Remove the featured image markup where it should not be shown.
	 *
	 * @param string            $html    Featured image HTML.
	 * @param int|\WP_Post|null $post_id Post.
	 * @return string
	 */
	public function filter_thumbnail_html( $html, $post_id ): string {
		return self::hidden_here( $post_id ) ? '' : (string) $html;
	}

	/**
	 * Report no featured image where it is hidden, so themes don't leave an
	 * empty frame or "has image" layout behind.
	 *
	 * @param bool              $has  Whether the post has a featured image.
	 * @param int|\WP_Post|null $post Post.
	 * @return bool
	 */
	public function filter_has_thumbnail( $has, $post ): bool {
		return $has && ! self::hidden_here( $post );
	}

	/**
	 * Whether a post's featured image is hidden in the current context: it is
	 * the post being viewed on its own page, inside the main loop (so feeds,
	 * lists, related posts and the page head, where SEO and social plugins
	 * read the image, are unaffected), and the image is still the one this
	 * plugin added.
	 *
	 * @param int|\WP_Post|null $post Post.
	 * @return bool
	 */
	private static function hidden_here( $post ): bool {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || Settings::POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( ! is_singular( Settings::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || get_queried_object_id() !== $post->ID ) {
			return false;
		}
		$added = absint( get_post_meta( $post->ID, Settings::POST_META_ADDED, true ) );
		return $added && Settings::featured_image_id( $post->ID ) === $added;
	}

	/**
	 * Assign an image when a post is first published.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post object.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post before the save, null for new posts.
	 */
	public function on_after_insert_post( $post_id, $post, $update, $post_before ): void {
		if ( ! current_user_can( 'edit_post', (int) $post_id ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( $this->is_first_publish( $post, $post_before instanceof \WP_Post ? $post_before : null ) ) {
			$this->assign( (int) $post_id );
		}
	}

	/**
	 * Whether this save first publishes the post (autosaves never do).
	 *
	 * @param \WP_Post      $post        Saved post.
	 * @param \WP_Post|null $post_before Post before the save.
	 * @return bool
	 */
	private function is_first_publish( \WP_Post $post, ?\WP_Post $post_before ): bool {
		// The post type check also excludes revisions and autosave revisions.
		if ( ! Settings::get()['enabled'] || Settings::POST_TYPE !== $post->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return false;
		}

		$is_live  = in_array( $post->post_status, self::LIVE_STATUSES, true );
		$was_live = $post_before && in_array( $post_before->post_status, self::LIVE_STATUSES, true );
		return $is_live && ! $was_live;
	}

	/**
	 * Assign an image to a post that has no featured image.
	 *
	 * Order: the post's own first image (if that setting is on), then its
	 * category's images, then the default images. For category and default
	 * images, the image is set first and the rotation advanced afterwards with
	 * a compare-and-swap, so a failed assignment never consumes a position. If
	 * another request advanced the same pool in the meantime, the next image
	 * is picked instead, so simultaneous posts never share an image.
	 *
	 * The featured image is read from post meta directly (not through the
	 * has_post_thumbnail filter, which themes and "default image" plugins
	 * change), and it is never overwritten or removed unless this request set it.
	 *
	 * @param int $post_id Post ID.
	 * @return array{post_id: int, image_id: int, term_id: int, source: string}|\WP_Error
	 *         Assignment details, or why nothing was assigned.
	 */
	public function assign( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || Settings::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'cfir_unsupported', __( 'Not a post.', 'wp-category-featured-image-rotator' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'cfir_forbidden', __( 'You are not allowed to edit this post.', 'wp-category-featured-image-rotator' ) );
		}
		if ( Settings::featured_image_id( $post_id ) ) {
			return self::already_has_image();
		}

		// The post's own image comes first, when that setting is on. It takes
		// no turn from any category.
		$own = Settings::get()['content_image'] ? self::image_in_content( $post ) : 0;
		if ( $own ) {
			if ( ! set_post_thumbnail( $post, $own ) ) {
				return self::could_not_set();
			}
			$result = array(
				'post_id'  => $post_id,
				'image_id' => $own,
				'term_id'  => 0,
				'source'   => 'content',
			);
			$this->record( $result );
			return $result;
		}

		$pool = $this->resolver->resolve( $post_id );
		if ( ! $pool ) {
			return new \WP_Error( 'cfir_no_pool', __( 'None of its categories have images.', 'wp-category-featured-image-rotator' ) );
		}
		if ( ! $pool['pool_id'] ) {
			$pool['pool_id'] = Settings::global_pool_id( true );
		}

		$mode = Settings::get()['rotation_mode'];
		$ours = 0; // The image this request has set on the post, if any.
		for ( $attempt = 0; $attempt < Rotation::MAX_ATTEMPTS; $attempt++ ) {
			list( $raw, $state ) = Rotation::read( $pool['pool_id'] );
			$pick                = Rotation::pick( $pool['images'], $state, $mode );

			$current = Settings::featured_image_id( $post_id );
			if ( $current && $current !== $ours ) {
				return self::already_has_image(); // Someone else set one meanwhile: keep it.
			}
			if ( $current !== $pick['image'] ) {
				if ( ! set_post_thumbnail( $post, $pick['image'] ) ) {
					$this->undo( $post_id, $ours );
					return self::could_not_set();
				}
				$ours = $pick['image'];
			}

			/**
			 * Fires after the image is set on the post and before the pool's
			 * rotation is advanced.
			 *
			 * @param int $pool_id  Pool term ID.
			 * @param int $post_id  Post ID.
			 * @param int $image_id Attachment ID.
			 */
			do_action( 'cfir_before_rotation_commit', $pool['pool_id'], $post_id, $pick['image'] );

			if ( Rotation::compare_and_swap( $pool['pool_id'], $raw, $pick['state'] ) ) {
				$result = array(
					'post_id'  => $post_id,
					'image_id' => $pick['image'],
					'term_id'  => $pool['term_id'],
					'source'   => $pool['source'],
				);
				$this->record( $result );
				return $result;
			}
		}

		// Could not commit a rotation position: don't leave an untracked image behind.
		$this->undo( $post_id, $ours );
		return new \WP_Error( 'cfir_contention', __( 'Another post took the same image at the same moment. Please try again.', 'wp-category-featured-image-rotator' ) );
	}

	/**
	 * Remove the image this request set, and only that one.
	 *
	 * @param int $post_id Post ID.
	 * @param int $ours    Image this request set (0 for none).
	 */
	private function undo( int $post_id, int $ours ): void {
		if ( $ours && Settings::featured_image_id( $post_id ) === $ours ) {
			delete_post_thumbnail( $post_id );
		}
	}

	/**
	 * Error: the post already has a featured image.
	 *
	 * @return \WP_Error
	 */
	private static function already_has_image(): \WP_Error {
		return new \WP_Error( 'cfir_has_thumbnail', __( 'It already has a featured image.', 'wp-category-featured-image-rotator' ) );
	}

	/**
	 * Error: setting the featured image failed.
	 *
	 * @return \WP_Error
	 */
	private static function could_not_set(): \WP_Error {
		return new \WP_Error( 'cfir_assign_failed', __( 'The featured image could not be set.', 'wp-category-featured-image-rotator' ) );
	}

	/**
	 * The first usable Media Library image in a post's content.
	 *
	 * Uses core's HTML parser. An image is recognised by the wp-image-{ID}
	 * class WordPress adds when inserting media (block and classic editors),
	 * or else by a URL in this site's uploads folder (full size, resized or
	 * "-scaled" copy). Images hosted elsewhere are ignored: nothing is ever
	 * downloaded. Only the first few images are examined, and only images the
	 * current user can see that are not attached to someone else's unpublished
	 * post qualify.
	 *
	 * @param \WP_Post $post Post.
	 * @return int Attachment ID, or 0.
	 */
	public static function image_in_content( \WP_Post $post ): int {
		$uploads = wp_get_upload_dir();
		$base    = self::without_scheme( (string) $uploads['baseurl'] );
		$tags    = new \WP_HTML_Tag_Processor( (string) $post->post_content );
		$checked = 0;
		while ( $checked < self::CONTENT_IMAGES_CHECKED && $tags->next_tag( 'img' ) ) {
			++$checked;
			$image_id = 0;
			if ( preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', (string) $tags->get_attribute( 'class' ), $match ) ) {
				$image_id = (int) $match[1];
			}
			$src = (string) $tags->get_attribute( 'src' );
			if ( ! $image_id && '' !== $base && str_starts_with( self::without_scheme( $src ), $base ) ) {
				$image_id = attachment_url_to_postid( $src );
				if ( ! $image_id ) {
					// A resized or "-scaled" copy, e.g. photo-300x200.jpg for photo.jpg.
					$image_id = attachment_url_to_postid( (string) preg_replace( '/-(?:\d+x\d+|scaled)(\.[a-z0-9]+)$/i', '$1', $src ) );
				}
			}
			if ( $image_id && self::may_use_content_image( $image_id, $post ) ) {
				return $image_id;
			}
		}
		return 0;
	}

	/**
	 * Whether an image found in a post may become its featured image.
	 *
	 * @param int      $image_id Attachment ID.
	 * @param \WP_Post $post     Post being given an image.
	 * @return bool
	 */
	private static function may_use_content_image( int $image_id, \WP_Post $post ): bool {
		if ( ! Settings::is_valid_image( $image_id ) || ! current_user_can( 'read_post', $image_id ) ) {
			return false;
		}
		$parent = (int) get_post_field( 'post_parent', $image_id );
		return 0 === $parent || $post->ID === $parent || 'publish' === get_post_status( $parent );
	}

	/**
	 * A URL without its scheme, so http and https copies compare equal.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function without_scheme( string $url ): string {
		return (string) preg_replace( '#^(?:https?:)?//#i', '//', $url );
	}

	/**
	 * Mark the image as added by this plugin, and add the assignment to the
	 * recent-assignments log (troubleshooting only; a lost entry under heavy
	 * concurrency is harmless).
	 *
	 * @param array $result Assignment details.
	 */
	private function record( array $result ): void {
		update_post_meta( $result['post_id'], Settings::POST_META_ADDED, $result['image_id'] );

		$entry = $result + array(
			'user_id' => get_current_user_id(),
			'time'    => time(),
		);

		$history = get_option( Settings::HISTORY_OPTION, array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift( $history, $entry );
		update_option( Settings::HISTORY_OPTION, array_slice( $history, 0, Settings::HISTORY_LIMIT ), false );
	}
}
