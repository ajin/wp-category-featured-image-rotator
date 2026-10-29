<?php
/**
 * Plugin Name:       Category Featured Image Rotator
 * Plugin URI:        https://github.com/ajin/wp-category-featured-image-rotator
 * Description:       Automatically assigns featured images to posts based on category-specific image pools, with rotation and configurable category precedence.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Ajin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-category-featured-image-rotator
 *
 * The plugin has no public surface. Visitors never reach its code: admin
 * code is only loaded in wp-admin, and the one hook outside wp-admin
 * (wp_after_insert_post, so posts published from any editor or API get an
 * image) starts with a capability check.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

define( 'CFIR_VERSION', '0.1.1' );
define( 'CFIR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFIR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CFIR_PLUGIN_DIR . 'includes/class-settings.php';
require_once CFIR_PLUGIN_DIR . 'includes/class-rotation.php';
require_once CFIR_PLUGIN_DIR . 'includes/class-category-resolver.php';
require_once CFIR_PLUGIN_DIR . 'includes/class-image-assigner.php';
require_once CFIR_PLUGIN_DIR . 'includes/class-plugin.php';

Plugin::instance()->boot();
