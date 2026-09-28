// @ts-check
const { defineConfig } = require( '@playwright/test' );
const os = require( 'os' );
const path = require( 'path' );

const WP_PORT = process.env.BORSFLOW_E2E_PORT || '8890';
const CRM_PORT = process.env.BORSFLOW_E2E_CRM_PORT || '4010';
const WP_DIR = process.env.BORSFLOW_E2E_WP_DIR || path.join( os.tmpdir(), 'borsflow-e2e-wp' );

process.env.BORSFLOW_E2E_URL = `http://127.0.0.1:${ WP_PORT }`;
process.env.BORSFLOW_E2E_CRM = `http://127.0.0.1:${ CRM_PORT }`;
process.env.BORSFLOW_E2E_WP_DIR = WP_DIR;

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	// One PHP built-in server and one SQLite file: run serially.
	workers: 1,
	fullyParallel: false,
	// Specs share one seeded site and run in file order (01-, 02-, 03-); the builder spec
	// mutates the form last. Retrying would replay against mutated data, so don't.
	retries: 0,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : 'list',
	use: {
		baseURL: process.env.BORSFLOW_E2E_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		viewport: { width: 1440, height: 1000 },
	},
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	webServer: [
		{
			command: `php tests/bin/install-wp.php ${ WP_DIR } http://127.0.0.1:${ WP_PORT } && php tests/e2e/fixtures.php ${ WP_DIR } http://127.0.0.1:${ CRM_PORT } && php -S 127.0.0.1:${ WP_PORT } -t ${ WP_DIR }`,
			url: `http://127.0.0.1:${ WP_PORT }/wp-login.php`,
			reuseExistingServer: false,
			timeout: 120000,
		},
		{
			command: `BORSFLOW_MOCK_KEY=test-key php -S 127.0.0.1:${ CRM_PORT } tools/mock-crm/server.php`,
			url: `http://127.0.0.1:${ CRM_PORT }/__reset`,
			reuseExistingServer: false,
		},
	],
} );
