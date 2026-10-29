/**
 * Featured images admin: the image picker (category screen and settings
 * page), the priority list and "Add featured images" for existing posts.
 * Uses only core-bundled jQuery, jQuery UI Sortable, wp.media and wp.i18n.
 *
 * @package CategoryFeaturedImageRotator
 */
( function ( $, i18n ) {
	'use strict';

	var data = window.cfirAdmin || {};
	var domain = 'wp-category-featured-image-rotator';
	var __ = i18n.__;
	var _n = i18n._n;
	var sprintf = i18n.sprintf;

	/* Unsaved changes -------------------------------------------------------- */

	// Like Appearance → Menus: warn before leaving with unsaved image changes.
	var dirty = false;

	function changed( $picker ) {
		dirty = true;
		refresh( $picker );
	}

	$( document ).on( 'change', '.cfir-category-form input, .cfir-settings-form input', function () {
		dirty = true;
	} );
	$( document ).on( 'submit', 'form', function () {
		dirty = false;
	} );
	$( window ).on( 'beforeunload', function () {
		return dirty ? true : undefined;
	} );

	/* Image picker ---------------------------------------------------------- */

	function control( action, icon, label ) {
		return $( '<button type="button" class="cfir-picker__control"></button>' )
			.attr( { 'data-action': action, 'aria-label': label, title: label } )
			.append( $( '<span class="dashicons" aria-hidden="true"></span>' ).addClass( icon ) );
	}

	function decorate( $item ) {
		var name = String( $item.data( 'name' ) || '' );
		$( '<span class="cfir-picker__controls"></span>' )
			/* translators: %s: file name. */
			.append( control( 'earlier', 'dashicons-arrow-left-alt2', sprintf( __( 'Move %s earlier', domain ), name ) ) )
			/* translators: %s: file name. */
			.append( control( 'later', 'dashicons-arrow-right-alt2', sprintf( __( 'Move %s later', domain ), name ) ) )
			/* translators: %s: file name. */
			.append( control( 'remove', 'dashicons-no-alt', sprintf( __( 'Remove %s', domain ), name ) ) )
			.appendTo( $item );
	}

	// Mark the image the next post will get (same rule as the server).
	function refresh( $picker ) {
		var $items = $picker.find( '.cfir-picker__item' );
		$items.removeClass( 'is-next' ).find( '.cfir-picker__badge' ).remove();
		$picker.toggleClass( 'is-empty', ! $items.length );
		if ( '1' !== String( $picker.data( 'showNext' ) ) || ! $items.length ) {
			return;
		}

		var index = 0;
		if ( ! $picker.closest( 'form' ).find( '.cfir-restart' ).is( ':checked' ) ) {
			var last = String( $picker.data( 'last' ) );
			var position = parseInt( $picker.data( 'position' ), 10 );
			var $last = $items.filter( function () {
				return String( $( this ).data( 'id' ) ) === last;
			} );
			if ( '0' !== last && $last.length ) {
				index = ( $items.index( $last ) + 1 ) % $items.length;
			} else if ( position >= 0 ) {
				index = position % $items.length;
			}
		}
		$items.eq( index ).addClass( 'is-next' )
			.append( $( '<span class="cfir-picker__badge"></span>' ).text( __( 'Next', domain ) ) );
	}

	function addImage( $picker, attachment ) {
		var exists = $picker.find( '.cfir-picker__item' ).filter( function () {
			return String( $( this ).data( 'id' ) ) === String( attachment.id );
		} ).length;
		if ( exists ) {
			return;
		}
		var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
		var $item = $( '<li class="cfir-picker__item"></li>' )
			.attr( { 'data-id': attachment.id, 'data-name': attachment.filename } )
			.append( $( '<img />' ).attr( { src: url, alt: attachment.filename } ) )
			.append( $( '<input type="hidden" />' ).attr( 'name', $picker.data( 'field' ) ).val( attachment.id ) );
		$picker.find( '.cfir-picker__list' ).append( $item );
		decorate( $item );
	}

	// Drag-and-drop is a convenience; the move buttons work without it.
	var canSort = typeof $.fn.sortable === 'function';

	$( '.cfir-picker' ).each( function () {
		var $picker = $( this );
		$picker.find( '.cfir-picker__item' ).each( function () {
			decorate( $( this ) );
		} );
		if ( canSort ) {
			$picker.find( '.cfir-picker__list' ).sortable( {
				items: '> .cfir-picker__item',
				tolerance: 'pointer',
				update: function () {
					changed( $picker );
				},
			} );
		}
		refresh( $picker );
	} );

	$( document ).on( 'change', '.cfir-restart', function () {
		$( this ).closest( 'form' ).find( '.cfir-picker' ).each( function () {
			refresh( $( this ) );
		} );
	} );

	$( document ).on( 'click', '.cfir-picker .cfir-picker__control', function () {
		var $button = $( this );
		var $item = $button.closest( '.cfir-picker__item' );
		var $picker = $item.closest( '.cfir-picker' );
		var action = $button.data( 'action' );

		if ( 'remove' === action ) {
			var $neighbour = $item.next().length ? $item.next() : $item.prev();
			$item.remove();
			( $neighbour.length ? $neighbour.find( '[data-action="remove"]' ) : $picker.find( '.cfir-picker__add' ) ).trigger( 'focus' );
		} else {
			move( $item, action );
			$button.trigger( 'focus' );
		}
		changed( $picker );
	} );

	function move( $item, direction ) {
		if ( 'earlier' === direction ) {
			$item.prev().before( $item );
		} else {
			$item.next().after( $item );
		}
	}

	$( document ).on( 'click', '.cfir-picker__add', function () {
		if ( ! wp.media || 'function' !== typeof wp.media ) {
			return;
		}
		var $picker = $( this ).closest( '.cfir-picker' );
		var frame = $picker.data( 'frame' );
		if ( ! frame ) {
			frame = wp.media( {
				title: __( 'Choose featured images', domain ),
				button: { text: __( 'Add images', domain ) },
				library: { type: 'image' },
				multiple: 'add',
			} );
			frame.on( 'open', function () {
				frame.state().get( 'selection' ).reset();
			} );
			frame.on( 'select', function () {
				frame.state().get( 'selection' ).each( function ( attachment ) {
					addImage( $picker, attachment.toJSON() );
				} );
				changed( $picker );
			} );
			$picker.data( 'frame', frame );
		}
		frame.open();
	} );

	/* Priority -------------------------------------------------------------- */

	$( '.cfir-priority__item' ).each( function () {
		var name = $( this ).find( 'strong' ).text();
		$( '<span class="cfir-priority__controls"></span>' )
			/* translators: %s: category name. */
			.append( control( 'earlier', 'dashicons-arrow-up-alt2', sprintf( __( 'Move %s up', domain ), name ) ) )
			/* translators: %s: category name. */
			.append( control( 'later', 'dashicons-arrow-down-alt2', sprintf( __( 'Move %s down', domain ), name ) ) )
			.appendTo( this );
	} );
	$( document ).on( 'click', '.cfir-priority__item .cfir-picker__control', function () {
		move( $( this ).closest( '.cfir-priority__item' ), $( this ).data( 'action' ) );
		$( this ).trigger( 'focus' );
		dirty = true;
	} );
	if ( canSort ) {
		$( '.cfir-priority' ).sortable( {
			axis: 'y',
			update: function () {
				dirty = true;
			},
		} );
	}

	/* Existing posts -------------------------------------------------------- */

	var $bulk = $( '.cfir-bulk' );
	var $check = $bulk.find( '.cfir-bulk__check' );
	var $start = $bulk.find( '.cfir-bulk__start' );
	var $stop = $bulk.find( '.cfir-bulk__cancel' );
	var $toggle = $bulk.find( '.cfir-bulk__toggle' );
	var $progress = $bulk.find( '.cfir-bulk__progress' );
	var $message = $bulk.find( '.cfir-bulk__message' );
	var $rows = $bulk.find( '.cfir-bulk__rows' );
	var $more = $bulk.find( '.cfir-bulk__more' );
	var $preview = $( '#' + $toggle.attr( 'aria-controls' ) );
	var previewLimit = 50;
	var running = false;
	var stopping = false;
	var missing = 0;
	var addable = 0;
	var added = 0;
	var skipped = 0;

	$toggle.on( 'click', function () {
		var expanded = 'true' === $toggle.attr( 'aria-expanded' );
		$toggle.attr( 'aria-expanded', String( ! expanded ) ).text( expanded ? __( 'Show which posts', domain ) : __( 'Hide posts', domain ) );
		$preview.prop( 'hidden', expanded );
	} );

	function errorMessage( response ) {
		return response && response.data && response.data.message ? response.data.message : __( 'Something went wrong. Images already added are kept; please try again.', domain );
	}

	function setRunning( isRunning ) {
		running = isRunning;
		stopping = false;
		$check.prop( 'disabled', isRunning );
		$start.prop( 'disabled', isRunning );
		$stop.prop( 'hidden', ! isRunning ).prop( 'disabled', false );
		$progress.prop( 'hidden', ! isRunning );
	}

	function progress( result ) {
		var done = Math.min( result.next_offset, result.total );
		$progress.val( result.total ? Math.round( ( done / result.total ) * 100 ) : 100 );
		/* translators: 1: posts checked so far, 2: total published posts. */
		$message.text( sprintf( __( 'Checked %1$d of %2$d posts…', domain ), done, result.total ) );
	}

	function request( step, offset, onResult ) {
		$.post( data.ajaxUrl, {
			action: data.bulkAction,
			nonce: data.bulkNonce,
			step: step,
			offset: offset,
			want: Math.max( 0, previewLimit - $rows.children().length ),
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					setRunning( false );
					$message.text( errorMessage( response ) );
					return;
				}
				onResult( response.data );
			} )
			.fail( function ( xhr ) {
				setRunning( false );
				$message.text( errorMessage( xhr.responseJSON ) );
			} );
	}

	function addRow( row ) {
		var $title = row.edit ? $( '<a></a>' ).attr( 'href', row.edit ).text( row.title ) : $( '<span></span>' ).text( row.title );
		$( '<tr></tr>' )
			.append( $( '<td></td>' ).append( $title ) )
			.append( $( '<td></td>' ).text( row.date ) )
			.append( $( '<td></td>' ).text( row.source ) )
			.appendTo( $rows );
	}

	// Step 1: find the posts without a featured image.
	function check( offset ) {
		request( 'check', offset, function ( result ) {
			missing += result.missing;
			addable += result.addable;
			result.rows.forEach( addRow );
			progress( result );
			if ( stopping ) {
				setRunning( false );
				$message.text( __( 'Stopped.', domain ) );
			} else if ( ! result.done ) {
				check( result.next_offset );
			} else {
				setRunning( false );
				$check.text( __( 'Check again', domain ) );
				if ( ! missing ) {
					$message.text( __( 'Every published post has a featured image.', domain ) );
					return;
				}
				/* translators: %s: number of posts. */
				var text = sprintf( _n( '%s published post has no featured image.', '%s published posts have no featured image.', missing, domain ), missing.toLocaleString() );
				if ( addable === missing ) {
					text += ' ' + _n( 'It can be given one now.', 'All of them can be given one now.', missing, domain );
				} else if ( addable ) {
					/* translators: %s: number of posts. */
					text += ' ' + sprintf( _n( '%s of them can be given one now; the others are in categories without images.', '%s of them can be given one now; the others are in categories without images.', addable, domain ), addable.toLocaleString() );
				} else {
					text += ' ' + _n( 'Its categories have no images yet, so add images to them first.', 'Their categories have no images yet, so add images to them first.', missing, domain );
				}
				$message.text( text );
				$start.prop( 'hidden', ! addable );
				$toggle.prop( 'hidden', false );
				$more.prop( 'hidden', missing <= previewLimit );
			}
		} );
	}

	// Step 2: give them images.
	function assign( offset ) {
		request( 'assign', offset, function ( result ) {
			added += result.assigned;
			skipped += result.skipped;
			progress( result );
			var summary = sprintf(
				/* translators: %d: number of posts. */
				_n( 'A featured image was added to %d post.', 'Featured images were added to %d posts.', added, domain ),
				added
			);
			if ( skipped ) {
				summary += ' ' + sprintf(
					/* translators: %d: number of posts. */
					_n( '%d post could not be given an image, usually because none of its categories have images.', '%d posts could not be given an image, usually because none of their categories have images.', skipped, domain ),
					skipped
				);
			}
			if ( stopping ) {
				setRunning( false );
				$message.text( __( 'Stopped.', domain ) + ' ' + summary + ' ' + __( 'You can continue at any time.', domain ) );
			} else if ( ! result.done ) {
				assign( result.next_offset );
			} else {
				setRunning( false );
				// The list is out of date now: show the result instead.
				$start.prop( 'hidden', true );
				$toggle.prop( 'hidden', true ).attr( 'aria-expanded', 'false' ).text( __( 'Show which posts', domain ) );
				$preview.prop( 'hidden', true );
				$message.text( __( 'Done.', domain ) + ' ' + summary );
			}
		} );
	}

	$check.on( 'click', function () {
		missing = 0;
		addable = 0;
		$rows.empty();
		$start.prop( 'hidden', true );
		$toggle.prop( 'hidden', true );
		$preview.prop( 'hidden', true );
		setRunning( true );
		check( 0 );
	} );

	$start.on( 'click', function () {
		var question = sprintf(
			/* translators: %d: number of posts. */
			_n( 'Add a featured image to %d published post? You can remove it from the post afterwards if you change your mind.', 'Add featured images to %d published posts? You can remove them from the posts afterwards if you change your mind.', addable, domain ),
			addable
		);
		// eslint-disable-next-line no-alert -- Same confirmation core uses before bulk changes.
		if ( running || ! window.confirm( question ) ) {
			return;
		}
		added = 0;
		skipped = 0;
		setRunning( true );
		assign( 0 );
	} );

	$stop.on( 'click', function () {
		stopping = true;
		$stop.prop( 'disabled', true );
	} );
}( jQuery, wp.i18n ) );
