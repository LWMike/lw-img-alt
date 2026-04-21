# CLAUDE.md — Project Context for Claude Code

## Project Overview

**Project:** LW Image Alt (`lw-img-alt`)
**Type:** WordPress plugin
**Purpose:** Scan a WordPress Media Library for images missing `alt` text, then bulk-update them via CSV upload or edit them manually inline.
**Target environment:** WordPress 6.4+, PHP 8.1+, primarily Kinsta-hosted WordPress multisites in the Lead Wolf client portfolio (KBB sector).
**Owner:** Mike (Lead Wolf Digital)
**Repo:** `github.com/LWMike/lw-img-alt`
**Default branch:** `master`

## Problem Being Solved

Across Lead Wolf's ~100–150 client sites, missing image alt text is a recurring SEO and accessibility issue. The WordPress admin UI makes bulk remediation painful — each image has to be opened and edited individually. This plugin gives site admins a single screen to:

1. **Audit** — scan the Media Library and report every attachment missing an `alt` value.
2. **Bulk fix via CSV** — export the missing list, fill in alt text in a spreadsheet, re-import.
3. **Manual fix** — edit alt text inline directly from the plugin screen when only a handful are missing.

## Core Features (MVP)

### 1. Scan / Audit

- Admin screen lists all attachments of type `image/*` where `_wp_attachment_image_alt` meta is empty or missing.
- Shows thumbnail, filename, upload date, attached post (if any), file size, dimensions.
- Filter by: attached/unattached, date range, file type.
- Pagination — must handle media libraries with 10,000+ images without timing out.
- "Scan" action is non-destructive and cacheable; cache invalidates on new upload or manual edit.

### 2. CSV Export

- One-click export of the missing-alt list as CSV.
- Columns: `attachment_id`, `filename`, `url`, `current_alt` (always blank for export, but kept for round-trip clarity), `new_alt` (blank for user to fill), `title`, `caption`.
- UTF-8 with BOM so Excel opens it cleanly.

### 3. CSV Import / Bulk Update

- Upload a CSV (same schema as export) and preview changes before committing.
- Match rows by `attachment_id` (primary) with filename as a fallback/sanity check.
- Validate: attachment exists, is an image, alt text length (≤125 chars recommended, warn but don't block over that).
- Sanitise: `sanitize_text_field()` on every value; strip HTML; normalise whitespace.
- Dry-run mode showing what would change, before a "Confirm & apply" button.
- Results screen: updated / skipped / errored, with reasons.

### 4. Manual Inline Edit

- On the scan screen, each row has an inline-editable alt field.
- Save via AJAX (no page reload), with a spinner and success/error toast.
- Uses the same sanitisation path as the CSV importer.

### 5. Safety & Logging

- Every change writes to a plugin-owned log table (`wp_lwia_log`) with: attachment_id, old_alt, new_alt, user_id, source (`manual` | `csv` | `batch`), batch_id, timestamp.
- Log viewer screen in admin showing recent changes, filterable by source, user, and date range.

### 6. Undo

- Every CSV import is assigned a `batch_id` (UUID) so all rows in that import are grouped.
- Manual inline edits are single-row "batches" — each edit is its own undoable unit.
- **Undo screen** lists recent batches with: timestamp, source, user, row count, summary (e.g. "Imported alt for 247 images").
- One-click "Undo this batch" restores the previous `old_alt` value for every row in the batch.
- Undo is itself logged (source = `undo`) so it's auditable and, in principle, re-doable.
- Undo is gated behind `manage_options` capability — same as bulk apply.
- Retention: log table keeps entries indefinitely by default; a future setting can prune entries older than N days.

### 7. WP-CLI

A `wp lwia` command namespace for scripted / multisite workflows:

```bash
wp lwia scan                          # Print a summary: X images missing alt, Y total images
wp lwia scan --format=csv             # Pipe missing list to stdout as CSV
wp lwia scan --format=csv > out.csv   # Redirect to file
wp lwia export path/to/file.csv       # Same as scan --format=csv but writes to a path
wp lwia import path/to/file.csv       # Apply a CSV
wp lwia import path/to/file.csv --dry-run   # Preview without writing
wp lwia undo <batch_id>               # Roll back a batch
wp lwia log --limit=50                # Tail the log
wp lwia log --batch=<batch_id>        # Show rows in a specific batch
```

CLI commands share the same write path (`class-updater.php`) and logger as the admin UI — no duplicated logic. Output uses `WP_CLI::line()` / `WP_CLI::success()` / `WP_CLI::error()` conventionally and supports `--format=table|csv|json|yaml` via `WP_CLI\Utils\format_items()` where a list is returned.

## Out of Scope for v1

- AI-generated alt text (could be a v2 feature via OpenAI or Claude API).
- Multisite network-wide scanning / network admin dashboard — v1 is per-site only. Network activation is supported but each site runs its own scan. WP-CLI gives you a scripted path to loop over sites in the meantime (`wp site list --field=url | xargs -I {} wp --url={} lwia scan`).
- Front-end rendering or schema changes — this plugin only touches `_wp_attachment_image_alt` meta.
- Image regeneration, compression, or replacement.
- Log retention UI — the log grows indefinitely in v1; pruning is a v1.1 concern.

## Architecture

### File / Directory Structure

```
lw-img-alt/
├── lw-img-alt.php           # Main plugin bootstrap — headers, constants, activation/deactivation hooks
├── uninstall.php            # Cleanup on plugin delete (drop log table if opted in)
├── includes/
│   ├── class-plugin.php     # Main plugin class — singleton, boot sequence
│   ├── class-scanner.php    # Finds attachments missing alt text
│   ├── class-csv-export.php # CSV generation
│   ├── class-csv-import.php # CSV parsing, validation, preview, apply
│   ├── class-updater.php    # Shared write path — updates alt meta + writes log
│   ├── class-logger.php     # Log table schema, inserts, queries
│   ├── class-undo.php       # Batch rollback logic
│   ├── class-admin.php      # Admin menu, screens, enqueues
│   ├── class-ajax.php       # AJAX handlers for inline edit + async scans
│   └── class-cli.php        # WP-CLI command registrations (loaded only if WP_CLI is defined)
├── admin/
│   ├── views/               # PHP templates for admin screens
│   │   ├── scan.php
│   │   ├── import.php
│   │   ├── log.php
│   │   └── undo.php
│   ├── css/admin.css
│   └── js/admin.js          # Inline edit, CSV upload UX, progress bars
├── languages/               # .pot file for translations (en_GB default)
├── readme.txt               # WordPress.org-style readme
└── README.md                # GitHub-facing readme
```

### Key WordPress APIs

- **`WP_Query` with `meta_query`** — for finding attachments where alt is empty. Careful with NOT EXISTS on serialized meta; prefer `meta_compare => 'NOT EXISTS'` or an explicit empty-string check joined with a raw query for performance on large libraries.
- **`update_post_meta()`** — writing `_wp_attachment_image_alt`.
- **`wp_handle_upload()`** — for the CSV upload, with a custom `mimes` filter allowing `text/csv`.
- **`admin-ajax.php`** — all AJAX endpoints, nonce-protected via `check_ajax_referer()`.
- **`$wpdb`** — for the custom log table and any performance-sensitive scan queries.

### Data Model

Custom table `{$wpdb->prefix}lwia_log`:

| Column        | Type               | Notes                                  |
| ------------- | ------------------ | -------------------------------------- |
| id            | BIGINT UNSIGNED PK | AUTO_INCREMENT                         |
| attachment_id | BIGINT UNSIGNED    | Indexed                                |
| old_alt       | TEXT               | Previous value (nullable)              |
| new_alt       | TEXT               | New value                              |
| user_id       | BIGINT UNSIGNED    | `get_current_user_id()`                |
| source        | VARCHAR(16)        | `manual` \| `csv` \| `batch` \| `undo` |
| batch_id      | VARCHAR(36)        | UUID per CSV import, for undo grouping |
| created_at    | DATETIME           | UTC                                    |

## Key Commands

This is a PHP/WordPress plugin so there's no npm-based dev server. Dev workflow:

```bash
# Symlink into a local WP install for testing
ln -s ~/MASTER-REPOS/lw-img-alt /path/to/wp-content/plugins/lw-img-alt

# Lint PHP
composer run lint           # if composer + PHPCS set up
phpcs --standard=WordPress .

# Build a distributable zip (excludes dev files)
./scripts/build-zip.sh
```

Admin JS/CSS is kept vanilla for v1 — no build step, no bundler. If the UX outgrows that, add Vite later.

## Conventions

- **PHP style:** WordPress Coding Standards (WPCS) via PHPCS.
- **Prefix:** `lwia_` for functions, `LWIA_` for class names and constants, `lw-img-alt` for hooks and text domain.
- **Nonces:** every form submission and AJAX call must be nonce-checked.
- **Capabilities:** all admin actions gated behind `upload_files` minimum, `manage_options` for destructive actions (bulk apply, undo).
- **Escaping:** output always escaped with `esc_html()`, `esc_attr()`, `esc_url()` at the point of echo. Input always sanitised at the point of entry.
- **i18n:** all user-facing strings wrapped in `__()` / `esc_html__()` with the `lw-img-alt` text domain. UK English in source strings (e.g. "colour", "organisation") — this is an internal Lead Wolf tool.
- **No direct DB writes** outside `class-updater.php` and `class-logger.php` — single write path makes auditing easy.
- **Conventional commits:** `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`.
- **Branching:** feature branches off `master`, PRs only — no direct commits to `master`.

## Performance Targets

- Scan of 10,000 attachments completes in under 15 seconds on a typical Kinsta staging environment.
- CSV import of 5,000 rows applies in under 30 seconds, in chunks of 250 via AJAX so the admin page stays responsive and doesn't hit PHP `max_execution_time`.
- Inline edit AJAX round-trip under 300ms.

## Security Checklist

- [ ] All AJAX endpoints nonce-verified
- [ ] All capability checks in place (`current_user_can()`)
- [ ] CSV uploads validated by MIME _and_ extension _and_ content sniff (first-line header check)
- [ ] CSV row count capped (e.g. 50,000) to prevent memory blow-up
- [ ] No `unserialize()` on user input
- [ ] All queries use `$wpdb->prepare()`
- [ ] Uninstall leaves no orphaned options unless user opts into "keep log" setting

## Current Focus

<!-- Update as priorities shift. -->

- [ ] Scaffold plugin bootstrap and main class
- [ ] Build scanner — get a working list of missing-alt attachments on a test site
- [ ] Admin screen rendering the scan results with pagination
- [ ] CSV export
- [ ] CSV import with dry-run preview and batch_id tagging
- [ ] Inline edit AJAX
- [ ] Log table + log viewer screen
- [ ] Undo screen + rollback logic
- [ ] WP-CLI commands (`scan`, `export`, `import`, `undo`, `log`)

## Environment

- PHP 8.1+
- WordPress 6.4+
- MySQL 5.7+ / MariaDB 10.3+
- Tested against Kinsta hosting (primary target) and a local LocalWP install for dev.

## Don'ts

- Don't commit `.env`, `.DS_Store`, IDE config, or built zips to the repo.
- Don't add runtime dependencies via Composer without discussion — keep the footprint small for a plugin that may run on 150 sites.
- Don't touch other `_wp_attachment_*` meta keys — this plugin is strictly scoped to `_wp_attachment_image_alt`.
- Don't bypass `class-updater.php` for meta writes — this applies to the admin UI, AJAX handlers, _and_ WP-CLI commands.
- Don't write to the log table directly — always go through `class-logger.php`.
- Don't duplicate business logic in `class-cli.php` — CLI commands are thin wrappers around the same classes the admin UI uses.
- Don't assume the Media Library is small — every query must paginate.

## Reference

- WordPress Plugin Handbook: https://developer.wordpress.org/plugins/
- WP-CLI Commands Cookbook: https://make.wordpress.org/cli/handbook/guides/commands-cookbook/
- Accessibility — alt text guidance: https://www.w3.org/WAI/tutorials/images/
- Related internal tooling: Wolf Tracker (tracking config engine), WolfWatch (site monitoring)
