<?php
/**
 * When images are assigned, and when they are left alone.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator\Tests\Integration;

use CategoryFeaturedImageRotator\Image_Assigner;
use CategoryFeaturedImageRotator\Plugin;
use CategoryFeaturedImageRotator\Settings;
use CategoryFeaturedImageRotator\Tests\TestCase;

/**
 * When images are assigned (first publish only) and preservation rules.
 */
class ImageAssignerTest extends TestCase {

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
	 * Create a pool and log in as an editor.
	 */
	public function set_up() {
		parent::set_up();
		$this->act_as( 'editor' );
		$this->images = $this->create_images( 3 );
		$this->cat    = $this->create_pool( 'Chess', $this->images );
	}

	/**
	 * Clean up request globals.
	 */
	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * AC-03: a manually chosen image is kept, and doesn't use a rotation slot.
	 */
	public function test_existing_featured_image_is_preserved() {
		$manual = $this->create_image( 'manual.jpg' );
		$post   = $this->create_post( array( $this->cat ), array( 'meta_input' => array( '_thumbnail_id' => $manual ) ) );

		$this->assertSame( $manual, $this->thumbnail( $post ) );
		$this->assertSame( $this->images[0], $this->thumbnail( $this->create_post( array( $this->cat ) ) ) );
	}

	/**
	 * Drafts are skipped in On Publish mode; publishing later assigns.
	 */
	public function test_on_publish_skips_drafts_until_published() {
		$post = $this->create_post( array( $this->cat ), array( 'post_status' => 'draft' ) );
		$this->assertSame( 0, $this->thumbnail( $post ) );

		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( $this->images[0], $this->thumbnail( $post ) );
	}

	/**
	 * Per-post opt-out: an image removed from a published post stays removed,
	 * because updates never assign.
	 */
	public function test_removed_image_stays_removed_on_update() {
		$post = $this->create_post( array( $this->cat ) );
		delete_post_thumbnail( $post );

		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Edited',
			)
		);

		$this->assertSame( 0, $this->thumbnail( $post ) );
		$this->assertSame( $this->images[1], $this->thumbnail( $this->create_post( array( $this->cat ) ) ), 'The removed post did not hold up the rotation.' );
	}

	/**
	 * Changing a post's categories never replaces its image.
	 */
	public function test_category_change_keeps_existing_image() {
		$other = $this->create_pool( 'Other', $this->create_images( 2, 'other' ) );
		$post  = $this->create_post( array( $this->cat ) );

		wp_update_post(
			array(
				'ID'            => $post,
				'post_category' => array( $other ),
			)
		);

		$this->assertSame( $this->images[0], $this->thumbnail( $post ) );
	}

	/**
	 * AC-14: scheduling a post assigns its image, which is kept when WordPress
	 * publishes it on schedule (with no user logged in).
	 */
	public function test_scheduled_post_is_assigned_when_scheduled() {
		$post = $this->create_post(
			array( $this->cat ),
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 'future', get_post_status( $post ) );
		$this->assertSame( $this->images[0], $this->thumbnail( $post ) );

		wp_set_current_user( 0 );
		wp_publish_post( $post );

		$this->assertSame( 'publish', get_post_status( $post ) );
		$this->assertSame( $this->images[0], $this->thumbnail( $post ) );
	}

	/**
	 * AC-13: publishing through the REST API (block editor) assigns an image.
	 */
	public function test_rest_api_publish_assigns_image() {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params(
			array(
				'title'      => 'REST post',
				'status'     => 'publish',
				'categories' => array( $this->cat ),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $this->images[0], $this->thumbnail( $response->get_data()['id'] ) );
		$this->assertSame( $this->images[0], $response->get_data()['featured_media'] );
	}

	/**
	 * XML-RPC publishing assigns an image.
	 */
	public function test_xmlrpc_publish_assigns_image() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . WPINC . '/class-IXR.php';
		require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';

		$user = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'xmlrpc-editor',
				'user_pass'  => 'xmlrpc-pass',
			)
		);
		wp_set_current_user( 0 );
		add_filter( 'pre_option_enable_xmlrpc', '__return_true' );

		$server  = new \wp_xmlrpc_server();
		$post_id = $server->wp_newPost(
			array(
				1,
				'xmlrpc-editor',
				'xmlrpc-pass',
				array(
					'post_title'  => 'XML-RPC post',
					'post_status' => 'publish',
					'terms'       => array( 'category' => array( $this->cat ) ),
				),
			)
		);

		$this->assertIsString( $post_id, is_object( $post_id ) ? $post_id->message : '' );
		$this->assertSame( $user, (int) get_post_field( 'post_author', (int) $post_id ) );
		$this->assertSame( $this->images[0], $this->thumbnail( (int) $post_id ) );
	}

	/**
	 * Nothing happens without a logged-in user who can edit the post.
	 */
	public function test_requires_logged_in_user_with_edit_capability() {
		wp_set_current_user( 0 );
		$anonymous = $this->create_post( array( $this->cat ) );
		$this->assertSame( 0, $this->thumbnail( $anonymous ) );

		$this->act_as( 'subscriber' );
		$subscriber = $this->create_post( array( $this->cat ) );
		$this->assertSame( 0, $this->thumbnail( $subscriber ) );

		$this->act_as( 'editor' );
		$this->assertSame( $this->images[0], $this->thumbnail( $this->create_post( array( $this->cat ) ) ) );
	}

	/**
	 * Switching automatic assignment off stops it.
	 */
	public function test_global_switch_disables_assignment() {
		Settings::update( array( 'enabled' => false ) );
		$this->assertSame( 0, $this->thumbnail( $this->create_post( array( $this->cat ) ) ) );
	}

	/**
	 * AC-15: a post with no eligible images publishes normally.
	 */
	public function test_post_without_eligible_images_publishes_normally() {
		$empty = $this->create_category( 'Empty' );
		$post  = $this->create_post( array( $empty ) );

		$this->assertSame( 'publish', get_post_status( $post ) );
		$this->assertSame( 0, $this->thumbnail( $post ) );
		$this->assertInstanceOf( \WP_Error::class, Plugin::instance()->assigner()->assign( $post ) );
	}

	/**
	 * Only posts are handled, not pages or other post types.
	 */
	public function test_ignores_other_post_types() {
		$page = $this->create_post( array( $this->cat ), array( 'post_type' => 'page' ) );
		$this->assertSame( 0, $this->thumbnail( $page ) );
	}

	/**
	 * Revisions created by updates are never given images.
	 */
	public function test_ignores_revisions() {
		$post = $this->create_post( array( $this->cat ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'Changed',
			)
		);

		$this->assertNotEmpty( wp_get_post_revisions( $post ) );
		foreach ( wp_get_post_revisions( $post ) as $revision ) {
			$this->assertSame( 0, $this->thumbnail( $revision->ID ) );
		}
		$this->assertSame( $this->images[0], $this->thumbnail( $post ) );
	}

	/**
	 * The assignment is recorded for troubleshooting.
	 */
	public function test_assignment_is_recorded_in_history() {
		$post    = $this->create_post( array( $this->cat ) );
		$history = get_option( Settings::HISTORY_OPTION );

		$this->assertSame( $post, $history[0]['post_id'] );
		$this->assertSame( $this->images[0], $history[0]['image_id'] );
		$this->assertSame( $this->cat, $history[0]['term_id'] );
	}

	/**
	 * A post's content with the given images, as the block editor saves it.
	 *
	 * @param int[] $image_ids Attachment IDs.
	 * @return string
	 */
	private function blocks_with_images( array $image_ids ): string {
		$html = '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->';
		foreach ( $image_ids as $image_id ) {
			$html .= sprintf(
				'<!-- wp:image {"id":%1$d} --><figure class="wp-block-image"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
				$image_id,
				wp_get_attachment_url( $image_id )
			);
		}
		return $html;
	}

	/**
	 * With "Images in the post" on, the post's first image becomes its
	 * featured image, and the category's turn is not used up.
	 */
	public function test_uses_first_image_in_post_when_enabled() {
		Settings::update( array( 'content_image' => true ) );
		list( $first, $second ) = $this->create_images( 2, 'inline' );

		$post = $this->create_post( array( $this->cat ), array( 'post_content' => $this->blocks_with_images( array( $first, $second ) ) ) );

		$this->assertSame( $first, $this->thumbnail( $post ) );
		$this->assertSame( 'content', get_option( Settings::HISTORY_OPTION )[0]['source'] );
		$this->assertSame( $this->images[0], $this->thumbnail( $this->create_post( array( $this->cat ) ) ), 'The category still starts with its first image.' );
	}

	/**
	 * The setting is off by default: the category's image is used.
	 */
	public function test_ignores_image_in_post_by_default() {
		$inline = $this->create_image( 'inline.jpg' );

		$post = $this->create_post( array( $this->cat ), array( 'post_content' => $this->blocks_with_images( array( $inline ) ) ) );

		$this->assertSame( $this->images[0], $this->thumbnail( $post ) );
	}

	/**
	 * Classic-editor images are recognised by URL, including resized copies.
	 */
	public function test_finds_image_in_post_by_url() {
		Settings::update( array( 'content_image' => true ) );
		$full    = $this->create_image( 'classic.jpg' );
		$resized = $this->create_image( 'resized.jpg' );

		$by_url = $this->create_post( array( $this->cat ), array( 'post_content' => '<p><img src="' . wp_get_attachment_url( $full ) . '" /></p>' ) );
		$this->assertSame( $full, $this->thumbnail( $by_url ) );

		$small   = str_replace( '.jpg', '-300x200.jpg', (string) wp_get_attachment_url( $resized ) );
		$by_size = $this->create_post( array( $this->cat ), array( 'post_content' => '<p><img src="' . $small . '" /></p>' ) );
		$this->assertSame( $resized, $this->thumbnail( $by_size ) );
	}

	/**
	 * Images hosted elsewhere or deleted from the Media Library are skipped;
	 * the first usable library image wins, else the category's image. Nothing
	 * is downloaded (the base test case fails on any HTTP request).
	 */
	public function test_skips_external_and_deleted_images_in_post() {
		Settings::update( array( 'content_image' => true ) );
		$library = $this->create_image( 'library.jpg' );
		$deleted = $this->create_image( 'deleted.jpg' );
		$content = '<p><img src="https://example.com/photo.jpg" /></p>' . $this->blocks_with_images( array( $deleted, $library ) );
		wp_delete_attachment( $deleted, true );

		$post = $this->create_post( array( $this->cat ), array( 'post_content' => $content ) );
		$this->assertSame( $library, $this->thumbnail( $post ) );

		$external_only = $this->create_post( array( $this->cat ), array( 'post_content' => '<p><img src="https://example.com/photo.jpg" /></p>' ) );
		$this->assertSame( $this->images[0], $this->thumbnail( $external_only ) );
	}

	/**
	 * A manually chosen featured image still wins over an image in the post.
	 */
	public function test_manual_featured_image_beats_image_in_post() {
		Settings::update( array( 'content_image' => true ) );
		list( $inline, $manual ) = $this->create_images( 2, 'mix' );

		$post = $this->create_post(
			array( $this->cat ),
			array(
				'post_content' => $this->blocks_with_images( array( $inline ) ),
				'meta_input'   => array( '_thumbnail_id' => $manual ),
			)
		);

		$this->assertSame( $manual, $this->thumbnail( $post ) );
	}

	/**
	 * Themes and "default image" plugins that filter has_post_thumbnail do
	 * not stop real assignment.
	 */
	public function test_ignores_filters_that_fake_a_featured_image() {
		add_filter( 'has_post_thumbnail', '__return_true' );
		add_filter( 'post_thumbnail_id', static fn() => 999999 );

		$post = $this->create_post( array( $this->cat ) );

		$this->assertSame( $this->images[0], (int) get_post_meta( $post, '_thumbnail_id', true ) );
	}

	/**
	 * An image someone sets while assignment is in progress is kept.
	 */
	public function test_never_replaces_an_image_set_meanwhile() {
		$manual    = $this->create_image( 'manual.jpg' );
		$other_cat = $this->cat;
		$interrupt = function ( $pool_id, $post_id ) use ( &$interrupt, $manual, $other_cat ) {
			remove_action( 'cfir_before_rotation_commit', $interrupt );
			// Another request wins the rotation, and an editor sets an image.
			$this->create_post( array( $other_cat ) );
			update_post_meta( $post_id, '_thumbnail_id', $manual );
		};
		add_action( 'cfir_before_rotation_commit', $interrupt, 10, 2 );

		$post = $this->create_post( array( $this->cat ) );

		$this->assertSame( $manual, $this->thumbnail( $post ) );
	}

	/**
	 * A filter that fakes a successful meta update cannot make two posts
	 * share an image: the write is read back.
	 */
	public function test_rotation_write_is_verified() {
		$fake = static fn( $check, $object_id, $meta_key ) => 'cfir_rotation' === $meta_key && null === $check ? true : $check;
		$post = $this->create_post( array( $this->cat ) );
		add_filter( 'update_term_metadata', $fake, 10, 3 );

		$blocked = $this->create_post( array( $this->cat ) );

		remove_filter( 'update_term_metadata', $fake, 10 );
		$this->assertSame( $this->images[0], $this->thumbnail( $post ) );
		$this->assertSame( 0, $this->thumbnail( $blocked ), 'No image rather than a duplicate.' );
		$this->assertSame( $this->images[1], $this->thumbnail( $this->create_post( array( $this->cat ) ) ) );
	}

	/**
	 * Only images in this site's uploads are looked up by URL, only the first
	 * few images are examined, and an image attached to someone else's
	 * unpublished post is not used.
	 */
	public function test_post_image_limits() {
		Settings::update( array( 'content_image' => true ) );
		$elsewhere = $this->create_image( 'elsewhere.jpg' );
		$late      = $this->create_image( 'late.jpg' );
		$private   = $this->create_image( 'private.jpg' );

		$other_host = str_replace( wp_get_upload_dir()['baseurl'], 'https://cdn.example.org/uploads', (string) wp_get_attachment_url( $elsewhere ) );
		$post       = $this->create_post( array( $this->cat ), array( 'post_content' => '<img src="' . $other_host . '" />' ) );
		$this->assertSame( $this->images[0], $this->thumbnail( $post ), 'URLs outside this site’s uploads are not looked up.' );

		$content = str_repeat( '<img src="https://example.com/x.jpg" />', Image_Assigner::CONTENT_IMAGES_CHECKED ) . '<img class="wp-image-' . $late . '" />';
		$post    = $this->create_post( array( $this->cat ), array( 'post_content' => $content ) );
		$this->assertSame( $this->images[1], $this->thumbnail( $post ), 'Images after the first few are not examined.' );

		$draft = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);
		wp_update_post(
			array(
				'ID'          => $private,
				'post_parent' => $draft,
			)
		);
		$post = $this->create_post( array( $this->cat ), array( 'post_content' => '<img class="wp-image-' . $private . '" />' ) );
		$this->assertSame( $this->images[2], $this->thumbnail( $post ), 'Not an image from someone else’s unpublished post.' );
	}

	/**
	 * View a URL as a logged-out visitor and enter the main loop, as a theme does.
	 *
	 * @param string $url URL.
	 */
	private function visit( string $url ): void {
		wp_set_current_user( 0 );
		$this->go_to( $url );
		if ( have_posts() ) {
			the_post();
		}
	}

	/**
	 * With "Don't show added images at the top of the post" on, an added image
	 * is hidden on the post's own page only, and still used everywhere else.
	 */
	public function test_hides_added_image_on_its_own_post_page() {
		Settings::update( array( 'hide_on_post' => true ) );
		Plugin::instance()->assigner()->register_display();
		$post = $this->create_post( array( $this->cat ) );
		$this->assertSame( $this->images[0], (int) get_post_meta( $post, Settings::POST_META_ADDED, true ), 'The added image is marked.' );

		$this->visit( get_permalink( $post ) );
		$this->assertSame( '', get_the_post_thumbnail( $post ) );
		$this->assertFalse( has_post_thumbnail( $post ) );
		$this->assertSame( $this->images[0], get_post_thumbnail_id( $post ), 'SEO and social plugins still find it.' );

		$this->visit( home_url( '/' ) );
		$this->assertStringContainsString( '<img', get_the_post_thumbnail( $post ), 'Shown in post lists.' );
	}

	/**
	 * Images people choose are never hidden, even if they replace an added one.
	 */
	public function test_never_hides_images_people_chose() {
		Settings::update( array( 'hide_on_post' => true ) );
		Plugin::instance()->assigner()->register_display();
		$chosen  = $this->create_image( 'chosen.jpg' );
		$manual  = $this->create_post( array( $this->cat ), array( 'meta_input' => array( '_thumbnail_id' => $chosen ) ) );
		$swapped = $this->create_post( array( $this->cat ) );
		set_post_thumbnail( $swapped, $chosen );

		$this->visit( get_permalink( $manual ) );
		$this->assertStringContainsString( '<img', get_the_post_thumbnail( $manual ) );

		$this->visit( get_permalink( $swapped ) );
		$this->assertStringContainsString( '<img', get_the_post_thumbnail( $swapped ) );
	}

	/**
	 * The setting is off by default, and only then does the plugin add any
	 * filter for visitors.
	 */
	public function test_display_filter_only_when_enabled() {
		$this->assertFalse( has_filter( 'post_thumbnail_html', array( Plugin::instance()->assigner(), 'filter_thumbnail_html' ) ) );

		Settings::update( array( 'hide_on_post' => true ) );
		Plugin::instance()->boot();
		$this->assertNotFalse( has_filter( 'post_thumbnail_html', array( Plugin::instance()->assigner(), 'filter_thumbnail_html' ) ) );
	}
}
