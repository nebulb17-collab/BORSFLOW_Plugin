# BorsFlow Forms – Setup & Local Testing

## 1. Install

1. Copy this repository into `wp-content/plugins/borsflow-forms/`, or zip it and upload it via **Plugins → Add New → Upload**. There is no build step: the builder uses jQuery UI and the block editor script is plain JS.
2. Activate **BorsFlow Forms**. Activation:
   - creates `wp_borsflow_submissions` and `wp_borsflow_sync_log`,
   - grants `borsflow_manage_forms` to Administrators (give it to other roles with any role editor),
   - creates the protected upload folder `wp-content/uploads/borsflow-private/`,
   - schedules the daily maintenance job.

## 2. Build your first form

1. **BorsFlow Forms → Add New**, name the form (e.g. "Contact us") and click **Create form**. It starts with Name, Email and Message.
2. **Fields tab**
   - Drag a field type from the left palette onto the canvas, or click it to append.
   - Drag cards to reorder, or use the ↑/↓ buttons (keyboard friendly).
   - Click a card to edit it on the right: label, key, placeholder, help, default, width (full, 1/2 or 1/3), required, validation, options and conditional logic.
   - The **Live preview** underneath is rendered by the real front-end renderer. Validation and conditional logic work there, but nothing is submitted.
3. **Settings tab**: button texts, success message or redirect, time trap / captcha, and style preset plus accent color.
4. **Notifications tab**: admin email (recipients, subject, body with `{field_key}` merge tags, and a Reply-To bound to an email field) and an optional autoresponder.
5. Click **Save form** (or press Ctrl/Cmd+S).

## 3. Connect BorsFlow CRM

1. **BorsFlow Forms → Settings**
2. Enter the **CRM base URL** (e.g. `https://app.borsflow.com`) and paste your **API key**. After saving, the key field shows only a mask; leave it blank to keep the stored key.
3. Click **Test connection**. It sends `GET {base}{health path}` with `Authorization: Bearer <key>` and reports the result inline. It uses whatever is typed in the fields, so you can test before saving.
4. Optional: change the endpoint paths (defaults are `/api/leads` and `/api/health`), the timeout, max attempts, and the default source, pipeline and stage.

## 4. Map fields

Open the form → **CRM mapping** tab:

- Map each form field to `firstName`, `lastName`, `fullName` (split into first/last), `email`, `phone`, `company`, `position`, `value` (sent as a number) or `notes`.
- Unmapped fields are appended to `notes` as `Label: value` lines.
- Override the **pipeline**, **stage** and **lead source** for this form if needed.

The request the plugin sends:

```http
POST {base}/api/leads
Authorization: Bearer <api key>
Content-Type: application/json
Idempotency-Key: wp-<site hash>-<submission id>

{
  "firstName": "Ada", "lastName": "Lovelace", "email": "ada@example.com",
  "phone": "+44 20 7946 0958", "company": "Acme Corp", "value": 2500,
  "notes": "Topic: sales\nInterests: b",
  "source": "website-contact", "pipeline": "…", "stage": "…",
  "externalId": "wp-<site hash>-<submission id>",
  "metadata": { "formId": 4, "formTitle": "Contact us", "submissionId": 1,
                "pageUrl": "…", "referrer": "…", "submittedAt": "2026-09-28T22:12:59+00:00", "site": "…" }
}
```

- Any 2xx response counts as success. The lead ID is read from `id`, `data.id`, `lead.id` or `leadId`.
- A 409 that carries a lead ID also counts as success, because the CRM recognized the idempotency key.
- Network errors, 408, 425, 429 and 5xx are retried after 1m, 5m, 30m, 2h, then every 6h, up to **Max sync attempts**.
- Other 4xx responses (bad request, auth, validation) are not retried automatically. Fix the cause and use **Retry sync**.
- Use the `borsflow_crm_lead_payload` filter to reshape the body if the real API differs.

## 5. Embed

- **Shortcode**: `[borsflow_form id="12"]`. The ID is shown in the builder header and on the Forms list.
- **Block editor**: add the **BorsFlow Form** block, then pick the form in the sidebar.
- **Elementor**: search the widget panel for **BorsFlow Form** (also under the "BorsFlow" category) and choose the form in the widget's content settings.

## 6. Test locally

### WordPress

Any local stack works (wp-env, LocalWP, DDEV, Docker, MAMP). With `@wordpress/env`:

```bash
npx @wordpress/env start   # from a folder whose .wp-env.json maps this plugin
```

For email, point WordPress at Mailpit/MailHog (DDEV and LocalWP include one), or use a mail-logging plugin.

### Mock CRM on localhost

The repository ships a tiny mock of the CRM API:

```bash
BORSFLOW_MOCK_KEY=test-key php -S 127.0.0.1:4000 tools/mock-crm/server.php
```

In **Settings**, use base URL `http://127.0.0.1:4000` and API key `test-key`, then **Test connection**. You should see "Connected (HTTP 200 …)".

- **WordPress in Docker** (wp-env, DDEV, docker-compose): `localhost` inside the container is the container itself. Use `http://host.docker.internal:4000` and start the mock on `0.0.0.0:4000`. On Linux, add `extra_hosts: ["host.docker.internal:host-gateway"]`.
- **Real local CRM over HTTPS with a self-signed certificate**: allow it only in development:

  ```php
  // wp-content/mu-plugins/borsflow-local.php
  add_filter( 'borsflow_crm_request_args', function ( $args ) {
      if ( wp_get_environment_type() === 'local' ) {
          $args['sslverify'] = false;
      }
      return $args;
  } );
  ```

Mock endpoints for exercising failure paths:

| Call | Effect |
|---|---|
| `curl localhost:4000/__mode?set=flaky` | Every lead fails twice with 503, then succeeds (shows backoff) |
| `…/__mode?set=fail500` | Always 500 (retried) |
| `…/__mode?set=fail422` | Always 422 (not retried automatically) |
| `…/__mode?set=fail401` | Rejects the key (Test connection shows "rejected") |
| `…/__mode?set=slow` | Sleeps 20 s (exceeds the default 15 s timeout) |
| `…/__mode?set=ok` | Back to normal |
| `curl -H 'Authorization: Bearer test-key' localhost:4000/api/leads` | Lists the leads received |
| `curl localhost:4000/__reset` | Clears everything |

### Making WP-Cron run promptly

WP-Cron only runs when someone visits the site. The sync is queued for "now", so the next page load after a submission sends it. To trigger it by hand:

```bash
wp cron event run borsflow_sync_submission     # WP-CLI
curl -s http://localhost:8888/wp-cron.php      # or just hit wp-cron.php
```

In production, prefer a real cron: `define( 'DISABLE_WP_CRON', true );` plus a system cron hitting `wp-cron.php` every minute.

### Suggested test pass

1. Build a form with a required email, a dropdown (Sales/Support), and a number field that is **shown only when** the dropdown equals Sales.
2. On the front end: submit empty (errors appear, focus jumps to the first one), choose Sales (the number field appears), and submit valid data (a success message is announced).
3. **Submissions**: the row shows `Pending` and an unread badge. Run cron and it becomes `Synced` with the lead ID. `GET /api/leads` on the mock shows the mapped lead.
4. `__mode?set=flaky` → submit again → cron: the row shows `Failed` with the error and a "Next retry" time about a minute out. Click **Retry sync** twice: it becomes `Synced`, and the mock still holds exactly one lead for that submission (idempotency).
5. Disable JavaScript and submit: errors and entered values survive the round trip, and success shows the message.
6. **Export CSV**, open a detail view, download an uploaded file, and try **Sync Log**.

## 7. Uninstall

Deleting the plugin removes its options, capability, transients and cron events. Forms, submissions, the sync log and uploaded files are deleted **only** if **Settings → Data → Delete all … when the plugin is deleted** is checked.
