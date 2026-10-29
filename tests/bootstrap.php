<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite shipped in the wp-env
 * container and activates this plugin before WordPress finishes loading.
 *
 * Run via `npm run test:php`, which sets WORDPRESS_TABLE_PREFIX=wptests_ so the
 * suite never touches the dev site's tables.
 *
 * @package CategoryFeaturedImageRotator
 */

if ( 'wptests_' !== getenv( 'WORDPRESS_TABLE_PREFIX' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions -- WordPress is not loaded yet.
	fwrite( STDERR, "Refusing to run: WORDPRESS_TABLE_PREFIX must be 'wptests_' so the dev database is not wiped. Use `npm run test:php`.\n" );
	exit( 1 );
}

$cfir_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

if ( ! file_exists( $cfir_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions -- WordPress is not loaded yet.
	fwrite( STDERR, "WordPress test library not found in {$cfir_tests_dir}. Run the tests inside wp-env.\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $cfir_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/wp-category-featured-image-rotator.php';
	}
);

require $cfir_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-testcase.php';
