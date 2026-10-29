<?php
/**
 * Admin controller. Loaded only in wp-admin.
 *
 * Everything lives on the plugin's own page, Posts → Featured Images, so the
 * plugin does not depend on the markup of core screens such as Categories.
 * It uses only long-standing public APIs: the admin menu, the Settings API,
 * admin-post.php and the Media Library picker.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
final class Admin {

	const PAGE_SLUG      = 'cfir-featured-images';
	const SETTINGS_GROUP = 'cfir_settings_group';
	const SAVE_ACTION    = 'cfir_save_category';

	/**
	 * Capability to open the page and edit category images. The settings and
	 * "Existing posts" sections need manage_options.
	 */
	const CAPABILITY = 'manage_categories';

	/**
	 * Bulk processor.
	 *
	 * @var Bulk_Processor
	 */
	private Bulk_Processor $bulk;

	/**
	 * Screen renderer.
	 *
	 * @var Admin_Page
	 */
	private Admin_Page $page;

	/**
	 * Hook suffix of the plugin page, as returned by add_posts_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Bulk_Processor $bulk Bulk processor.
	 */
	public function __construct( Bulk_Processor $bulk ) {
		$this->bulk = $bulk;
		$this->page = new Admin_Page( $bulk );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_category' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CFIR_PLUGIN_DIR . 'wp-category-featured-image-rotator.php' ), array( $this, 'action_links' ) );
		$this->bulk->register();
	}

	/**
	 * URL of the plugin page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::PAGE_SLUG ) + $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Add Posts → Featured Images.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_posts_page(
			__( 'Featured Images', 'wp-category-featured-image-rotator' ),
			__( 'Featured Images', 'wp-category-featured-image-rotator' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this->page, 'render' )
		);
	}

	/**
	 * Register the settings with the Settings API (saved through options.php,
	 * which checks the manage_options capability and the nonce).
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Load scripts and styles on the plugin page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'cfir-admin', CFIR_PLUGIN_URL . 'admin/css/admin.css', array(), self::asset_version( 'admin/css/admin.css' ) );
		wp_enqueue_script( 'cfir-admin', CFIR_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'media-views', 'wp-i18n' ), self::asset_version( 'admin/js/admin.js' ), true );
		wp_set_script_translations( 'cfir-admin', 'wp-category-featured-image-rotator' );
		wp_localize_script(
			'cfir-admin',
			'cfirAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'bulkAction' => Bulk_Processor::AJAX_ACTION,
				'bulkNonce'  => current_user_can( Bulk_Processor::CAPABILITY ) ? wp_create_nonce( Bulk_Processor::NONCE ) : '',
			)
		);
	}

	/**
	 * Version for an asset URL: changes whenever the file changes, so browsers
	 * never keep running an old copy after the plugin is updated.
	 *
	 * @param string $file Path relative to the plugin folder.
	 * @return string
	 */
	private static function asset_version( string $file ): string {
		$modified = filemtime( CFIR_PLUGIN_DIR . $file );
		return CFIR_VERSION . ( $modified ? '.' . $modified : '' );
	}

	/**
	 * Save a category's images (admin-post.php, logged-in users only), then
	 * return to the page.
	 */
	public function handle_save_category(): void {
		check_admin_referer( self::SAVE_ACTION );
		$term_id = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
		$images  = isset( $_POST['cfir_images'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['cfir_images'] ) ) : array();

		if ( ! $this->save_category( $term_id, $images, ! empty( $_POST['cfir_enabled'] ), ! empty( $_POST['cfir_restart'] ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this category.', 'wp-category-featured-image-rotator' ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect(
			self::url(
				array(
					'category' => $term_id,
					'updated'  => 1,
				)
			)
		);
		exit;
	}

	/**
	 * Store a category's images and options.
	 *
	 * @param int   $term_id Category ID.
	 * @param int[] $images  Attachment IDs, in order (invalid ones are dropped).
	 * @param bool  $enabled Use the images for new posts.
	 * @param bool  $restart Start again from the first image.
	 * @return bool False when the category does not exist or the user may not edit it.
	 */
	public function save_category( int $term_id, array $images, bool $enabled, bool $restart ): bool {
		if ( ! get_term( $term_id, Settings::TAXONOMY ) instanceof \WP_Term || ! current_user_can( 'edit_term', $term_id ) ) {
			return false;
		}
		Settings::update_images( $term_id, $images );
		Settings::set_pool_enabled( $term_id, $enabled );
		if ( $restart ) {
			Rotation::reset( $term_id );
		}
		return true;
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function action_links( $links ): array {
		if ( current_user_can( self::CAPABILITY ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'wp-category-featured-image-rotator' ) ) );
		}
		return $links;
	}
}
