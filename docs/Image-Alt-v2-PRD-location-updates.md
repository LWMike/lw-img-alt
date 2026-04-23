# Image Alt v2 PRD — Location Handling Updates

**Date:** 22 April 2026
**Changes to:** `Image-Alt-v2-PRD.md`
**Summary:** Structured location handling via business name, primary location, service area, and a sensitivity setting. Replaces the earlier approach of embedding location guidance in the free-text style guide. Prompt template bumped to v2.1.

This document captures only the changes. The full updated PRD lives in `Image-Alt-v2-PRD.md`.

---

## Change 1 — Section 3.4 (Per-site AI settings) replaced

### Before

> Fields:
>
> - **AI features** — toggle, default ON.
> - **Style guide** — multiline text, per-site brand context. e.g. *"This is Square Kitchens at Ponsford, a kitchen showroom in Sheffield specialising in German brands including Nobilia and Schüller. When describing kitchens, use terms like 'handleless', 'integrated', 'matt', 'gloss', 'island' where accurate. Avoid mentioning the location in alt text unless the image clearly shows signage or exterior."*
> - **Spend cap** — per-month £ limit.
> - **Model** — read-only.
>
> Style guide gets prepended to every prompt sent for that site.

### After

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

---

## Change 2 — New section 3.4.1 added

### Location inclusion report

After every batch job completes, the review screen shows a small summary banner:

> *"Location mentioned in 12 of 247 suggestions (5%). Review these carefully to confirm they're genuinely location-relevant."*

Clicking the number filters the review screen to show only those rows, so the user can quickly spot-check whether location inclusion was warranted. This is the safety net for the model's judgement — cheap to implement (just a string match against the business name and primary location) and catches over-eager inclusions before they land.

---

## Change 3 — Section 4.3 (Prompt) rewritten

### Before

System prompt (v2.0):

```
You write alt text for images on WordPress websites. Output rules:

- Describe what is visible in the image — the subject, key objects, style, and composition.
- Write in UK English.
- Maximum 125 characters. Prefer 80–120.
- One sentence. No line breaks.
- Never start with "Image of", "Photo of", or "Picture of".
- Never keyword-stuff. Do not include location names, brand names, or SEO phrases unless they are visibly depicted (e.g. a visible shop sign).
- If rewriting existing alt text, improve it by describing what's actually shown.
- Return JSON with fields: alt (string), confidence (0.0–1.0 float).

The site-specific style guide, if any, is appended below.
```

### After

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

---

## Change 4 — Section 3.5 (Enhanced logging) prompt version bumped

### Before

| ai_prompt_version | VARCHAR(16) | e.g. `v2.0` — bumped when the prompt template changes |

### After

| ai_prompt_version | VARCHAR(16) | e.g. `v2.1` — bumped when the prompt template changes |

---

## Change 5 — Section 7.1 (Manual acceptance checklist) — 6 new checks added

Inserted after the existing "Style guide: reflected in output" check:

- [ ] **Location — Minimal:** product shot gets described without business or location name
- [ ] **Location — Minimal:** showroom exterior correctly includes business name and/or location
- [ ] **Location — Moderate:** hero project shot may include location where appropriate
- [ ] **Location — Generous:** more liberal inclusion but still never appends as a suffix
- [ ] **Location report:** post-batch banner counts location mentions correctly, filter works
- [ ] **Sensitivity change takes effect:** switching Minimal → Moderate produces different output on the same image set

---

## Change 6 — Section 10 (Locked decisions) — one addition, one operational note

### Before

> None — all key decisions are locked:
>
> - Model: **Haiku 4.5 only**
> - Billing: **Single Lead Wolf key**
> - Default: **Opt-out (on by default per site)**
> - Scope: **Missing + rewrite in v2**
> - Filenames: **Out of scope**
>
> If anything shifts during build, log it here and flag in the next review.

### After

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

## Rationale recap

The reason for replacing a free-text "mention location when appropriate" guideline with structured fields plus a sensitivity radio:

1. **Consistency across 150 sites.** A structured field is set the same way everywhere; free text isn't.
2. **Scriptable population.** Client database → WP-CLI helper → 150 sites populated in one command.
3. **Unambiguous prompt interpolation.** The model sees clearly-labelled fields with an explicit sensitivity level, not a paragraph of freeform text it has to parse and infer intent from.
4. **Defaults that protect against the Ponsford problem.** Minimal sensitivity means the model will, by default, not append location to product shots — which is exactly the failure mode we're fixing.
5. **Per-site tunability.** A client who wants more aggressive local relevance can opt up to Moderate or Generous without Mike editing prompt text anywhere.
6. **Auditability.** The location inclusion report after every batch catches over-eager inclusions at review time, not in production.
