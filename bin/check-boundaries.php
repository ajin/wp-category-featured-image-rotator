<?php
/**
 * Static checks that phpcs cannot express. Exits non-zero if any rule from
 * CLAUDE.md is broken. Run with `npm run check:boundaries`.
 *
 * Rules:
 *   1. No private core API: functions prefixed with "_" (except i18n helpers),
 *      classes documented "@access private" in core, direct $wpdb usage.
 *   2. No unauthenticated or background entry points: nopriv AJAX/admin-post
 *      handlers, REST routes, cron, Action Scheduler.
 *   3. No external resources: hard-coded http(s) URLs in code (comments and the
 *      plugin header are allowed), remote CSS imports.
 *
 * @package CategoryFeaturedImageRotator
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- CLI script, WordPress is not loaded.

$cfir_root     = dirname( __DIR__ );
$cfir_skip_dir = '#/(vendor|node_modules|tests|bin|\.git)/#';
$cfir_checks   = array(
	'No private core functions (_-prefixed, except i18n)' => '/(?<![\w$>:\\\\])_(?!_|e\(|x\(|ex\(|n\(|nx\(|n_noop\(|nx_noop\()[a-z][a-z0-9_]*\s*\(/',
	'No private core classes'                             => '/\b(WP_\w*List_Table\w*|_WP_\w*List_Table\w*|_WP_Editors|WP_Internal_Pointers)\b/',
	'No direct $wpdb usage (use core APIs)'               => '/\$wpdb\b/',
	'No nopriv AJAX or admin-post handlers'               => '/(wp_ajax_nopriv_|admin_post_nopriv_)/',
	'No REST routes, cron or Action Scheduler'            => '/(register_rest_route|rest_api_init|wp_schedule_(single_)?event|cron_schedules|as_(schedule|enqueue)_)/',
	'No hard-coded external URLs in code'                 => '#https?://#',
	'No remote CSS @import / url()'                       => '#(@import\s+(url\()?["\']?(https?:)?//|url\(\s*["\']?(https?:)?//)#',
);

$cfir_files    = array();
$cfir_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cfir_root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $cfir_iterator as $cfir_file ) {
	$cfir_path = $cfir_file->getPathname();
	if ( preg_match( '/\.(php|js|css)$/', $cfir_path ) && ! preg_match( $cfir_skip_dir, substr( $cfir_path, strlen( $cfir_root ) ) ) ) {
		$cfir_files[] = $cfir_path;
	}
}

$cfir_failed = false;
foreach ( $cfir_checks as $cfir_title => $cfir_pattern ) {
	$cfir_hits = array();
	foreach ( $cfir_files as $cfir_path ) {
		foreach ( file( $cfir_path ) as $cfir_n => $cfir_line ) {
			// Comment-only lines (docblocks, //, #) are allowed to mention anything.
			if ( preg_match( '#^\s*(//|\#|\*|/\*)#', $cfir_line ) ) {
				continue;
			}
			if ( preg_match( $cfir_pattern, $cfir_line ) ) {
				$cfir_hits[] = sprintf( '    %s:%d: %s', substr( $cfir_path, strlen( $cfir_root ) + 1 ), $cfir_n + 1, trim( $cfir_line ) );
			}
		}
	}
	if ( $cfir_hits ) {
		$cfir_failed = true;
		echo "✖ {$cfir_title}\n" . implode( "\n", $cfir_hits ) . "\n";
	} else {
		echo "✔ {$cfir_title}\n";
	}
}

if ( $cfir_failed ) {
	echo "\nBoundary check failed. See CLAUDE.md for the rules.\n";
	exit( 1 );
}
