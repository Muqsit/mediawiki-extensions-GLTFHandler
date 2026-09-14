'use strict';

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

function makeEmbeddedGltf() {
	const vertices = Buffer.alloc( 36 );
	const values = [ 0, 0, 0, 1, 0, 0, 0, 1, 0 ];
	values.forEach( ( value, index ) => vertices.writeFloatLE( value, index * 4 ) );
	return Buffer.from( JSON.stringify( {
		scene: 0,
		scenes: [ { nodes: [ 0 ] } ],
		nodes: [ { mesh: 0 } ],
		meshes: [ { primitives: [ { attributes: { POSITION: 0 } } ] } ],
		buffers: [ { uri: `data:application/octet-stream;base64,${ vertices.toString( 'base64' ) }`, byteLength: vertices.length } ],
		bufferViews: [ { buffer: 0, byteLength: vertices.length, target: 34962 } ],
		accessors: [ { bufferView: 0, componentType: 5126, count: 3, type: 'VEC3', min: [ 0, 0, 0 ], max: [ 1, 1, 0 ] } ],
		asset: { version: '2.0' }
	} ) );
}

test( 'accepts valid and rejects invalid GLB and glTF uploads', async ( { request } ) => {
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
	const negativeDirectory = path.join( __dirname, '..', 'resources', 'negative' );
	const negativeGlb = fs.readFileSync( path.join( negativeDirectory, 'Mesh_PrimitiveRestart_00.glb' ) );
	const negativeGltf = fs.readFileSync( path.join( negativeDirectory, 'Mesh_PrimitiveRestart_00.gltf' ) );
	await upload( request, `Playwright-${ suffix }.glb`, 'model/gltf-binary', glb, csrfToken );
	await upload( request, `Playwright-${ suffix }.gltf`, 'model/gltf+json', makeEmbeddedGltf(), csrfToken );
	await rejectUpload( request, `Invalid-Playwright-${ suffix }.glb`, 'model/gltf-binary', negativeGlb, csrfToken );
	await rejectUpload( request, `Invalid-Playwright-${ suffix }.gltf`, 'model/gltf+json', negativeGltf, csrfToken );
} );
