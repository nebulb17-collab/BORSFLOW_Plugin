const { test, expect } = require( '@playwright/test' );
const { adminState, crmMode, runCron, submitViaRest } = require( './helpers' );

test.use( { storageState: adminState } );

test.describe( 'Admin screens', () => {
	test( 'settings mask the key and test the connection', async ( { page, request } ) => {
		await crmMode( request, 'ok' );
		await page.goto( '/wp-admin/admin.php?page=borsflow-settings' );
		await expect( page.locator( '#bf-crm-api-key' ) ).toHaveValue( '' );
		await expect( page.locator( '#bf-crm-api-key' ) ).toHaveAttribute( 'placeholder', /•+/ );
		expect( await page.content() ).not.toContain( 'test-key' );

		await page.click( '#borsflow-test-connection' );
		await expect( page.locator( '#borsflow-test-result' ) ).toContainText( 'Connected' );

		await page.fill( '#bf-crm-api-key', 'wrong' );
		await page.click( '#borsflow-test-connection' );
		await expect( page.locator( '#borsflow-test-result' ) ).toContainText( 'rejected the API key' );
	} );

	test( 'submissions list, detail, sync log and CSV export', async ( { page, request } ) => {
		await crmMode( request, 'ok' );
		const res = await submitViaRest( request, { name: 'Grace Lovelace', email: 'grace@example.com', topic: 'support', consent: 'yes' } );
		expect( res.success ).toBe( true );
		await runCron( request );
		await page.goto( '/wp-admin/admin.php?page=borsflow-submissions' );
		const rows = page.locator( '#the-list tr:not(.no-items)' );
		await expect( rows.first() ).toBeVisible();
		await expect( page.locator( '#toplevel_page_borsflow-forms .awaiting-mod' ).first() ).toBeVisible();

		await page.fill( '#borsflow-submissions-search-input', 'grace@example.com' );
		await page.press( '#borsflow-submissions-search-input', 'Enter' );
		await expect( rows ).toHaveCount( 1 );
		await expect( rows.first() ).toContainText( 'Synced' );

		const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.click( 'a:has-text("Export CSV")' ) ] );
		const csv = require( 'fs' ).readFileSync( await download.path(), 'utf8' );
		expect( csv ).toContain( 'Grace Lovelace' );

		await rows.first().locator( '.row-title' ).click();
		await expect( page.locator( 'h1' ) ).toContainText( 'Submission #' );
		await expect( page.locator( '.borsflow-detail-table' ) ).toContainText( 'grace@example.com' );
		await expect( page.locator( '.postbox' ).filter( { hasText: 'Sync history' } ).locator( 'tbody tr' ) ).toHaveCount( 1 );

		await page.goto( '/wp-admin/admin.php?page=borsflow-sync-log' );
		await expect( page.locator( '#the-list' ) ).toContainText( '201' );
	} );
} );
