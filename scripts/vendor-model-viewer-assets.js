'use strict';

const { copyFileSync, mkdirSync, readFileSync, writeFileSync } = require( 'fs' );
const path = require( 'path' );

const bundlePath = 'resources/ext.gltfHandler/model-viewer-umd.min.js';
const remoteDefaults = [
	'https://www.gstatic.com/draco/versioned/decoders/1.5.6/',
	'https://www.gstatic.com/basis-universal/versioned/2021-04-15-ba1c3e4/',
	'https://cdn.jsdelivr.net/npm/three@0.149.0/examples/jsm/loaders/LottieLoader.js'
];
let bundle = readFileSync( bundlePath, 'utf8' );
for ( const url of remoteDefaults ) {
	if ( !bundle.includes( url ) ) {
		throw new Error( `model-viewer no longer contains expected remote default: ${ url }` );
	}
	bundle = bundle.replace( url, '' );
}
writeFileSync( bundlePath, bundle );

for ( const file of [ 'draco/draco_decoder.js', 'draco/draco_decoder.wasm', 'draco/draco_wasm_wrapper.js', 'basis/basis_transcoder.js', 'basis/basis_transcoder.wasm' ] ) {
	const source = file.startsWith( 'draco/' ) ? file.replace( 'draco/', 'draco/gltf/' ) : file;
	const destination = path.join( 'resources/ext.gltfHandler/decoders', file );
	// These paths are derived exclusively from the fixed list above.
	// eslint-disable-next-line security/detect-non-literal-fs-filename
	mkdirSync( path.dirname( destination ), { recursive: true } );
	copyFileSync( path.join( 'node_modules/three/examples/jsm/libs', source ), destination );
}
