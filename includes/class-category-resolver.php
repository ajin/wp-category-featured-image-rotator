<?php
/**
 * Decides which image pool applies to a post.
 *
 * Only the post's own categories and their ancestors are ever considered, plus
 * the optional default images. An unrelated category is never chosen just because
 * it has images.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

/**
 * Category hierarchy and precedence rules.
 */
final class Category_Resolver {

	/**
	 * Resolve the pool for a post from its saved categories.
	 *
	 * @param int $post_id Post ID.
	 * @return array{pool_id: int, term_id: int, images: int[], source: string}|null
	 */
	public function resolve( int $post_id ): ?array {
		$term_ids = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
		return $this->resolve_terms( is_array( $term_ids ) ? $term_ids : array() );
	}

	/**
	 * Resolve the pool for a set of category IDs.
	 *
	 * Hierarchy First drops any assigned category whose descendant is also
	 * assigned, then applies precedence. Priority First applies precedence to
	 * every assigned category. Either way, if no candidate has usable images,
	 * the candidates' ancestors are tried (nearest first, candidates in
	 * precedence order), then the default images.
	 *
	 * @param int[] $term_ids Category IDs.
	 * @return array{pool_id: int, term_id: int, images: int[], source: string}|null
	 *         source is 'category', 'parent' or 'global'; term_id is 0 for global.
	 */
	public function resolve_terms( array $term_ids ): ?array {
		$settings = Settings::get();
		$terms    = array();
		foreach ( array_unique( array_map( 'absint', $term_ids ) ) as $term_id ) {
			if ( get_term( $term_id, Settings::TAXONOMY ) instanceof \WP_Term ) {
				$terms[] = $term_id;
			}
		}

		$candidates = 'priority' === $settings['strategy'] ? $terms : self::without_assigned_ancestors( $terms );
		$candidates = $this->sort_by_precedence( $candidates );

		foreach ( $candidates as $term_id ) {
			$images = Settings::usable_images( $term_id );
			if ( $images ) {
				return self::result( $term_id, $term_id, $images, 'category' );
			}
		}

		if ( $settings['parent_fallback'] ) {
			foreach ( $candidates as $term_id ) {
				foreach ( get_ancestors( $term_id, Settings::TAXONOMY, 'taxonomy' ) as $ancestor_id ) {
					$images = Settings::usable_images( (int) $ancestor_id );
					if ( $images ) {
						return self::result( (int) $ancestor_id, (int) $ancestor_id, $images, 'parent' );
					}
				}
			}
		}

		$images = Settings::default_images();
		if ( $images ) {
			// pool_id is 0 until the default images are first used.
			return self::result( Settings::global_pool_id(), 0, $images, 'global' );
		}

		return null;
	}

	/**
	 * Display name of a resolved pool.
	 *
	 * @param array $pool Resolution (or history entry) with term_id and source.
	 * @return string
	 */
	public static function pool_name( array $pool ): string {
		if ( 'global' === $pool['source'] ) {
			return __( 'Default images', 'wp-category-featured-image-rotator' );
		}
		if ( 'content' === $pool['source'] ) {
			return __( 'Image in the post', 'wp-category-featured-image-rotator' );
		}
		$term = get_term( (int) $pool['term_id'], Settings::TAXONOMY );
		return $term instanceof \WP_Term ? $term->name : __( '(deleted category)', 'wp-category-featured-image-rotator' );
	}

	/**
	 * Where an image comes from, for people: "Chess", "Sports (parent
	 * category)", "Default images" or "Image in the post".
	 *
	 * @param array $pool Resolution or history entry with term_id and source.
	 * @return string
	 */
	public static function source_label( array $pool ): string {
		$name = self::pool_name( $pool );
		/* translators: %s: category name. */
		return 'parent' === $pool['source'] ? sprintf( __( '%s (parent category)', 'wp-category-featured-image-rotator' ), $name ) : $name;
	}

	/**
	 * Remove categories that are ancestors of another category in the set.
	 *
	 * @param int[] $term_ids Category IDs.
	 * @return int[]
	 */
	private static function without_assigned_ancestors( array $term_ids ): array {
		$ancestors = array();
		foreach ( $term_ids as $term_id ) {
			foreach ( get_ancestors( $term_id, Settings::TAXONOMY, 'taxonomy' ) as $ancestor_id ) {
				$ancestors[ (int) $ancestor_id ] = true;
			}
		}
		return array_values( array_filter( $term_ids, static fn( $id ) => ! isset( $ancestors[ $id ] ) ) );
	}

	/**
	 * Order category IDs by configured precedence.
	 *
	 * @param int[] $term_ids Category IDs.
	 * @return int[]
	 */
	private function sort_by_precedence( array $term_ids ): array {
		$rank = array_flip( Settings::precedence() );
		usort(
			$term_ids,
			static fn( $a, $b ) => array( $rank[ $a ] ?? PHP_INT_MAX, $a ) <=> array( $rank[ $b ] ?? PHP_INT_MAX, $b )
		);
		return $term_ids;
	}

	/**
	 * Build a resolution result.
	 *
	 * @param int    $pool_id Pool term ID (0 for default images not used yet).
	 * @param int    $term_id Category ID (0 for global).
	 * @param int[]  $images  Usable images.
	 * @param string $source  How the pool was reached.
	 * @return array
	 */
	private static function result( int $pool_id, int $term_id, array $images, string $source ): array {
		return array(
			'pool_id' => $pool_id,
			'term_id' => $term_id,
			'images'  => $images,
			'source'  => $source,
		);
	}
}
