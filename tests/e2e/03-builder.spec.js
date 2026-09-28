const { test, expect } = require( '@playwright/test' );
const { fixtures, adminState } = require( './helpers' );

test.use( { storageState: adminState } );

test.describe( 'Form builder', () => {
	test( 'adds fields by drag and click, edits, previews and saves', async ( { page } ) => {
		const { formId } = fixtures();
		await page.goto( `/wp-admin/admin.php?page=borsflow-builder&form_id=${ formId }` );
		const cards = page.locator( '.bf-b-card' );
		await expect( cards.first() ).toBeVisible();
		const initial = await cards.count();

		// Drag "Date" from the palette to the top of the canvas.
		const src = await page.locator( '.bf-b-palette-item[data-type="date"]' ).boundingBox();
		const dst = await cards.first().boundingBox();
		await page.mouse.move( src.x + 20, src.y + 10 );
		await page.mouse.down();
		for ( let i = 1; i <= 25; i++ ) {
			await page.mouse.move( src.x + 20 + ( dst.x + 60 - src.x - 20 ) * i / 25, src.y + 10 + ( dst.y + 5 - src.y - 10 ) * i / 25 );
		}
		await page.mouse.up();
		await expect( cards ).toHaveCount( initial + 1 );
		await expect( cards.first() ).toContainText( 'Date' );

		// Click-to-add a radio group and edit it.
		await page.click( '.bf-b-add[data-type="radio"]' );
		await page.fill( '#bf-b-p-label', 'Preferred contact' );
		await expect( page.locator( '#bf-b-p-key' ) ).toHaveValue( 'preferred_contact' );
		await page.locator( '.bf-b-opt-label' ).first().fill( 'By email' );
		await page.selectOption( '#bf-b-p-width', 'half' );

		const preview = page.frameLocator( '#bf-b-preview-frame' );
		await expect( preview.locator( '.bf-type-radio' ) ).toContainText( 'By email' );

		await page.click( '#bf-b-save' );
		await expect( page.locator( '.bf-b-status' ) ).toHaveText( 'Saved' );

		await page.reload();
		await expect( page.locator( '.bf-b-card' ).filter( { hasText: 'Preferred contact' } ) ).toHaveCount( 1 );
	} );

	test( 'renaming a key updates conditions, CRM mapping and merge tags', async ( { page } ) => {
		const { formId } = fixtures();
		await page.goto( `/wp-admin/admin.php?page=borsflow-builder&form_id=${ formId }` );

		// Rename "topic" (used by Budget's rule and by {topic} in the notification body).
		await page.locator( '.bf-b-card' ).filter( { hasText: 'Topic' } ).locator( '.bf-b-card-inner' ).click();
		await page.fill( '#bf-b-p-key', 'subject' );
		await page.locator( '#bf-b-p-key' ).dispatchEvent( 'change' );
		await expect( page.locator( '.bf-b-status' ) ).toContainText( 'updated 2 reference' );

		// Rename "email" (mapped to CRM email, bound as Reply-To and autoresponder recipient).
		await page.locator( '.bf-b-card' ).filter( { hasText: 'Email' } ).first().locator( '.bf-b-card-inner' ).click();
		await page.fill( '#bf-b-p-key', 'work_email' );
		await page.locator( '#bf-b-p-key' ).dispatchEvent( 'change' );

		await page.click( '#bf-b-save' );
		await expect( page.locator( '.bf-b-status' ) ).toHaveText( 'Saved' );

		await page.reload();
		await page.locator( '.bf-b-card' ).filter( { hasText: 'Budget' } ).locator( '.bf-b-card-inner' ).click();
		await expect( page.locator( '.bf-b-rule-field' ) ).toHaveValue( 'subject' );
		await page.click( '.nav-tab[data-tab="crm"]' );
		await expect( page.locator( '#bf-b-map-work_email' ) ).toHaveValue( 'email' );
		await page.click( '.nav-tab[data-tab="notifications"]' );
		await expect( page.locator( '#bf-b-s-notify-body' ) ).toHaveValue( /\{subject\}/ );
		await expect( page.locator( '#bf-b-s-notify-reply_to_field' ) ).toHaveValue( 'work_email' );
	} );

	test( 'deleting a referenced field warns and cleans up references', async ( { page } ) => {
		const { formId } = fixtures();
		await page.goto( `/wp-admin/admin.php?page=borsflow-builder&form_id=${ formId }` );
		let message = '';
		page.once( 'dialog', ( d ) => {
			message = d.message();
			d.accept();
		} );
		await page.locator( '.bf-b-card' ).filter( { hasText: /Topic|subject/ } ).first().locator( '.bf-b-del' ).click();
		expect( message ).toContain( 'It is used by:' );
		expect( message ).toContain( 'conditional logic of “Budget”' );

		await page.locator( '.bf-b-card' ).filter( { hasText: 'Budget' } ).locator( '.bf-b-card-inner' ).click();
		await expect( page.locator( '.bf-b-cond-enabled' ) ).not.toBeChecked();
	} );
} );
