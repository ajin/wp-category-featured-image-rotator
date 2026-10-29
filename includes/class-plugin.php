<?php
/**
 * Builds the plugin's components and registers their hooks.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap: builds the components and registers their hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Category resolver.
	 *
	 * @var Category_Resolver
	 */
	private Category_Resolver $resolver;

	/**
	 * Image assigner.
	 *
	 * @var Image_Assigner
	 */
	private Image_Assigner $assigner;

	/**
	 * Admin controller, built on first use.
	 *
	 * @var Admin|null
	 */
	private ?Admin $admin = null;

	/**
	 * Bulk processor, built on first use.
	 *
	 * @var Bulk_Processor|null
	 */
	private ?Bulk_Processor $bulk = null;

	/**
	 * The plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build the components every request type may need.
	 */
	private function __construct() {
		$this->resolver = new Category_Resolver();
		$this->assigner = new Image_Assigner( $this->resolver );
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->assigner->register();
		if ( is_admin() ) {
			$this->admin()->register();
		} elseif ( Settings::get()['hide_on_post'] ) {
			$this->assigner->register_display();
		}
	}

	/**
	 * Image assigner.
	 *
	 * @return Image_Assigner
	 */
	public function assigner(): Image_Assigner {
		return $this->assigner;
	}

	/**
	 * Admin controller (loads the admin code on first use).
	 *
	 * @return Admin
	 */
	public function admin(): Admin {
		$this->load_admin();
		return $this->admin;
	}

	/**
	 * Bulk processor (loads the admin code on first use).
	 *
	 * @return Bulk_Processor
	 */
	public function bulk(): Bulk_Processor {
		$this->load_admin();
		return $this->bulk;
	}

	/**
	 * Load and build the admin-only components.
	 */
	private function load_admin(): void {
		if ( $this->admin ) {
			return;
		}
		require_once CFIR_PLUGIN_DIR . 'admin/class-bulk-processor.php';
		require_once CFIR_PLUGIN_DIR . 'admin/class-admin-page.php';
		require_once CFIR_PLUGIN_DIR . 'admin/class-admin.php';
		$this->bulk  = new Bulk_Processor( $this->assigner, $this->resolver );
		$this->admin = new Admin( $this->bulk );
	}
}
