/* Lumaha Simple Raffle Tickets: one row per book/ticket, books fill in from the first number. */
jQuery( function ( $ ) {
	$( '.lsrt' ).each( function () {
		var $box   = $( this );
		var $form  = $box.closest( 'form.cart' );
		var $rows  = $box.find( '.lsrt-rows' );
		var sizes  = $box.data( 'sizes' ) || {};
		// Let the theme's flex row wrap so the add to cart button stays on screen (see raffle.css).
		$box.parent().addClass( 'lsrt-wrap' );
		var size   = parseInt( $box.data( 'default-size' ), 10 ) || 0;

		function qty() {
			var q = parseInt( $form.find( 'input.qty' ).val(), 10 );
			return q > 0 ? q : 1;
		}

		function fill( $row ) {
			var v   = parseInt( $row.find( 'input' ).val(), 10 );
			var out = '';
			if ( size > 1 && v > 0 ) {
				var nums = [];
				for ( var i = 0; i < size; i++ ) { nums.push( v + i ); }
				out = 'Tickets: ' + nums.join( ', ' );
			}
			$row.find( '.lsrt-fill' ).text( out );
		}

		var drawnN = -1, drawnSize = -1;
		var refill = $box.data( 'old' ) || [];

		function render() {
			var n = qty();
			// Only redraw when the number of rows or the book size changes, so focus isn't lost.
			if ( n === drawnN && size === drawnSize ) { return; }
			drawnN = n; drawnSize = size;
			var old = $rows.find( 'input' ).map( function () { return this.value; } ).get();
			$rows.empty();
			if ( ! size ) { $box.hide(); return; }
			$box.show();
			if ( ! old.length && refill.length ) { old = refill; refill = []; }
			for ( var i = 0; i < n; i++ ) {
				var label = size > 1
					? 'Book ' + ( i + 1 ) + ' (' + size + ' tickets): first ticket number'
					: 'Ticket ' + ( i + 1 ) + ': ticket number';
				var id   = 'lsrt-' + i;
				var $row = $( '<div class="lsrt-row"></div>' );
				$row.append( $( '<label></label>' ).attr( 'for', id ).text( label ) );
				$row.append( $( '<input type="number" min="1" step="1" inputmode="numeric" required name="lsrt_start[]">' ).attr( 'id', id ).val( old[ i ] || '' ) );
				$row.append( '<div class="lsrt-fill" aria-live="polite"></div>' );
				$rows.append( $row );
				fill( $row );
			}
		}

		$form.on( 'found_variation', function ( e, v ) {
			size = parseInt( sizes[ v.variation_id ], 10 ) || 1;
			render();
		} );
		$form.on( 'reset_data hide_variation', function () {
			if ( ! $.isEmptyObject( sizes ) ) { size = 0; render(); }
		} );
		$form.on( 'change input', 'input.qty', render );
		$form.on( 'click', '.plus, .minus', function () { setTimeout( render, 0 ); } );
		$rows.on( 'input', 'input', function () { fill( $( this ).closest( '.lsrt-row' ) ); } );

		render();
	} );
} );
