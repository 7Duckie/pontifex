# Pontifex Design Language

Pontifex's admin UI follows **Swiss design** (the International Typographic
Style): hierarchy through type and whitespace, not decoration; a near-monochrome
palette with a single restrained accent; a consistent grid and rhythm; sharp,
quiet surfaces. This document is the concrete vocabulary — palette, type scale,
spacing, components — promoted from the principles in
[`../.github/CONTRIBUTING.md`](../.github/CONTRIBUTING.md#design-language) now
that admin UI work has begun (v0.5.0). It grows as the UI does; everything here
is implemented in `assets/admin/pontifex-admin.css`.

## Principles

- **Typography over decoration.** Hierarchy comes from type weight, size and
  position, not boxes, borders or alert colours.
- **Generous whitespace.** Density through rhythm, not by packing pixels.
- **Sans-serif faces.** A system sans-serif stack; no serifs, no display faces.
- **Restrained colour.** Black, white and grey are the working palette, with one
  accent used sparingly. No traffic-light status colours unless the status
  genuinely demands them.
- **Grid-based layouts.** Asymmetric balance, sharp hairline rules, alignment to
  a consistent grid.
- **Restraint over alarm.** Destructive actions are communicated through clear
  language and explicit confirmation, never warning-coloured boxes that operators
  learn to dismiss.

## Palette

A near-monochrome scale with a single accent. Defined as CSS custom properties on
the `.pontifex-admin` root.

| Token | Value | Use |
|---|---|---|
| `--pontifex-ink` | `#111111` | Primary text, key figures, the heaviest rules. |
| `--pontifex-muted` | `#6b6b6b` | Secondary text, labels, captions. |
| `--pontifex-rule` | `#e3e3e3` | Hairline rules between rows. |
| `--pontifex-fill` | `#f6f6f6` | Subtle fills (used sparingly). |
| `--pontifex-paper` | `#ffffff` | Background. |
| `--pontifex-accent` | `#1a3a8f` | The single accent — a calm deep blue for links and active states, distinct from wp-admin's brighter blue. Used sparingly, **never** to signal alarm. |

There are deliberately **no** red/amber/green status colours. A failure is
communicated by its label and position, not by colour, per "restraint over alarm".

## Type scale

System sans-serif (`-apple-system, BlinkMacSystemFont, "Helvetica Neue",
Helvetica, Arial, sans-serif`), 14px base, 1.5 line-height. Hierarchy is mostly
weight and size:

| Role | Size | Weight |
|---|---|---|
| Page title | 34px | 700 |
| Operation / lead figure | 16px | 600 |
| Body | 14px | 400 |
| Section title (overline) | 12px uppercase, `0.08em` tracking | 700 |
| Table header (overline) | 11px uppercase, `0.06em` tracking | 700 |
| Caption / size note | 13px | 400 |

Numbers use `font-variant-numeric: tabular-nums` so figures and tables align.

## Spacing

An **8px rhythm** (`--pontifex-step: 8px`). Margins and padding are multiples of
it (8 / 16 / 24 / 32 / 40), so vertical rhythm stays consistent. Content sits in a
single left-aligned column capped at `960px`, with a 16px left inset so the column
does not sit flush against the wp-admin chrome.

## Components (v0.5.0)

- **Page header** — a 34px title and a muted subtitle, underlined by a 2px ink
  rule. The strongest line on the page.
- **Section** — an uppercase muted overline title, then content; sections are
  separated by whitespace, not boxes.
- **Stat** — an operation name, a line of tabular figures (succeeded / failed /
  attempted), and a muted size note, each stat introduced by a 1px top rule.
- **Table** — hairline rows, a 2px ink header underline, uppercase muted column
  headers; no zebra striping or cell borders.
- **Empty state** — a single muted sentence under a hairline rule, written to
  explain what will fill the space, not to alarm.
- **Button** — a solid accent rectangle with white 600-weight text, sharp corners,
  no border; it darkens to ink on hover/focus and fades when disabled. The one
  filled element on a page, used for the primary action (e.g. "Create backup").
- **Progress / notice** — a quiet muted line (tabular figures) that reports the
  running count of a long operation, and an ink line for its final result; neither
  is boxed or colour-coded, per "restraint over alarm". A secondary text action
  (e.g. a backup's "Delete") is an underlined muted link, never a coloured button.
- **Progress bar** — a determinate two-pixel hairline track in the rule colour that
  fills with the accent as a long operation advances, carrying the running-count
  line as its label. Hidden until the operation starts, it reaches full before the
  page reloads. A thin line, never a heavy bar or a spinner, and never colour-coded
  by state — restraint over alarm.

## Conventions

- **Scope every selector under `.pontifex-admin`.** The admin pages render inside
  `<div class="wrap pontifex pontifex-admin">`; the stylesheet is enqueued only on
  Pontifex screens. Both together keep Pontifex styles off every other admin page.
- **Escape all output** at the point of echo (`esc_html`, `esc_attr`,
  `esc_html__`); the markup stays plain so escaping is easy to verify.
- **No build step.** Plain CSS, shipped as-is; the plugin has no JavaScript build.

## See also

- [`../.github/CONTRIBUTING.md`](../.github/CONTRIBUTING.md#design-language) — the
  originating principles.
- `assets/admin/pontifex-admin.css` — the implementation.
