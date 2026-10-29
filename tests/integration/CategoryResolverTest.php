<?php
/**
 * Category hierarchy, precedence and fallback.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator\Tests\Integration;

use CategoryFeaturedImageRotator\Category_Resolver;
use CategoryFeaturedImageRotator\Settings;
use CategoryFeaturedImageRotator\Tests\TestCase;

/**
 * Category resolution.
 */
class CategoryResolverTest extends TestCase {

	/**
	 * Category IDs by name.
	 *
	 * @var int[]
	 */
	private array $cat = array();

	/**
	 * Build News → Technology → Cybersecurity, Sports → Chess → Youth Chess, Events.
	 */
	public function set_up() {
		parent::set_up();
		$this->act_as( 'editor' );

		$this->cat['News']          = $this->create_pool( 'News', $this->create_images( 2, 'news' ) );
		$this->cat['Technology']    = $this->create_pool( 'Technology', $this->create_images( 2, 'tech' ), $this->cat['News'] );
		$this->cat['Cybersecurity'] = $this->create_pool( 'Cybersecurity', $this->create_images( 2, 'cyber' ), $this->cat['Technology'] );
		$this->cat['Sports']        = $this->create_category( 'Sports' );
		$this->cat['Chess']         = $this->create_pool( 'Chess', $this->create_images( 2, 'chess' ), $this->cat['Sports'] );
		$this->cat['Youth Chess']   = $this->create_category( 'Youth Chess', $this->cat['Chess'] );
		$this->cat['Events']        = $this->create_pool( 'Events', $this->create_images( 2, 'events' ) );
	}

	/**
	 * Resolve a list of category names.
	 *
	 * @param string[] $names Category names.
	 * @return array|null Resolution result.
	 */
	private function resolve( array $names ): ?array {
		return ( new Category_Resolver() )->resolve_terms( array_map( fn( $name ) => $this->cat[ $name ], $names ) );
	}

	/**
	 * Set precedence by category names, highest first.
	 *
	 * @param string[] $names Category names.
	 */
	private function precedence( array $names ): void {
		Settings::update( array( 'precedence' => array_map( fn( $name ) => $this->cat[ $name ], $names ) ) );
	}

	/**
	 * AC-04: the most specific category wins in Hierarchy First mode.
	 */
	public function test_hierarchy_first_prefers_most_specific_category() {
		$this->precedence( array( 'News', 'Technology', 'Cybersecurity' ) );

		$pool = $this->resolve( array( 'News', 'Technology', 'Cybersecurity' ) );

		$this->assertSame( $this->cat['Cybersecurity'], $pool['term_id'] );
		$this->assertSame( 'category', $pool['source'] );
	}

	/**
	 * AC-05: precedence decides between unrelated categories.
	 */
	public function test_precedence_decides_between_unrelated_categories() {
		$this->precedence( array( 'Events', 'Cybersecurity', 'Technology', 'News' ) );
		$this->assertSame( $this->cat['Events'], $this->resolve( array( 'News', 'Technology', 'Cybersecurity', 'Events' ) )['term_id'] );

		$this->precedence( array( 'Cybersecurity', 'Events', 'Technology', 'News' ) );
		$this->assertSame( $this->cat['Cybersecurity'], $this->resolve( array( 'News', 'Technology', 'Cybersecurity', 'Events' ) )['term_id'] );
		Settings::update( array( 'strategy' => 'priority' ) );
		$this->assertSame( $this->cat['Cybersecurity'], $this->resolve( array( 'News', 'Technology', 'Cybersecurity', 'Events' ) )['term_id'] );
	}

	/**
	 * With News at priority 1, Priority First picks News; Hierarchy First excludes it.
	 */
	public function test_priority_first_ignores_depth() {
		$this->precedence( array( 'News', 'Events', 'Cybersecurity', 'Technology' ) );
		$names = array( 'News', 'Technology', 'Cybersecurity', 'Events' );

		$this->assertSame( $this->cat['Events'], $this->resolve( $names )['term_id'] );

		Settings::update( array( 'strategy' => 'priority' ) );
		$this->assertSame( $this->cat['News'], $this->resolve( $names )['term_id'] );
	}

	/**
	 * Youth Chess has no images, so its parent Chess supplies one.
	 */
	public function test_falls_back_to_parent_category() {
		$pool = $this->resolve( array( 'Youth Chess' ) );
		$this->assertSame( $this->cat['Chess'], $pool['term_id'] );
		$this->assertSame( 'parent', $pool['source'] );

		Settings::update( array( 'parent_fallback' => false ) );
		$this->assertNull( $this->resolve( array( 'Youth Chess' ) ) );
	}

	/**
	 * AC-06: a disabled category's images are never selected.
	 */
	public function test_disabled_category_is_excluded() {
		$this->precedence( array( 'Events', 'Cybersecurity' ) );
		Settings::set_pool_enabled( $this->cat['Events'], false );

		$this->assertSame( $this->cat['Cybersecurity'], $this->resolve( array( 'Cybersecurity', 'Events' ) )['term_id'] );

		Settings::set_pool_enabled( $this->cat['Cybersecurity'], false );
		$this->assertSame( $this->cat['Technology'], $this->resolve( array( 'Cybersecurity', 'Events' ) )['term_id'], 'Disabled pools fall back to the nearest enabled ancestor.' );
	}

	/**
	 * An unrelated category with images is never chosen.
	 */
	public function test_never_selects_an_unrelated_category() {
		$this->assertNull( $this->resolve( array( 'Sports' ) ) );

		$lonely = $this->create_category( 'Lonely' );
		$this->assertNull( ( new Category_Resolver() )->resolve_terms( array( $lonely ) ) );
		$this->assertNull( ( new Category_Resolver() )->resolve_terms( array() ) );
	}

	/**
	 * Default images are used only when none of the post's categories (or
	 * their parents) have images, and only if some are chosen.
	 */
	public function test_default_images_are_the_last_resort() {
		$this->assertNull( $this->resolve( array( 'Sports' ) ) );

		$image = $this->create_image( 'default.jpg' );
		Settings::update( array( 'default_images' => array( $image ) ) );

		$pool = $this->resolve( array( 'Sports' ) );
		$this->assertSame( 'global', $pool['source'] );
		$this->assertSame( array( $image ), $pool['images'] );
		$this->assertSame( $this->cat['Events'], $this->resolve( array( 'Events' ) )['term_id'], 'Category images come before default images.' );

		$post = $this->create_post( array( $this->cat['Sports'] ) );
		$this->assertSame( $image, $this->thumbnail( $post ) );
	}

	/**
	 * AC-17: changing priorities only affects future posts.
	 */
	public function test_changing_precedence_only_affects_future_posts() {
		$this->precedence( array( 'Events', 'News' ) );
		$categories = array( $this->cat['Events'], $this->cat['News'] );
		$first      = $this->create_post( $categories );
		$events_img = Settings::get_images( $this->cat['Events'] )[0];
		$this->assertSame( $events_img, $this->thumbnail( $first ) );

		$this->precedence( array( 'News', 'Events' ) );
		$second = $this->create_post( $categories );

		$this->assertSame( Settings::get_images( $this->cat['News'] )[0], $this->thumbnail( $second ) );
		$this->assertSame( $events_img, $this->thumbnail( $first ) );
	}

	/**
	 * Categories nobody ordered follow the ordered ones alphabetically.
	 */
	public function test_unordered_categories_follow_alphabetically() {
		$this->precedence( array( 'Sports' ) );
		$order = Settings::precedence();

		$this->assertSame( $this->cat['Sports'], $order[0] );
		$this->assertLessThan( array_search( $this->cat['News'], $order, true ), array_search( $this->cat['Events'], $order, true ) );
	}
}
