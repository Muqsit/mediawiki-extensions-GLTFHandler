$( () => {
	// simulate width=100%, height=auto using javascript
	const elements = document.querySelectorAll( '.model-viewer-dynsize[data-width][data-height]' );
	if ( elements.length === 0 ) {
		return;
	}

	const decoderPath = mw.config.get( 'wgExtensionAssetsPath' ) + '/GLTFHandler/resources/ext.gltfHandler/decoders/';
	window.ModelViewerElement = {
		dracoDecoderLocation: decoderPath + 'draco/',
		ktx2TranscoderLocation: decoderPath + 'basis/',
		lottieLoaderLocation: ''
	};
	mw.loader.load( 'ext.gltfHandler' );
	window.addEventListener( 'resize', () => {
		for ( const element of elements ) {
			const figureNode = element.closest( 'figure' );
			if ( figureNode === null || figureNode.parentNode === null ) {
				continue;
			}

			const width = parseFloat( element.getAttribute( 'data-width' ) );
			const height = parseFloat( element.getAttribute( 'data-height' ) );
			const availableSpace = figureNode.parentNode.getBoundingClientRect();

			const newWidth = Math.min( availableSpace.width, width );
			element.style.width = newWidth + 'px';
			element.style.height = ( ( height / width ) * newWidth ) + 'px';
		}
	} );
} );
