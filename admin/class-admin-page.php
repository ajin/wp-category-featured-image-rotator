<?php
/**
 * Markup for Posts → Featured Images.
 *
 * Laid out like Posts → Categories: a list of categories with their images,
 * and an edit screen per category. Uses WordPress's documented admin CSS
 * classes (wrap, form-table, description, widefat, button, notice) so the
 * page looks native without depending on any core screen's markup.
 *
 * @package CategoryFeaturedImageRotator
 */

namespace CategoryFeaturedImageRotator;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen renderer.
 */
final class Admin_Page {

	/**
	 * Recent assignments shown on the settings page.
	 */
	const RECENT_LIMIT = 10;

	/**
	 * Bulk processor (for counts and preview).
	 *
	 * @var Bulk_Processor
	 */
	private Bulk_Processor $bulk;

	/**
	 * Constructor.
	 *
	 * @param Bulk_Processor $bulk Bulk processor.
	 */
	public function __construct( Bulk_Processor $bulk ) {
		$this->bulk = $bulk;
	}

	/**
	 * Posts → Featured Images: the category list, or one category's edit screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-category-featured-image-rotator' ) );
		}
		$term = get_term( absint( self::query( 'category' ) ), Settings::TAXONOMY );
		if ( $term instanceof \WP_Term ) {
			$this->render_edit( $term );
		} else {
			$this->render_list();
		}
	}

	/**
	 * Read a GET parameter (read-only screen navigation, no nonce).
	 *
	 * @param string $key Parameter name.
	 * @return string
	 */
	private static function query( string $key ): string {
		// filter_input() reads the raw request, which WordPress has not slashed.
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Every category with its images, like Posts → Categories; then settings.
	 */
	private function render_list(): void {
		$search = self::query( 's' );
		$rows   = $this->category_tree();
		if ( '' !== $search ) {
			// Core's own term search, so matching works like Posts → Categories.
			$found = get_terms(
				array(
					'taxonomy'   => Settings::TAXONOMY,
					'hide_empty' => false,
					'search'     => $search,
					'fields'     => 'ids',
				)
			);
			$found = is_array( $found ) ? array_map( 'intval', $found ) : array();
			$rows  = array_values( array_filter( $rows, static fn( $row ) => in_array( (int) $row['term']->term_id, $found, true ) ) );
		}
		$settings = Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Featured Images', 'wp-category-featured-image-rotator' ); ?></h1>
			<?php
			settings_errors();
			$this->status_notices();
			?>
			<p>
				<?php
				if ( $settings['content_image'] ) {
					esc_html_e( 'When a post is published without a featured image, it gets the first image in the post, or else one of the images chosen for its category.', 'wp-category-featured-image-rotator' );
				} else {
					esc_html_e( 'When a post is published without a featured image, it gets one of the images chosen for its category.', 'wp-category-featured-image-rotator' );
				}
				echo ' ';
				esc_html_e( 'Click a category to choose its images.', 'wp-category-featured-image-rotator' );
				?>
			</p>

			<form method="get" class="cfir-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_SLUG ); ?>" />
				<label class="screen-reader-text" for="cfir-search"><?php esc_html_e( 'Search Categories:', 'wp-category-featured-image-rotator' ); ?></label>
				<input type="search" id="cfir-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
				<?php submit_button( __( 'Search Categories', 'wp-category-featured-image-rotator' ), '', '', false ); ?>
			</form>
			<?php if ( '' !== $search ) : ?>
				<p class="cfir-search__results">
					<?php
					/* translators: %s: search terms. */
					echo esc_html( sprintf( __( 'Search results for: “%s”', 'wp-category-featured-image-rotator' ), $search ) );
					?>
					<a href="<?php echo esc_url( Admin::url() ); ?>"><?php esc_html_e( 'Show all categories', 'wp-category-featured-image-rotator' ); ?></a>
				</p>
			<?php endif; ?>

			<table class="widefat striped cfir-categories">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'wp-category-featured-image-rotator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Featured images', 'wp-category-featured-image-rotator' ); ?></th>
						<th scope="col" class="cfir-categories__count"><?php esc_html_e( 'Posts', 'wp-category-featured-image-rotator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No categories found.', 'wp-category-featured-image-rotator' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $rows as $row ) :
						$term = $row['term'];
						$edit = Admin::url( array( 'category' => $term->term_id ) );
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( str_repeat( '— ', $row['depth'] ) . $term->name ); ?></a></strong>
								<div class="cfir-categories__action">
									<a href="<?php echo esc_url( $edit ); ?>">
										<?php
										/* translators: %s: category name (screen readers only). */
										printf( esc_html__( 'Edit images %s', 'wp-category-featured-image-rotator' ), '<span class="screen-reader-text">' . esc_html( $term->name ) . '</span>' );
										?>
									</a>
								</div>
							</td>
							<td><a href="<?php echo esc_url( $edit ); ?>" class="cfir-categories__images"><?php echo wp_kses_post( $this->thumbnails_html( (int) $term->term_id ) ); ?></a></td>
							<td class="cfir-categories__count"><a href="<?php echo esc_url( admin_url( 'edit.php?cat=' . (int) $term->term_id ) ); ?>"><?php echo esc_html( number_format_i18n( (int) $term->count ) ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			if ( current_user_can( 'manage_options' ) ) {
				$this->settings_form();
				$this->existing_posts();
				$this->recent();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Tell people when nothing will happen, and why.
	 */
	private function status_notices(): void {
		if ( ! Settings::get()['enabled'] ) {
			wp_admin_notice(
				current_user_can( 'manage_options' )
					? esc_html__( 'Automatic featured images are turned off, so new posts will not get one. Turn them on under Settings below.', 'wp-category-featured-image-rotator' )
					: esc_html__( 'Automatic featured images are turned off by an administrator, so new posts will not get one.', 'wp-category-featured-image-rotator' ),
				array( 'type' => 'warning' )
			);
			return;
		}
		$any_images = (bool) Settings::default_images();
		foreach ( $this->category_tree() as $row ) {
			if ( $any_images ) {
				break;
			}
			$any_images = (bool) Settings::get_valid_images( (int) $row['term']->term_id );
		}
		if ( ! $any_images ) {
			wp_admin_notice(
				esc_html__( 'To get started, click a category below and add a few images. New posts in that category will then get one automatically.', 'wp-category-featured-image-rotator' ),
				array( 'type' => 'info' )
			);
		}
	}

	/**
	 * One category's images, like the Edit Category screen.
	 *
	 * @param \WP_Term $term Category.
	 */
	private function render_edit( \WP_Term $term ): void {
		$term_id = (int) $term->term_id;
		$state   = Rotation::current( $term_id );
		$path    = self::parent_path( $term );
		$images  = Settings::get_valid_images( $term_id );
		// A category without images may borrow its parent's: say so.
		$borrowed = $images ? null : ( new Category_Resolver() )->resolve_terms( array( $term_id ) );
		$borrowed = $borrowed && 'parent' === $borrowed['source'] ? $borrowed : null;
		$back     = '<a href="' . esc_url( Admin::url() ) . '">' . esc_html__( '← Go to Featured Images', 'wp-category-featured-image-rotator' ) . '</a>';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Edit Featured Images', 'wp-category-featured-image-rotator' ); ?></h1>
			<?php
			if ( '1' === self::query( 'updated' ) ) {
				wp_admin_notice(
					esc_html__( 'Featured images updated.', 'wp-category-featured-image-rotator' ) . ' ' . $back,
					array(
						'type'        => 'success',
						'dismissible' => true,
					)
				);
			}
			if ( ! Settings::get()['enabled'] ) {
				$this->status_notices();
			}
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cfir-category-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( Admin::SAVE_ACTION ); ?>" />
				<input type="hidden" name="category" value="<?php echo esc_attr( (string) $term_id ); ?>" />
				<?php wp_nonce_field( Admin::SAVE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Category', 'wp-category-featured-image-rotator' ); ?></th>
						<td>
							<strong><?php echo esc_html( $term->name ); ?></strong>
							<?php if ( '' !== $path ) : ?>
								<span class="description"><?php echo esc_html( self::in_parent( $path ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Featured images', 'wp-category-featured-image-rotator' ); ?></th>
						<td>
							<?php $this->image_picker( 'cfir_images[]', $images, $state ); ?>
							<p class="description">
								<?php
								if ( $borrowed ) {
									/* translators: %s: parent category name. */
									echo esc_html( sprintf( __( 'No images yet, so new posts here use %s’s images.', 'wp-category-featured-image-rotator' ), Category_Resolver::pool_name( $borrowed ) ) );
								} elseif ( 'shuffle' === Settings::get()['rotation_mode'] ) {
									esc_html_e( 'New posts in this category that have no featured image get these images in random order, each used once before any repeats.', 'wp-category-featured-image-rotator' );
								} else {
									esc_html_e( 'New posts in this category that have no featured image get these images in turn, starting with the one marked “Next”. Drag images to change the order.', 'wp-category-featured-image-rotator' );
								}
								if ( Settings::get()['content_image'] ) {
									echo ' ';
									esc_html_e( 'A post that contains its own image uses that instead.', 'wp-category-featured-image-rotator' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New posts', 'wp-category-featured-image-rotator' ); ?></th>
						<td>
							<input type="hidden" name="cfir_enabled" value="0" />
							<label>
								<input type="checkbox" name="cfir_enabled" value="1" <?php checked( Settings::is_pool_enabled( $term_id ) ); ?> />
								<?php esc_html_e( 'Use these images for new posts', 'wp-category-featured-image-rotator' ); ?>
							</label>
							<?php if ( $state['l'] && 'round_robin' === Settings::get()['rotation_mode'] ) : ?>
								<br />
								<label>
									<input type="checkbox" name="cfir_restart" value="1" class="cfir-restart" />
									<?php esc_html_e( 'Start again from the first image', 'wp-category-featured-image-rotator' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Update', 'wp-category-featured-image-rotator' ) ); ?>
			</form>
			<p><?php echo wp_kses_post( $back ); ?></p>
		</div>
		<?php
	}

	/**
	 * Categories in tree order, each with its depth.
	 *
	 * @return array<int, array{term: \WP_Term, depth: int}>
	 */
	private function category_tree(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Settings::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$ids      = wp_list_pluck( $terms, 'term_id' );
		$children = array();
		foreach ( $terms as $term ) {
			$children[ in_array( $term->parent, $ids, true ) ? (int) $term->parent : 0 ][] = $term;
		}
		$rows = array();
		$walk = static function ( int $parent_id, int $depth ) use ( &$walk, &$rows, $children ) {
			foreach ( $children[ $parent_id ] ?? array() as $term ) {
				$rows[] = array(
					'term'  => $term,
					'depth' => $depth,
				);
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $rows;
	}

	/**
	 * The settings form (Settings API).
	 */
	private function settings_form(): void {
		$settings = Settings::get();
		$name     = Settings::OPTION;
		?>
		<h2><?php esc_html_e( 'Settings', 'wp-category-featured-image-rotator' ); ?></h2>
		<form method="post" action="options.php" class="cfir-settings-form">
			<?php settings_fields( Admin::SETTINGS_GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic featured images', 'wp-category-featured-image-rotator' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[enabled]" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
							<?php esc_html_e( 'Add a featured image when a post without one is published', 'wp-category-featured-image-rotator' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Posts that already have a featured image are never changed. If you remove an added image from a post, it stays removed.', 'wp-category-featured-image-rotator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Images in the post', 'wp-category-featured-image-rotator' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[content_image]" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[content_image]" value="1" <?php checked( $settings['content_image'] ); ?> />
							<?php esc_html_e( 'If the post contains an image, use the first one as its featured image', 'wp-category-featured-image-rotator' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only images from your Media Library are used. Posts without one get an image from their category.', 'wp-category-featured-image-rotator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post page', 'wp-category-featured-image-rotator' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[hide_on_post]" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hide_on_post]" value="1" <?php checked( $settings['hide_on_post'] ); ?> />
							<?php esc_html_e( 'Don’t show the featured image on the post', 'wp-category-featured-image-rotator' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only for images added by this plugin. Images you choose yourself always show. The image is still used in post lists, search results and when the post is shared.', 'wp-category-featured-image-rotator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Image order', 'wp-category-featured-image-rotator' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Image order', 'wp-category-featured-image-rotator' ); ?></legend>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[rotation_mode]" value="round_robin" <?php checked( $settings['rotation_mode'], 'round_robin' ); ?> /> <?php esc_html_e( 'Take turns, in the order shown', 'wp-category-featured-image-rotator' ); ?></label><br />
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[rotation_mode]" value="shuffle" <?php checked( $settings['rotation_mode'], 'shuffle' ); ?> /> <?php esc_html_e( 'Shuffle, using every image once before repeating', 'wp-category-featured-image-rotator' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default images', 'wp-category-featured-image-rotator' ); ?></th>
					<td>
						<?php
						$global_id = Settings::global_pool_id();
						$this->image_picker( $name . '[default_images][]', Settings::default_images(), $global_id ? Rotation::current( $global_id ) : Rotation::initial_state() );
						?>
						<p class="description"><?php esc_html_e( 'Used when none of a post’s categories have images. Leave empty to publish those posts without a featured image.', 'wp-category-featured-image-rotator' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Posts in more than one category', 'wp-category-featured-image-rotator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Subcategories', 'wp-category-featured-image-rotator' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Subcategories', 'wp-category-featured-image-rotator' ); ?></legend>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[strategy]" value="hierarchy" <?php checked( $settings['strategy'], 'hierarchy' ); ?> /> <?php esc_html_e( 'Prefer the most specific category (a post in Sports and Chess uses Chess)', 'wp-category-featured-image-rotator' ); ?></label><br />
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[strategy]" value="priority" <?php checked( $settings['strategy'], 'priority' ); ?> /> <?php esc_html_e( 'Always follow the priority order below', 'wp-category-featured-image-rotator' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Categories without images', 'wp-category-featured-image-rotator' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[parent_fallback]" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[parent_fallback]" value="1" <?php checked( $settings['parent_fallback'] ); ?> />
							<?php esc_html_e( 'Use the parent category’s images', 'wp-category-featured-image-rotator' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Priority', 'wp-category-featured-image-rotator' ); ?></th>
					<td><?php $this->priority_list( $name . '[precedence][]' ); ?></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * The "Featured images" cell: thumbnails, or where the images come from.
	 *
	 * @param int $term_id Category ID.
	 * @return string
	 */
	private function thumbnails_html( int $term_id ): string {
		$images = Settings::get_valid_images( $term_id );
		if ( ! $images ) {
			$pool = ( new Category_Resolver() )->resolve_terms( array( $term_id ) );
			if ( $pool && 'parent' === $pool['source'] ) {
				/* translators: %s: parent category name. */
				return '<span class="cfir-thumbs__muted">' . esc_html( sprintf( __( 'Uses %s’s images', 'wp-category-featured-image-rotator' ), Category_Resolver::pool_name( $pool ) ) ) . '</span>';
			}
			return '<span class="cfir-thumbs__muted">' . esc_html__( 'Add images', 'wp-category-featured-image-rotator' ) . '</span>';
		}
		$shown = 6;
		$html  = '<span class="cfir-thumbs">';
		foreach ( array_slice( $images, 0, $shown ) as $image_id ) {
			$html .= wp_get_attachment_image( $image_id, array( 32, 32 ) );
		}
		if ( count( $images ) > $shown ) {
			$html .= '<span class="cfir-thumbs__more">+' . esc_html( number_format_i18n( count( $images ) - $shown ) ) . '</span>';
		}
		$html .= '</span>';
		if ( ! Settings::is_pool_enabled( $term_id ) ) {
			$html .= ' <span class="cfir-thumbs__muted">' . esc_html__( 'Not used for new posts', 'wp-category-featured-image-rotator' ) . '</span>';
		}
		return $html;
	}

	/**
	 * A sortable grid of images with an "Add images" button.
	 *
	 * The "Next" badge is placed by the script from the rotation state, so it
	 * follows the images as they are reordered.
	 *
	 * @param string $field  Input name for the attachment IDs.
	 * @param int[]  $images Attachment IDs, in order.
	 * @param array  $state  Rotation state.
	 */
	private function image_picker( string $field, array $images, array $state ): void {
		$show_next = 'round_robin' === Settings::get()['rotation_mode'];
		?>
		<div class="cfir-picker" data-field="<?php echo esc_attr( $field ); ?>" data-last="<?php echo esc_attr( (string) $state['l'] ); ?>" data-position="<?php echo esc_attr( (string) $state['p'] ); ?>" data-show-next="<?php echo $show_next ? '1' : '0'; ?>">
			<ul class="cfir-picker__list">
				<?php foreach ( $images as $image_id ) : ?>
					<?php $file = wp_basename( (string) get_attached_file( $image_id ) ); ?>
					<li class="cfir-picker__item" data-id="<?php echo esc_attr( (string) $image_id ); ?>" data-name="<?php echo esc_attr( $file ); ?>">
						<?php echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'alt' => $file ) ); ?>
						<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $image_id ); ?>" />
					</li>
				<?php endforeach; ?>
			</ul>
			<button type="button" class="button cfir-picker__add"><?php esc_html_e( 'Add images', 'wp-category-featured-image-rotator' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Sortable priority list of the categories that have images.
	 *
	 * @param string $field Input name for the category IDs.
	 */
	private function priority_list( string $field ): void {
		$with_images = array_values( array_filter( Settings::precedence(), static fn( $term_id ) => (bool) Settings::get_valid_images( $term_id ) ) );
		if ( count( $with_images ) < 2 ) {
			// Keep the saved order for when more categories get images.
			foreach ( Settings::get()['precedence'] as $term_id ) {
				printf( '<input type="hidden" name="%s" value="%d" />', esc_attr( $field ), (int) $term_id );
			}
			echo '<p class="description">' . esc_html__( 'This matters once two or more categories have images.', 'wp-category-featured-image-rotator' ) . '</p>';
			return;
		}
		?>
		<ol class="cfir-priority">
			<?php
			foreach ( $with_images as $term_id ) :
				$term = get_term( $term_id, Settings::TAXONOMY );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$parents = self::parent_path( $term );
				?>
				<li class="cfir-priority__item">
					<span class="dashicons dashicons-menu" aria-hidden="true"></span>
					<strong><?php echo esc_html( $term->name ); ?></strong>
					<?php if ( '' !== $parents ) : ?>
						<span class="description"><?php echo esc_html( $parents ); ?></span>
					<?php endif; ?>
					<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $term_id ); ?>" />
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="description"><?php esc_html_e( 'Drag to reorder. When a post is in more than one of these categories, the higher one is used.', 'wp-category-featured-image-rotator' ); ?></p>
		<?php
	}

	/**
	 * "in News › Technology".
	 *
	 * @param string $path Parent path from parent_path().
	 * @return string
	 */
	private static function in_parent( string $path ): string {
		/* translators: %s: parent categories, e.g. "News › Technology". */
		return sprintf( __( 'in %s', 'wp-category-featured-image-rotator' ), $path );
	}

	/**
	 * "News › Technology" for a subcategory, or '' for a top-level one.
	 *
	 * @param \WP_Term $term Category.
	 * @return string
	 */
	private static function parent_path( \WP_Term $term ): string {
		$names = array();
		foreach ( array_reverse( get_ancestors( (int) $term->term_id, Settings::TAXONOMY, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, Settings::TAXONOMY );
			if ( $ancestor instanceof \WP_Term ) {
				$names[] = $ancestor->name;
			}
		}
		return implode( ' › ', $names );
	}

	/**
	 * "Existing posts": find published posts without a featured image, then
	 * add images to them. Nothing is scanned until the administrator asks, and
	 * the work happens in small batches (see admin.js and Bulk_Processor).
	 */
	private function existing_posts(): void {
		?>
		<h2><?php esc_html_e( 'Existing posts', 'wp-category-featured-image-rotator' ); ?></h2>
		<div class="cfir-bulk">
			<p><?php esc_html_e( 'Give a featured image to posts that were published without one. The oldest posts go first, using the same images and order as new posts.', 'wp-category-featured-image-rotator' ); ?></p>
			<p>
				<button type="button" class="button cfir-bulk__check"><?php esc_html_e( 'Check existing posts', 'wp-category-featured-image-rotator' ); ?></button>
				<button type="button" class="button button-primary cfir-bulk__start" hidden><?php esc_html_e( 'Add featured images', 'wp-category-featured-image-rotator' ); ?></button>
				<button type="button" class="button cfir-bulk__cancel" hidden><?php esc_html_e( 'Stop', 'wp-category-featured-image-rotator' ); ?></button>
				<button type="button" class="button-link cfir-bulk__toggle" aria-expanded="false" aria-controls="cfir-bulk-preview" hidden><?php esc_html_e( 'Show which posts', 'wp-category-featured-image-rotator' ); ?></button>
			</p>
			<div class="cfir-bulk__status" aria-live="polite">
				<progress class="cfir-bulk__progress" max="100" value="0" hidden></progress>
				<p class="cfir-bulk__message"></p>
			</div>
			<div id="cfir-bulk-preview" hidden>
				<table class="widefat striped cfir-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Post', 'wp-category-featured-image-rotator' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Published', 'wp-category-featured-image-rotator' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Image from', 'wp-category-featured-image-rotator' ); ?></th>
						</tr>
					</thead>
					<tbody class="cfir-bulk__rows"></tbody>
				</table>
				<p class="description cfir-bulk__more" hidden>
					<?php
					/* translators: %d: number of posts shown. */
					echo esc_html( sprintf( __( 'Showing the oldest %d.', 'wp-category-featured-image-rotator' ), Bulk_Processor::PREVIEW_LIMIT ) );
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * "Recently added": the last automatic assignments.
	 */
	private function recent(): void {
		$history = get_option( Settings::HISTORY_OPTION, array() );
		$history = array_slice( is_array( $history ) ? $history : array(), 0, self::RECENT_LIMIT );
		if ( ! $history ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Recently added', 'wp-category-featured-image-rotator' ); ?></h2>
		<table class="widefat striped cfir-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Post', 'wp-category-featured-image-rotator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Image', 'wp-category-featured-image-rotator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Image from', 'wp-category-featured-image-rotator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'wp-category-featured-image-rotator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $history as $entry ) :
					$entry = wp_parse_args(
						(array) $entry,
						array(
							'post_id'  => 0,
							'image_id' => 0,
							'term_id'  => 0,
							'source'   => '',
							'time'     => 0,
						)
					);
					?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $entry['post_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $entry['post_id'] ) ); ?></a></td>
						<td><?php echo wp_get_attachment_image( (int) $entry['image_id'], array( 40, 40 ) ); ?></td>
						<td><?php echo esc_html( Category_Resolver::source_label( $entry ) ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
