const { chromium } = require( '@playwright/test' );
const path = require( 'path' );

/** Log in once and share the admin session with every test. */
module.exports = async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto( `${ process.env.BORSFLOW_E2E_URL }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page.context().storageState( { path: path.join( __dirname, '.auth.json' ) } );
	await browser.close();
};
