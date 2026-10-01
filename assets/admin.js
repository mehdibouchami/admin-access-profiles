( function () {
	var i18n = window.aapAdmin || {};
	var available = 'input[name="allowed[]"]:not(:disabled)';

	// Ticking a top-level menu ticks its available submenus.
	document.querySelectorAll( '.aap-tree .aap-top' ).forEach( function ( top ) {
		var parent = top.querySelector( ':scope > .aap-row input[type=checkbox]' );
		if ( ! parent || parent.disabled ) {
			return;
		}
		parent.addEventListener( 'change', function () {
			top.querySelectorAll( 'ul ' + available ).forEach( function ( box ) {
				box.checked = parent.checked;
			} );
		} );
	} );

	function setAll( checked ) {
		document.querySelectorAll( '.aap-tree ' + available ).forEach( function ( box ) {
			box.checked = checked;
		} );
	}
	document.querySelectorAll( '.aap-tick-all' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () { setAll( true ); } );
	} );
	document.querySelectorAll( '.aap-untick-all' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () { setAll( false ); } );
	} );

	document.querySelectorAll( '.aap-delete' ).forEach( function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( i18n.confirmDelete || 'Delete?' ) ) {
				event.preventDefault();
			}
		} );
	} );
} )();
