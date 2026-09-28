const { test, expect } = require( '@playwright/test' );
const { fixtures, runCron, crmLeads, crmMode } = require( './helpers' );

test.describe( 'Front-end form', () => {
	test.beforeEach( async ( { request } ) => {
		await crmMode( request, 'ok' );
	} );

	test( 'validates, reveals conditional fields, submits and syncs to the CRM', async ( { page, request } ) => {
		const { pageId } = fixtures();
		await page.goto( `/?page_id=${ pageId }` );
		const form = page.locator( 'form.bf-form' );
		const budget = form.locator( '[data-bf-field="budget"]' );

		await expect( budget ).toBeHidden();

		// Empty submit: inline errors, ARIA wiring, focus on the first invalid field.
		await form.locator( '.bf-submit' ).click();
		const name = form.locator( 'input[name="bf[name]"]' );
		await expect( name ).toBeFocused();
		await expect( name ).toHaveAttribute( 'aria-invalid', 'true' );
		const errorId = await name.getAttribute( 'aria-describedby' );
		await expect( page.locator( `#${ errorId }` ) ).toHaveText( 'This field is required.' );
		await expect( form.locator( '[data-bf-field="consent"] .bf-error' ) ).toHaveText( 'Please check this box to continue.' );

		// Conditional logic.
		await form.locator( 'select[name="bf[topic]"]' ).selectOption( 'sales' );
		await expect( budget ).toBeVisible();

		await name.fill( 'Ada Lovelace' );
		await form.locator( 'input[name="bf[email]"]' ).fill( 'ada@example.com' );
		await budget.locator( 'input' ).fill( '50' );
		await form.locator( 'input[name="bf[code]"]' ).fill( 'ab12' );
		await form.locator( 'input[name="bf[consent]"]' ).check();
		await form.locator( '.bf-submit' ).click();
		await expect( budget.locator( '.bf-error' ) ).toHaveText( 'Please enter a value of at least 100.' );
		await expect( form.locator( '[data-bf-field="code"] .bf-error' ) ).toHaveText( 'Use two letters and two digits' );

		// Backslash-escaped pattern ([A-Z]{2}\d{2}) must survive storage and work client-side.
		await form.locator( 'input[name="bf[code]"]' ).fill( 'AB12' );
		await budget.locator( 'input' ).fill( '2500' );
		const [ response ] = await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( '/submit' ) ),
			form.locator( '.bf-submit' ).click(),
		] );
		expect( response.status() ).toBe( 200 );

		const status = page.locator( '.bf-status' );
		await expect( status ).toContainText( 'Thanks!' );
		await expect( status ).toBeFocused();
		await expect( form ).toBeHidden();

		// Sync happens on cron, not during the request.
		const before = ( await crmLeads( request ) ).filter( ( l ) => l.email === 'ada@example.com' );
		expect( before ).toHaveLength( 0 );
		await runCron( request );
		const leads = ( await crmLeads( request ) ).filter( ( l ) => l.email === 'ada@example.com' );
		expect( leads ).toHaveLength( 1 );
		expect( leads[ 0 ] ).toMatchObject( { firstName: 'Ada', lastName: 'Lovelace', value: 2500 } );
		expect( leads[ 0 ].notes ).toContain( 'Promo code: AB12' );
	} );

	test( 'works without JavaScript', async ( { browser } ) => {
		const { pageId } = fixtures();
		const context = await browser.newContext( { javaScriptEnabled: false } );
		const page = await context.newPage();
		await page.goto( `/?page_id=${ pageId }` );

		await page.fill( 'input[name="bf[name]"]', 'No Script' );
		await page.fill( 'input[name="bf[email]"]', 'not-an-email' );
		await page.selectOption( 'select[name="bf[topic]"]', 'support' );
		await page.click( '.bf-submit' );

		await expect( page ).toHaveURL( /borsflow_result=/ );
		await expect( page.locator( '.bf-alert' ) ).toBeVisible();
		await expect( page.locator( 'input[name="bf[name]"]' ) ).toHaveValue( 'No Script' );
		await expect( page.locator( 'input[name="bf[email]"]' ) ).toHaveAttribute( 'aria-invalid', 'true' );

		await page.fill( 'input[name="bf[email]"]', 'noscript@example.com' );
		await page.check( 'input[name="bf[consent]"]' );
		await page.click( '.bf-submit' );
		await expect( page.locator( '.bf-success' ) ).toContainText( 'Thanks!' );
		await context.close();
	} );

	test( 'refreshes a stale token from a cached page', async ( { page } ) => {
		const { pageId } = fixtures();
		await page.goto( `/?page_id=${ pageId }` );
		// Simulate a page served from cache 3 days ago by rewinding the token timestamp.
		const tokenUrl = await page.evaluate( () => {
			const input = document.querySelector( 'input[name="bf_token"]' );
			input.value = ( Math.floor( Date.now() / 1000 ) - 3 * 86400 ) + '.' + input.value.split( '.' )[ 1 ];
			const form = input.form;
			form.__borsflow = null;
			window.BorsFlowForms.init( form );
			return JSON.parse( form.dataset.bfConfig ).tokenUrl;
		} );
		await page.waitForResponse( ( r ) => r.url().startsWith( tokenUrl.split( '?' )[ 0 ] ) && r.url().includes( 'token' ) );
		const issued = await page.evaluate( () => parseInt( document.querySelector( 'input[name="bf_token"]' ).value, 10 ) );
		expect( Date.now() / 1000 - issued ).toBeLessThan( 60 );
	} );
} );
