# Image Alt Plugin — UI/UX Improvement Spec

**Context:** Post-MVP review of the live admin screens. The plugin works; this spec tightens the UI, fixes a bug, and aligns terminology with the user guide. Work through this list top-down as a single PR or as several small PRs — your choice.

**Principle:** Stay within the WordPress admin design system. Don't introduce custom frameworks, new CSS libraries, or anything that would look alien next to native WP screens. Use `wp-admin`'s built-in component classes (`.button`, `.button-primary`, `.notice`, `.wp-list-table`, `.tablenav`, `.row-actions`) wherever possible.

---

## 1. Bug fixes (do first)

### 1.1 Placeholder showing literal `\u2026`

**Where:** Scan screen, every row's alt text input, and likely any other input using the same placeholder string.

**Symptom:** Input shows `Enter alt text\u2026` instead of `Enter alt text…`.

**Cause:** A string somewhere contains the literal characters `\u2026` rather than the Unicode ellipsis character. Likely a `__()` or `esc_attr__()` call with an unprocessed escape sequence, or a JSON-encoded string being output raw.

**Fix:** Replace with the actual ellipsis character `…` or plain `...`. Grep the codebase for `\\u2026` and fix at source. Verify with `grep -rn 'u2026' includes/ admin/`.

### 1.2 Menu label mismatch

**Where:** Top-level admin menu.

**Current:** `Image Alt`

**Desired:** Keep `Image Alt` — it's clearer for internal team members working on client sites. **Update the plugin so it's consistently labelled "Image Alt" everywhere in the UI**, including screen titles, the settings page, and any user-facing text. The user guide will match.

---

## 2. Scan screen improvements

### 2.1 Auto-save inline edits

**Current:** Every row has a `Save` button next to the alt text input. 177 rows = 177 buttons. Visual noise, extra clicks.

**Change:**
- Remove the per-row `Save` button entirely.
- On `blur` (field loses focus) OR `Enter` keypress, trigger the AJAX save.
- Show a subtle inline indicator to the right of the input: a spinner during save, then a green tick on success (fades after 2 seconds) or a red cross with tooltip on error.
- If the value hasn't changed since the field was focused, don't send a request.

**Accessibility:** The spinner/tick should have `aria-live="polite"` announcements for screen reader users ("Saved" / "Error saving").

### 2.2 Success/error toasts

**Current:** No feedback after a save.

**Change:**
- Use a dismissible `notice notice-success is-dismissible` WordPress admin notice appended to a fixed container at the top of the content area.
- Success: `Alt text saved for <filename>.` — auto-dismiss after 3 seconds.
- Error: `Couldn't save alt text for <filename>. <error reason>.` — persistent until dismissed.
- Stack multiple toasts vertically if several saves happen in quick succession.

### 2.3 Rename and demote the "Scan" button

**Current:** `Scan` sits next to `Export CSV` and looks like a primary action. The PRD says the scan cache auto-refreshes on new upload or edit, so clicking Scan is only useful for forcing a refresh.

**Change:**
- Rename to `Refresh results`.
- Change from primary button (`button-primary`) to secondary (`button`).
- Add tooltip: *"Results update automatically when images are added or edited. Click to force a refresh."*

### 2.4 Filter dropdown labels

**Current:** Dropdowns labelled only `All images` and `All types` — no indication of what they filter.

**Change:**
- Add a `Filter:` label to the left of the filter row.
- Add visible labels above each control: `Status` (All images / Attached / Unattached) and `Type` (All types / JPEG / PNG / etc.).
- Add a `Clear filters` link on the right when any filter is active.

### 2.5 Column order and widths

**Current:** Preview, Filename, Uploaded, Attached to, Type, Dimensions, Size, Alt text.

**Change to:** Preview, Filename, **Alt text** (wide), Attached to, Uploaded, Type | Dimensions | Size (combined into one compact "Details" column).

The alt text input is the whole point of the screen — it should have room to show 60+ characters, not a cramped narrow column at the far right.

### 2.6 Placeholder image for broken thumbnails

**Current:** Some images (PDFs, SVGs, broken files) show a blank preview cell.

**Change:** Use the WordPress default mime-type icon via `wp_mime_type_icon()` as fallback when a thumbnail isn't available. Keep it consistent with Media Library.

---

## 3. Import screen improvements

### 3.1 Sample CSV download

**Change:** Add a `Download sample CSV` button below or next to the file input. Generates a 3-row example CSV with the correct columns and UTF-8 BOM so new users can see the format.

### 3.2 Humanise the helper text

**Current:** *"Maximum 50000 rows. UTF-8 encoding. BOM optional."*

**Change:** *"Up to 50,000 rows. Save as CSV UTF-8 from Excel or Sheets to avoid encoding issues with special characters (é, £, etc.)."*

### 3.3 Step indicator

**Change:** Above the upload control, show a 3-step progress indicator: `1. Upload → 2. Preview → 3. Apply`. Current step highlighted. This reassures users that nothing is applied immediately on upload.

Use simple semantic markup:
```html
<ol class="lwia-steps">
  <li class="is-current">Upload</li>
  <li>Preview</li>
  <li>Apply</li>
</ol>
```

Style with minimal CSS — numbered pills, current one filled, others outlined.

### 3.4 Preview screen polish

(Covered by existing PRD; flagging here for completeness.)

- Summary counts at the top: *"247 rows will update · 3 will be skipped · 0 errors"*.
- Rows grouped by status (Update / Skip / Error) with collapsible sections.
- Each row shows thumbnail, filename, current alt (truncated), new alt (truncated), diff indicator.
- `Cancel` button alongside `Confirm & apply` — clicking Cancel returns to step 1 without applying.

### 3.5 Button label

**Current:** `Upload & Preview`

**Change:** Keep `Upload & Preview` — it's clear and sets expectation. Just confirm the user guide matches.

---

## 4. Change Log screen improvements

### 4.1 Show empty old/new alt values visibly

**Current:** Empty `Old alt` cells render as blank, which looks broken.

**Change:** When old or new alt is an empty string, render `(empty)` in muted grey italic. Use a span with class `lwia-muted` styled as `color: #757575; font-style: italic;`.

### 4.2 Thumbnail in Attachment column

**Current:** Column shows `#2982` linked.

**Change:** Show a 40×40px thumbnail alongside the attachment ID. Fall back to mime-type icon if no thumbnail. Keep the `#ID` as a clickable link to the WP attachment edit screen.

### 4.3 Hover tooltip on truncated alt text

**Current:** Long alt values are truncated with `…` but there's no way to see the full value without inspecting.

**Change:** Add `title="<full alt text>"` attribute on every truncated cell. For richer UX, use a JS-based tooltip (WordPress ships with jQuery tipsy or you can use a small custom implementation — 10 lines of JS max).

### 4.4 Clickable Batch ID

**Current:** `1f60965e…` — no interaction.

**Change:**
- Clicking the Batch ID copies the full UUID to clipboard.
- Small inline feedback: briefly change text to `Copied!` for 1 second.
- Alongside, add a `Filter` icon/link that filters the log to just this batch.

### 4.5 Filter controls

**Current:** A `Source` dropdown and a `Filter` button.

**Change:**
- Add `Date range` picker (from/to).
- Add `User` dropdown (showing only users who've made changes).
- Auto-apply filters on change — remove the `Filter` button entirely. If form submission is needed for the date range, submit on date blur.
- Add `Clear filters` link on the right when any filter is active.

### 4.6 Pagination

**Change:** Standard WordPress `tablenav` pagination below the table: `‹ prev 1 2 3 next ›` with page size 50. Use `WP_List_Table` if possible for this — it gives you pagination, column sorting, and bulk actions for free.

### 4.7 Relative time for recent entries

**Current:** `22/04/2026 07:55`.

**Change:** Use `human_time_diff()` for anything within the last 7 days: `27 minutes ago`, `3 hours ago`, `2 days ago`. Show absolute date/time on hover via `title` attribute. Entries older than 7 days keep the absolute format.

---

## 5. Undo screen improvements

### 5.1 Undo button styling

**Current:** Red outlined button that reads as disabled.

**Change:** Use a filled destructive-action button. WP doesn't have a standard red button class, so define one:
```css
.button-danger {
    background: #d63638;
    border-color: #b32d2e;
    color: #fff;
}
.button-danger:hover {
    background: #b32d2e;
    border-color: #96292a;
    color: #fff;
}
```
Or simpler: use a text-link style `<a class="submitdelete">Undo</a>` following WordPress conventions for destructive row actions.

### 5.2 Explicit confirmation dialog

**Current:** Unknown what the current confirmation says.

**Change:** On click, show a browser `confirm()` or a native WP modal with this text:
> This will restore the previous alt text for **{n} images** in batch {short_id}. A new 'undo' batch will be logged so you can undo this undo if needed. Proceed?

### 5.3 "View details" link

**Change:** Next to each batch's `Undo` button, add a `View details` link that opens the Change Log filtered to that batch_id. Uses the same filter link as improvement 4.4.

### 5.4 Human-readable "Changes" column

**Current:** `17`

**Change:** `17 images`. Use `_n()` for correct pluralisation (`1 image` vs `2 images`).

### 5.5 Relative time + same filters as Change Log

Apply the same relative-time rule (5.4.7) and the same filter controls (5.4.5 — source, user, date range).

### 5.6 Pagination

Same as Change Log (5.4.6).

---

## 6. New: Dashboard landing screen (nice to have)

**Current:** Clicking `Image Alt` in the sidebar lands on Scan by default.

**Change (optional, for v1.1):** Add a `Dashboard` sub-menu item that becomes the default landing page. Shows:
- Total images in library
- Images missing alt text (count + percentage)
- Images with alt text (count + percentage)
- Changes made in last 7 days
- Changes made in last 30 days
- Most recent batch (with link to Change Log)
- Quick-action buttons: `Scan`, `Import CSV`, `View Change Log`

Keep it simple — just cards with numbers and headings. No charts, no graphs. This gives team members a one-glance status check when they open the plugin on a new client site.

---

## 7. Cross-cutting housekeeping

### 7.1 Consistent page titles

All screens should have an H1 matching the sidebar label:
- Scan screen → `Image Alt — Scan`
- Import screen → `Image Alt — Import`
- Change Log screen → `Image Alt — Change Log`
- Undo screen → `Image Alt — Undo`

### 7.2 Help tab in the top-right

**Change:** Populate the native WP `Help` dropdown on every plugin screen with 2–3 short paragraphs explaining what the screen does, plus a link to the internal user guide. Use `get_current_screen()->add_help_tab()`.

### 7.3 Screen options

**Change:** Use `add_screen_option()` to let users set the number of rows per page on Scan, Change Log, and Undo screens (default 50, options 20/50/100/200).

### 7.4 Menu icon

**Change:** Current icon is fine, but consider `dashicons-format-image` or `dashicons-images-alt` for better distinction. Try `dashicons-universal-access-alt` if you want to reinforce the accessibility angle.

### 7.5 Text domain consistency

Audit every user-facing string and confirm it's wrapped in `__()` / `esc_html__()` with the `lw-img-alt` text domain. Run `wp i18n make-pot . languages/lw-img-alt.pot` to regenerate the pot file after changes.

---

## 8. Testing checklist

After implementing, manually verify:

- [ ] `\u2026` no longer appears anywhere in the UI
- [ ] Inline edit saves on blur and on Enter
- [ ] Toast appears on save success and error
- [ ] Import step indicator shows correct state at each step
- [ ] Change Log shows `(empty)` for blank old/new alt values
- [ ] Change Log thumbnails render, including for non-image attachments
- [ ] Undo confirmation dialog shows correct row count
- [ ] View details link filters Change Log correctly
- [ ] Batch ID click copies UUID to clipboard
- [ ] Pagination works on Change Log and Undo screens
- [ ] Filter controls auto-apply on change
- [ ] Screen options dropdown appears in top-right on list screens
- [ ] Help tab populated on every plugin screen
- [ ] All strings translatable (grep for hardcoded user-facing strings)

---

## 9. Out of scope for this pass

These are parked, not forgotten:

- AI-generated alt text suggestions
- Bulk inline edit (select multiple rows, apply same alt)
- Multisite network dashboard
- Log retention / pruning UI
- Export formats other than CSV (JSON, XLSX)
- Keyboard shortcuts for power users
