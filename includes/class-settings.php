<?php
/**
 * Plugin settings and per-pool configuration storage.
 *
 * Global settings (including the default images and category priority) live
 * in one option; each category's images live in its term meta. The default
 * images' rotation state is kept on a term in a hidden taxonomy, so it uses
 * the same atomic term-meta storage as categories.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

/**
 * Settings and pool configuration.
 */
final class Settings {

	const OPTION = 'cfir_settings';

	const META_IMAGES   = 'cfir_images';
	const META_ENABLED  = 'cfir_enabled';
	const META_ROTATION = 'cfir_rotation';

	/**
	 * Post meta: the image this plugin assigned (to tell it apart from one a
	 * person chose later).
	 */
	const POST_META_ADDED = '_cfir_added_image';

	const HISTORY_OPTION = 'cfir_history';
	const HISTORY_LIMIT  = 100;

	const POOL_TAXONOMY    = 'cfir_pool';
	const GLOBAL_POOL_SLUG = 'global';

	const POST_TYPE = 'post';
	const TAXONOMY  = 'category';

	const STRATEGIES     = array( 'hierarchy', 'priority' );
	const ROTATION_MODES = array( 'round_robin', 'shuffle' );

	/**
	 * Default global settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'         => true,
			'rotation_mode'   => 'round_robin',
			'strategy'        => 'hierarchy',
			'parent_fallback' => true,
			'content_image'   => false,
			'hide_on_post'    => false,
			'default_images'  => array(),
			'precedence'      => array(),
		);
	}

	/**
	 * Current global settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Merge and store settings.
	 *
	 * @param array $values Settings to change.
	 */
	public static function update( array $values ): void {
		update_option( self::OPTION, self::sanitize( array_merge( self::get(), $values ) ), false );
	}

	/**
	 * Sanitize settings for storage (also the Settings API callback): unknown
	 * values fall back to defaults and only valid images are kept.
	 *
	 * @param mixed $values Raw values.
	 * @return array
	 */
	public static function sanitize( $values ): array {
		$values                   = self::normalize( is_array( $values ) ? $values : array() );
		$values['default_images'] = self::sanitize_images( $values['default_images'] );
		return $values;
	}

	/**
	 * Coerce a settings array to the expected shape and types (cheap; used on
	 * every read).
	 *
	 * @param array $values Raw values.
	 * @return array
	 */
	private static function normalize( array $values ): array {
		$defaults = self::defaults();
		$values   = wp_parse_args( $values, $defaults );

		return array(
			'enabled'         => (bool) $values['enabled'],
			'rotation_mode'   => in_array( $values['rotation_mode'], self::ROTATION_MODES, true ) ? $values['rotation_mode'] : $defaults['rotation_mode'],
			'strategy'        => in_array( $values['strategy'], self::STRATEGIES, true ) ? $values['strategy'] : $defaults['strategy'],
			'parent_fallback' => (bool) $values['parent_fallback'],
			'content_image'   => (bool) $values['content_image'],
			'hide_on_post'    => (bool) $values['hide_on_post'],
			'default_images'  => array_values( array_map( 'absint', (array) $values['default_images'] ) ),
			'precedence'      => array_values( array_unique( array_map( 'absint', (array) $values['precedence'] ) ) ),
		);
	}

	/**
	 * All category IDs in precedence order (index 0 = highest priority).
	 *
	 * Categories the administrator has ordered come first; any others follow
	 * alphabetically.
	 *
	 * @return int[]
	 */
	public static function precedence(): array {
		$all = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'fields'     => 'ids',
			)
		);
		$all = is_array( $all ) ? array_map( 'intval', $all ) : array();

		$saved = array_values( array_intersect( self::get()['precedence'], $all ) );

		return array_merge( $saved, array_values( array_diff( $all, $saved ) ) );
	}

	/**
	 * Default images that can currently be assigned, in order.
	 *
	 * @return int[]
	 */
	public static function default_images(): array {
		return array_values( array_filter( self::get()['default_images'], array( self::class, 'is_valid_image' ) ) );
	}

	/**
	 * Attachment IDs configured for a category, in order (may include deleted ones).
	 *
	 * @param int $pool_id Category term ID.
	 * @return int[]
	 */
	public static function get_images( int $pool_id ): array {
		$images = get_term_meta( $pool_id, self::META_IMAGES, true );
		return is_array( $images ) ? array_values( array_map( 'intval', $images ) ) : array();
	}

	/**
	 * Configured images that can currently be assigned, in order.
	 *
	 * @param int $pool_id Category or global pool term ID.
	 * @return int[]
	 */
	public static function get_valid_images( int $pool_id ): array {
		return array_values( array_filter( self::get_images( $pool_id ), array( self::class, 'is_valid_image' ) ) );
	}

	/**
	 * Store a pool's images (only existing image attachments are kept).
	 *
	 * @param int   $pool_id Category or global pool term ID.
	 * @param int[] $images  Attachment IDs in order.
	 */
	public static function update_images( int $pool_id, array $images ): void {
		update_term_meta( $pool_id, self::META_IMAGES, self::sanitize_images( $images ) );
	}

	/**
	 * Deduplicate and keep only valid image attachment IDs.
	 *
	 * @param array $images Raw IDs.
	 * @return int[]
	 */
	public static function sanitize_images( array $images ): array {
		$images = array_unique( array_map( 'absint', $images ) );
		return array_values( array_filter( $images, array( self::class, 'is_valid_image' ) ) );
	}

	/**
	 * The featured image stored on a post (0 for none).
	 *
	 * Reads the _thumbnail_id meta that set_post_thumbnail() writes, rather
	 * than has_post_thumbnail() / get_post_thumbnail_id(), whose filters
	 * themes and "default featured image" plugins use to report an image the
	 * post does not really have.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function featured_image_id( int $post_id ): int {
		return absint( get_post_meta( $post_id, '_thumbnail_id', true ) );
	}

	/**
	 * Whether an attachment exists, is an image and is not trashed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_valid_image( int $attachment_id ): bool {
		return $attachment_id > 0
			&& wp_attachment_is_image( $attachment_id )
			&& 'trash' !== get_post_status( $attachment_id );
	}

	/**
	 * Whether a pool is enabled (pools are enabled unless switched off).
	 *
	 * @param int $pool_id Term ID.
	 * @return bool
	 */
	public static function is_pool_enabled( int $pool_id ): bool {
		return '0' !== get_term_meta( $pool_id, self::META_ENABLED, true );
	}

	/**
	 * Enable or disable a pool.
	 *
	 * @param int  $pool_id Term ID.
	 * @param bool $enabled New state.
	 */
	public static function set_pool_enabled( int $pool_id, bool $enabled ): void {
		update_term_meta( $pool_id, self::META_ENABLED, $enabled ? '1' : '0' );
	}

	/**
	 * Images usable for assignment: empty when the pool is disabled.
	 *
	 * @param int $pool_id Term ID.
	 * @return int[]
	 */
	public static function usable_images( int $pool_id ): array {
		return self::is_pool_enabled( $pool_id ) ? self::get_valid_images( $pool_id ) : array();
	}

	/**
	 * Register the hidden taxonomy that holds the global default pool, on
	 * first use. It has no UI, URLs, query var or REST exposure.
	 */
	public static function ensure_pool_taxonomy(): void {
		if ( taxonomy_exists( self::POOL_TAXONOMY ) ) {
			return;
		}
		register_taxonomy(
			self::POOL_TAXONOMY,
			array(),
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_tagcloud'      => false,
				'show_admin_column'  => false,
				'query_var'          => false,
				'rewrite'            => false,
				'hierarchical'       => false,
			)
		);
	}

	/**
	 * Term ID of the global default pool.
	 *
	 * @param bool $create Create the term if it does not exist yet.
	 * @return int 0 when it does not exist.
	 */
	public static function global_pool_id( bool $create = false ): int {
		self::ensure_pool_taxonomy();
		$term = get_term_by( 'slug', self::GLOBAL_POOL_SLUG, self::POOL_TAXONOMY );
		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}
		if ( ! $create ) {
			return 0;
		}
		$result = wp_insert_term( 'Global default pool', self::POOL_TAXONOMY, array( 'slug' => self::GLOBAL_POOL_SLUG ) );
		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
	}
}
