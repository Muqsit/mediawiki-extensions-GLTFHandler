'use strict';

/* global document, mw, window */

const { test, expect } = require( '@playwright/test' );

function getModuleNames( requestUrl ) {
	const url = new URL( requestUrl );
	return url.pathname.endsWith( '/load.php' ) ? url.searchParams.get( 'modules' ) : null;
}

test( 'extension modules load without breaking the browser runtime', async ( { page } ) => {
	const pageErrors = [];

	page.on( 'pageerror', ( error ) => {
		pageErrors.push( error.message );
	} );

	await page.goto( '/index.php/Main_Page' );
	await page.waitForFunction( () => {
		if ( !window.mw ) {
			return false;
		}
		const scriptState = mw.loader.getState( 'ext.gltfHandler.scripts' );
		return scriptState === 'ready' || scriptState === 'error';
	} );

	const runtime = await page.evaluate( () => ( {
		jquery: typeof window.jQuery,
		mediaWiki: typeof window.mw,
		scriptModuleState: mw.loader.getState( 'ext.gltfHandler.scripts' )
	} ) );

	expect( runtime ).toEqual( {
		jquery: 'function',
		mediaWiki: 'object',
		scriptModuleState: 'ready'
	} );
	expect( pageErrors ).toEqual( [] );
} );

test( 'core scripts survive a model-viewer bundle failure', async ( { page } ) => {
	let vendorRequestFailed = false;

	await page.route( '**/load.php?**', async ( route ) => {
		if ( getModuleNames( route.request().url() ) === 'ext.gltfHandler' ) {
			vendorRequestFailed = true;
			await route.fulfill( {
				contentType: 'text/javascript',
				body: '.invalid'
			} );
			return;
		}
		await route.continue();
	} );

	await page.goto( '/index.php/Main_Page' );
	await page.waitForFunction( () => typeof window.jQuery === 'function' && typeof window.mw === 'object' );
	await page.evaluate( () => mw.loader.load( 'ext.gltfHandler' ) );
	await expect.poll( () => vendorRequestFailed ).toBe( true );

	expect( await page.evaluate( () => window.jQuery( document.createElement( 'div' ) ).length === 1 ) ).toBe( true );
} );
