jQuery( document ).ready(function ($) {

	$( '.if-js-closed' ).removeClass( 'if-js-closed' ).addClass( 'closed' )

	$( '.postbox' ).children( 'h3' ).on('click', function () {
		$( this.parentNode ).toggleClass( 'closed' )
	})

	/* Handle clicks to add/remove sites to/from selected list */
	$( 'input[name=assign]' ).on('click', function () {
		move( 'from', 'to' )
	})

	$( 'input[name=unassign]' ).on('click', function () {
		move( 'to', 'from' )
	})

	/* Select all sites in "selected" box when submitting */
	$( '#edit-network-form' ).on('submit', function () {
		$( '#to' ).children( 'option:enabled' ).prop( 'selected', true )
		$( '#from' ).children( 'option:enabled' ).prop( 'selected', true )
	})

	function move(from, to) {
		$( '#' + from ).children( 'option:selected' ).each(function () {
			$( '#' + to ).append( $( this ).clone() )
			$( this ).remove()
		})
	}
})
