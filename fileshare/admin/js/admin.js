/* FileShare 管理画面 — ドラッグ&ドロップとコピー */
( function () {
	'use strict';

	var i18n = ( window.FileShareAdmin && window.FileShareAdmin.i18n ) || {};

	function formatSize( bytes ) {
		if ( bytes === 0 ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = Math.floor( Math.log( bytes ) / Math.log( 1024 ) );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
	}

	function renderList( input, list ) {
		list.innerHTML = '';
		var files = input.files;
		if ( ! files || files.length === 0 ) {
			return;
		}
		for ( var i = 0; i < files.length; i++ ) {
			var li = document.createElement( 'li' );
			var name = document.createElement( 'span' );
			name.textContent = files[ i ].name;
			var size = document.createElement( 'span' );
			size.textContent = formatSize( files[ i ].size );
			li.appendChild( name );
			li.appendChild( size );
			list.appendChild( li );
		}
	}

	function initDropzone() {
		var zone = document.getElementById( 'fileshare-dropzone' );
		var input = document.getElementById( 'fileshare-input' );
		var list = document.getElementById( 'fileshare-filelist' );
		if ( ! zone || ! input || ! list ) {
			return;
		}

		input.addEventListener( 'change', function () {
			renderList( input, list );
		} );

		[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
			zone.addEventListener( ev, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				zone.classList.add( 'is-dragover' );
			} );
		} );

		[ 'dragleave', 'drop' ].forEach( function ( ev ) {
			zone.addEventListener( ev, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				zone.classList.remove( 'is-dragover' );
			} );
		} );

		zone.addEventListener( 'drop', function ( e ) {
			if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length ) {
				input.files = e.dataTransfer.files;
				renderList( input, list );
			}
		} );
	}

	function initCopy() {
		document.querySelectorAll( '.fileshare-copy-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var text = btn.getAttribute( 'data-copy' ) || '';
				var done = function () {
					var original = btn.textContent;
					btn.textContent = i18n.copyDone || 'Copied';
					setTimeout( function () {
						btn.textContent = original;
					}, 1500 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done );
				} else {
					var tmp = document.createElement( 'textarea' );
					tmp.value = text;
					document.body.appendChild( tmp );
					tmp.select();
					document.execCommand( 'copy' );
					document.body.removeChild( tmp );
					done();
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initDropzone();
		initCopy();
	} );
} )();
