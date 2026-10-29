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
	var $category = $bulk.find( '.cfir-bulk__category' );
	var $from = $bulk.find( '.cfir-bulk__from' );
	var $to = $bulk.find( '.cfir-bulk__to' );
	var $check = $bulk.find( '.cfir-bulk__check' );
	var $results = $bulk.find( '.cfir-bulk__results' );
	var $rows = $bulk.find( '.cfir-bulk__rows' );
	var $all = $bulk.find( '.cfir-bulk__all' );
	var $more = $bulk.find( '.cfir-bulk__more' );
	var $addSelected = $bulk.find( '.cfir-bulk__add-selected' );
	var $addAll = $bulk.find( '.cfir-bulk__add-all' );
	var $stop = $bulk.find( '.cfir-bulk__cancel' );
	var $progress = $bulk.find( '.cfir-bulk__progress' );
	var $message = $bulk.find( '.cfir-bulk__message' );
	var previewLimit = 100;
	var perRequest = 20;
	var running = false;
	var stopping = false;
	var missing = 0;
	var addable = 0;
	var added = 0;
	var skipped = 0;

	function filters() {
		return { category: $category.val() || 0, from: $from.val(), to: $to.val() };
	}

	function errorMessage( response ) {
		return response && response.data && response.data.message ? response.data.message : __( 'Something went wrong. Images already added are kept; please try again.', domain );
	}

	function setRunning( isRunning ) {
		running = isRunning;
		stopping = false;
		$bulk.find( '.cfir-bulk__filters' ).find( 'select, input, button' ).prop( 'disabled', isRunning );
		$addAll.prop( 'disabled', isRunning );
		$stop.prop( 'hidden', ! isRunning ).prop( 'disabled', false );
		$progress.prop( 'hidden', ! isRunning );
		updateSelection();
	}

	function setProgress( done, total ) {
		$progress.val( total ? Math.round( ( Math.min( done, total ) / total ) * 100 ) : 100 );
	}

	function request( params, onResult ) {
		$.post( data.ajaxUrl, $.extend( { action: data.bulkAction, nonce: data.bulkNonce }, filters(), params ) )
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

	function selectable() {
		return $rows.find( '.cfir-bulk__pick:not(:disabled)' );
	}

	function updateSelection() {
		var count = selectable().filter( ':checked' ).length;
		/* translators: %d: number of selected posts. */
		$addSelected.text( count ? sprintf( _n( 'Add a featured image to %d selected post', 'Add featured images to %d selected posts', count, domain ), count ) : __( 'Add featured images to selected posts', domain ) );
		$addSelected.prop( 'disabled', running || ! count );
		$all.prop( 'checked', count > 0 && count === selectable().length );
	}

	function addRow( row ) {
		var $title = row.edit ? $( '<a></a>' ).attr( 'href', row.edit ).text( row.title ) : $( '<span></span>' ).text( row.title );
		var $pick = $( '<input type="checkbox" class="cfir-bulk__pick" />' )
			.val( row.id )
			/* translators: %s: post title. */
			.attr( 'aria-label', sprintf( __( 'Select %s', domain ), row.title ) )
			.prop( { checked: row.addable, disabled: ! row.addable } );
		$( '<tr></tr>' )
			.attr( 'data-id', row.id )
			.append( $( '<th scope="row" class="cfir-bulk__select"></th>' ).append( $pick ) )
			.append( $( '<td></td>' ).append( $title ) )
			.append( $( '<td></td>' ).text( row.date ) )
			.append( $( '<td class="cfir-bulk__source"></td>' ).text( row.source ) )
			.appendTo( $rows );
	}

	// The list no longer matches once the filters change.
	$bulk.find( '.cfir-bulk__filters' ).on( 'change', 'select, input', function () {
		if ( ! running ) {
			$results.prop( 'hidden', true );
			$message.text( '' );
		}
	} );

	$rows.on( 'change', '.cfir-bulk__pick', updateSelection );
	$all.on( 'change', function () {
		selectable().prop( 'checked', $all.prop( 'checked' ) );
		updateSelection();
	} );

	// Step 1: find matching posts without a featured image.
	function find( offset ) {
		request( { step: 'check', offset: offset, want: Math.max( 0, previewLimit - $rows.children().length ) }, function ( result ) {
			missing += result.missing;
			addable += result.addable;
			result.rows.forEach( addRow );
			setProgress( result.next_offset, result.total );
			/* translators: 1: posts checked so far, 2: matching published posts. */
			$message.text( sprintf( __( 'Checked %1$d of %2$d posts…', domain ), Math.min( result.next_offset, result.total ), result.total ) );
			if ( stopping ) {
				setRunning( false );
				$message.text( __( 'Stopped.', domain ) );
			} else if ( ! result.done ) {
				find( result.next_offset );
			} else {
				setRunning( false );
				showFound();
			}
		} );
	}

	function showFound() {
		if ( ! missing ) {
			$message.text( __( 'Every matching post already has a featured image.', domain ) );
			return;
		}
		/* translators: %s: number of posts. */
		var text = sprintf( _n( '%s matching post has no featured image.', '%s matching posts have no featured image.', missing, domain ), missing.toLocaleString() );
		if ( ! addable ) {
			text += ' ' + _n( 'Its categories have no images yet, so add images to them first.', 'Their categories have no images yet, so add images to them first.', missing, domain );
		} else if ( addable < missing ) {
			/* translators: %s: number of posts. */
			text += ' ' + sprintf( _n( '%s of them can be given one now; the others are in categories without images.', '%s of them can be given one now; the others are in categories without images.', addable, domain ), addable.toLocaleString() );
		}
		$message.text( text );
		$results.prop( 'hidden', false );
		$more.prop( 'hidden', missing <= previewLimit );
		var listedAddable = $rows.find( '.cfir-bulk__pick:not(:disabled)' ).length;
		// Offer "all matching" when more posts can get an image than are listed.
		$addAll.prop( 'hidden', addable <= listedAddable )
			/* translators: %s: number of posts. */
			.text( sprintf( _n( 'Add to all %s matching post', 'Add to all %s matching posts', addable, domain ), addable.toLocaleString() ) );
		updateSelection();
	}

	function summary() {
		/* translators: %d: number of posts. */
		var text = sprintf( _n( 'A featured image was added to %d post.', 'Featured images were added to %d posts.', added, domain ), added );
		if ( skipped ) {
			text += ' ' + sprintf(
				/* translators: %d: number of posts. */
				_n( '%d post could not be given an image.', '%d posts could not be given an image.', skipped, domain ),
				skipped
			);
		}
		return text;
	}

	// Step 2a: add images to the selected posts, showing the result on each row.
	function addSelected( ids, done ) {
		var batch = ids.slice( done, done + perRequest );
		request( { step: 'assign_selected', ids: batch }, function ( result ) {
			added += result.assigned;
			skipped += result.skipped;
			result.results.forEach( function ( item ) {
				var $row = $rows.find( 'tr[data-id="' + parseInt( item.id, 10 ) + '"]' );
				$row.find( '.cfir-bulk__pick' ).prop( { checked: false, disabled: true } );
				$row.find( '.cfir-bulk__source' ).text( item.message );
			} );
			done += batch.length;
			setProgress( done, ids.length );
			if ( stopping || done >= ids.length ) {
				setRunning( false );
				$message.text( ( stopping ? __( 'Stopped.', domain ) + ' ' : __( 'Done.', domain ) + ' ' ) + summary() );
			} else {
				addSelected( ids, done );
			}
		} );
	}

	// Step 2b: add images to every matching post.
	function addAll( offset ) {
		request( { step: 'assign', offset: offset }, function ( result ) {
			added += result.assigned;
			skipped += result.skipped;
			setProgress( result.next_offset, result.total );
			if ( stopping ) {
				setRunning( false );
				$message.text( __( 'Stopped.', domain ) + ' ' + summary() + ' ' + __( 'You can continue at any time.', domain ) );
			} else if ( ! result.done ) {
				addAll( result.next_offset );
			} else {
				setRunning( false );
				// The list is out of date now: show the result instead.
				$results.prop( 'hidden', true );
				$message.text( __( 'Done.', domain ) + ' ' + summary() );
			}
		} );
	}

	function confirmAdd( count ) {
		var question = sprintf(
			/* translators: %d: number of posts. */
			_n( 'Add a featured image to %d published post? You can remove it from the post afterwards if you change your mind.', 'Add featured images to %d published posts? You can remove them from the posts afterwards if you change your mind.', count, domain ),
			count
		);
		// eslint-disable-next-line no-alert -- Same confirmation core uses before bulk changes.
		return ! running && window.confirm( question );
	}

	$check.on( 'click', function () {
		missing = 0;
		addable = 0;
		$rows.empty();
		$results.prop( 'hidden', true );
		setRunning( true );
		find( 0 );
	} );

	$addSelected.on( 'click', function () {
		var ids = selectable().filter( ':checked' ).map( function () {
			return parseInt( this.value, 10 );
		} ).get();
		if ( ! ids.length || ! confirmAdd( ids.length ) ) {
			return;
		}
		added = 0;
		skipped = 0;
		setRunning( true );
		addSelected( ids, 0 );
	} );

	$addAll.on( 'click', function () {
		if ( ! confirmAdd( addable ) ) {
			return;
		}
		added = 0;
		skipped = 0;
		setRunning( true );
		addAll( 0 );
	} );

	$stop.on( 'click', function () {
		stopping = true;
		$stop.prop( 'disabled', true );
	} );
}( jQuery, wp.i18n ) );
