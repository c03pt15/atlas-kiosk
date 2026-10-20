# Adding Training or Events to the Kiosk

## Overview

Training and events data live in `index.html` inside `<script>` tags. Each item is an object in a JavaScript array. The page renders dynamically from these arrays — there is no separate CMS.

---

## 1. Training (`TRAINING_DATA` array)

Located at `const TRAINING_DATA = [` (search for it in `index.html`).

### Object fields

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `category` | string | Yes | One of: `'live'`, `'self'`, `'connect'` — maps to a tag label (Live training / Self-paced training / Learn through connection) |
| `year` | string | Yes | e.g. `'2026'` |
| `day` | string | Yes | Day number or range, e.g. `'1'` or `'22–25'`. Empty string `''` for ongoing/self-paced |
| `dayFr` | string | Yes | French day (same as `day` usually) |
| `month` | string | Yes | Uppercase month, e.g. `'OCT'`, `'DEC'`, `'FEB'`, `'MARS'` |
| `monthFr` | string | Yes | French month, e.g. `'OCT'`, `'DÉC'`, `'FÉV'` |
| `title` | string | Yes | English title |
| `titleFr` | string | Yes | French title |
| `meta` | string | Yes | Short meta line (type · location · audience) |
| `metaFr` | string | Yes | French meta |
| `desc` | string | Yes | English description (template literal with backticks) |
| `descFr` | string | Yes | French description |
| `url` | string | Yes | English URL on atlasveterans.ca |
| `urlFr` | string | Yes | French URL on atlasveterans.ca |

### Auto-generated fields (do NOT set manually)

- `id` — set automatically as `'trn-' + index` at line ~2630
- `format` — set automatically to `'Training'`
- `formatFr` — set automatically to `'Formation'`

### Example object

```js
{
    category: 'live',
    year: '2026',
    day: '27',
    dayFr: '27',
    month: 'OCT',
    monthFr: 'OCT',
    title: 'Veteran and Family research training program',
    titleFr: 'Programme de formation en recherche pour les vétérans et leur famille',
    meta: 'Workshop series and online course · Online · For Veterans and Veteran Family members',
    metaFr: `Série d'ateliers et cours en ligne · Participation virtuelle · Pour les vétérans et les membres de leur famille`,
    desc: `A free research training program ...`,
    descFr: `Un programme de formation en recherche gratuit ...`,
    url: 'https://atlasveterans.ca/community/training/veteran-and-family-research-training-program/',
    urlFr: 'https://atlasveterans.ca/fr/communaute/formation/programme-de-formation-en-recherche-pour-les-veterans-et-leur-famille/'
}
```

### Rendering

`renderTraining()` (search for it) loops over `TRAINING_DATA`, reads `currentLang` to pick EN/FR fields, and injects HTML into `#trainingGrid`. Each item gets a date badge, tag chip, title, meta, description, "View details" link, and a select/add button.

---

## 2. Events (`EVENTS_DATA` array)

Located at `const EVENTS_DATA = [` (search for it).

### Object fields

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `year` | string | Yes | e.g. `'2026'` |
| `day` | string | Yes | Day or range, e.g. `'19–21'` |
| `dayFr` | string | Yes | French day |
| `month` | string | Yes | Uppercase month |
| `monthFr` | string | Yes | French month |
| `title` | string | Yes | English title |
| `titleFr` | string | Yes | French title |
| `location` | string | Yes | Event location |
| `locationFr` | string | Yes | French location |
| `desc` | string | Yes | English description |
| `descFr` | string | Yes | French description |
| `url` | string | Yes | English URL |
| `urlFr` | string | Yes | French URL |

### Auto-generated fields (do NOT set manually)

- `id` — set automatically as `'evt-' + index`
- `format` — set automatically to `'Event'`
- `formatFr` — set automatically to `'Événement'`

### Rendering

`renderEvents()` loops over `EVENTS_DATA`, injects HTML into `#eventsList`.

---

## 3. How to add an item

1. Open `index.html`.
2. Find `const TRAINING_DATA = [` or `const EVENTS_DATA = [`.
3. Add a new object entry to the array (place it wherever you want it to appear — order in array = order on screen).
4. Follow the field template above. Provide both EN and FR versions of every text field.
5. Save the file. No build step needed — changes are live on refresh.

---

## 4. Language switching

- The kiosk has a language toggle (EN/FR).
- `currentLang` holds `'en'` or `'fr'`.
- Render functions pick `fr ? t.titleFr : t.title` etc.
- Always provide both `*` and `*Fr` fields, or the FR view will show EN text (or undefined).

---

## 5. Gotchas

- Use template literals (backticks `` ` ``) for `desc`/`descFr`/`metaFr` if they contain apostrophes or special chars, OR escape single quotes.
- The `month`/`monthFr` values are displayed verbatim in the date badge — use the uppercase 3-letter French abbreviations used elsewhere (`OCT`, `DÉC`, `FÉV`, `MARS`, etc.).
- The `day` field can be empty `''` for ongoing/self-paced items (see `Introduction to trauma-exposed professionals: TExP1`).
- `urlFr` should point to the French version of the same page on atlasveterans.ca.
- After editing, open `index.html` in a browser and switch languages to verify both EN and FR render correctly.
