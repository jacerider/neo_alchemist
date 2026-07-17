---
name: neo-component
description: Create and modify Drupal Single Directory Components (SDC) for the neo_alchemist module. Use when the user asks to build, add, edit, or scaffold a page-building component in web/themes/front/components — or when referencing terms like "Neo component", "Alchemist component", "SDC", or file patterns like *.component.yml and *.twig under the theme's components directory.
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Creating Neo Alchemist Components

The site uses the `neo_alchemist` module (Drupal contrib) to provide page-building via Drupal Single Directory Components (SDC). Components live in [web/themes/front/components/](web/themes/front/components/) and can be composed into pages by editors.

> **Modifying the neo_alchemist module itself** (its PHP — shapes, plugins, services, the render pipeline)? That's a different job — use the `neo-alchemist-dev` skill and [web/modules/contrib/neo_alchemist/ARCHITECTURE.md](web/modules/contrib/neo_alchemist/ARCHITECTURE.md). This skill is for authoring components in a theme.

## Directory layout

Every component is a folder. Required files:

```
web/themes/front/components/<machine_name>/
├── <machine_name>.component.yml   # Schema/prop definitions (REQUIRED)
├── <machine_name>.twig            # Render template (REQUIRED)
├── README.md                      # Editor/developer notes (optional)
├── <machine_name>.js              # Component-local JS (optional, auto-loaded)
├── <machine_name>.css             # Component-local CSS (optional, auto-loaded)
└── thumbnail.png                  # Preview image (optional)
```

The `machine_name` MUST match the folder and file names. Use snake_case (e.g. `cards_s1`, `media_text_s1`). The `_s1`/`_s2` suffix is the site convention for "style 1/2" variants.

> **Favor Tailwind utilities in the `.twig`; treat `<machine_name>.css` as a last resort.**
> Layout, spacing, sizing, color (scheme utilities like `text-accent-500`, `bg-primary`,
> `border-primary-600`), typography — including arbitrary values like `text-[0.62rem]`,
> `tracking-[0.34em]`, `aspect-[5/4]`, `basis-[calc((100%_-_5rem)/5)]` (use `_` for the
> spaces a value needs) — and **interaction states** (`hover:`, `group` + `group-hover:`,
> `focus-visible:`, `disabled:`, `motion-reduce:`) all have utilities. Write them as
> classes; drive per-element hover choreography with `group`/`group-hover:` on the card,
> not a `.css` `:hover` rule. Reach for a component `.css` file **only** for what
> genuinely has no utility: `@keyframes`, gradient overlays, exact-value `box-shadow`s,
> scrollbar hiding, `::before`/`::after` decorative content, and styling nested
> elements inside markup you don't hand-write. (The `<img>` from `neo_image_style()` is
> **not** such a case — you can't `.addClass()` its render array, but you *can* pass it a
> `class` via the function's 5th `attributes` argument:
> `neo_image_style(src, {…}, alt, '', {class: ['h-7', 'w-auto']})`.) If
> you catch yourself writing `display: flex`, `padding`, `color`, or `:hover` in a
> component `.css`, convert it to utilities. See [hero_video](web/themes/front/components/hero_video/hero_video.css) —
> its `.css` is just keyframes/gradients/exact shadows; all layout is utilities in the twig.

> **Tailwind version: v4** (the neo build runs Tailwind CSS 4). This matters for
> sizing: v4's spacing scale is **dynamic** — `--spacing` is `0.25rem` and any
> integer generates a utility on demand, `w-<n>` → `calc(var(--spacing) * n)`.
> So a size that lands on the 4px grid should **always** use the numeric class and
> **never** an arbitrary value — small sizes too: `w-6` (24px) not `w-[24px]`,
> `size-8` (32px) not `size-[32px]`, `h-135` (540px) not `h-[540px]`, `w-130`
> (520px) not `w-[520px]`, `w-105` (420px), `w-65` (260px). To convert, divide the
> px by 4 (`24 / 4 = 6`, `540 / 4 = 135`). Numeric classes are rem-based (scale with
> root font-size) and stay on the design grid; prefer them for
> widths/heights/padding/margins/gaps. An arbitrary `w-[…]` / `h-[…]` / `p-[…]` /
> `gap-[…]` is a **last resort** — treat one as a smell that a scale step was
> missed, and convert it (`w-[16px]`→`w-4`, `mt-[12px]`→`mt-3`) unless the value is
> genuinely off-grid.
> **Reserve arbitrary `[...]` values for genuinely off-grid values** that have no
> scale step: sub-step font sizes (`text-[0.62rem]`), em letter-spacing
> (`tracking-[0.34em]`), unitless line-heights (`leading-[1.15]`), custom
> transition timings (`duration-[1.2s]`), aspect ratios (`aspect-[4/3]`), and
> `calc()` (`basis-[calc((100%_-_5rem)/5)]`). (This is Tailwind v4, so the color
> theme is JS-registered, not `@theme` — see the neo-build skill.)

## The `.component.yml` file

Every component yml starts with this boilerplate:

```yaml
$schema: 'https://git.drupalcode.org/project/drupal/-/raw/10.1.x/core/modules/sdc/src/metadata.schema.json'
name: 'Human Readable Name'
status: stable
description: 'One sentence for editors.'
neo: true   # REQUIRED — tells Alchemist this is a managed component
props:
  type: object
  properties:
    # props defined here
  required:
    # optional list of required prop keys
slots:
  # optional named slots
```

Key fields:
- **`neo: true`** — required flag. Without it the component is not picked up by Alchemist.
- **`status`** — `stable`, `beta`, `experimental`, or `deprecated`.
- **`libraryOverrides.dependencies`** — attach Drupal libraries (e.g. `neo/library.alpine` for Alpine.js).

## Prop types (Alchemist shapes)

Alchemist extends SDC with custom "shapes" — reusable prop definitions from [neo_alchemist.neo_component_prop_defs.yml](web/modules/contrib/neo_alchemist/neo_alchemist.neo_component_prop_defs.yml). Use these by name rather than raw JSON Schema.

> **Get shapes from the CLI (authoritative):** `drush neo:alchemist:shapes` lists every available shape; `drush neo:alchemist:shapes <name>` (e.g. `heading`) dumps that shape's resolved schema, a paste-ready `.component.yml` prop snippet, and its Twig render pattern. Prefer this over guessing from the summary below.

### Content shapes
- `heading` — object with `supertitle`, `title`, `subtitle`, `size`, `anchor`. Provide `examples` with the text fields you use. When the heading is the component's **main title**, render `size` (see the `heading_size` style shape below and the render table) so editors control the title grade. `title` is **optional** — a heading may be `supertitle`/`subtitle`-only (handy when reusing this shape for a two-tone item caption/label whose emphasized word is the only text, e.g. a single accent word). When you hand-render the heading, guard each part with `{% if %}` so a missing `title` doesn't emit an empty `<h2>`.
- `markup` — rich text / array. Use for prose descriptions.
- `string` — plain text.
- `image` — object `{src, alt, width, height}`. Render with `neo_image_style()` or `neo_image()`. The `src` accepts a URL/path **or** one of two local-asset schemes, resolved to a real URL by the image shape: **`component://<path>`** points at a file bundled *inside the component folder* (e.g. store `web/themes/front/components/callout_s1/images/monogram.png` and set `src: 'component://images/monogram.png'`), and **`theme://<path>`** points at the default theme's directory. Prefer `component://` for a component's own default/decorative art (a monogram, emblem, texture) — it ships and versions with the component and needs no editor upload or external `placehold.co` URL. Real content images still come from the editor (a `media`/uploaded image).
- `image-uri` — just an image URL.
- `file` — object `{src, title, name}` for downloadable files.
- `remote_video` — YouTube/Vimeo embed `{src, thumbnail, title}`.
- `icon` — icon machine name (rendered via `icon(name)` Twig function). Find valid names with `drush neo:icon:list <search>` (e.g. `drush neo:icon:list arrow`) — don't guess, invalid names render nothing. **Strip the library prefix that `neo:icon:list` prints.** The command lists names like `regular-chevron-left`, but `icon()` wants the bare name: `icon('chevron-left')` renders — `icon('regular-chevron-left')` renders **nothing** (silently, no error). So from a listed name drop the leading `regular-`/`solid-`/`light-`/etc. segment. To force a specific library, use the `|icon_library('name')` filter, not a name prefix.
- `link` — button-style link `{uri, title, options, icon, target, access}`. Usually paired with a `button_style`.
- `url` — similar to link but for anchor-style links.
- `email`, `telephone`, `uri` — single-value types.
- `address` — postal address object.
- `menu` — editable list of nav items `{title, description, icon, url}` (each item's `url` is a full `url` shape, so it keeps `target`/`access`; use `item.title` for the label). Prefer this for navigation over a hand-rolled `array` of links. When fed by the `menu` value provider, items also carry runtime keys the schema doesn't list: `in_active_trail`/`is_expanded`/`is_collapsed`, nested `below` children, and — with `neo_alchemist_menu` enabled — `region: true` + `content` (a render array) on **component region** items: render with `{% if item.region %}{{ item.content }}{% endif %}` instead of a link. Mega menu reference: [web/themes/front/components/header_s1/header_s1.twig](web/themes/front/components/header_s1/header_s1.twig) and the **neo-alchemist-menu** skill.
- `breadcrumb` — array of `{title, url}`.
- `slug` — anchor/slug string.
- `media` — Drupal media entity reference.

### Style shapes (applied as CSS classes via attributes)

> **Authoritative styling guide:** [web/modules/contrib/neo_alchemist/STYLING.md](web/modules/contrib/neo_alchemist/STYLING.md) covers schemes, colors, spacing, and containers in full. The essentials are summarized here and in the Twig patterns below.

- `scheme` — color-scheme selector. With `apply: true` it adds a `scheme-*` class to the root, which **re-scopes every color utility** (`bg-default`, `bg-primary`, …) to the chosen scheme — and a scheme region adapts its default **text color, border color, link colors, and `.btn*` button colors automatically** (see "What the scheme system handles for you"). Let the scheme recolor the component; don't hardcode one scheme's colors.
- `spacing` — vertical component spacing (`xs|sm|md|lg|xl|2xl|3xl`). Has `apply: true` built in: it adds a `component-spacing-*` class to the root, which sets the `--spacing-component` CSS variable. You **consume** that variable with `my-component`/`py-component` etc. — the prop itself does NOT add `my-component` (see Twig patterns).
- `containment` — horizontal width (`xs|sm|md|lg|full`). `apply: true` to auto-add. (Or use the `container-content` / `container-center` utilities directly — see Twig patterns.)
- `text_align` — `left|center|right` → `text-left|center|right`.
- `heading_size` — `xs|sm|md|lg|xl|2xl|3xl` → `title-*` classes (xl and up also add the
  `title-page` marker). Built into every `heading` prop as its `size` sub-prop —
  **use it whenever the heading prop is the component's main title**, so editors
  control the title grade. Two-part mechanism (neo_base utilities): the `title-*`
  class only sets CSS **variables** (`--title-size`, `--supertitle-size`,
  `--subtitle-size`); the consumer classes `component-title` /
  `component-supertitle` / `component-subtitle` read them and add the heading
  font, weight, and the responsive `title-scale` factor (×0.75 mobile, ×0.875 md,
  ×1 lg). A size class with no consumer class changes nothing. Per-size values are
  site-tunable in [web/themes/front/src/css/_utilities.css](web/themes/front/src/css/_utilities.css)
  (`@theme` → `--title-size-{size}`; this site runs md=4xl, lg=5xl, xl=6xl at
  desktop). The editor default is `md` ("recommended for component title") and the
  editor **stores it explicitly on save** — when a component is designed around a
  different grade (a hero's page title → `xl`), set `size:` in the heading's
  `examples` so previews and new placements start right.
- `button_style` — solid/outline/text variants in base/primary/secondary/accent (`btn`, `btn-outline-primary`, `btn-text-accent`, …).
- `button_size` — `xs|sm|md|lg|xl|2xl|3xl` → `btn-*`.

> `component-bg` is **not** a prop — it's a marker class you add (with `bg-default`) to a background-section root so adjacent same-scheme sections collapse their doubled spacing. See the "Root element & structure" Twig patterns below.

### Structural shapes
- `region` — a nested drop zone where editors can place more components (used for tabs, accordions, containers with children).
- `array` — a repeater. Pair with `items:` to define the per-row schema, and provide `examples:` with sample rows (use `TRUE` as a placeholder entry if the items have no required text fields).

> **Reach for a semantic composite shape before hand-rolling an `array` of objects.** Several shapes already model common repeating structures — `menu` (nav links), `breadcrumb`, `address`, `file`, `remote_video`, `media` — and single composites like `link`/`url` and `heading`. They're one line instead of a nested `array → object → …`, get a purpose-built editor UI, and carry the right sub-fields (e.g. a `menu` item's `url` is the full `url` shape). Only hand-roll an `array` when no existing shape fits. Run `drush neo:alchemist:shapes` to scan them first.

### Inline custom `style` shapes
Define a per-component style selector inline:

```yaml
border_top:
  type: style
  title: 'Border Top'
  apply: false        # don't auto-inject; reference via .getValue() in twig
  examples: none
  styles:
    none:
      label: None
      value: border-t-0
    top:
      label: Top
      value: border-t
```

`apply: true` auto-adds the `value` as a class on the element. `apply: false` lets you read it in Twig with `name.getValue()` and branch logic yourself.

### `maxItems`
Set on an `array` prop to cap editor input (e.g. `maxItems: 1` for a single optional CTA).

## Slots

Slots are named regions in the Twig template that editors fill with other components (block-level composition, not prop data). Declare in yml:

```yaml
slots:
  content:
    title: Content
```

And render in Twig with `{% block content %}{% endblock %}`. See [web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/example_container/](web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/example_container/) for a working example.

> **Slot vs region prop:** Use `slots` for top-level composable content areas. Use a `region` prop inside an `array` when you have multiple repeating drop zones (e.g. each tab or accordion panel gets its own region).

## The `.twig` file

### Root element & structure

Always put `{{ attributes.addClass(classes) }}` on a **single root element** — Alchemist injects the classes from `apply: true` style props (scheme, spacing, …) there. Pick one of two layout patterns depending on whether the component paints a background.

**Plain component (no background)** — spacing as margin so it collapses with neighbors:

```twig
<div {{ attributes.addClass(['container-content', 'my-component']) }}>
  ...
</div>
```

**Background / full-bleed section** — background spans the viewport, content is constrained, spacing as padding so the background fills it:

```twig
{% set classes = ['bg-default', 'component-bg'] %}   {# scheme-aware bg + collapse marker #}
<div {{ attributes.addClass(classes) }}>              {# full-width background #}
  <div class="container-content py-component">         {# centered content + vertical spacing #}
    ...
  </div>
</div>
```

Rules of thumb:
- **`container-content`** = centered, responsive max-width, **with** side gutters (the standard content wrapper). **`container-center`** = same but **no** gutters. Both are provided globally by the neo base theme.
- **`my-component`** (margin — collapses between stacked components) vs **`py-component`** (padding — for background sections, since margin sits outside the background). Both read `--spacing-component` set by the `spacing` prop; size variants exist (`p-component-sm`, `m-component-lg`, …).
- **`component-bg`** marker: add it (alongside `bg-default`) to a background-section root so two adjacent same-scheme sections collapse their doubled spacing into a single, continuous-background gap.
- **Colors:** apply `bg-default` (scheme-reactive) where you want a surface fill — text and borders inside a scheme then adapt **automatically with no class** (see next section). Use the `base|primary|secondary|accent` palettes (shades `-0…-950`, with `-content` foreground pairings, e.g. `bg-primary text-primary-content`) for emphasis. Full details in [web/modules/contrib/neo_alchemist/STYLING.md](web/modules/contrib/neo_alchemist/STYLING.md).
- **Prefer `base`; gray is a fallback.** Use `base` / `bg-default` for neutrals in components you author — that's the house style — and convert `gray-*` to `base-*` when adapting pasted markup. As a safety net, Tailwind's neutral scales (`gray`, `slate`, `zinc`, `neutral`, `stone`) auto-fall back to `base` when those pallets aren't enabled, so copied markup using `bg-gray-100`, `text-slate-700`, etc. still renders correctly and stays scheme-reactive. (Non-neutral colors like `blue`/`red` are **not** aliased.)

### What the scheme system handles for you

Inside any `scheme-*` region (including the un-schemed page root), these adapt with **no classes at all** — every scheme picks contrast-checked values, including dark and colorized schemes. Writing extra color classes for these is at best redundant and at worst fights the scheme:

| Concern | What to write | What NOT to write |
|---|---|---|
| Body text | nothing — inherits the scheme's readable foreground | `text-default` (only needed to *re-assert* after an override), `text-base-900` |
| Borders | just a border width (`border`, `border-t-4`) | `border-default`, per-scheme border colors |
| Text links | a bare `<a>` — gets scheme-aware link + hover colors | `text-primary-600 hover:text-primary-800` |
| Buttons | the `.btn*` classes (`btn`, `btn-primary`, `btn-outline-accent`, `btn-text-secondary`, …) | hand-built buttons from `bg-*`/`text-*` utilities — they won't retune per scheme and lose the managed hover states |
| Prose links | `prose` — links inside it follow the scheme link color | per-link color classes |

**Button classes (compose directly in Twig).** A `.btn*` is built from two independent axes: a **style/color** class — `btn` (solid), `btn-outline-{primary|secondary|accent|base}`, `btn-text-{…}` — plus an optional **size** class: `btn-xs`, `btn-sm`, `btn` (default, `md`), `btn-lg`, `btn-xl`, `btn-2xl`, `btn-3xl`. E.g. `class="btn btn-outline-primary btn-lg"`. Prefer a size class over hand-tuning `px-*`/`py-*`/`text-[…]` on a button — it keeps it on the managed, scheme-aware scale (contrast-checked colors + hover states per scheme). The `button_style` / `button_size` style props emit these for `link` props; use the raw classes when you hand-write the `<a>`/`<button>`.

Semantic CSS variables, for component-local CSS or inline styles (all scheme-scoped):
`--text-color-default`, `--background-color-default`, `--color-border-default`, `--link-color` / `--link-color-hover`, `--color-{base|primary|secondary|accent}-{0…950}` (+ `-content`), and `--color-shadow-{0…950}` — a brand-tinted shadow ramp **guaranteed darker than the surface** in every scheme (use it for `box-shadow` colors that won't glow on dark/colorized schemes, e.g. `box-shadow: 0 8px 20px -6px rgb(var(--color-shadow-500) / 0.45)`).

### Finding this site's real colors

Default to the tokens above — they recolor per scheme, so you rarely need a literal hex. But when a decision genuinely needs the resolved value (a gradient stop, an overlay/tint opacity, matching bundled artwork, an SVG fill), don't dig through config or compiled CSS. Three Neo Color commands report it, all `--format=json`-friendly:

- **`drush neo:color:pallets`** — the enabled pallets with their brand anchor hex (the raw 500), content pairing, and which scheme role slots use each. Answers "what color *is* `primary` on this site" in one line.
- **`drush neo:color:schemes`** — every enabled scheme with its role→pallet mapping (base/primary/secondary/accent) plus resolved surface + text hex. Schemes remap the role slots (e.g. the `accent` scheme swaps primary↔accent), so this table is how you pick the scheme that actually gives the look you want rather than one that merely exists.
- **`drush neo:color:scheme <id>`** — one scheme resolved in full: surface/text/border, link + hover, the contrast-picked button fill/content per role, the bare role tokens, and each role's auto-contrast flag — all normalized to hex. Add `--vars` for the complete raw CSS-variable set (every ramp step, under a `vars` object) when you need a specific shade like `--color-primary-300`.

Remember the resolved value is scheme-specific: if you hardcode a hex from one scheme into a component meant to be recolored, it breaks under the others. Read a value to *inform* a token-based or `--vars`-driven choice, not to replace the token.

### Rendering props

| Shape | Twig pattern |
|---|---|
| `heading` | Canonical: `<div{{ heading.size }}>` wrapper with `component-title`/`component-supertitle`/`component-subtitle` children, then access `.supertitle`, `.title`, `.subtitle`, `.anchor`. Hand-rolled main title: put size + consumer on the h-tag itself — `<h2{{ heading.size.addClass(['component-title', 'text-balance']) }}>{{ heading.title }}</h2>` (see [list_s2](web/themes/front/components/list_s2/list_s2.twig)) |
| `markup` | `{{ description }}` wrapped in `<div class="prose max-w-none">` |
| `image` | `{{ neo_image_style(img.src, {focal: {width: 1200, height: 575}}, img.alt) }}` or `neo_image()` for responsive |
| `image` (**SVG**, e.g. a logo) | Image styles can't rasterize an SVG, so the original file is emitted and the size op only sets HTML `width`/`height` attributes — which the theme's base reset (`img{height:auto}` in `@layer base`) then overrides. A viewBox-only SVG has no intrinsic size, so it collapses to 0×0. **Size it with a CSS class** (utilities win over the base layer): `{{ neo_image_style(logo.src, {scale: {height: 30}}, logo.alt, '', {class: ['h-7', 'w-auto']}) }}` |
| `icon` | `{{ icon(name) }}` — add modifiers: `|icon_class('text-3xl')`, `|icon_only`, `|icon_library('regular')` |
| `link` | `<a{{ item.button_style }} href="{{ neo_uri(item.link.uri, item.link.options) }}">{{ item.link.title }}</a>` |
| `url` | Same as link — check `item.link.access` for permission-gated links |
| `remote_video` | `{{ neo_oembed(video.src) }}` inline, or `{{ neo_modal(thumb, {video: src}, 'media') }}` |
| `region` | `{{ accordion.region }}` — auto-renders nested components |
| `style` (apply:false) | `{{ border_top.getValue() }}` or `.addClass()` |
| `array` | `{% for item in items %} ... {% endfor %}` |

### Preview-mode hooks

When the component has interactive state (tabs, accordions), expose event hooks to the Alchemist editor preview. These are no-ops at runtime:

```twig
<button
  {% if neoIsPreview %}
    data-event='{"group": "tabs"}'   {# grouped: only one visible at a time #}
    data-event='{"action": "toggle"}' {# toggle: independent show/hide #}
    data-event                        {# basic: just allow clicks in preview #}
  {% endif %}
>
```

### Fixed / floating roots and the preview iframe

A component whose root is `position: fixed` (or `absolute`) has **no flow height**, so the Alchemist preview iframe — which sizes to document height — collapses and the component looks blank even though it renders. Render it **in-flow for preview**: switch the positioning behind `{% if neoIsPreview %}`, and give it a solid background if it's normally transparent (e.g. a header that overlays a hero). `drush neo:alchemist:render` renders the preview branch by default; add `--live` to render the runtime (`neoIsPreview` false) path.

```twig
{% set classes = ['transition-all'] %}
{% if neoIsPreview %}
  {% set classes = classes|merge(['relative', 'bg-default']) %}   {# in-flow + visible in the iframe #}
{% else %}
  {% set classes = classes|merge(['fixed', 'top-displace-t', 'inset-x-0', 'z-50']) %}
{% endif %}
<header {{ attributes.addClass(classes) }}> … </header>
```

**Pin to `top-displace-t`, not `top-0`.** A `fixed`/`sticky` root pinned to `top-0` renders *behind* the Drupal admin toolbar for logged-in users. `top-displace-t` sets `top: var(--spacing-displace-t)`, which resolves to the toolbar height (`--drupal-displace-offset-top`, `0px` when there's no toolbar) — so the element sits just below the toolbar for admins and flush with the top for anonymous visitors. This mirrors the theme's own `.region--header` ([web/themes/front/templates/region/region--header.html.twig](web/themes/front/templates/region/region--header.html.twig)). Related utilities from the same displace tokens: `mt-displace-t` (margin), `h-displace`/`min-h-displace`/`max-h-displace` (viewport height minus toolbars).

### Alpine.js

Add `- neo/library.alpine` to `libraryOverrides.dependencies` in the yml. For the Collapse plugin, also `{{ attach_library('neo/library.alpine.collapse') }}` at the top of the twig. Use `x-data`, `x-show`, `x-collapse`, `x-cloak` as normal.

### Swiper (carousels)

Image carousels use the built-in `swiper()` Twig function — see [web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/image/image.twig](web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/image/image.twig) for the canonical pattern (`swiper.getWrapperAttributes()`, `getSlideAttributes()`, `getNavigationPrevAttributes()`, etc.).

### Animating a component (neo_animate)

Scroll-reveal animations come from the `neo_animate` module — see the **neo-animate** skill for the full system. The short version: add the props

```yaml
animate: { type: animate }
animate_speed: { type: animate_speed }
animate_delay: { type: animate_delay }
animate_stagger: { type: animate_stagger }
```

and the component root reveals on scroll (editor-selectable, `apply: true`, no twig change). For a staggered cascade, also put `neo-animate-item` on the repeating element in the twig. No `libraryOverrides` needed — the driver is attached globally. Never write `neo-animate--animated` or raw catalog classes (`neo-animate--fadeInUp`) statically; authors write markers only.

> **Background components (`bg-default component-bg`): don't reveal the root.** `apply: true` puts the reveal on the root, so the whole **colored block** animates in. Instead override `apply: false` on `animate`/`animate_speed`/`animate_delay` in the yml and apply them to an inner content wrapper — and if the component staggers, move `animate_stagger` there too (the enter class and `neo-animate-stagger` must share one element, or the cascade silently no-ops). The reveal props are Attribute objects, so **merge** them onto the wrapper (`getValue()` returns the raw key, not the classes): `<div{{ animate.merge(animate_speed).merge(animate_delay).merge(animate_stagger).addClass(['container-content','py-component']) }}>`. Full details + the non-stagger case in the **neo-animate** skill's *Background components* section.

## Workflow for a new component

1. **Pick a machine name** — snake_case, typically `<purpose>_s<n>` (e.g. `testimonial_s1`). Confirm it's not already taken with `drush neo:alchemist:components` (lists every Neo component with its provider, prop, and slot counts).
2. **Find the closest existing component** and read its yml + twig. Copy that pattern — don't invent from scratch.
3. **Create the folder** at `web/themes/front/components/<name>/`.
4. **Write `<name>.component.yml`** — always include `$schema`, `name`, `status: stable`, `neo: true`, and a `spacing` prop. Use existing shapes (`heading`, `markup`, `image`, etc.) rather than raw JSON Schema types.
5. **Provide `examples:`** for every prop — these populate the Alchemist editor's default values and the preview. Arrays with `region` or booleans can use `- TRUE` as placeholder rows.
6. **Write `<name>.twig`** — root div with `{{ attributes.addClass(classes) }}`, wrap optional sections in `{% if ... %}`, use `neo_uri()` for all URLs, `icon()` for icons, `neo_image_style()` for images.
7. **Test interactive elements** with `{% if neoIsPreview %}data-event...{% endif %}` so the editor preview remains clickable.
8. **Clear the cache** (`drush cr`) after adding a new component — SDC registration is cached.
9. **Verify from the CLI before finishing** — run `drush neo:alchemist:validate <provider>:<name>` then `drush neo:alchemist:render <provider>:<name>`. Don't hand off a component you haven't rendered. See "Verify from the CLI" below.

## Preview & iterate

Each Alchemist SDC has a live preview workspace:

```
/admin/config/neo/alchemist/preview/{provider}:{machine_name}
```

e.g. `/admin/config/neo/alchemist/preview/front:accordion_test`. There you can:
- Edit every editable prop/style (scheme, spacing, alignment, text, …) and see the
  preview refresh instantly — great for sanity-checking `examples` and prop wiring.
- Use the **Above** / **Below** selectors to render neighbor components around the one
  you're previewing — the right way to test spacing between stacked components and the
  `component-bg` same-color collapse.
- View at desktop/tablet/mobile widths.

With the neo build watcher running, edits to the `.twig`/`.css`/`.yml` reload the
preview automatically. For how the asset build works — running the watcher, when a
new Tailwind class needs a rebuild, front vs back scopes, dev-server vs `dist/` — see
the **neo-build** skill.

**Always sanity-check a component under more than one scheme** (the Color Scheme
style prop in the preview): at minimum the default, one dark, and one colorized
scheme. Get the list of enabled schemes with `drush neo:color:schemes` (id,
label, dark/colorized, role→pallet mapping, and resolved surface/text), then render
under one with `drush neo:alchemist:render <id> --scheme=<scheme>`. To see exactly what
a scheme resolves colors to, `drush neo:color:scheme <id>` (see "Finding this site's real
colors"). `/admin/config/neo/scheme-preview`
shows every enabled scheme's surfaces, button matrix, link colors, and palette
ramps — the reference for what your component's colors will resolve to per scheme.

## Verify from the CLI

You don't need a browser to confirm a component works. Two commands close the loop:

- **`drush neo:alchemist:validate <provider>:<name>`** — static lint. Flags missing
  `neo: true`, props with no `examples`, unknown prop types, `{% if/for %}` references
  to props that aren't declared, and dynamically-assembled Tailwind classes that won't
  compile. Exits non-zero on hard errors.
- **`drush neo:alchemist:render <provider>:<name>`** — renders the component headlessly
  from its `examples` and reports PASS/FAIL, surfacing Twig/render errors as a message
  instead of a white screen. Add `--html` to print the markup, `--scheme=<id>` to render
  under a scheme, and `--live` to render the runtime path (`neoIsPreview` false) instead
  of the editor preview.

Supporting introspection: `drush neo:alchemist:components` (list all), `drush neo:alchemist:info <id>`
(one component's resolved props/slots/libraries), `drush neo:alchemist:shapes [name]`,
`drush neo:icon:list <search>` (icon names, from Neo Icon), and the Neo Color trio
`drush neo:color:pallets` / `neo:color:schemes` / `neo:color:scheme <id>` (this site's
resolved colors — see "Finding this site's real colors"). All tabular commands accept
`--format=json` for machine parsing.

## Common pitfalls

> Most of the pitfalls below are now caught automatically — run `drush neo:alchemist:validate <id>`
> and `drush neo:alchemist:render <id>` and they'll flag missing `neo: true`, missing
> `examples`, unknown prop types, and dynamic Tailwind classes before you ship.


- **Forgetting `neo: true`** — component won't appear in Alchemist's picker.
- **Raw `{{ url }}` instead of `{{ neo_uri(link.uri, link.options) }}`** — breaks internal `internal:/` URIs.
- **Missing `examples`** — editor shows empty previews and broken defaults.
- **Not wrapping in `{% if prop %}`** — component renders empty scaffolding when editor leaves fields blank.
- **Using `heading.title` for the `<h2>`** but dropping `heading.size` — the editor's Size selector silently no-ops. And `heading.size` alone isn't enough: `title-*` only sets variables, so the same element (or a child) needs `component-title` to consume them. `<h2{{ heading.size.addClass(['component-title']) }}>` is the minimal correct form for a hand-rolled main title.
- **New style prop with `apply: true` but missing `examples`** — class won't be present on first render.
- **Using `my-component` on a background section** — margin sits *outside* the background, leaving an unfilled gap. Background sections use `py-component` on the inner `container-content` wrapper, with `bg-default` + `component-bg` on the root.
- **Background section without the `component-bg` marker** — two adjacent same-color sections stack double padding. Add `component-bg` (next to `bg-default`) so the seam collapses.
- **Dynamic Tailwind class names never compile.** The build only emits classes that appear **literally** in scanned source — `bg-{{ color }}-500`, `'text-' ~ tone`, or classes assembled in JS produce nothing in the CSS. Enumerate full class names (in the yml `styles:` values, a Twig mapping, or a comment), or use inline CSS variables for genuinely data-driven color: `style="background-color: rgb(var(--color-{{ pallet }}-500))"` works because the *variables* always exist.
- **Hardcoding one scheme's colors** (e.g. `bg-base-0`) on a component meant to be recolored — use `bg-default` for the surface and let text/borders adapt automatically so the `scheme` prop can recolor it.
- **Coloring links or buttons by hand** — `text-primary-600` on an `<a>`, or a "button" built from `bg-primary text-white` utilities, will be unreadable on some schemes (the bare brand tokens can match the surface on colorized schemes). Bare `<a>` elements and the `.btn*` classes are contrast-managed per scheme, hover states included.
- **Overloading the component `.css`.** Layout, spacing, color, sizing, and hover states all have Tailwind utilities — put them in the `.twig` (use arbitrary values like `text-[0.62rem]`/`basis-[calc((100%_-_5rem)/5)]` for off-scale numbers, and `group`/`group-hover:` for per-element hover). A `.css` full of `display:flex` / `padding` / `color` / `:hover` is a smell; the file is only for what has no utility (keyframes, gradient overlays, exact shadows, scrollbar-hide, `::after` content, styling a generated `<img>`).
- **Placeholder image dimensions out of sync with the twig transform** — the `placehold.co/WxH.png` URL (and `width`/`height` fields) in the prop's `examples:` should match the dimensions produced by `neo_image_style()` / `neo_image()` in the twig. The right target depends on the size op (see [web/modules/contrib/neo_image/README.md](web/modules/contrib/neo_image/README.md)):
  - Fixed-output ops — `scaleCrop`, `crop`, `focal`, `exact`, and `auto` with both width+height: placeholder must be exactly `{width}x{height}`. E.g. `{scaleCrop: {width: 300, height: 200}}` → `placehold.co/300x200.png`, `width: 300, height: 200`.
  - Width-only ops — `scale`, `focalWidth`, and `auto` with only width (or only height): output keeps the source aspect, so pick a placeholder that matches the *intended display aspect* (e.g. a `scale: {width: 1200}` slot shown in a 4:3 container → `placehold.co/1200x900.png`).
  - Responsive `neo_image()` with multiple breakpoints: use the largest breakpoint's dimensions for the placeholder.
  - Items rendered via a shared include (e.g. `@front/includes/list_s1--items.html.twig` uses `scaleCrop: 75x75`): match the include's dimensions, not the wrapper component.
- **SVG (e.g. a logo) rendered via `neo_image_style` collapses to 0×0 / a tiny square** — image styles are raster ops (GD), so an SVG can't be transformed: the original file is emitted and the size op only sets HTML `width`/`height` attributes. The theme's base reset (`img{height:auto}` in `@layer base`) overrides those attributes, and a viewBox-only SVG has no intrinsic size, so it renders at 0×0 (or a fabricated square if a single-axis op is used). Fix by sizing with a **CSS class** via the 5th `attributes` arg — utilities beat the base layer: `{{ neo_image_style(logo.src, {scale: {height: 30}}, logo.alt, '', {class: ['h-7', 'w-auto']}) }}`. (`w-auto` lets the browser derive width from the SVG's `viewBox` aspect ratio.)
- **Fixed/floating component blank in the Alchemist preview** — a `position: fixed`/`absolute` root has no flow height, so the preview iframe collapses. Render it in-flow (`relative`) behind `{% if neoIsPreview %}`, with a solid background if it's normally transparent. See "Fixed / floating roots and the preview iframe".
- **Fixed/sticky component hidden behind the admin toolbar** — pinning a `fixed`/`sticky` root to `top-0` puts it under the Drupal toolbar for logged-in users. Use `top-displace-t` instead (offsets by the toolbar height, `0px` when absent). See "Fixed / floating roots and the preview iframe".
- **Clearing cache** — after editing `.component.yml`, run `drush cr` or the prop changes won't reflect.
