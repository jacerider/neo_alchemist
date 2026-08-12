# Styling Neo Alchemist Components

A guide for **frontend developers** building Single Directory Components (SDCs) for
Neo Alchemist. It covers the three styling systems you'll use in almost every
component: **color schemes**, **spacing**, and **style props** (text alignment,
heading size, button styles, …).

These systems are built on Tailwind (v4) utilities plus a small set of CSS custom
properties, so most of your work is writing normal utility classes in your `.twig`
and declaring a few props in your `.component.yml`.

---

## Mental model

A component has two halves:

- **`*.component.yml`** — declares **props**. Some props are *style props*: the
  editor picks a value (a color scheme, a spacing size, an alignment) and Alchemist
  turns it into a CSS class.
- **`*.twig`** — your markup. The component's **root element** receives the style
  classes that were marked `apply: true`, and you write the rest of the layout with
  Tailwind utilities.

```twig
{# accordion_test.twig #}
{% set classes = ['bg-default', 'component-bg'] %}
<div {{ attributes.addClass(classes) }}>   {# root: gets scheme-*, component-spacing-*, neo-section-y via apply #}
  <div class="container-content">
    …
  </div>
</div>
```

```yaml
# accordion_test.component.yml
props:
  type: object
  properties:
    scheme:
      type: scheme        # color scheme picker
      title: Color Scheme
      apply: true         # adds the scheme-* class to the root
    spacing:
      type: spacing       # spacing size picker (defaults to "md")
    gap:
      type: gap           # applies the vertical padding + merge behavior
    text_align:
      type: text_align    # alignment picker
      apply: true
```

> **The `apply` flag.** When a style prop is `apply: true`, its CSS class is merged
> into the root element's `attributes` automatically — you just need
> `{{ attributes.addClass(...) }}` on that element. When `apply` is omitted/`false`,
> the value is passed to your Twig instead so you can place it yourself (see
> [Using a style prop manually](#using-a-style-prop-manually)).

---

## Colors & schemes

### Color palettes

Four palettes are always available as Tailwind colors: **`base`**, **`primary`**,
**`secondary`**, **`accent`**.

Each palette gives you:

| Class pattern | Example | Meaning |
| --- | --- | --- |
| `{prop}-{palette}` | `bg-primary`, `text-accent`, `border-base` | The palette's default color |
| `{prop}-{palette}-{shade}` | `bg-base-0`, `bg-primary-500`, `bg-base-950` | A specific shade, `0` (lightest) → `950` (darkest) |
| `{prop}-{palette}-content` | `text-primary-content` | The readable foreground color to use **on** that color |
| `{prop}-{palette}-{shade}-content` | `text-base-500-content` | Readable foreground on a specific shade |
| `{prop}-{palette}-hover` | `hover:text-primary-hover` | The default color's **adaptive hover step** — contrast-picked per scheme. Pair as `text-primary hover:text-primary-hover`; never `hover:text-primary-600` (numbered shades don't adapt) |
| `{prop}-{palette}-hover-content` | `text-primary-hover-content` | Readable foreground on the hover color (for hover fills) |

`shadow-{shade}` is also available (from the base palette), plus `white`/`black`
aliases (mapped to `base-0` / `base-950`).

**`link` / `link-hover`** color any element like a scheme link — the same
contrast-picked pair a bare `<a>` gets automatically: `text-link
hover:text-link-hover`. Use them on non-anchor elements, or anchors that must
carry other color classes; they stay legible in every scheme, including schemes
that pin a role's contrast for brand fidelity.

> **Adaptive vs raw — the one-line rule:** naming a shade number opts *out* of
> scheme adaptation. `bg-default`, bare tokens (`text-primary`), `-hover` steps,
> `link` tokens, `.btn*` and bare `<a>` all adapt; `text-primary-500` is always
> the raw brand ramp — keep it only for deliberate raw-brand marks (decor).

> **`-content` pairing.** Whenever you set a background, set its text with the
> matching `-content` color so it stays readable in every scheme:
> `class="bg-primary text-primary-content"`.

### Gray scales fall back to `base`

> **Prefer `base`.** In components you author, use `base` / `bg-default` for
> neutral surfaces and text — that is the house style. The gray-family fallback
> below is a safety net for pasted markup, **not** a reason to reach for `gray-*`.
> When adapting copied markup, convert `gray-*` (etc.) to the matching `base-*`.

The site standardizes neutral colors on **`base`**, so Tailwind's gray-family
scales — **`gray`, `slate`, `zinc`, `neutral`, `stone`** — are automatically
aliased to `base` whenever that pallet isn't enabled. Markup copied from the
internet that uses `bg-gray-100`, `text-slate-700`, `border-zinc-200`, etc. still
works: each maps to the matching `base` shade and is **scheme-reactive** (it
resolves through `--color-base-*`, like `bg-default`).

- `bg-gray-100` → `base-100`, `text-slate-700` → `base-700`, … (full `50…950`
  scale plus `-content`).
- If a gray-family pallet is explicitly enabled in neo_color, it keeps its own
  colors (the alias only fills the gap).
- Only the **neutral** family falls back. Non-neutral internet colors (`blue`,
  `red`, …) are not aliased — enable that pallet or use `primary`/`accent`.

> Implementation: neo_color's `NeoBuildEventSubscriber`.

### Scheme-reactive defaults

Inside a scheme, **two things apply automatically — no class needed**:

| What | Behavior |
| --- | --- |
| Text color | Unstyled text uses the scheme's readable foreground. Override with any `text-*` utility (or an explicit color) — directly-set always wins. |
| Border color | Any element with a border width uses the scheme's border color. |

So a surface usually only needs `bg-default` (the scheme's base background,
`--background-color-default`) — text and borders follow on their own. The
`text-default` utility still exists (resolves to `--text-color-default`) for when
you want to re-assert the scheme foreground on something that overrode it.

> `bg-default` is intentionally **not** automatic — apply it where you want a
> surface fill, so a bare scheme wrapper never paints an unexpected background.
> Give text a background (`bg-default` or otherwise) so its contrast is defined.

### The `scheme` prop

Add a scheme picker to a component and let the editor recolor it:

```yaml
scheme:
  type: scheme
  title: Color Scheme
  apply: true     # adds e.g. `scheme-primary` to the root
```

When a scheme is selected, the root gets a `scheme-*` class (e.g. `scheme-dark`,
`scheme-primary`). That class **re-scopes every color variable** for that subtree, so
`bg-default`, `text-default`, `bg-primary`, `text-primary-content`, etc. all
automatically adopt the chosen scheme's colors. You don't write per-scheme classes —
you write `bg-default` / palette colors once and they follow the scheme.

### Scheme variants

When you do need scheme-specific tweaks, use the generated variants:

| Variant | Applies when… |
| --- | --- |
| `scheme:` | any scheme is active |
| `dark:` | the active scheme is a *dark* scheme |
| `color:` | the active scheme is a *colorized* scheme |
| `{scheme-id}:` | a specific scheme is active (e.g. `primary:`, `accent-solid:`) |

```twig
<span class="text-base-700 dark:text-base-200">…</span>
```

> Variants are compiled on demand — they only appear in the CSS once you actually use
> them in a `.twig`/`.yml`.

### Best practice

- Default surface + text: `bg-default text-default`.
- Emphasis colors: palette utilities (`bg-primary text-primary-content`).
- Hover states: the `-hover` step (`hover:text-primary-hover`, `hover:bg-primary-hover`)
  or the link pair (`text-link hover:text-link-hover`) — never a numbered shade.
- Let the `scheme` prop do the recoloring; avoid hardcoding one scheme's colors.

---

## Spacing

### How it works

The `spacing` prop sets a single CSS variable, **`--spacing-component`**, on the root
(it applies a `component-spacing-*` class). Every `*-component` utility reads that
variable, so one editor choice scales all of a component's rhythm consistently.

```yaml
spacing:
  type: spacing   # apply is built in; defaults to "md"
```

The picker offers seven sizes (responsive — they grow at the `md` and `lg`
breakpoints):

| Value | Class applied to root |
| --- | --- |
| `xs` | `component-spacing-xs` |
| `sm` | `component-spacing-sm` |
| `md` *(default)* | `component-spacing` |
| `lg` | `component-spacing-lg` |
| `xl` | `component-spacing-xl` |
| `2xl` | `component-spacing-2xl` |
| `3xl` | `component-spacing-3xl` |

### The `*-component` utilities

Use these anywhere inside the component; they all derive from `--spacing-component`:

| Utility | Value |
| --- | --- |
| `m-component`, `p-component`, `gap-component`, `space-y-component`, … (and `mt-`, `py-`, etc.) | `1×` the component spacing |
| `…-component-xs` | `÷3` |
| `…-component-sm` | `÷1.5` |
| `…-component-lg` | `×1.5` |
| `…-component-xl` | `×3` |

> **Two different scales — don't confuse them:**
> - `component-spacing-*` (the **prop**) sets the *base* size for the whole component.
> - `p-component-sm`, `m-component-lg`, … (the **utilities**) scale *relative to that
>   base* for an individual element.

### The `gap` prop (vertical rhythm between sections)

A component's own vertical padding is applied by the **`gap`** prop — never write
the carrier class in a component's twig yourself:

```yaml
spacing:
  type: spacing   # apply is built in; defaults to "md" — the SIZE
gap:
  type: gap       # apply is built in; defaults to "auto" — the APPLICATION
```

`gap` puts `neo-section neo-section-y` on the root, so every declaring component
gets top + bottom padding sized by `spacing`. Its editor-facing options control
how that padding merges with neighboring sections:

| Value | Effect |
| --- | --- |
| `auto` *(default)* | padding on both sides; seams to same-color neighbors collapse automatically |
| `keep` | never merge with a neighbor (adds `component-bg-flush-none`) |
| `flush_top` | zero the top side (adds `component-flush-t`) |
| `flush_bottom` | zero the bottom side (adds `component-flush-b`) |
| `flush_both` | zero both sides |

Use padding for section rhythm everywhere — including transparent components.
Hand-written carriers (`py-component`, `my-component`) are **deprecated** for
stacking rhythm: seam collapsing (below) is what turns doubled padding into a
single gap, and it handles transparent components too.

**Special layouts** (a full-bleed band above the padded area, padding on a deep
child): opt out of root application and place the prop's classes manually — the
editor picker keeps working:

```yaml
gap:
  type: gap
  apply: false
```

```twig
<div{{ gap.removeClass('neo-section').addClass('container-content') }}>…</div>
```

### How seams collapse (the `-t`/`-b` channels)

`neo-section-y` resolves its two sides through the **inherited** override
channels `--spacing-component-t` / `--spacing-component-b`. Unset, each side is
its natural size; set to `0` on a section root, the carrier loses that side
*wherever it sits inside* — root, direct child, or deeper. That's what makes
collapsing carrier-position-proof.

- `component-flush-t` / `component-flush-b` — force a side to zero (what the
  `gap` prop's flush options apply).
- `component-spacing-reset` — restore natural spacing for a subtree (an inner
  element whose `pb-component` is layout, not rhythm). `.neo-region` gets this
  automatically, so an outer collapse never bleeds into nested component trees.

> **Legacy carriers.** Components written before the `gap` prop hand-write
> `py-component` / `my-component` on their section root or inner wrapper. A
> deprecated shim in the module's `_utilities.css` makes those **base-size**
> vertical utilities (`py/pt/pb/my/mt/mb-component`) channel-aware too, so those
> components keep collapsing with no markup change. The relative size variants
> (`-xs`, `-sm`, `-lg`, `-xl`) are deliberately left alone — they're meant for
> spacing *inside* a component, so a collapsed section can't silently zero
> internal spacing. Prefer the variants (or numeric utilities) for internal
> rhythm; migrate section carriers to the `gap` prop.

### Background sections: the `component-bg` marker

Add the **`component-bg`** marker class to the root of any full-bleed background
section (alongside `bg-default`):

```twig
{% set classes = ['bg-default', 'component-bg'] %}
```

Padding doesn't collapse on its own, so two adjacent sections showing the same
color would stack `2×` spacing. Generated CSS fixes this: at build time every
`(scheme × surface)` combination is resolved to the **actual color it renders**
(the same neo_color token values the schemes emit) and grouped by color. When a
section is immediately followed by another section in the same color group, the
first one's bottom channel is zeroed — the gap collapses from `2×` back to `1×`.
Different-colored neighbors keep their full separation. No per-page work; it
adapts as editors reorder components.

**"Same color" is computed, not class-matched.** `scheme-default` next to a
no-scheme section, or two different schemes that happen to share a background,
collapse whenever the rendered pixels match. `bg-default` next to `bg-base-100`
under one scheme is two colors and keeps its separation.

**Transparent components participate.** A section root carrying `neo-section`
(from the `gap` prop) with **no** `component-bg` paints nothing — the page shows
through — so it belongs to the page background's color group and collapses
against neighbors of that color. The page background defaults to the base
surface (`--color-base-0`); a theme painting its canvas differently alters it
via `hook_neo_alchemist_page_background_alter()`.

The recognized surfaces are `bg-default`, `bg-base-50`, `bg-base-100`,
`bg-base-200`, `bg-base-300`. A section painting anything else never collapses —
that fails safe (a doubled gap, never two mismatched colors overlapping). A
theme can extend the vocabulary with
`hook_neo_alchemist_component_bg_surfaces_alter()` (utility class ⇒ neo_color
token).

> The seam left behind is the **following** section's top spacing, so neighbors
> with different `spacing` sizes still collapse cleanly — nothing is pulled with
> a negative margin, so sections can never overlap each other's content.
>
> Implementation: `src/EventSubscriber/NeoBuildInlineEventSubscriber.php` (the
> generated rules) and `src/css/_utilities.css` (the channel contract).

---

## Layout containers

Two container utilities (provided globally by the **neo base theme**) constrain and
center content. Use them as the **standard inner wrapper** of a component so content
lines up with the rest of the site while a full-bleed background can still span the
viewport.

| Class | What it does | Side gutters |
| --- | --- | --- |
| `container-center` | Centers content with a responsive max-width (`= container mx-auto`) | **No** |
| `container-content` | `container-center` **plus** responsive side padding (`px-container`, i.e. `--spacing-container`) | **Yes** |

Both are responsive: width is `100%` up to a max-width that steps up at each
breakpoint (`40rem → 48rem → 64rem → 80rem → 96rem`).

- **`container-content`** is the default for most component content — it keeps the
  reading width comfortable *and* adds the standard left/right gutter.
- **`container-center`** is for when you want the same centered max-width but will
  manage horizontal padding yourself (e.g. a child that should bleed to the gutter
  edge, or a nested grid that sets its own padding).

**Flushing a component nested in a region.** `container-content` is
`@apply container-center px-container!`, so its horizontal padding resolves through the
`--spacing-container` variable rather than a fixed value. A parent component that renders
a `region` zeroes that variable for everything inside it with `neo-region-flush-x`, so its
children fill the parent's column instead of adding a second gutter. Because the utility
sets the variable rather than overriding `padding`, it works at any nesting depth, it
survives `container-content`'s `!important` (the important governs which `padding-inline`
declaration wins, not what the variable resolves to), and it matches both spellings —
`container-content` and the longhand `container-center px-container`. A child component
needs no markup, class or prop of its own to participate: which gutters survive is the
parent's decision, made once on the region. Companion utilities `neo-region-flush-y` /
`-t` / `-b` do the same for the children's outer vertical spacing.

### The full-bleed pattern

Put the **background on the root** (full width) and the **`container-content` on an
inner wrapper** (constrained). Vertical spacing comes from the `gap` prop on the
root, so the background fills it:

```twig
{% set classes = ['bg-default', 'component-bg'] %}
<div {{ attributes.addClass(classes) }}>     {# full-width bg + neo-section-y via the gap prop #}
  <div class="container-content">            {# centered, gutters #}
    …
  </div>
</div>
```

This is the standard structure for section components: edge-to-edge color, centered
readable content.

---

## Style props

Style props let an editor pick a value that becomes a CSS class. The built-in ones:

| Prop `type:` | Picks | Emits class(es) |
| --- | --- | --- |
| `scheme` | Color scheme | `scheme-*` |
| `spacing` | Component spacing size | `component-spacing-*` |
| `gap` | Vertical padding + neighbor merging | `neo-section neo-section-y` (+ `component-flush-*`) |
| `text_align` | Text alignment | `text-left` / `text-center` / `text-right` |
| `heading_size` | Heading size | `title-md`, `title-page title-xl`, … |
| `button_style` | Button look | `btn`, `btn-outline-primary`, `btn-text-accent`, … |
| `button_size` | Button size | `btn-sm`, `btn-lg`, … |

Declare one by referencing its `type`:

```yaml
text_align:
  type: text_align
  title: Text Alignment
  apply: true
```

### Using a style prop manually

If a style prop is **not** `apply: true`, place its value yourself in Twig:

```twig
{# Render as an attribute string -> ` class="title-md"` #}
<div{{ heading.size }}>{{ heading.title }}</div>

{# Or get the raw selected key #}
<span class="text-{{ text_align.getValue() }}">{{ title }}</span>
```

- `{{ prop }}` → renders the resolved class as an attribute (` class="…"`).
- `{{ prop.getValue() }}` → the raw selected key (e.g. `left`, `md`).

### Adding your own style prop

Style options live in `*.neo_component_prop_defs.yml` (see
`neo_alchemist.neo_component_prop_defs.yml`). A style prop is `type: style` with a
`styles` map of `key → { label, value }`, where `value` is the CSS class to apply:

```yaml
# my_theme.neo_component_prop_defs.yml
overlay:
  title: Overlay
  type: style
  examples: none
  apply: true
  styles:
    none:  { label: None,  value: '' }
    light: { label: Light, value: 'bg-base-0/60' }
    dark:  { label: Dark,  value: 'bg-base-950/60' }
```

Then reference it from any component: `overlay: { type: overlay }`. Style props are
automatically editable and live-previewable in the component manage/preview UI.

---

## A complete example

```twig
{# components/cta/cta.twig #}
{%
  set classes = ['bg-default', 'component-bg']   {# scheme-aware bg + collapse marker #}
%}
<div {{ attributes.addClass(classes) }}>          {# root gets scheme-*, component-spacing-*, neo-section-y #}
  <div class="container-content">                  {# centered, gutters #}
    <div class="max-w-prose mx-auto text-center">
      <h2 class="title-lg text-default">{{ title }}</h2>
      <p class="mt-component-sm text-default/80">{{ summary }}</p>
      <a href="{{ link.url }}" class="mt-component btn btn-primary">{{ link.title }}</a>
    </div>
  </div>
</div>
```

```yaml
# components/cta/cta.component.yml
props:
  type: object
  properties:
    title:   { type: string, title: Title }
    summary: { type: string, title: Summary }
    link:    { type: link,   title: Link }
    scheme:  { type: scheme,  title: Color Scheme, apply: true }
    spacing: { type: spacing, title: Spacing }
    gap:     { type: gap,     title: Gap }
```

This component:
- recolors entirely from the `scheme` prop (via `bg-default` / `text-default`),
- sizes its rhythm from the `spacing` prop and paints it via the `gap` prop
  (`neo-section-y` on the root, `mt-component*` inside),
- merges cleanly against an adjacent same-color section (`component-bg`).

---

## Preview & iterate

Open a component's live preview at:

```
/admin/config/neo/alchemist/preview/{provider}:{component}
```

e.g. `/admin/config/neo/alchemist/preview/front:cta`. You can change every editable
prop (scheme, spacing, alignment, text, …) and the preview refreshes instantly. With
the Neo build watcher running, edits to your `.twig`/`.css`/`.yml` reload the preview
automatically.

---

## Quick reference

**Colors**
- Surfaces/text: `bg-default`, `text-default` (scheme-aware).
- Palettes: `base`, `primary`, `secondary`, `accent` → `bg-…`, `text-…`, `border-…`,
  shades `-0…-950`, and `-content` companions.
- Gray family (`gray`, `slate`, `zinc`, `neutral`, `stone`) falls back to `base` when
  not enabled — pasted internet markup using them works and is scheme-reactive.
- Scheme variants: `scheme:`, `dark:`, `color:`, `{scheme-id}:`.

**Spacing**
- Props: `spacing` (size) → `component-spacing-{xs,sm,md,lg,xl,2xl,3xl}`;
  `gap` (application) → `neo-section neo-section-y` + auto/keep/flush options.
- Utilities: `m/p/gap/space-…-component` + `-xs ÷3 · -sm ÷1.5 · -lg ×1.5 · -xl ×3`.
- Background component → add the `component-bg` marker + `bg-*` on the root;
  transparent components need nothing extra. Never hand-write a section carrier.

**Layout**
- `container-content` — centered, responsive max-width, **with** side gutters (default
  inner wrapper).
- `container-center` — centered, responsive max-width, **no** side gutters.
- Full-bleed pattern: background on the root, `container-content` on the inner wrapper.
