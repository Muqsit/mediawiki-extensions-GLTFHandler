'use strict';

const { defineConfig } = require( '@playwright/test' );

const webServerCommand = process.env.PLAYWRIGHT_WEB_SERVER_COMMAND;
const baseURL = process.env.MW_SERVER_URL || 'http://127.0.0.1:8080';

module.exports = defineConfig( {
	testDir: './tests/playwright',
	fullyParallel: true,
	forbidOnly: Boolean( process.env.CI ),
	retries: process.env.CI ? 2 : 0,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		baseURL,
		trace: 'retain-on-failure'
	},
	webServer: webServerCommand ? {
		command: webServerCommand,
		url: `${ baseURL }/index.php/Main_Page`,
		reuseExistingServer: !process.env.CI,
		timeout: 120000
	} : undefined
} );
