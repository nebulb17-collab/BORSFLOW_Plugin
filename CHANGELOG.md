# Changelog

All notable changes to BorsFlow Forms are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-09-29

### Added
- Builder: renaming a field key carries the change to conditional rules, CRM mapping, Reply-To/autoresponder bindings and `{key}` merge tags. Deleting a referenced field lists what uses it and removes those references. On save, the server also drops conditional rules that point at missing fields.
- Background email delivery via WP-Cron (`borsflow_send_notifications`), claimed atomically so each submission is emailed at most once, with a daily sweep for lost events. Can be switched back to synchronous.
- "Visitor IP source" setting (CF-Connecting-IP, X-Forwarded-For, X-Real-IP, True-Client-IP). For X-Forwarded-For the right-most public address is used, so a client-supplied value can't be used to spoof.
- Form token lifetime (default 48 h) plus `GET /borsflow/v1/forms/{id}/token`. The front end swaps in a fresh token when a cached page's token is past half its lifetime.
- Personal data exporter and eraser, a "store IP and user agent" toggle, and privacy policy guide text.
- `BORSFLOW_API_KEY`, `BORSFLOW_CRM_BASE_URL`, `BORSFLOW_RECAPTCHA_SECRET` and `BORSFLOW_TURNSTILE_SECRET` wp-config constants. They override settings and are never persisted.
- PHPUnit integration suite (WordPress on SQLite, no MySQL needed), Playwright end-to-end suite, WPCS/PHPCompatibility ruleset, GitHub Actions CI.

### Changed
- DB version 1.1.0 adds `emails_sent` to the submissions table. The migration marks existing rows as already emailed.

### Fixed
- Regex patterns lost backslashes on save because of an extra `wp_unslash()`.
- `{all_fields}` rendered empty in notification and autoresponder bodies.
- Settings sanitization raised an undefined-index notice when the proxy header field was absent.

## [1.0.0] - 2026-09-28

### Added
- Visual form builder (jQuery UI, no build step) with 17 field types, palette drag and drop, canvas reordering, full / 1/2 / 1/3 widths, and a per-field settings panel.
- Validation settings: required, min/max length, min/max value, step, regex pattern with custom message, file extension and size limits.
- Conditional logic with equals / not equals / contains / is empty / is filled rules and AND/OR matching, evaluated identically in the browser and on the server.
- Per-form settings: submit and loading text, success message or redirect, admin notification and autoresponder with merge tags and Reply-To, honeypot, time trap, reCAPTCHA v3 / Turnstile, style presets (inherit / card / minimal), accent color, radius and field size.
- Live preview rendered server-side from unsaved builder state.
- Form duplicate, rename, enable/disable, and delete with confirmation.
- Shortcode `[borsflow_form id=""]`, Gutenberg block `borsflow/form`, and an Elementor widget.
- Front end: fetch submit to REST with a no-JS `admin-post.php` fallback, client validation that mirrors the server, and accessible errors (`aria-invalid`, `aria-describedby`, focus on the first error, polite live region).
- `{prefix}borsflow_submissions` and `{prefix}borsflow_sync_log` tables, a `WP_List_Table` dashboard with filters, search, sorting, bulk actions, CSV export, a detail view, an unread badge, and data retention.
- Asynchronous CRM sync with bearer auth, idempotency key, exponential backoff, manual and bulk retry, and a Sync Log screen.
- Global settings with a masked API key and an inline Test connection button.
- Private file uploads with an allowlist, deny-all storage and an authenticated download handler.
- Per-IP per-form rate limiting.
- Activation (dbDelta, defaults, capability), deactivation (cron cleanup), `uninstall.php` with an opt-in full data wipe, and versioned migrations.
- Mock CRM server (`tools/mock-crm/server.php`) for local development.
