'use strict';

/* global mw, window */

const { test, expect } = require( '@playwright/test' );

function getModuleNames( requestUrl ) {
	const url = new URL( requestUrl );
	return url.pathname.endsWith( '/load.php' ) ? url.searchParams.get( 'modules' ) : null;
}

test( 'extension modules load without breaking the browser runtime', async ( { page } ) => {
	const pageErrors = [];
	const moduleRequests = [];

	page.on( 'pageerror', ( error ) => {
		pageErrors.push( error.message );
	} );
	page.on( 'request', ( request ) => {
		const modules = getModuleNames( request.url() );
		if ( modules !== null ) {
			moduleRequests.push( modules );
		}
	} );

	await page.goto( '/index.php/Main_Page' );
	await page.waitForFunction( () => {
		if ( !window.mw ) {
			return false;
		}
		const vendorState = mw.loader.getState( 'ext.gltfHandler' );
		const scriptState = mw.loader.getState( 'ext.gltfHandler.scripts' );
		return ( vendorState === 'ready' || vendorState === 'error' ) &&
			( scriptState === 'ready' || scriptState === 'error' );
	} );

	const runtime = await page.evaluate( () => ( {
		jquery: typeof window.jQuery,
		mediaWiki: typeof window.mw,
		moduleState: mw.loader.getState( 'ext.gltfHandler' ),
		scriptModuleState: mw.loader.getState( 'ext.gltfHandler.scripts' ),
		modelViewer: Boolean( window.customElements.get( 'model-viewer' ) )
	} ) );

	expect( runtime ).toEqual( {
		jquery: 'function',
		mediaWiki: 'object',
		moduleState: 'ready',
		scriptModuleState: 'ready',
		modelViewer: true
	} );
	expect( pageErrors ).toEqual( [] );

	const vendorRequest = moduleRequests.find( ( modules ) => modules === 'ext.gltfHandler' );
	expect( vendorRequest ).toBeDefined();
	expect( vendorRequest ).not.toContain( 'jquery' );
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
	await expect.poll( () => vendorRequestFailed ).toBe( true );

	const jqueryWorks = await page.evaluate( () => window.jQuery( '<div>' ).length === 1 );
	expect( jqueryWorks ).toBe( true );
} );
