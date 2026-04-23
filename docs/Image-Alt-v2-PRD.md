# Image Alt v2 — AI Alt Text PRD

**Version:** 2.1
**Status:** Draft — ready for build
**Owner:** Mike (Technical Lead) & Graeme (Business Lead)
**Team:** Lead Wolf Digital
**Date:** April 2026
**Builds on:** Image Alt v1 (Scan, Import, Change Log, Undo, WP-CLI)

---

## 1. Summary

Add AI-powered alt text generation and rewriting to the Image Alt plugin. Uses Claude Haiku 4.5 via the Anthropic Batch API, with a human review gate on every change. Auto-available on all Lead Wolf client sites with a per-site opt-out.

**Key decisions already made:**

- **Model:** Claude Haiku 4.5 for everything. No tier toggle in v2.
- **Billing:** Single Lead Wolf API key, centrally managed. Cost absorbed into client retainer.
- **Rollout:** Opt-out — feature is on by default on every site, clients can disable.
- **Scope:** Both missing-alt generation *and* existing-alt rewrite in the same feature.
- **Filenames:** Explicitly out of scope.

---

## 2. What changes from v1

v1 gives users a CSV round-trip and an inline editor. v2 adds an AI suggestion step anywhere a human would otherwise type alt text.

| v1 flow | v2 flow |
|---|---|
| Export CSV → fill `new_alt` manually → import | Export CSV → click **AI Suggest All** → review → import |
| Inline editor: click field, type alt text, Save | Inline editor: click **Generate**, review, edit if needed, Save |
| Scan shows only images missing alt | Scan can also filter to "existing alt, flagged as low quality" for rewrite |
| Change log records who edited what | Change log records model + prompt version used per row |

The existing v1 screens and flows are unchanged. AI is additive, never replacing.

---

## 3. Features

### 3.1 AI single-image generation (inline)

**Where:** Scan screen, on every row.

**UI:** Next to the alt text input and Save button, a new **Generate** button. Clicking it:

1. Shows a spinner in place of the button.
2. Calls the Anthropic API synchronously (Haiku 4.5, ~1–2 second response).
3. Populates the alt text field with the suggestion.
4. Enables the Save button (the field is now "dirty").
5. User reviews, optionally edits, and clicks Save (or Enter, or tab-out — same save paths as v1).

If the field already contains text, Generate replaces it but the original is preserved in the undo history via the standard log table.

**Error handling:**
- API timeout or error: button returns to normal state, toast shows "AI generation failed: <reason>". User can retry or type manually.
- Rate limit: toast shows "AI is busy — try batch mode for large jobs".
- Empty/unclear response: field populated with whatever came back; user edits as normal.

### 3.2 AI batch generation (asynchronous)

**Where:** New sub-menu item **AI Suggest** under Image Alt.

**UI:**
- Filter controls identical to the Scan screen: Status, Type, From, To.
- Plus a new filter: **Mode** — `Missing alt only` (default) / `Existing alt — flag for rewrite` / `Both`.
- Count preview: *"This will generate suggestions for 247 images. Estimated cost: £0.63. Estimated time: 5–15 minutes."*
- **Start batch** button.

**Behaviour:**
1. Clicking Start creates a new batch job via the Anthropic Batch API (50% discount vs synchronous).
2. Batch jobs are polled every 60 seconds via WP-Cron until complete.
3. Job status screen shows: Queued / In progress / Complete / Failed.
4. When complete, user gets a WP admin notice with a link to **Review suggestions**.
5. Review screen is a paginated table: thumbnail, filename, current alt (if any), AI suggestion, checkbox.
6. Inline-edit the suggestion if needed. Check/uncheck rows to include/exclude.
7. **Apply selected** button writes alt text for all checked rows, logged as a single batch (reusing v1's batch_id / undo machinery).

**Concurrency:** Only one batch job per site at a time. Starting a second shows an error until the first completes.

### 3.3 AI rewrite of existing alt text

Unlocked by the `Existing alt — flag for rewrite` mode in batch generation.

**Flagging logic:** The scanner pre-filters likely-bad existing alt text using heuristics (not AI — this is to avoid paying for model time on obviously-fine alts):

- Contains the `|` character (strong keyword-stuffing indicator, like the Ponsford data)
- Contains the client's brand/location name (configurable per site)
- Matches regex patterns for "SEO keyword style" (multiple 2-word phrases separated by commas or pipes)
- Is used on more than 3 different images on the site (duplicate alt = almost certainly generic)
- Exceeds 125 characters
- Contains forbidden phrases: "image of", "photo of", "picture of"

The scanner shows a "quality score" column on the Scan screen for existing alts: `Good` (green), `Questionable` (yellow), `Poor` (red). Users can filter by quality score.

**Rewrite prompt:** When rewriting an existing alt, the model receives both the image and the current alt, with an instruction like *"This image currently has this alt text: 'X'. Rewrite it to describe what's actually visible in the image, in UK English, under 125 characters, avoiding keyword-stuffing."* This tends to produce better results than starting blind, because the existing alt hints at brand/context.

### 3.4 Per-site AI settings

**New screen:** **Image Alt → AI Settings**

Fields:

- **AI features** — toggle, default ON. Turning off hides all AI buttons and the AI Suggest sub-menu. Required for opt-out.
- **Business name** — single-line, e.g. *"Square Kitchens at Ponsford"*.
- **Primary location** — single-line, e.g. *"Sheffield"*.
- **Service area** — optional single-line, e.g. *"South Yorkshire, Derbyshire, North Nottinghamshire"*.
- **Location sensitivity** — radio buttons:
  - *Minimal* — location only when visibly depicted in the image (exterior shots, signage, landmarks). **Default.**
  - *Moderate* — model's judgement; location included when genuinely relevant.
  - *Generous* — location may be included when relevant to page context, not only the image itself.
- **Style guide** — multiline text, per-site brand context for everything else. e.g. *"Specialises in German brands including Nobilia and Schüller. When describing kitchens, use terms like 'handleless', 'integrated', 'matt', 'gloss', 'island' where accurate. Prefer British spelling."*
- **Spend cap** — per-month £ limit. Default £20/site/month. Hard-stops new jobs if exceeded; admin notice warns at 80%.
- **Model** — read-only field showing `Claude Haiku 4.5`. Not configurable in v2 (future v2.1 may add Sonnet toggle).

The business name, primary location, service area, and sensitivity setting are structured — they're referenced explicitly in the prompt template rather than being buried in free text. This keeps location handling consistent across 150 sites and lets the plugin generate quality reports (see 3.4.1).

The style guide is a free-text field for everything that isn't location — brand terminology, forbidden words, tone, product categories, etc. It is prepended to the system prompt verbatim.

### 3.4.1 Location inclusion report

After every batch job completes, the review screen shows a small summary banner:

> *"Location mentioned in 12 of 247 suggestions (5%). Review these carefully to confirm they're genuinely location-relevant."*

Clicking the number filters the review screen to show only those rows, so the user can quickly spot-check whether location inclusion was warranted. This is the safety net for the model's judgement — cheap to implement (just a string match against the business name and primary location) and catches over-eager inclusions before they land.

### 3.5 Enhanced logging

Existing `wp_lwia_log` table gains three columns:

| Column | Type | Notes |
|---|---|---|
| ai_model | VARCHAR(64) | e.g. `claude-haiku-4-5` or NULL for manual edits |
| ai_prompt_version | VARCHAR(16) | e.g. `v2.1` — bumped when the prompt template changes |
| ai_confidence | DECIMAL(3,2) | 0.00–1.00, returned by the model; NULL if not available |

Change Log screen adds an `AI` badge on rows where `ai_model IS NOT NULL`, with the model name on hover.

### 3.6 WP-CLI commands

New subcommands under `wp lwia`:

```bash
wp lwia ai-suggest                          # Interactive: prompts for mode, starts a batch
wp lwia ai-suggest --mode=missing           # Missing alt only, start a batch job
wp lwia ai-suggest --mode=rewrite           # Existing alts flagged as poor, start a batch job
wp lwia ai-suggest --mode=both              # Combined
wp lwia ai-suggest --dry-run                # Fetch suggestions to CSV without applying — reviewer can edit and re-import
wp lwia ai-status <job_id>                  # Poll job status
wp lwia ai-apply <job_id>                   # Apply completed job (with optional --only=<ids>)
wp lwia ai-cost --since=2026-04-01          # Spend report for a time window
```

Multisite network-wide example:
```bash
wp site list --field=url | xargs -I {} wp --url={} lwia ai-suggest --mode=missing
```

---

## 4. Architecture

### 4.1 New files

```
includes/
  class-ai-provider.php          # Abstract provider interface (future-proof for OpenAI/Gemini)
  class-ai-anthropic.php         # Anthropic implementation — sync + batch
  class-ai-prompt.php            # Prompt template + versioning
  class-ai-quality-score.php     # Heuristic quality scorer for existing alts
  class-ai-batch-queue.php       # Batch job lifecycle (create, poll, retrieve)
  class-ai-settings.php          # Per-site settings page + spend tracking
admin/
  views/
    ai-suggest.php               # Batch start screen
    ai-review.php                # Review-suggestions screen
    ai-settings.php              # Settings page
  js/
    ai-generate.js               # Inline Generate button behaviour
    ai-review.js                 # Batch review screen interactions
```

### 4.2 Provider abstraction

Even though v2 only ships Anthropic, wrap it behind an abstract class so a v2.1 or v3 can add OpenAI or Gemini without rewriting the calling code.

```php
abstract class LWIA_AI_Provider {
    abstract public function generate_single(string $image_url, array $context): LWIA_AI_Result;
    abstract public function create_batch(array $jobs): string; // returns batch_id
    abstract public function poll_batch(string $batch_id): LWIA_Batch_Status;
    abstract public function retrieve_batch(string $batch_id): array; // array of LWIA_AI_Result
}
```

### 4.3 Prompt

System prompt (v2.1) — sent on every request, built dynamically per site:

```
You write alt text for images on WordPress websites. Output rules:

- Describe what is visible in the image — the subject, key objects, style, and composition.
- Write in UK English.
- Maximum 125 characters. Prefer 80–120.
- One sentence. No line breaks.
- Never start with "Image of", "Photo of", or "Picture of".
- Never use the pipe character | or keyword-stuffed phrasing.
- If rewriting existing alt text, improve it by describing what's actually shown, not what's on the page around it.
- Return JSON with fields: alt (string), confidence (0.0–1.0 float).

LOCATION CONTEXT
Business name: {business_name}
Primary location: {primary_location}
Service area: {service_area}
Sensitivity: {minimal | moderate | generous}

Rules for location mentions:

- Minimal (default): Only mention the business name or location when the image clearly depicts location-relevant content — exterior shots, visible signage, local landmarks, team photos at the premises, or events in the area. Never append location as a suffix. For product shots, interior close-ups, detail shots, or generic imagery, describe what is visible without mentioning business or location names.

- Moderate: As Minimal, plus you may include location when the image is a clear hero shot of a completed project at a known site, or when location context would genuinely help a screen reader user understand the image.

- Generous: As Moderate, plus you may include location when the page context strongly implies it (e.g. a project showcase for a specific local installation). Still never append location as a suffix, and never include it for generic product or detail shots.

When uncertain, OMIT location. Describing the image well always beats adding location that isn't genuinely depicted.

STYLE GUIDE
{site_style_guide_or_empty}
```

Placeholders (`{business_name}`, `{primary_location}`, `{service_area}`, `{sensitivity}`, `{site_style_guide_or_empty}`) are interpolated from the AI Settings screen values. If a field is empty, the placeholder is replaced with `(not specified)` so the prompt stays well-formed.

User message contains the image and, if rewriting, the current alt text as context.

### 4.4 Spend tracking

A new options record `lwia_spend_log` stores `{month: YYYY-MM, tokens_in: int, tokens_out: int, images: int, estimated_gbp: float}` per site.

Updated on every API call (response includes token usage). Displayed on the AI Settings screen. Hard-stop on new batch jobs if cap hit.

A central Lead Wolf dashboard (optional v2.1) could aggregate spend across sites for Mike's monthly reconciliation — out of scope for v2 but the schema supports it.

### 4.5 API key storage

Single key stored in `wp_options` under `lwia_anthropic_api_key` with `autoload=no`. Set once per site by Mike during install. Never exposed via REST API. Never displayed in the UI after setting (shown as `sk-ant-**********abc123`).

Consider Kinsta environment variables as a v2.1 hardening — but `wp_options` is acceptable for v2 given admin-only access.

### 4.6 Safeguards (post-generation validation)

Every AI response goes through validation before being written to the field or log:

1. **Length check.** If >125 chars, truncate to nearest word boundary and flag with confidence penalty.
2. **Forbidden phrases.** Strip "image of" / "photo of" / "picture of" at the start. Re-prompt once if stripping leaves nothing useful.
3. **Whitespace normalisation.** Collapse multiple spaces, trim.
4. **Bracket/pipe filter.** Reject responses containing `|` (classic keyword-stuff indicator) — re-prompt once.
5. **Confidence threshold.** Responses under 0.5 confidence get flagged in the review screen with a yellow warning icon. Still shown, still editable, but the user's attention is drawn.

Rejected/re-prompted responses cost double for that image but are rare (<5% expected).

---

## 5. UX flows

### 5.1 Inline generate (single image)

```
Scan screen → user sees a row with missing alt
    ↓
Click [Generate] next to the input
    ↓
Spinner (1–2s)
    ↓
Field populated with suggestion, Save button enabled (blue)
    ↓
User reviews / edits / clicks Save
    ↓
Standard v1 save flow: green tick, entry in log table with ai_model='claude-haiku-4-5'
```

### 5.2 Batch generate (site-wide)

```
AI Suggest screen → select filters + mode
    ↓
Preview: "247 images · ~£0.63 · 5–15 min"
    ↓
[Start batch] — creates Anthropic batch job, returns job_id
    ↓
Progress screen: "Queued → In progress (128/247) → Complete"
    ↓ (WP-Cron polls every 60s; admin notice when done)
    ↓
Review screen: paginated table with thumbnails + suggestions + checkboxes
    ↓
User unchecks anything they don't want, optionally edits suggestions
    ↓
[Apply selected 235 suggestions]
    ↓
Single batch_id writes all alts, one log entry per image, one Undo batch
```

### 5.3 Existing alt rewrite

```
Scan screen shows all images including those with alt text
    ↓
"Quality score" column: Poor alts highlighted red
    ↓
Filter: Quality = Poor
    ↓
Export as CSV, or click [AI Suggest] with mode=rewrite
    ↓
(Same batch flow as 5.2, but prompt includes existing alt as context)
```

---

## 6. Implementation phases

Target 5–6 focused days of Claude Code work, broken into PRs.

**Phase 1 — Foundations (day 1)**
- Abstract provider class
- Anthropic implementation skeleton (sync generate only)
- API key storage + settings page shell
- Extended log table columns via plugin version bump + migration

**Phase 2 — Inline generate (day 1)**
- Generate button on Scan screen
- AJAX handler → sync API call → populate field
- Error handling + toasts
- Log entry with ai_model

**Phase 3 — Quality scorer + Scan enhancements (day 1)**
- `class-ai-quality-score.php` with the heuristics from 3.3
- Quality score column on Scan screen
- Filter by quality score

**Phase 4 — Batch generation (day 1.5)**
- Anthropic Batch API integration
- WP-Cron polling job
- AI Suggest screen
- Job progress screen
- Review screen with per-row accept/edit/skip
- Apply flow using existing v1 batch/undo machinery

**Phase 5 — Rewrite mode + prompt polish (day 0.5)**
- Prompt variant for existing-alt rewrite
- Validation + re-prompt retry logic
- Confidence scoring surface in UI

**Phase 6 — WP-CLI + spend tracking (day 0.5)**
- `wp lwia ai-*` commands
- Spend log table + cap enforcement
- Cost estimation on batch start screen

**Phase 7 — Docs + testing (day 1)**
- Update CLAUDE.md with v2 architecture
- Update user guide PDF
- Test on a dev site with a real KBB image set
- Dry run on one friendly client site (e.g. Square Kitchens) before portfolio rollout

---

## 7. Testing

### 7.1 Manual acceptance

- [ ] Inline Generate: 1-second response, populates field, Save enabled
- [ ] Inline Generate: API error shows toast, doesn't break the row
- [ ] Batch Generate: cost estimate within 10% of actual
- [ ] Batch Generate: job completes within expected window
- [ ] Batch Generate: review screen loads paginated suggestions
- [ ] Apply Selected: exactly the checked rows are written
- [ ] Apply Selected: single undo batch rolls back all at once
- [ ] Rewrite mode: existing alts replaced, old values in log
- [ ] Quality scorer: pipes, duplicates, forbidden phrases all flagged
- [ ] Style guide: reflected in output (test with distinctive phrasing)
- [ ] **Location — Minimal:** product shot gets described without business or location name
- [ ] **Location — Minimal:** showroom exterior correctly includes business name and/or location
- [ ] **Location — Moderate:** hero project shot may include location where appropriate
- [ ] **Location — Generous:** more liberal inclusion but still never appends as a suffix
- [ ] **Location report:** post-batch banner counts location mentions correctly, filter works
- [ ] **Sensitivity change takes effect:** switching Minimal → Moderate produces different output on the same image set
- [ ] Spend cap: jobs blocked when cap hit, warning at 80%
- [ ] AI toggle off: all AI UI disappears
- [ ] WP-CLI: every subcommand works, including multisite loop

### 7.2 Quality sampling

Before portfolio rollout, manually review 100 AI-generated alts across a mix of KBB image types (kitchens, bathrooms, bedrooms, product shots, interior lifestyle, logo/banner images). Reject threshold: if >5% need significant edits, iterate on the prompt before rollout.

### 7.3 Dry run

Full run on Square Kitchens Ponsford (your existing test case — we have the CSV data). Compare AI output to:
- The original keyword-stuffed alts
- The merged CSV you imported earlier
- A sample of hand-written alts by you

If AI output is materially better than the keyword-stuffed baseline *and* comparable to hand-written, proceed with portfolio rollout.

---

## 8. Rollout plan

1. **Dev site only.** Full end-to-end test on your sacrificial Kinsta dev install. Fix all issues.
2. **Square Kitchens Ponsford.** Friendly client, already rich context. Run batch rewrite of existing poor alts. You or Graeme reviews 100% of suggestions before apply.
3. **3–5 pilot clients.** Medium variety of KBB sites. Same 100% review process. Gather prompt/style-guide tweaks.
4. **Portfolio rollout.** Enable on all sites with AI toggle ON (opt-out default). Email clients explaining the feature, how to disable, how to review.
5. **Ongoing.** Monthly reviews of log + spend per site. Adjust style guides as needed. Consider Sonnet toggle for v2.1 if quality gaps emerge.

---

## 9. Out of scope for v2

- OpenAI / Gemini providers (structurally possible via the abstract class, not shipped)
- Filename optimisation (rejected in feasibility review)
- Sonnet/Opus model tier toggle (v2.1 candidate)
- Automatic nightly runs without human review (rejected — Option B from feasibility)
- Cross-site AI spend dashboard for Lead Wolf-level reconciliation (v2.1 candidate)
- Image content moderation / SafeSearch filtering (not needed for KBB)
- AI-suggested image captions or titles (alt only for v2)
- Integration with Smush Pro or other media plugins beyond what WordPress core exposes
- Retroactive processing of images deleted from the Media Library

---

## 10. Open items for Mike before build

None — all key decisions are locked:

- Model: **Haiku 4.5 only**
- Billing: **Single Lead Wolf key**
- Default: **Opt-out (on by default per site)**
- Scope: **Missing + rewrite in v2**
- Filenames: **Out of scope**
- Location handling: **Structured fields (business name, location, service area) + sensitivity setting. Default sensitivity: Minimal. No blanket suffix appending.**

One operational task for rollout: populate business name, primary location, and service area for each client site from the Lead Wolf client database. A WP-CLI helper is worth adding (`wp lwia set-location --business="..." --location="..." --service-area="..."`) so initial population across 150 sites can be scripted rather than clicked.

If anything shifts during build, log it here and flag in the next review.

---

## 11. Appendix — cost baseline reminder

For scoping sanity during build:

| Scenario | Images | Haiku 4.5 via Batch API |
|---|---|---|
| One client site, missing only | ~150 | £0.15 |
| One client site, full rewrite | ~900 | £0.91 |
| Portfolio initial rollout (missing) | 22,500 | £23 |
| Portfolio full regeneration | 135,000 | £137 |
| Monthly ongoing (new uploads) | ~3,000 | £3 |

Default spend cap of £20/site/month is ~10× expected monthly usage — generous headroom for bursty months or rewrite-heavy cleanups.
