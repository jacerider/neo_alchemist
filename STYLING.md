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
<div {{ attributes.addClass(classes) }}>   {# root: gets scheme-*, component-spacing-* via apply #}
  <div class="container-content py-component">
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
      type: spacing       # spacing picker (defaults to "md")
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

`shadow-{shade}` is also available (from the base palette), plus `white`/`black`
aliases (mapped to `base-0` / `base-950`).

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

### Margin vs padding (the important part)

Spacing between stacked components is usually done on the component **root**:

- **Plain components → use margin (`my-component`).** Vertical margins **collapse**,
  so two stacked components share a single gap. ✅
- **Components with a background color → use padding (`py-component`).** Margin sits
  *outside* the background, so it wouldn't be filled; padding keeps the background
  spanning the spacing.

```twig
{# plain component #}
<div {{ attributes }}><div class="container-content my-component">…</div></div>

{# background component #}
{% set classes = ['bg-default', 'component-bg'] %}
<div {{ attributes.addClass(classes) }}><div class="container-content py-component">…</div></div>
```

### Background sections: the `component-bg` marker

Padding doesn't collapse. So two **adjacent** background components of the **same
color** would stack `2×` padding and look like one band with too much empty space.

To fix this, add the **`component-bg`** marker class to the root of any full-bleed
background section (alongside `bg-default`):

```twig
{% set classes = ['bg-default', 'component-bg'] %}
```

Generated CSS then detects when a `component-bg` section is immediately preceded by
another `component-bg` of the **same scheme** and pulls it up by one spacing unit —
the two same-color backgrounds merge seamlessly and the gap collapses from `2×` back
to `1×`. Different-colored neighbors keep their full separation. No per-page work
required; it adapts as editors reorder components.

> Adjacent same-color sections should use the **same** spacing size for an exact
> collapse (the natural choice anyway).
>
> Implementation: `src/EventSubscriber/NeoBuildInlineEventSubscriber.php`.

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

### The full-bleed pattern

Put the **background on the root** (full width) and the **`container-content` on an
inner wrapper** (constrained). Vertical spacing (`py-component`) goes on the inner
wrapper too, so the background fills it:

```twig
{% set classes = ['bg-default', 'component-bg'] %}
<div {{ attributes.addClass(classes) }}>     {# full-width background #}
  <div class="container-content py-component"> {# centered, gutters, vertical spacing #}
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
| `spacing` | Component spacing | `component-spacing-*` |
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
<div {{ attributes.addClass(classes) }}>          {# root gets scheme-* + component-spacing-* #}
  <div class="container-content py-component">     {# padding so bg fills the spacing #}
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
```

This component:
- recolors entirely from the `scheme` prop (via `bg-default` / `text-default`),
- spaces itself from the `spacing` prop (`py-component`, `mt-component*`),
- merges cleanly against an adjacent same-scheme section (`component-bg`).

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
- Prop: `spacing` → `component-spacing-{xs,sm,md,lg,xl,2xl,3xl}`.
- Utilities: `m/p/gap/space-…-component` + `-xs ÷3 · -sm ÷1.5 · -lg ×1.5 · -xl ×3`.
- Plain component → `my-component`. Background component → `py-component` +
  `component-bg` marker on the root.

**Layout**
- `container-content` — centered, responsive max-width, **with** side gutters (default
  inner wrapper).
- `container-center` — centered, responsive max-width, **no** side gutters.
- Full-bleed pattern: background on the root, `container-content` on the inner wrapper.
