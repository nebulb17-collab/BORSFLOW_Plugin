const fs = require( 'fs' );
const path = require( 'path' );

const WP = () => process.env.BORSFLOW_E2E_URL;
const CRM = () => process.env.BORSFLOW_E2E_CRM;

function fixtures() {
	return JSON.parse( fs.readFileSync( path.join( process.env.BORSFLOW_E2E_WP_DIR, 'e2e.json' ), 'utf8' ) );
}

/** Run due WP-Cron events (DISABLE_WP_CRON is on, so nothing runs by itself). */
async function runCron( request ) {
	await request.get( `${ WP() }/wp-cron.php` );
}

async function crmLeads( request ) {
	const res = await request.get( `${ CRM() }/api/leads`, { headers: { Authorization: 'Bearer test-key' } } );
	return ( await res.json() ).data;
}

async function crmMode( request, mode ) {
	await request.get( `${ CRM() }/__mode?set=${ mode }` );
}

/** Submit through the public REST API exactly as the front-end script does. */
async function submitViaRest( request, values ) {
	const { formId } = fixtures();
	const base = `${ WP() }/index.php?rest_route=/borsflow/v1/forms/${ formId }`;
	const { token } = await ( await request.get( `${ base }/token` ) ).json();
	const multipart = { bf_token: token, bf_page_url: `${ WP() }/contact/` };
	for ( const [ k, v ] of Object.entries( values ) ) {
		multipart[ `bf[${ k }]` ] = v;
	}
	// The time trap (if enabled) measures from token issue time.
	const res = await request.post( `${ base }/submit`, { multipart } );
	return res.json();
}

const adminState = path.join( __dirname, '.auth.json' );

module.exports = { WP, CRM, fixtures, runCron, crmLeads, crmMode, submitViaRest, adminState };
