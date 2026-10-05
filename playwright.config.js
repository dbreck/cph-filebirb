/**
 * Browser tests against wp-env (http://localhost:8888, admin/password).
 * Run: npm run test:e2e   (wp-env must already be running)
 */
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60000,
	reporter: 'list',
	outputDir: process.env.CPHFB_E2E_OUT || require( 'os' ).tmpdir() + '/cphfb-e2e',
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		viewport: { width: 1440, height: 900 },
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
