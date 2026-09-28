=== BorsFlow Forms ===
Contributors: borsflow
Tags: contact form, form builder, crm, leads, elementor
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build custom contact forms, collect submissions in wp-admin, and sync every lead to BorsFlow CRM.

== Description ==

BorsFlow Forms is a drag-and-drop form builder with a submissions dashboard and a reliable, asynchronous sync to BorsFlow CRM.

**Form builder**

* 17 field types: text, email, phone, number, paragraph text, dropdown, multi-select, radio group, checkbox group, consent checkbox, date, time, file upload, URL, hidden field, section heading and paragraph/HTML block.
* Drag fields from a palette, reorder them, and lay them out full width, 1/2 or 1/3 in rows.
* Per-field label, key, placeholder, help text, default value, required, min/max length, min/max value, regex pattern with a custom error message, and an options editor for choice fields.
* Conditional logic: show or hide a field when other fields equal, don't equal, contain, are empty or are filled, with AND/OR.
* Live preview rendered by the same code as the front end.
* Per-form settings: button and loading text, success message or redirect, admin notification and autoresponder with `{field_key}` merge tags, spam protection, and style presets.

**Embedding**

* Shortcode: `[borsflow_form id="12"]`
* Gutenberg block "BorsFlow Form" with a form picker in the sidebar.
* Elementor widget "BorsFlow Form" (Elementor 3.5+).

**Submissions**

* Every submission is stored locally *before* anything is sent to the CRM.
* List screen with filters (form, sync status, date range), search, sorting, bulk actions (delete, mark read/unread, retry sync, export) and CSV export of the filtered view.
* Detail view with every field, metadata, file downloads and full sync history.
* Unread badge in the admin menu, and optional automatic deletion after N days.

**CRM sync**

* Asynchronous via WP-Cron, so visitors never wait on the CRM.
* Bearer-token auth, an idempotency key per submission, and exponential backoff (1m, 5m, 30m, 2h, 6h) up to a configurable number of attempts.
* Per-form field mapping. Unmapped fields go into the lead notes. Pipeline, stage and source can be overridden per form.
* Sync log screen with HTTP status codes.

**Security**

* Nonces and capability checks everywhere (`manage_options` for settings, `borsflow_manage_forms` for everything else).
* Signed form token, an always-on honeypot, an optional time trap, optional reCAPTCHA v3 or Cloudflare Turnstile, and per-IP rate limiting.
* Uploads are checked against an extension/MIME allowlist, stored under random names in a deny-all directory, and served only to authorized users.
* The API key is masked in the UI and never written to logs.

== Installation ==

1. Upload the `borsflow-forms` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **BorsFlow Forms → Settings**, enter your CRM base URL and API key, and click **Test connection**.
4. Go to **BorsFlow Forms → Add New**, build your form, map fields in the **CRM mapping** tab and save.
5. Embed the form with the shortcode, the block or the Elementor widget.

See `docs/SETUP.md` for a step-by-step guide and local testing instructions.

== Frequently Asked Questions ==

= What happens if the CRM is down? =

The submission is already saved. The sync is retried with backoff, and you can retry manually from the Submissions screen at any time. Retries reuse the same idempotency key, so the CRM never creates duplicates.

= Does it work without JavaScript? =

Yes. The form posts to `admin-post.php` and the visitor is redirected back with errors or the success message. Captchas need JavaScript.

= Does it work with page caching? =

Yes. Forms use a signed, non-expiring render token instead of a session nonce, so cached pages keep working.

= My site runs on nginx. Are uploads protected? =

nginx ignores `.htaccess`. Files are stored with random, non-executable names, but you should either deny `/wp-content/uploads/borsflow-private/` in your nginx config or move storage outside the web root with the `borsflow_upload_dir` filter.

= My site is behind Cloudflare / a load balancer. =

Set Settings → Spam protection → Visitor IP source to the header your proxy sets. Otherwise the rate limit sees every visitor as the same IP.

= Can I keep the API key out of the database? =

Yes. Define `BORSFLOW_API_KEY` (and optionally `BORSFLOW_CRM_BASE_URL`, `BORSFLOW_RECAPTCHA_SECRET`, `BORSFLOW_TURNSTILE_SECRET`) in wp-config.php.

= Is it GDPR friendly? =

Submissions are included in WordPress's personal data export and erasure tools. Storing IP addresses can be turned off, and suggested privacy policy text is provided.

= Are there developer hooks? =

Filters: `borsflow_field_types`, `borsflow_form_html`, `borsflow_validation_errors`, `borsflow_crm_lead_payload`, `borsflow_crm_request_args`, `borsflow_sync_backoff`, `borsflow_upload_dir`, `borsflow_client_ip`, `borsflow_notification_headers`, `borsflow_autoresponder_headers`.
Actions: `borsflow_submission_created`, `borsflow_submission_synced`, `borsflow_submission_sync_failed`, `borsflow_spam_blocked`.

== Changelog ==

= 1.1.0 =
* Renaming a field key in the builder now updates conditional rules, CRM mapping, Reply-To/autoresponder bindings and email merge tags. Deleting a referenced field warns first and cleans up.
* Notification emails are sent in the background via WP-Cron, at most once per submission.
* New "Visitor IP source" setting for sites behind Cloudflare or a proxy.
* Form tokens expire (default 48 h) to block replay; cached pages fetch a fresh token automatically.
* Personal data exporter and eraser, an option to not store IP/user agent, and privacy policy text.
* Secrets can be defined as wp-config.php constants.
* Fix: regex patterns lost their backslashes on save (e.g. `\d` became `d`).
* Fix: the `{all_fields}` merge tag rendered empty in emails.
* Fix: the "references updated" notice in the builder was immediately overwritten.
* Added PHPUnit, Playwright and PHPCS tooling plus GitHub Actions CI.

= 1.0.0 =
* Initial release. See CHANGELOG.md.

== Upgrade Notice ==

= 1.1.0 =
Fixes regex patterns losing backslashes and an empty {all_fields} in emails. Emails are now sent in the background. Sites behind Cloudflare or a proxy should set the new Visitor IP source setting.

= 1.0.0 =
Initial release.
