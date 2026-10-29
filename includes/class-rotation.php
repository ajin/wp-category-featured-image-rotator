<?php
/**
 * Image rotation: choosing the next image and storing each pool's position.
 *
 * Choosing is pure (pick()/peek() take the state as input). Storage is a JSON
 * string in term meta, and every write is a compare-and-swap:
 * update_term_meta() with $prev_value only updates the row whose value still
 * matches what was read, and returns false otherwise. Each state carries a
 * sequence number that increases on every write, so two requests that read
 * the same state can never both commit, and each win is confirmed by reading
 * the row back. No locks are held, so an interrupted request can never leave
 * a pool locked. The stored JSON holds integers only, so slashing never
 * changes it.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

/**
 * Rotation algorithms and state storage.
 */
final class Rotation {

	/**
	 * Maximum compare-and-swap attempts before giving up.
	 */
	const MAX_ATTEMPTS = 10;

	/**
	 * State of a pool that has never assigned an image.
	 *
	 * Keys: s = sequence, l = last assigned attachment ID, p = position of the
	 * last assigned image in the pool (-1 = none), u = images used in the
	 * current shuffle cycle.
	 *
	 * @return array
	 */
	public static function initial_state(): array {
		return array(
			's' => 0,
			'l' => 0,
			'p' => -1,
			'u' => array(),
		);
	}

	/**
	 * Current state for display. Read-only: never creates the row.
	 *
	 * @param int $pool_id Term ID.
	 * @return array
	 */
	public static function current( int $pool_id ): array {
		$raw = get_term_meta( $pool_id, Settings::META_ROTATION, true );
		return self::decode( is_string( $raw ) ? $raw : '' );
	}

	/**
	 * Read the current state straight from the database, ready for a
	 * compare-and-swap.
	 *
	 * @param int $pool_id Term ID.
	 * @return array{0: string, 1: array} Raw stored value and decoded state.
	 */
	public static function read( int $pool_id ): array {
		$raw = self::fetch( $pool_id );
		if ( '' === $raw ) {
			// First use: create the row so later writes can compare-and-swap.
			add_term_meta( $pool_id, Settings::META_ROTATION, self::encode( self::initial_state() ), true );
			$raw = self::fetch( $pool_id );
		}
		return array( $raw, self::decode( $raw ) );
	}

	/**
	 * Replace the state only if it is still $expected_raw.
	 *
	 * @param int    $pool_id      Term ID.
	 * @param string $expected_raw Raw value returned by read().
	 * @param array  $new_state    State to store; its sequence is set here.
	 * @return bool Whether the write won.
	 */
	public static function compare_and_swap( int $pool_id, string $expected_raw, array $new_state ): bool {
		if ( '' === $expected_raw ) {
			return false;
		}
		$new_state['s'] = self::decode( $expected_raw )['s'] + 1;
		$new_raw        = self::encode( $new_state );
		if ( true !== update_term_meta( $pool_id, Settings::META_ROTATION, $new_raw, $expected_raw ) ) {
			return false;
		}
		// Confirm the row really holds our state: the atomicity comes from how
		// core performs the update, and a short-circuit filter on
		// update_term_metadata could report success without writing.
		return self::fetch( $pool_id ) === $new_raw;
	}

	/**
	 * Restart a pool's rotation from its first image. Past assignments are untouched.
	 *
	 * @param int $pool_id Term ID.
	 * @return bool
	 */
	public static function reset( int $pool_id ): bool {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			list( $raw ) = self::read( $pool_id );
			if ( self::compare_and_swap( $pool_id, $raw, self::initial_state() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decode a stored state, tolerating anything malformed.
	 *
	 * @param string $raw Stored JSON.
	 * @return array
	 */
	private static function decode( string $raw ): array {
		$data  = json_decode( $raw, true );
		$state = self::initial_state();
		if ( ! is_array( $data ) ) {
			return $state;
		}
		$state['s'] = isset( $data['s'] ) ? absint( $data['s'] ) : 0;
		$state['l'] = isset( $data['l'] ) ? absint( $data['l'] ) : 0;
		$state['p'] = isset( $data['p'] ) ? max( -1, (int) $data['p'] ) : -1;
		$state['u'] = isset( $data['u'] ) && is_array( $data['u'] ) ? array_values( array_map( 'absint', $data['u'] ) ) : array();
		return $state;
	}

	/**
	 * Encode a state for storage.
	 *
	 * @param array $state State.
	 * @return string
	 */
	private static function encode( array $state ): string {
		return (string) wp_json_encode(
			array(
				's' => (int) $state['s'],
				'l' => (int) $state['l'],
				'p' => (int) $state['p'],
				'u' => array_values( array_map( 'intval', $state['u'] ) ),
			)
		);
	}

	/**
	 * Read the raw stored value, bypassing the object cache so a value another
	 * request just wrote is seen.
	 *
	 * @param int $pool_id Term ID.
	 * @return string
	 */
	private static function fetch( int $pool_id ): string {
		// Core caches all of a term's meta under the 'term_meta' group (see update_meta_cache()).
		wp_cache_delete( $pool_id, 'term_meta' );
		$raw = get_term_meta( $pool_id, Settings::META_ROTATION, true );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Pick the next image and the state to store once it has been assigned.
	 *
	 * Round-robin continues after the last assigned image, so reordering keeps
	 * the sequence going. If that image has been removed from the pool, the
	 * image now occupying its old position is next.
	 *
	 * Shuffle uses each image once per cycle in random order and never starts
	 * a new cycle with the image that ended the previous one.
	 *
	 * @param int[]  $images Valid attachment IDs, in pool order. Must not be empty.
	 * @param array  $state  Current state (see initial_state()).
	 * @param string $mode   'round_robin' or 'shuffle'.
	 * @return array{image: int, state: array}
	 */
	public static function pick( array $images, array $state, string $mode ): array {
		$images = array_values( $images );
		$used   = array();

		if ( 'shuffle' === $mode ) {
			$used      = array_values( array_intersect( $state['u'], $images ) );
			$remaining = array_values( array_diff( $images, $used ) );
			if ( ! $remaining ) {
				$used      = array();
				$remaining = count( $images ) > 1 ? array_values( array_diff( $images, array( $state['l'] ) ) ) : $images;
			}
			$image  = $remaining[ wp_rand( 0, count( $remaining ) - 1 ) ];
			$index  = (int) array_search( $image, $images, true );
			$used[] = $image;
		} else {
			$index = self::next_index( $images, $state );
			$image = $images[ $index ];
		}

		return array(
			'image' => $image,
			'state' => array(
				's' => $state['s'],
				'l' => $image,
				'p' => $index,
				'u' => $used,
			),
		);
	}

	/**
	 * Describe what will happen next, for the admin screens.
	 *
	 * Round-robin knows the next image; shuffle only knows how many images are
	 * left in the current cycle.
	 *
	 * @param int[]  $images Valid attachment IDs, in pool order. Must not be empty.
	 * @param array  $state  Current state.
	 * @param string $mode   Rotation mode.
	 * @return array{image: int, position: int, remaining: int} image and position
	 *         (1-based) for round-robin; remaining for shuffle. Unused keys are 0.
	 */
	public static function peek( array $images, array $state, string $mode ): array {
		$images = array_values( $images );
		if ( 'shuffle' === $mode ) {
			$remaining = count( array_diff( $images, $state['u'] ) );
			return array(
				'image'     => 0,
				'position'  => 0,
				'remaining' => $remaining ? $remaining : count( $images ),
			);
		}
		$index = self::next_index( $images, $state );
		return array(
			'image'     => $images[ $index ],
			'position'  => $index + 1,
			'remaining' => 0,
		);
	}

	/**
	 * Round-robin position of the next image.
	 *
	 * @param int[] $images Attachment IDs, re-indexed.
	 * @param array $state  Current state.
	 * @return int
	 */
	private static function next_index( array $images, array $state ): int {
		$count = count( $images );
		$last  = $state['l'] ? array_search( $state['l'], $images, true ) : false;
		if ( false !== $last ) {
			return ( $last + 1 ) % $count;
		}
		if ( $state['p'] >= 0 ) {
			return $state['p'] % $count;
		}
		return 0;
	}
}
