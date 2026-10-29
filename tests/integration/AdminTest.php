<?php
/**
 * Admin UI: Posts → Featured Images.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator\Tests\Integration;

use CategoryFeaturedImageRotator\Admin;
use CategoryFeaturedImageRotator\Admin_Page;
use CategoryFeaturedImageRotator\Plugin;
use CategoryFeaturedImageRotator\Rotation;
use CategoryFeaturedImageRotator\Settings;
use CategoryFeaturedImageRotator\Tests\TestCase;

/**
 * Admin screens.
 */
class AdminTest extends TestCase {

	/**
	 * Load the admin code, as wp-admin requests do.
	 */
	public function set_up() {
		parent::set_up();
		Plugin::instance()->admin();
	}

	/**
	 * Clean up request globals.
	 */
	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Capture output.
	 *
	 * @param callable $render Renderer.
	 * @return string
	 */
	private function capture( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	/**
	 * The page HTML for the current user.
	 *
	 * @return string
	 */
	private function render_page(): string {
		return $this->capture( fn() => ( new Admin_Page( Plugin::instance()->bulk() ) )->render() );
	}

	/**
	 * AC-01: a category's images are saved in order; anything that is not an
	 * existing image is dropped.
	 */
	public function test_saves_category_images_in_order() {
		$this->act_as( 'editor' );
		$cat                   = $this->create_category( 'Chess' );
		list( $a, $b, $c, $d ) = $this->create_images( 4 );
		$pdf                   = self::factory()->attachment->create_object(
			array(
				'file'           => 'doc.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);

		$saved = Plugin::instance()->admin()->save_category( $cat, array( $d, $b, $pdf, self::factory()->post->create(), 999999, $a, $b, $c ), true, false );

		$this->assertTrue( $saved );
		$this->assertSame( array( $d, $b, $a, $c ), Settings::get_images( $cat ) );
		$this->assertTrue( Settings::is_pool_enabled( $cat ) );
	}

	/**
	 * AC-06: unticking "Use these images for new posts" pauses the category.
	 */
	public function test_unticking_use_for_new_posts_pauses_the_category() {
		$this->act_as( 'editor' );
		$cat = $this->create_pool( 'Chess', $this->create_images( 2 ) );

		Plugin::instance()->admin()->save_category( $cat, Settings::get_images( $cat ), false, false );

		$this->assertFalse( Settings::is_pool_enabled( $cat ) );
		$this->assertSame( 0, $this->thumbnail( $this->create_post( array( $cat ) ) ) );
	}

	/**
	 * "Start again from the first image" restarts the rotation.
	 */
	public function test_start_again_restarts_from_first_image() {
		$this->act_as( 'editor' );
		list( $a, $b ) = $this->create_images( 2 );
		$cat           = $this->create_pool( 'Chess', array( $a, $b ) );
		$this->create_post( array( $cat ) );

		Plugin::instance()->admin()->save_category( $cat, array( $a, $b ), true, true );

		$this->assertSame( 0, Rotation::current( $cat )['l'] );
		$this->assertSame( $a, $this->thumbnail( $this->create_post( array( $cat ) ) ) );
	}

	/**
	 * Saving needs the edit_term capability and a real category.
	 */
	public function test_saving_requires_capability_and_category() {
		$this->act_as( 'editor' );
		$images = $this->create_images( 2 );
		$cat    = $this->create_pool( 'Chess', $images );
		$tag    = self::factory()->tag->create();

		$this->assertFalse( Plugin::instance()->admin()->save_category( $tag, $images, true, false ) );
		$this->assertFalse( Plugin::instance()->admin()->save_category( 999999, $images, true, false ) );

		$this->act_as( 'author' );
		$this->assertFalse( Plugin::instance()->admin()->save_category( $cat, array(), true, false ) );
		$this->assertSame( $images, Settings::get_images( $cat ) );
	}

	/**
	 * The save form is refused without its nonce.
	 */
	public function test_save_form_requires_nonce() {
		$this->act_as( 'editor' );
		$cat   = $this->create_pool( 'Chess', $this->create_images( 1 ) );
		$_POST = array(
			'category'    => (string) $cat,
			'cfir_images' => array(),
		);

		try {
			Plugin::instance()->admin()->handle_save_category();
			$this->fail( 'Expected the request to be refused.' );
		} catch ( \WPDieException $e ) {
			$this->assertCount( 1, Settings::get_images( $cat ) );
		}
	}

	/**
	 * With a valid nonce the form saves and returns to the same category.
	 */
	public function test_save_form_saves_and_returns_to_category() {
		$this->act_as( 'editor' );
		$cat                  = $this->create_category( 'Chess' );
		$images               = $this->create_images( 2 );
		$_POST                = array(
			'category'     => (string) $cat,
			'cfir_images'  => array_map( 'strval', $images ),
			'cfir_enabled' => '1',
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin::SAVE_ACTION );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( esc_url_raw( $location ) );
			}
		);

		try {
			Plugin::instance()->admin()->handle_save_category();
			$this->fail( 'Expected a redirect.' );
		} catch ( \RuntimeException $redirect ) {
			$this->assertStringContainsString( 'category=' . $cat, $redirect->getMessage() );
			$this->assertStringContainsString( 'updated=1', $redirect->getMessage() );
		}
		unset( $_REQUEST['_wpnonce'] );
		$this->assertSame( $images, Settings::get_images( $cat ) );
	}

	/**
	 * Settings saved through the Settings API are sanitised: the hidden "0"
	 * of an unticked box turns it off, unknown values fall back to defaults,
	 * and only real images are kept.
	 */
	public function test_settings_api_sanitises_saved_values() {
		$this->act_as( 'administrator' );
		Plugin::instance()->admin()->register_settings();
		$image = $this->create_image();

		update_option(
			Settings::OPTION,
			array(
				'enabled'         => '0',
				'rotation_mode'   => 'bogus',
				'strategy'        => 'priority',
				'parent_fallback' => '1',
				'content_image'   => '1',
				'default_images'  => array( (string) $image, '999999' ),
				'precedence'      => array( '7', '7', 'x' ),
			)
		);

		$settings = Settings::get();
		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( 'round_robin', $settings['rotation_mode'] );
		$this->assertSame( 'priority', $settings['strategy'] );
		$this->assertTrue( $settings['parent_fallback'] );
		$this->assertTrue( $settings['content_image'] );
		$this->assertSame( array( $image ), $settings['default_images'] );
		$this->assertSame( array( 7, 0 ), $settings['precedence'] );
	}

	/**
	 * Call one of the page's private views.
	 *
	 * @param string $view Method name.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	private function view( string $view, ...$args ): string {
		$method = new \ReflectionMethod( Admin_Page::class, $view );
		$method->setAccessible( true );
		return $this->capture( fn() => $method->invoke( new Admin_Page( Plugin::instance()->bulk() ), ...$args ) );
	}

	/**
	 * The list shows every category, in hierarchy, with its images and a way
	 * to edit them. Names are escaped and rendering never writes.
	 */
	public function test_list_shows_every_category_with_its_images() {
		$this->act_as( 'editor' );
		$parent = $this->create_pool( 'Rock & Roll "Live"', $this->create_images( 1 ) );
		$chess  = $this->create_pool( 'Chess', $this->create_images( 2, 'chess' ), $parent );
		$this->create_category( 'Youth Chess', $chess );
		$this->create_category( 'Empty' );

		$html = $this->view( 'render_list' );

		$this->assertStringContainsString( 'Rock &amp; Roll &quot;Live&quot;', $html );
		$this->assertStringNotContainsString( 'Rock & Roll "Live"', $html );
		$this->assertStringContainsString( '— Chess', $html );
		$this->assertStringContainsString( '— — Youth Chess', $html );
		$this->assertStringContainsString( 'Uses Chess’s images', $html );
		$this->assertStringContainsString( 'Add images', $html, 'Empty categories invite adding images.' );
		$this->assertStringContainsString( esc_url( Admin::url( array( 'category' => $chess ) ) ), $html );
		$this->assertSame( 3, substr_count( $html, '<img' ) );
		$this->assertFalse( metadata_exists( 'term', $chess, Settings::META_ROTATION ), 'Rendering never writes.' );
	}

	/**
	 * Editors see the category list only; administrators also get the
	 * settings and existing-posts sections.
	 */
	public function test_list_sections_by_role() {
		$this->act_as( 'administrator' );
		$cat = $this->create_pool( 'Chess', $this->create_images( 1 ) );
		Settings::update( array( 'enabled' => false ) );
		$this->create_post( array( $cat ) );
		Settings::update( array( 'enabled' => true ) );

		$html = $this->view( 'render_list' );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'name="cfir_settings[content_image]"', $html );
		$this->assertStringContainsString( 'Find posts', $html, 'Nothing is scanned until asked.' );
		$this->assertMatchesRegularExpression( '/<select[^>]+name=[\'"]cfir-bulk-category[\'"]/', $html, 'Posts can be narrowed down by category.' );

		$this->act_as( 'editor' );
		$html = $this->view( 'render_list' );
		$this->assertStringContainsString( 'Chess', $html );
		$this->assertStringNotContainsString( 'action="options.php"', $html );
		$this->assertStringNotContainsString( 'Existing posts', $html );
	}

	/**
	 * The edit screen shows the category's images and saves through admin-post.
	 */
	public function test_edit_screen() {
		$this->act_as( 'editor' );
		$cat  = $this->create_pool( 'Chess', $this->create_images( 2 ) );
		$html = $this->view( 'render_edit', get_term( $cat, 'category' ) );

		$this->assertStringContainsString( 'Edit Featured Images', $html );
		$this->assertSame( 2, substr_count( $html, 'name="cfir_images[]"' ) );
		$this->assertStringContainsString( 'name="action" value="' . Admin::SAVE_ACTION . '"', $html );
		$this->assertStringContainsString( '← Go to Featured Images', $html );
		$this->assertStringNotContainsString( 'cfir_restart', $html, '"Start again" only once an image has been used.' );

		$this->create_post( array( $cat ) );
		$this->assertStringContainsString( 'cfir_restart', $this->view( 'render_edit', get_term( $cat, 'category' ) ) );
	}

	/**
	 * A subcategory without images says whose images it uses.
	 */
	public function test_edit_screen_explains_borrowed_parent_images() {
		$this->act_as( 'editor' );
		$chess = $this->create_pool( 'Chess', $this->create_images( 1 ) );
		$youth = $this->create_category( 'Youth Chess', $chess );

		$this->assertStringContainsString( 'new posts here use Chess’s images', $this->view( 'render_edit', get_term( $youth, 'category' ) ) );
	}

	/**
	 * The page is for users who can manage categories.
	 */
	public function test_page_requires_manage_categories() {
		$this->act_as( 'author' );
		$this->expectException( \WPDieException::class );
		( new Admin_Page( Plugin::instance()->bulk() ) )->render();
	}

	/**
	 * The Plugins screen links to the page, under Posts.
	 */
	public function test_settings_link() {
		$this->assertSame( admin_url( 'edit.php?page=' . Admin::PAGE_SLUG ), Admin::url() );

		$this->act_as( 'editor' );
		$links = Plugin::instance()->admin()->action_links( array( 'deactivate' => 'Deactivate' ) );
		$this->assertStringContainsString( esc_url( Admin::url() ), $links[0] );

		$this->act_as( 'author' );
		$this->assertCount( 1, Plugin::instance()->admin()->action_links( array( 'deactivate' => 'Deactivate' ) ) );
	}

	/**
	 * The page says when nothing will happen: feature switched off, or no
	 * images chosen yet.
	 */
	public function test_status_notices() {
		$this->act_as( 'administrator' );
		$this->create_category( 'Chess' );

		$this->assertStringContainsString( 'To get started', $this->view( 'render_list' ) );

		Settings::update( array( 'enabled' => false ) );
		$html = $this->view( 'render_list' );
		$this->assertStringContainsString( 'Automatic featured images are turned off', $html );
		$this->assertStringNotContainsString( 'To get started', $html );
	}

	/**
	 * The edit screen explains the "Next" badge, and describes shuffle mode
	 * without talking about order.
	 */
	public function test_edit_screen_explains_what_will_happen() {
		$this->act_as( 'editor' );
		$cat  = $this->create_pool( 'Chess', $this->create_images( 2 ) );
		$term = get_term( $cat, 'category' );

		$this->assertStringContainsString( 'marked “Next”', $this->view( 'render_edit', $term ) );

		Settings::update( array( 'rotation_mode' => 'shuffle' ) );
		$html = $this->view( 'render_edit', $term );
		$this->assertStringContainsString( 'random order', $html );
		$this->assertStringNotContainsString( 'marked “Next”', $html );
	}
}
