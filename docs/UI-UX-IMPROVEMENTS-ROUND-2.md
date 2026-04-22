# Image Alt — Scan Screen UI Fixes (Round 2)

**Context:** After round 1, the filter row is misaligned and the autosave behaviour isn't discoverable. Team members don't know they can press Enter or tab out to save. Fix both.

---

## 1. Filter row alignment

**Problem:** The `STATUS` and `TYPE` dropdowns have labels above them, while `From` / `To` are labelled inline to the left, and the `Refresh results` / `Export CSV` buttons sit at the same visual height as the dropdowns — not the labels. The row looks broken.

**Fix:** Give every control in the filter row the same structure — a label above, the control below, all baseline-aligned on the control row.

### Target layout

```
Filter:  STATUS        TYPE         FROM              TO                  
         [All images▾] [All types▾] [dd/mm/yyyy 📅]   [dd/mm/yyyy 📅]   [Refresh] [Export CSV]
```

- `Filter:` sits to the left as a bold label, vertically centred on the control row (not the label row).
- Every control has a small uppercase label above it: `STATUS`, `TYPE`, `FROM`, `TO`. Same font size, same colour, same weight.
- Remove the inline `From` / `To` words entirely — they're now labels above the date inputs like the dropdowns.
- All controls align along the bottom edge.
- `Refresh results` and `Export CSV` buttons align to the bottom edge of the controls, not the top.

### Implementation

Use a flexbox row with `align-items: flex-end`. Each `.lwia-filter-field` is a flex column containing a label and its input. Buttons go in their own flex column with `margin-left: auto` or a separator to push them right.

```html
<div class="lwia-filter-row">
  <span class="lwia-filter-prefix">Filter:</span>

  <div class="lwia-filter-field">
    <label for="lwia-status">STATUS</label>
    <select id="lwia-status">...</select>
  </div>

  <div class="lwia-filter-field">
    <label for="lwia-type">TYPE</label>
    <select id="lwia-type">...</select>
  </div>

  <div class="lwia-filter-field">
    <label for="lwia-from">FROM</label>
    <input type="date" id="lwia-from">
  </div>

  <div class="lwia-filter-field">
    <label for="lwia-to">TO</label>
    <input type="date" id="lwia-to">
  </div>

  <div class="lwia-filter-actions">
    <button class="button">Refresh results</button>
    <button class="button button-primary">Export CSV</button>
  </div>
</div>
```

```css
.lwia-filter-row {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  flex-wrap: wrap;
  margin: 16px 0 12px;
}
.lwia-filter-prefix {
  font-weight: 600;
  padding-bottom: 8px;  /* align with bottom of inputs */
}
.lwia-filter-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.lwia-filter-field label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #50575e;
}
.lwia-filter-actions {
  display: flex;
  gap: 8px;
  margin-left: auto;
}
@media (max-width: 960px) {
  .lwia-filter-actions {
    margin-left: 0;
    width: 100%;
  }
}
```

The `margin-left: auto` on the actions cluster pushes Refresh/Export to the far right on desktop. On narrow screens they wrap to a new line.

---

## 2. Save button is back — with autosave kept as a bonus

**Problem:** Users don't know the alt text field autosaves. There's nothing telling them what to do. Removing the per-row Save button was a mistake — I prioritised visual cleanliness over discoverability.

**Fix:** Restore the per-row `Save` button, but keep the autosave-on-blur behaviour as a power-user shortcut. The button is the obvious, discoverable way to save; Enter and tab-out are the faster ways once you know they exist.

### Behaviour

- Each alt text row has a `Save` button next to it.
- The button is **disabled** (greyed out) when the field's value matches the last-saved value — nothing to save.
- The button **enables** (becomes a primary blue button) the moment the field is edited.
- After a successful save, the button returns to disabled and shows a brief "Saved" state (e.g. a green tick to the right of the button that fades after 2 seconds).
- **Enter** in the field triggers the same save (keyboard shortcut).
- **Blur** (clicking outside) triggers the same save IF the value has changed — silent autosave for power users.
- Errors still surface via the toast + red cross indicator as in round 1.

### A small helper line

Add a subtle helper line below the summary count, above the table:

> *"Edit alt text and click Save, or press Enter. Changes can be rolled back from the Undo screen."*

Styling: `color: #50575e; font-size: 13px; margin: 4px 0 12px;`

### Button styling states

```css
.lwia-row-save {
  /* uses .button base styles */
  min-width: 68px;
}
.lwia-row-save:disabled,
.lwia-row-save[aria-disabled="true"] {
  opacity: 0.5;
  cursor: default;
}
.lwia-row-save.is-dirty {
  /* Same styling as .button-primary — use that class */
}
.lwia-save-indicator {
  display: inline-block;
  margin-left: 8px;
  font-size: 18px;
  line-height: 1;
  opacity: 0;
  transition: opacity 0.2s;
}
.lwia-save-indicator.is-success { color: #00a32a; opacity: 1; }
.lwia-save-indicator.is-error   { color: #d63638; opacity: 1; }
.lwia-save-indicator.is-fading  { opacity: 0; }
```

### Column order update

The round 1 spec said to reorder columns with Alt text promoted. Keep that, and add a `Save` column at the end of the alt text cell (or integrated into the Alt text column as a flex row). Suggestion:

```
Preview | Filename | Alt text [input] [Save] [indicator] | Attached to | Details
```

Alt text column becomes a flex container:

```html
<td class="lwia-alt-cell">
  <input type="text" class="lwia-alt-input" value="...">
  <button class="button lwia-row-save" disabled>Save</button>
  <span class="lwia-save-indicator" aria-live="polite"></span>
</td>
```

---

## 3. JS behaviour (explicit)

For whoever implements this, here's the event wiring so nothing falls through the cracks:

```js
// Track original values so we can detect "dirty"
input.dataset.original = input.value;

input.addEventListener('input', () => {
  const dirty = input.value !== input.dataset.original;
  saveBtn.disabled = !dirty;
  saveBtn.classList.toggle('is-dirty', dirty);
  saveBtn.classList.toggle('button-primary', dirty);
});

input.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    save(input, saveBtn, indicator);
  }
});

input.addEventListener('blur', () => {
  if (input.value !== input.dataset.original) {
    save(input, saveBtn, indicator);
  }
});

saveBtn.addEventListener('click', () => save(input, saveBtn, indicator));
```

On successful save:
- Update `input.dataset.original` to the new value.
- Disable the button, remove `is-dirty`.
- Show success indicator, fade after 2s.

On error:
- Leave the button enabled so the user can retry.
- Show error indicator (stays until next edit).
- Show a dismissible toast with the error reason.

---

## 4. Testing checklist

- [ ] Filter row: all controls baseline-aligned, labels matching style
- [ ] Buttons right-aligned on desktop, wrap to new line on narrow viewports
- [ ] Save button visible on every row, disabled by default
- [ ] Save button enables (turns primary blue) when field is edited
- [ ] Clicking Save persists the change, shows green tick, disables button
- [ ] Pressing Enter in the field persists the change
- [ ] Tabbing out of an edited field autosaves silently
- [ ] Tabbing out of an unchanged field does nothing
- [ ] Error shows red cross + toast
- [ ] Helper line "Edit alt text and click Save, or press Enter." is visible above the table
