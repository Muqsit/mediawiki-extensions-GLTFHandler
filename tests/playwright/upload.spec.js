'use strict';

/* global customElements, document, mw */

const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );

async function getToken( request, type ) {
	const response = await request.get( '/api.php', {
		params: { action: 'query', meta: 'tokens', type, format: 'json' }
	} );
	const body = await response.json();
	expect( response.ok(), JSON.stringify( body ) ).toBe( true );
	return body.query.tokens[ `${ type }token` ];
}

async function requestUpload( request, filename, mimeType, buffer, token ) {
	const response = await request.post( '/api.php', {
		multipart: {
			action: 'upload',
			filename,
			ignorewarnings: '1',
			token,
			format: 'json',
			file: { name: filename, mimeType, buffer }
		}
	} );
	const body = await response.json();
	expect( response.ok(), JSON.stringify( body ) ).toBe( true );
	return body;
}

async function upload( request, filename, mimeType, buffer, token ) {
	const body = await requestUpload( request, filename, mimeType, buffer, token );
	expect( body.error, JSON.stringify( body ) ).toBeUndefined();
	expect( body.upload.result, JSON.stringify( body ) ).toBe( 'Success' );
}

async function rejectUpload( request, filename, mimeType, buffer, token ) {
	const body = await requestUpload( request, filename, mimeType, buffer, token );
	expect( body.error, JSON.stringify( body ) ).toBeDefined();
	expect( body.upload, JSON.stringify( body ) ).toBeUndefined();
}

test( 'accepts valid and rejects invalid GLB and glTF uploads', async ( { page, request } ) => {
	const username = process.env.MW_USERNAME || 'Admin';
	const password = process.env.MW_PASSWORD || 'AdminPassword123!';
	const loginToken = await getToken( request, 'login' );
	const loginResponse = await request.post( '/api.php', {
		form: { action: 'login', lgname: username, lgpassword: password, lgtoken: loginToken, format: 'json' }
	} );
	const loginBody = await loginResponse.json();
	expect( loginBody.login.result, JSON.stringify( loginBody ) ).toBe( 'Success' );

	const csrfToken = await getToken( request, 'csrf' );
	const suffix = Date.now();
	const glb = fs.readFileSync( path.join( __dirname, '..', 'resources', 'BoxInterleaved.glb' ) );
	const gltf = fs.readFileSync( path.join( __dirname, '..', 'resources', 'TriangleDraco.gltf' ) );
	const negativeDirectory = path.join( __dirname, '..', 'resources', 'negative' );
	const negativeGlb = fs.readFileSync( path.join( negativeDirectory, 'Mesh_PrimitiveRestart_00.glb' ) );
	const negativeGltf = fs.readFileSync( path.join( negativeDirectory, 'Mesh_PrimitiveRestart_00.gltf' ) );
	const validGltfName = `Playwright-${ suffix }.gltf`;
	await upload( request, `Playwright-${ suffix }.glb`, 'model/gltf-binary', glb, csrfToken );
	await upload( request, validGltfName, 'model/gltf+json', gltf, csrfToken );
	await rejectUpload( request, `Invalid-Playwright-${ suffix }.glb`, 'model/gltf-binary', negativeGlb, csrfToken );
	await rejectUpload( request, `Invalid-Playwright-${ suffix }.gltf`, 'model/gltf+json', negativeGltf, csrfToken );

	const decoderRequests = [];
	page.on( 'request', ( assetRequest ) => {
		if ( /\/draco\/draco_(wasm_wrapper\.js|decoder\.wasm)$/.test( assetRequest.url() ) ) {
			decoderRequests.push( assetRequest.url() );
		}
	} );
	await page.goto( `/index.php/File:${ validGltfName }` );
	await page.waitForFunction( () => Boolean( document.querySelector( 'model-viewer' ).loaded ) );
	expect( decoderRequests ).toHaveLength( 2 );
	expect( await page.evaluate( () => {
		const ModelViewer = customElements.get( 'model-viewer' );
		const assets = mw.config.get( 'wgExtensionAssetsPath' );
		const decoders = assets + '/GLTFHandler/resources/ext.gltfHandler/decoders/';
		return {
			draco: ModelViewer.dracoDecoderLocation === decoders + 'draco/',
			ktx2: ModelViewer.ktx2TranscoderLocation === decoders + 'basis/',
			lottie: ModelViewer.lottieLoaderLocation
		};
	} ) ).toEqual( { draco: true, ktx2: true, lottie: '' } );
} );
