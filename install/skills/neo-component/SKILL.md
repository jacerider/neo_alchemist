---
name: neo-component
description: Create and modify Drupal Single Directory Components (SDC) for the neo_alchemist module, and wire their saved neo_component config — value providers (bind props to entity fields, menus, views, taxonomy, site settings), slot plugins, per-placement filters, and access rules. Use when the user asks to build, add, edit, or scaffold a page-building component in web/themes/front/components, to bind/feed a component's props from data, or when referencing terms like "Neo component", "Alchemist component", "SDC", or file patterns like *.component.yml and *.twig under the theme's components directory.
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

Alchemist extends SDC with custom "shapes" — reusable prop definitions from [neo_alchemist.neo_component_prop_defs.yml](web/modules/contrib/neo_alchemist/neo_alchemist.neo_component_prop_defs.yml), plus other modules' `*.neo_component_prop_defs.yml` (`icon` from neo_icon, the four `animate*` types from neo_animate). Use these by name rather than raw JSON Schema; the raw JSON types (`string`, `boolean`, `integer`, `number`, `array`, `object`) also work directly. An unknown `type:` doesn't error — it's logged and silently downgraded to `string`, so a typo shows up as a text field in the editor, not a failure.

> **Get shapes from the CLI (authoritative):** `drush neo:alchemist:shapes` lists every available shape; `drush neo:alchemist:shapes <name>` (e.g. `heading`) dumps that shape's resolved schema, a paste-ready `.component.yml` prop snippet, and its Twig render pattern. Prefer this over guessing from the summary below.

### Content shapes
- `heading` — object with `supertitle`, `title`, `subtitle`, `size`, `anchor`. **Every one of the three text parts must render — see "Rendering a `heading`: all three parts, always" below; it is the single most-missed rule in this skill.** Guard the block with plain `{% if heading %}`: the shape reports itself empty when all three text parts are (its `size`/`anchor` children are presentational and don't count), so the object is falsy exactly when there is nothing to render. Provide `examples` with the text fields you use (title-only examples are fine — the *markup* must still handle all three). When the heading is the component's **main title**, render `size` (see the `heading_size` style shape below and the render table) so editors control the title grade. `title` is **optional** — a heading may be `supertitle`/`subtitle`-only (handy when reusing this shape for a two-tone item caption/label whose emphasized word is the only text, e.g. a single accent word). Free wiring: a filled heading also sets the component root's `id` (from `anchor`, else a machine-cased title), adds `scroll-mt-neo`, and stamps `data-component-title` — any component with a heading is anchor-linkable without extra markup.
- `markup` — rich text / array. Use for prose descriptions. Ships with the `formatted_text` value plugin attached (text-format-aware editing) by default.
- `string` — plain text. Add `enum: [a, b, c]` for a fixed-choice select, or `format: textarea` for multi-line plain text.
- `boolean`, `integer`, `number` — real typed scalars; Twig receives a bool / int / float, not a string. A `boolean` can never be "empty" (use it for on/off toggles you `{% if %}` on); `integer`/`number` support `enum:`. Note the editor widget truncates decimals typed into an `integer` prop — use `number` for anything fractional.
- `image` — object `{src, alt, width, height}`. Render with `neo_image_style()` or `neo_image()`. The `src` accepts a URL/path **or** one of two local-asset schemes, resolved to a real URL by the image shape: **`component://<path>`** points at a file bundled *inside the component folder* (e.g. store `web/themes/front/components/callout_s1/images/monogram.png` and set `src: 'component://images/monogram.png'`), and **`theme://<path>`** points at the default theme's directory. Prefer `component://` for a component's own default/decorative art (a monogram, emblem, texture) — it ships and versions with the component and needs no editor upload or external `placehold.co` URL. Real content images still come from the editor (a `media`/uploaded image).
- `image-uri` — just an image URL string (it's also the `src` sub-type inside `image`). Pattern-locked to image extensions reached via `/`, `theme://`, `component://` or `http(s)://`.
- `file` — object `{src, title, name}` for downloadable files. `file-uri` is the bare-URL variant (document extensions: pdf/doc/xls/ppt/odt…).
- `remote_video` — YouTube/Vimeo oEmbed `{src, thumbnail, thumbnail_width, thumbnail_height, title}`. Render inline with `neo_oembed(src)` or as a lightbox (see render table).
- `video` — **local** video file `{src, poster, mime, width, height}` (Drupal's video media type; `remote_video` is the oEmbed one). `poster` comes from the media thumbnail and is only set when a real (non-generic) thumbnail exists — guard it. Typical render: `<video autoplay muted loop playsinline>` for background video. `examples: { src: 'theme://videos/placeholder.mp4' }`. `video-uri` is the bare-URL variant (mp4/webm/ogg/mov).
- `icon` — icon machine name (rendered via `icon(name)` Twig function). Find valid names with `drush neo:icon:list <search>` (e.g. `drush neo:icon:list arrow`) — don't guess, invalid names render nothing. **Strip the library prefix that `neo:icon:list` prints.** The command lists names like `regular-chevron-left`, but `icon()` wants the bare name: `icon('chevron-left')` renders — `icon('regular-chevron-left')` renders **nothing** (silently, no error). So from a listed name drop the leading `regular-`/`solid-`/`light-`/etc. segment. To force a specific library, use the `|icon_library('name')` filter, not a name prefix.
- `link` — button-style link `{uri, title, options, icon, target, access}`. Usually paired with a `button_style`.
- `url` — similar to link but for anchor-style links.
- `email`, `telephone`, `uri` — single-value string types (`uri` accepts `internal:/` URIs; render through `neo_uri()`). `telephone` and `icon` ship no usable default (their prop-defs carry a singular `example:` key SDC ignores) — always provide your own `examples:`.
- `address` — postal address object (needs the contrib `address` module; fields `given_name`/`family_name`/`organization`/`address_line1-3`/`locality`/`administrative_area`/`postal_code`/`country_code`, all strings).
- `menu` — editable list of nav items `{title, description, icon, url}` (each item's `url` is a full `url` shape, so it keeps `target`/`access`; use `item.title` for the label). Prefer this for navigation over a hand-rolled `array` of links. When fed by the `menu` value provider, items also carry runtime keys the schema doesn't list: `in_active_trail`/`is_expanded`/`is_collapsed`, nested `below` children, and — with `neo_alchemist_menu` enabled — `region: true` + `content` (a render array) on **component region** items: render with `{% if item.region %}{{ item.content }}{% endif %}` instead of a link. Mega menu reference: [web/themes/front/components/header_s1/header_s1.twig](web/themes/front/components/header_s1/header_s1.twig) and the **neo-alchemist-menu** skill.
- `breadcrumb` — array of `{title, url}`; the real breadcrumb value plugin is attached automatically. The last item conventionally has no `url` — render it as `<span aria-current="page">`.
- `slug` — anchor/slug string, uniquified per page (`-2`, `-3` appended on collision), so two placements of the same component can't emit duplicate ids.
- `media` — Drupal media entity reference. Scope the allowed bundles with `media_types: [image]` on the prop (default `image`). Twig receives `{entity_id, src, title, render}` — `{{ media.render }}` renders the media's own view display, which is the point of this shape over `image`: the site's media display config does the rendering. A media prop **cannot carry `examples`** — previews borrow the most recent published media of an allowed type, so an empty media library means an empty preview.

### Style shapes (applied as CSS classes via attributes)

> **Authoritative styling guide:** [web/modules/contrib/neo_alchemist/STYLING.md](web/modules/contrib/neo_alchemist/STYLING.md) covers schemes, colors, spacing, and containers in full. The essentials are summarized here and in the Twig patterns below.

- `scheme` — color-scheme selector. With `apply: true` it adds a `scheme-*` class to the root, which **re-scopes every color utility** (`bg-default`, `bg-primary`, …) to the chosen scheme — and a scheme region adapts its default **text color, border color, link colors, and `.btn*` button colors automatically** (see "What the scheme system handles for you"). Let the scheme recolor the component; don't hardcode one scheme's colors.
- `spacing` — vertical component spacing **size** (`xs|sm|md|lg|xl|2xl|3xl`). Has `apply: true` built in: it adds a `component-spacing-*` class to the root, which sets the `--spacing-component` CSS variable that every `*-component` utility reads.
- `gap` — vertical spacing **application** (`auto|keep|flush_top|flush_bottom|flush_both`). Has `apply: true` built in: it adds `neo-section neo-section-y` to the root — the component's actual top/bottom padding — plus the editor's merge behavior (`component-bg-flush-none` for `keep`, `component-flush-t/b` for the flush options). Declare it in every stacking component alongside `spacing`; **never hand-write a section carrier in twig** (see Twig patterns for the `apply: false` deep-carrier escape).
- `containment` — horizontal width (`xs|sm|md|lg|full`). `apply: true` to auto-add. (Or use the `container-content` / `container-center` utilities directly — see Twig patterns.)
- `text_align` — `left|center|right` → `text-left|center|right`.
- `text_size` — `sm|md|lg|xl|2xl` → `prose prose-sm…prose-2xl`. An editor knob for prose scale; put it on the element that would otherwise hardcode `prose prose-lg`.
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
- `button_size` — `xs|sm|md|lg|xl|2xl|3xl` → `btn-*` (`md` maps to an empty value — it's the built-in default size).
- `image_size` — an editor-selectable image transform. Unlike every other style shape its `styles:` values are **neo dynamic-style arrays**, not class strings, and the prop hands Twig a ready transform you pass straight to the image function — the editor picks the crop, the yml stays the single source of dimensions:

  ```yaml
  image_size:
    type: image_size
    styles:
      default: { label: Default,     value: { op: focal, width: 1200, height: 575 } }
      scale:   { label: Scale width, value: { width: 1200 } }   # op inferred: scale
  ```

  ```twig
  {{ neo_image_style(image.src, image_size, image.alt) }}
  ```

  Pairs with the **Media Image Size** value plugin when the size choice should live on the saved component instead. Working example: [example_image](web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/example_image/example_image.component.yml).

  Derive these numbers from the column's widest rendered width rather than copying the `1200` above — see the sizing pitfall under "Common pitfalls". Crops sharing one column keep the **same width** and vary only the height.

  > **`examples:` on an `image_size` prop must be the keyed array `{value: <key>}`, not a bare key.** Unlike `style` props (backed by `list_string`), this shape's field type is `map`, and `MapItem::setValue()` runs any non-array through `unserialize()` — so `examples: wide` throws `unserialize(): Error at offset 0 of 4 bytes` on the component's manage screen. Omitting `examples:` entirely also works: the shape falls back to the first key in `styles:`.

> `component-bg` is **not** a prop — it's a marker class you add (with `bg-default`) to a background-section root so adjacent sections rendering the **same color** collapse their doubled spacing. See the "Root element & structure" Twig patterns below.

### Structural shapes
- `region` — a nested drop zone where editors can place more components (used for tabs, accordions, containers with children). In the yml you can constrain what fits with `sizes: [xs, sm, md]`. On the saved component's prop config (not in the yml), site builders can enable two value plugins per region: **Region Size** (restrict which component sizes may be dropped in — config wins over the yml `sizes:` where set, but the UI only lists **top-level** props, so a region nested inside an `array` item can only be constrained via yml) and **Entity Customizable** (`region_custom`) — the latter turns a locked tree field (`allow_custom` off) into **hybrid mode**: content creators manage just this region's components per entity while the field default layout keeps control of everything around it (the header/body/footer pattern). Regions cannot nest inside regions. Hybrid internals → the **neo-alchemist-dev** skill.
- `array` — a repeater. Pair with `items:` to define the per-row schema (`items.title` labels the rows in the editor), and provide `examples:` with sample rows (use `TRUE` as a placeholder entry if the items have no required text fields). Twig receives a clean re-indexed list, already sorted by the editor's drag order. A required child that resolves empty drops the **whole item** (so the component still validates) — make a child `required` only when a row is meaningless without it.
- `object` — named sub-props via `properties:` (each any shape, resolved recursively), optional `required:`. Twig receives an assoc array; children that resolve empty are **unset**, so guard with `{% if obj.child %}`. In the saved component an object prop can be *expanded* so each child binds to its own entity field.

> **Reach for a semantic composite shape before hand-rolling an `array` of objects.** Several shapes already model common repeating structures — `menu` (nav links), `breadcrumb`, `address`, `file`, `remote_video`, `media` — and single composites like `link`/`url` and `heading`. They're one line instead of a nested `array → object → …`, get a purpose-built editor UI, and carry the right sub-fields (e.g. a `menu` item's `url` is the full `url` shape). Only hand-roll an `array` when no existing shape fits. Run `drush neo:alchemist:shapes` to scan them first.

### `views_filter` — a designed exposed filter

When a component's `items` are bound to a view, a `views_filter` prop hands you **one exposed
filter as pure data** — so you design the filter UI (Alpine dropdown, checkbox panel, pills)
instead of styling Drupal's form markup. The enabling fact: an exposed filter is just a GET
parameter, so a link with the right URL — or a hand-written `<form method="get">` of native
checkboxes — is a fully valid submission surface. Site builders bind it with the
**Views | Exposed Filter** value plugin (context + filter identifier).

```yaml
type_filter:            # declare AFTER the views-bound prop — props resolve in yml order,
  type: views_filter    # and this provider reads the context the items binding registers
  title: 'Type filter'
  examples: { label: 'Type', param: 'type', options: [ { label: 'News', value: '1', url: '#', active: false, below: [] } ], … }
```

The value is a **`ViewsFilterTwig` helper object** — plain data access plus wiring methods, the
`SwiperTwig` pattern. Data (via dot access, unchanged): `label`, `param`, `multiple`,
`active`/`active_count`/`active_labels`, `value` (always an array), `placeholder`, `reset_url`,
`action`, `carry[]`, and `options[]` — a `{label, value, url, active, below[]}` **tree**
(`below` nests like the `menu` shape; taxonomy filters get real hierarchy). Every option `url`
applies/toggles that value and resets paging. Text filters (fulltext search) resolve too, with
empty `options` and a `placeholder`.

The methods hand over the wiring a hand-written GET form can silently get wrong — all return
chainable `Attribute`s (sandbox rule: object methods callable from twig must start with
get/has/is, same as `SwiperTwig`):

| Method | Emits |
|---|---|
| `getForm()` | `method="get" action` for the `<form>` tag |
| `getHidden()` | every `carry` pair as hidden inputs — **forgetting these is the silent killer**: submitting one filter clears the others |
| `getCheckbox(option)` / `getRadio(option)` | `type`/`name`(`[]`)/`value`/`checked`, agreeing by construction |
| `getTextfield('search')` | `type`/`name`/`value`/`placeholder` for a text filter's input |
| `getLink(option)` | `href` + `aria-current` when active |

**Interaction style is the template's design decision — pick ONE markup per filter.** Links for
single-select, a GET form of checkboxes for multi-select, a mini-form input for search. Want
submit-on-change instead of an Apply button? Add `x-data @change="$el.requestSubmit()"` to the
form — your call, hardcoded:

```twig
{# Single-select: instant links. #}
<a href="{{ filter.reset_url }}">{{ 'All'|t }}</a>
{% for option in filter.options %}
  <a{{ filter.getLink(option).addClass(option.active ? 'font-bold text-primary') }}>{{ option.label }}</a>
{% endfor %}

{# Multi-select: plain GET form, batch-then-Apply. #}
<form{{ filter.getForm() }}>
  {{ filter.getHidden() }}
  {% for option in filter.options %}
    <label><input{{ filter.getCheckbox(option) }}> {{ option.label }}</label>
    {# nest option.below for hierarchy #}
  {% endfor %}
  <button type="submit">{{ 'Apply'|t }}</button>
</form>

{# Text filter (search): its own mini-form. #}
<form{{ filter.getForm() }}>
  {{ filter.getHidden() }}
  <input{{ filter.getTextfield('search') }}>
  <button type="submit">{{ icon('search') }}</button>
</form>
```

Composability rules:

1. **The filter must stay exposed on the view** — `?param=` only applies to exposed filters.
2. When **every** filter on the page is designed (each mini-form printing `getHidden()`), no
   native exposed form is needed at all — remove the `views_exposed_filters` slot item. Only
   in **mixed mode** (a native exposed form remains, e.g. for a filter you haven't designed)
   must that form's override template *hide* the designed filters' native widgets instead of
   omitting them: `<div class="hidden">{{ form.type }}</div>`, so its submits preserve them.

### `views_active_filters` — applied filters as removable chips

The designed replacement for the active_filters module's views area. Bind with the
**Views | Active Filters** value plugin (context only; covers every exposed filter with
input). Same declare-after-the-views-prop rule. The value (a `ViewsActiveFiltersTwig`):
`active`, `count`, `clear_url`, and `items[]` of `{param, filter_label, value, label,
remove_url}` — labels are resolved option labels (clean term names on hierarchical filters;
the entered text on search). `getLink(item)` / `getClearLink()` add `href` + descriptive
`aria-label` + `rel="nofollow"`:

```twig
{% if active_filters.count %}
  <div class="flex flex-wrap gap-2">
    {% for item in active_filters.items %}
      <a{{ active_filters.getLink(item).addClass('bg-base-200 px-3 py-1 text-sm') }}>
        {{ item.filter_label }}: {{ item.label }} ✕</a>
    {% endfor %}
    <a{{ active_filters.getClearLink() }}>{{ 'Clear all'|t }}</a>
  </div>
{% endif %}
```

### `views_summary` — result counts as data

Bind with the **Views | Result Summary** value plugin (context only). Same
declare-after-the-views-prop rule. The value is plain scalars — no helper object, because
the wording/pluralization is deliberately the template's job: `total`, `count` (rows on
this page), `start`, `end`, `page` (1-based), `pages`, `per_page`, `exact` (bool).
Unbound or in preview the prop is **undefined, not empty** — write
`{{ summary.total ?? items|length }}`. **`exact` is load-bearing**: with a mini/some/none
pager, or a Search API display with "skip result count", `total` is a lower bound and
`exact` is false — render "142+" or hide the number rather than asserting it.

Working example for all of it: `front:list_insight` — `type_filter` links dropdown,
`markets_filter` 3-level checkbox panel, `search_filter` mini-form, `active_filters` chips,
no native exposed form on the page.

**AJAX result swapping (opt-in).** Two attributes and a library line upgrade every filter
interaction to an in-place update with history support — no PHP, no contract change, and
JS-off (or any fetch failure) falls back to the normal navigation:

```yaml
libraryOverrides:
  dependencies:
    - neo_alchemist/swap
```

```twig
<section {{ attributes }} data-neo-uuid="{{ neoUuid }}" data-neo-swap>
  …
  <span data-neo-swap-announce>{{ items|length }} {{ 'results'|t }}</span>
```

The behavior intercepts same-path links (filter options, chips, reset — and the footer
`views_pager`'s links, so paging is AJAX for free) and same-path GET mini-forms inside the
boundary, fetches the destination page, and swaps the component's subtree by its `neoUuid`.
Drupal behaviors re-attach (component JS re-inits) and Alpine picks the new tree up itself;
focus returns to the same-named control (a visitor typing in search keeps their caret);
Back/Forward walk the filter states. `data-neo-swap-announce` marks the element whose text is
announced to screen readers after each swap. Opt any single control out with `data-no-swap`.
Card links point at other paths and are never intercepted.

### Views pages the Alchemist way (node-owns-the-page)

A views **page display** (e.g. a `/search` page) renders raw views markup a site builder
cannot touch. The convention is to hand the page to a node instead:

1. A `system` (or `page`) node takes the path via an **explicit alias**, and the views page
   display is **removed** — never rely on an alias silently shadowing a views route. The
   view keeps its `default` display and becomes a headless query.
2. A component binds to that display (`views` value plugin + `views_filter` /
   `views_active_filters` / `views_summary` props + a `views_pager` slot). Exposed input
   (`?s=`), `?page`, and every filter URL are read from/built against the **current page
   URL** automatically — no extra wiring, wherever the component is placed.
3. Site builders then edit the page in the normal Layout editor.

Automated conversion:

```
drush neo:alchemist:views-page <view_id>:<display_id> [--bundle=system] [--title=…] [--alias=/…] [--component=<neo_component id>]
```

Creates the node (published, pathauto-skipped alias), optionally seeds its tree with a
saved component, removes the page display, rebuilds routes, and warns about: `mini` pagers
(no count query → `views_summary` totals inexact — use `full`), `none`/`search_api_none`
cache plugins (max-age 0 → the bound component is uncacheable — use `tag`/`search_api_tag`),
and quick-search configs whose all-results link pointed at the removed display.

Mixed-datasource indexes (node + taxonomy rows in one view): every mapping source is
type-agnostic — `title → _entity:label`, `link → _entity:link:canonical`, type badges
via **`_entity:bundle_label_page`** (bundle-info label; converts the admin-facing
`system` bundle to "Page") and **`_entity:icon_page`** (the bundle's configured
neo_icon; same System→Page conversion). Bundle-info labels need no indexed fields, no
view fields, and no access grants. Plain `_entity:bundle_label` / `_entity:icon` skip
the conversion. Excerpts: `_view:search_api_excerpt`. `?s`/`?page` are page-global —
two views-bound components on one page share them.

Self-reference note: a search page's own node can appear in its own results. ViewsValue
guards re-entrant execution of the same view/display (a nested resolution returns no
view), and the metatag image token skips component-tree walks while a views value is
executing (`ViewsValue::isExecuting()`) — result caching serializes row entities, which
computes metatags mid-execution.

Worked example: the `search` view + `list_search` component + `/search` system node.
neo_search ships that component as a scaffold (`install/components/list_search`) and
`drush neo:search:setup`, which provisions the whole stack in one idempotent command —
index checks, templated view, a theme-owned copy of the component (bound as
`<theme>:list_search`; the site restyles it freely), page conversion, quick-search
variation, permissions.

### Inline custom `style` shapes
Define a per-component style selector inline:

```yaml
border_top:
  type: style
  title: 'Border Top'
  apply: false        # don't auto-inject onto the root; place it yourself in twig
  examples: none
  styles:
    none:
      label: None
      value: border-t-0
    top:
      label: Top
      value: border-t
```

The prop passed to Twig is an **`Attribute` that already carries the selected option's `value` classes** — so you emit those classes by **rendering the prop as an attribute**, never by re-typing them. `apply: true` auto-merges it onto the **root** `attributes`. `apply: false` skips that auto-merge; you render/merge it onto whatever element you choose — e.g. `<div{{ border_top.addClass(['relative']) }}>` outputs the selected `border-t*` class *plus* `relative` on that div.

**`name.getValue()` returns the selected _key_ (`'top'`/`'none'`), NOT the classes** — use it **only** to branch logic (`{% if border_top.getValue() == 'top' %}…{% endif %}`), never to map the key back to hand-written classes. Re-typing the `value:` strings in the Twig (e.g. `{% set c = x.getValue() == 'short' ? 'h-72' : 'h-96' %}`) duplicates the yml, silently drifts out of sync (the yml `value:` becomes dead metadata that never renders), and is the wrong pattern. Let `styles.*.value` be the single source of truth and let the attribute carry it.

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

### Taking control of what a site builder puts in a slot

Keep the component's own `.twig` generic — `{% block content %}{% endblock %}` and nothing more. Two optional files inside the component directory let you shape the contents without a preprocess function or a theme override:

```
components/my_list/
├── my_list.component.yml
├── my_list.twig                       ← stays generic
└── slots/
    ├── header.twig                                        ← layout of the slot
    └── views-exposed-form--my-list--header--filters.html.twig  ← one item's internals
```

**Start here** — either works, and both name the same files:

- **In the UI:** the slot's **Customize** form has a *Theming this slot* panel — the layout template and every variable in it, then per item its override filename and the variables that file receives. The component's slot table also shows each layout template and whether it exists.
- **On the CLI:** `drush neo:alchemist:slot <component> [<slot>]` prints the same thing.

Neither template announces itself in the markup — a layout template is `{% include %}`d rather than themed, so it never appears in Twig's `FILE NAME SUGGESTIONS`, and an item override only shows up there once it exists. In a development environment each slot and item is wrapped in an HTML comment naming its file, so view-source works too.

**`slots/<slot>.twig` — arrange the items.** Each item is a variable named by its Twig key (the slot plugin id, unless a key is set in the **Twig key** column of the slot's Customize form). Also available: `items` (all of them), `slot` (`name`/`title`), `neoIsPreview`.

```twig
<div class="flex items-end justify-between gap-8">
  {% if filters %}<div>{{ filters }}</div>{% endif %}
  {{ items|without('filters') }}   {# anything this template doesn't know about #}
</div>
```

Print each item **exactly once** — printing twice renders it twice. Keep the `|without` remainder: an item you never print is silently dropped, and its cache metadata with it.

**`slots/<hook>--<component>--<slot>--<key>.html.twig` — control one item's internals.** An ordinary theme suggestion; Alchemist adds the suggestion automatically, so the filename is the whole wiring (note `.html.twig`, and `-` where the hook has `_`). It inherits the base hook's variables and preprocessing, and — crucially — `#theme_wrappers` is still applied *around* your output, so a form keeps its `<form>` tag and `#action`:

```twig
{# views-exposed-form--my-list--header--filters.html.twig — gets {{ form }} #}
<div class="flex items-end gap-3">
  <div class="grow">{{ form.iq }}</div>
  <div>{{ form.actions }}</div>
  {{ form|without('iq', 'actions') }}
</div>
```

> **Only drill into a form from an item template, never from the slot template.** `{{ filters.iq }}` in `slots/header.twig` renders that widget *outside* any `<form>`, because the `<form>` comes from the wrapper around the whole item. In the item template you are the `#theme` implementation, so `|without` is correct there.

Both files need `drush cr` to be picked up (unless `npm start` is running). `drush neo:alchemist:validate` warns about a `slots/*.twig` matching no declared slot — otherwise it would fail silently.

**Debugging:** `{{ neo_inspect() }}` lists every variable in scope in the current template; `{{ neo_inspect(form) }}` walks one value's addressable children instead. Works in any Twig file, needs `twig.config.debug` on, and outputs nothing otherwise.

## Wiring the saved component: values, slots, filters, access

The `.component.yml` is half the system. A site builder then adds one or more
**`neo_component` config entities** on top of it (`config/neo_alchemist.neo_component.<id>.yml`,
managed at `/admin/config/neo/alchemist/{id}`) — this does **not** happen automatically when
you write the SDC, see workflow step 10 — and everything a *site builder* wires — where prop
values come from, what fills slots, per-placement parameters, who sees the component — is
plugin config on that entity, not in the yml. Know this catalog even when only authoring:
a prop's *type* is chosen partly for what can later bind to it. (Plugin internals and how
to write new ones → the **neo-alchemist-dev** skill.)

| Family | Configured at | Stored under |
|---|---|---|
| **Value** (per prop) | the prop's row on the component manage screen | `settings.props.<prop>.plugins` |
| **Slot** (per slot item) | the slot's Customize form | `settings.slots.<slot>.plugins.<uuid>` |
| **Filter** (per component) | Add Filter | `settings.filters.<uuid>` |
| **Access** (per component) | Add Access | `settings.access.<uuid>` |

Programmatic edits to `settings` follow the same shapes; after writing them, save the
entity and verify on a **real page** — `neo:alchemist:render` uses a transient entity, so
saved-component plugin config never runs there (see "Verify from the CLI").

### Value plugins — where a prop's value comes from

Each prop runs a pipeline seeded from its schema `examples`: **providers** (source a
value) → **fallback** (the `default` plugin — the site builder's configured Default Value)
→ **modifiers** (transform it) → **settings** (configure the widget, never the value).
The per-prop Customize form lists only the **active** plugins per group, as summary rows
with an *Add provider* select for the rest; Edit opens one plugin's settings at a time,
and everything stages on the form until Save. Every provider's chain behavior is the
**"When this provider runs"** radios at the top of its settings: *Use its value and stop*
(non-empty wins, empty falls through), *Add its value and continue* (never final — a
later provider can overwrite), *Always use its value — final* (always claims: empty
renders **nothing**; the shipped default for list-like providers whose `examples` are
scaffolding). Two rules of thumb: a provider that finds nothing (and isn't on the final
mode) leaves the previous value standing, so attaching one can't make a prop worse; and a
provider on the final mode starves the Default Value plugin — never combine it with a
configured default.

**Primary source with a fallback** — the ordering + modes recipe, and it works on list
props and on an aggregated component's `_aggregate` alike: `entity_reference` (mode
*Use its value and stop*) dragged **above** `entity_query` (mode *Always use its value —
final*). A filled reference claims and the query never runs; an empty (or dangling)
reference falls through to the query; an empty query claims emptiness so schema examples
never leak. Map the fields once — under **Advanced**, the second provider's form offers
"Copy field mapping from" to clone a sibling's mapping. Editor previews on an unsaved host always show the query
fallback (a new entity has no reference values yet). To hide the component when both
sources come up empty, add a `prop_value` access rule on a mapped child that empties
cleanly (a string like a card title — not a boolean/number, whose FALSE/0 count as
values). Live example: `callout_s1` (service term → `field_related_projects`, else the
newest project referencing the term via `field_related_services`). On media/image props
no recipe is needed for the common case: the auto-attached `media` provider is the
built-in widget + fallback and never claims, so a provider added anywhere in the list
supplies the image and the picked/fallback media fills in otherwise.

**Providers** — pick by data source (⊕ = auto-attached to its shape):

| Plugin | Binds | Use for |
|---|---|---|
| `entity` | any non-object prop | a field on the host entity ("heading ← node title", "image ← field_hero") |
| `entity_reference` | array/object | entities referenced by a host field, mapped to child props via "Shape Fields" (array: one item per entity; object/aggregate: the first published entity) |
| `entity_query` | array/object | an entity query — type/bundle, two sort levels, reference & taxonomy-hierarchy filters, offset/length, optional pager |
| `entity_load` | object | one specific entity by ID (hard-bound promo) |
| `entity_filter` | array/object | the entity a component **filter** picked (see Filters) |
| `views` | array/object | a view display's result rows; also registers the executed view as the context every other `views_*` plugin/slot reads |
| `views_exposed_filter` / `views_active_filters` / `views_summary` | the matching `views_*` shapes | designed filter UI / chips / result counts from that context |
| `menu` | menu | a Drupal menu tree (level/depth/expand-all, mirrors SystemMenuBlock) |
| `taxonomy_menu` | menu | a vocabulary rendered as a menu tree (neo_alchemist_taxonomy) |
| `taxonomy_children` / `taxonomy_siblings` | array (term pages) | child / sibling terms of the host term (neo_alchemist_taxonomy) |
| `breadcrumb` ⊕ | breadcrumb | the real page breadcrumb (hide-home / hide-current options) |
| `page_title` | string | the resolved page title |
| `heading` ⊕ | heading | per-key (supertitle/title/subtitle) sourcing: page title / entity label / a field / a literal, plus per-key editability |
| `media` ⊕ | media/image/file/video | auto-attached infrastructure, locked (removing it would destroy the widget): the media-library picker, the media-to-image conversion, and a shipped fallback file per media type. Defaults to *Add its value and continue*, so a provider added after it still runs — no reordering needed |
| `share` | menu | social share links for the host entity's canonical URL |
| `read_time` | string | client-side "N min read" (word count runs in the browser) |
| `event` | anything | custom module code supplies the value (`ComponentValueEvent`) |
| `entity_has_value` / `user_has_role` | boolean | **vetoes** — claim FALSE when a field is empty / the user lacks a role; `{% if %}` the markup on the prop |
| `site_settings` / `site_settings_field` / `site_settings_links` / `site_settings_fallback_media` | object / any / menu / media | site-wide Site Settings entities (contact info, socials, a client-managed fallback image) |

**Modifiers** — transform an existing value: `prefix` / `suffix` (literal text on
strings/numbers), `token` (compose from a token template — `"[term:name] Projects"`),
`date` (timestamp/ISO → formatted date; raw `created` ints arrive unformatted without it),
`number` (thousands separators), `link_title` / `link_uri` (override a link's text/URL,
token-aware — the dynamic-CTA pattern), `formatted_text` ⊕ (text-format rendering on
markup), `media_image_size` (editor-selectable size variants on an image prop).

**Settings** — never touch the value: `widget` (tune the edit widget), `region_size` /
`region_custom` (see the `region` shape above).

### Slot plugins — what a site builder can put in a slot

| Plugin | Renders | Notes |
|---|---|---|
| `block` | a block **placement** entity | only enabled blocks of the **default theme** are offered |
| `block_plugin` | a block plugin + its own config form | no placement entity needed; runs its access check |
| `entity` | a picked entity's default display | pick statically (env-fragile — the form warns) or via an entity **filter** |
| `entity_display` | the **host** entity in a chosen view mode | only on entity-bound components |
| `entity_field` | one field of the host (or a referenced) entity | full formatter config, like Manage Display |
| `entity_query_pager` | the pager for an `entity_query` prop | appears only when the component has one |
| `form` | any form class by FQCN + up to 2 args (raw / prop / filter) | **no validation** — a typo'd class is a WSOD at render |
| `product_variation_field` | a Commerce variation field, AJAX variation-aware | commerce product components only |
| `views` | a whole view display | maps its contextual args to component **filters** |
| `views_header` / `views_pager` / `views_exposed_filters` | pieces of the view bound via the `views` **value** provider | the decompose-a-view pattern: one prop yields rows, slots surface header/pager/exposed form as separately-themeable items |

Each item gets a **Twig key** (the variable name in `slots/<slot>.twig`) — configured key,
else plugin id, `_2` on collision.

### Filters — per-placement parameters (not visibility)

A ComponentFilter is a named, typed **parameter** on the component — "which term", "how
many items" — with a default value, optional per-instance editability (`Allow Edit`), and
`Required`. It configures data sources; it never gates rendering (that's Access). Consumers:
the `views` slot's contextual arguments, the `form` slot's arguments, the `entity` slot's
dynamic pick, and the `entity_filter` / `entity_query` / `views` value providers. Types:
`string`, `number`, `entity` (autocomplete/select/options; multi-value joins with `,`
(AND) / `+` (OR), Views-argument style), and `options:<set>` (a fixed select — requires a
`*.neo_component_filter_options.yml` declaring the set; none ship enabled). All filter
values round-trip as **strings**; consumers cast.

### Access — deny-only visibility rules

Access plugins can only **forbid**, never grant; the first forbidden wins; users with
`administer neo_alchemist` bypass them (except where noted). Three ops: `view` (frontend),
`update` (backend edit), `create` (backend add/remove).

- `role` / `permission` — per-op role or permission checks (any/all matching).
- `protected` — backend management (`update`+`create`) requires the `use protected
  components` permission; `view` untouched. The "don't let editors break the header" lock.
- `prop_value` — hide the component on the frontend unless every selected prop resolves
  non-empty ("no image + no link ⇒ no teaser card"). **Admins do not bypass this one on
  `view`** — an empty component hides for everyone, by design.
- `entity_field_value` — hide the component unless every selected **field on the target
  entity** has a value ("no field_awards ⇒ no awards section"). Only offered on
  components registered against an entity type; neutral on new/placeholder entities so
  builder previews stay visible; admins don't bypass on `view` (same rationale as
  `prop_value`). The whole-component counterpart of the `entity_has_value` boolean
  value-plugin veto — reach for the value plugin when one prop should react, this when
  the entire component should.

## The `.twig` file

### Root element & structure

Always put `{{ attributes.addClass(classes) }}` on a **single root element** — Alchemist injects the classes from `apply: true` style props (scheme, spacing, gap, …) there, **including the component's vertical padding** (`neo-section-y` from the `gap` prop). Pick one of two layout patterns depending on whether the component paints a background.

**Plain component (no background)** — the root paints nothing; the `gap` prop still pads it and collapses it against same-color neighbors:

```twig
<div {{ attributes.addClass(['container-content']) }}>
  ...
</div>
```

**Background / full-bleed section** — background spans the viewport, content is constrained; the `gap` prop's padding sits on the root so the background fills it:

```twig
{% set classes = ['bg-default', 'component-bg'] %}   {# scheme-aware bg + collapse marker #}
<div {{ attributes.addClass(classes) }}>              {# full-width background + neo-section-y via gap #}
  <div class="container-content">                      {# centered content #}
    ...
  </div>
</div>
```

**Deep-carrier layout** (a full-bleed band above the padded area, so root padding would break it): declare `gap: { type: gap, apply: false }` and merge the prop onto the inner wrapper yourself — the editor's merge picker keeps working, and seam collapsing still reaches it (the zeroing variable is set on the root and inherits):

```twig
<div{{ gap.removeClass('neo-section').addClass('container-content') }}>
  ...
</div>
```

Rules of thumb:
- **`container-content`** = centered, responsive max-width, **with** side gutters (the standard content wrapper). **`container-center`** = same but **no** gutters. Both are provided globally by the neo base theme.
- **Nesting inside another component's region: nothing to do in the child.** A parent that renders a `region` strips its children's side gutters with `neo-region-flush-x`, and reclaims their outer vertical spacing with `neo-region-flush-y` / `-t` / `-b` — see [insight_body](web/themes/front/components/insight_body/insight_body.twig), whose article region hosts Text, Accordion and Testimonial. Those utilities zero `--spacing-container` on the child's content wrapper and match **both** spellings, `container-content` or its longhand `container-center px-container`, so write the wrapper whichever way reads best. Never hand-roll a "flush" variant in the child, and don't add a prop for it: which gutters survive is the *parent's* decision, made once on the region.
- **Never hand-write a section carrier** — the `gap` prop applies `neo-section-y`. The `*-component` utilities stay available for spacing **inside** the component (`p-component-sm`, `mt-component-lg`, `gap-component`, …); they all read `--spacing-component` set by the `spacing` prop. Prefer the relative size variants (`-xs`, `-sm`, `-lg`, `-xl`) for internal spacing: the base-size vertical ones (`py/pt/pb/my/mt/mb-component`) are still channel-aware for backward compatibility with pre-`gap` components, so a collapsed section zeroes them — use a variant, a numeric utility, or wrap in `component-spacing-reset`.
- **`component-bg`** marker: add it (alongside `bg-default`) to a background-section root so two adjacent sections rendering the **same color** collapse their doubled spacing into a single, continuous-background gap. "Same color" is **computed at build time** from the actual neo_color token values — `scheme-default` next to a no-scheme section collapses (identical pixels), and transparent components (no `component-bg`) collapse against neighbors matching the **page background**. `bg-default` next to `bg-base-100` under one scheme stays two colors and keeps its full separation. Recognized surfaces: `bg-default`, `bg-base-50/100/200/300`; anything else never collapses (fails safe; extend via `hook_neo_alchemist_component_bg_surfaces_alter()`). The editor opts a section out with the `gap` prop's `keep` option (or hand-add `component-bg-flush-none`).
- **Colors:** apply `bg-default` (scheme-reactive) where you want a surface fill — text and borders inside a scheme then adapt **automatically with no class** (see next section). Use the `base|primary|secondary|accent` palettes (shades `-0…-950`, with `-content` foreground pairings, e.g. `bg-primary text-primary-content`) for emphasis. Full details in [web/modules/contrib/neo_alchemist/STYLING.md](web/modules/contrib/neo_alchemist/STYLING.md).
- **Prefer `base`; gray is a fallback.** Use `base` / `bg-default` for neutrals in components you author — that's the house style — and convert `gray-*` to `base-*` when adapting pasted markup. As a safety net, Tailwind's neutral scales (`gray`, `slate`, `zinc`, `neutral`, `stone`) auto-fall back to `base` when those pallets aren't enabled, so copied markup using `bg-gray-100`, `text-slate-700`, etc. still renders correctly and stays scheme-reactive. (Non-neutral colors like `blue`/`red` are **not** aliased.)

### What the scheme system handles for you

Inside any `scheme-*` region (including the un-schemed page root), these adapt with **no classes at all** — every scheme picks contrast-checked values, including dark and colorized schemes. Writing extra color classes for these is at best redundant and at worst fights the scheme:

| Concern | What to write | What NOT to write |
|---|---|---|
| Body text | nothing — inherits the scheme's readable foreground | `text-default` (only needed to *re-assert* after an override), `text-base-900` |
| Borders | just a border width (`border`, `border-t-4`) | `border-default`, per-scheme border colors |
| Text links | a bare `<a>` — gets scheme-aware link + hover colors; `text-link hover:text-link-hover` for a non-anchor (or a classed anchor) that should read as a link | `text-primary-600 hover:text-primary-800` |
| Buttons | the `.btn*` classes (`btn`, `btn-primary`, `btn-outline-accent`, `btn-text-secondary`, …) | hand-built buttons from `bg-*`/`text-*` utilities — they won't retune per scheme and lose the managed hover states |
| Prose links | `prose` — links inside it follow the scheme link color | per-link color classes |

**Scheme-conditional tweaks: use the generated variants, never per-scheme classes.**
`scheme:` (any scheme active) · `dark:` (the active scheme is a **dark** scheme) ·
`color:` (a colorized scheme) · `{scheme-id}:` (one specific scheme, e.g. `accent:`).
E.g. `opacity-20 dark:opacity-40` to strengthen an overlay on dark schemes. ⚠ `dark:`
is **redefined by neo_color** — it matches the active *scheme* (`.scheme-x &`), not
Tailwind's stock `prefers-color-scheme` media query, so it composes with the `scheme`
prop and ignores the OS setting. Variants compile on demand like any utility — write
them literally in the twig/yml.

**Token semantics that trip authors** (full cheat-sheet + the resolution engine: the
**neo-color-dev** skill):

- `bg-default` is the scheme **surface** (`base-0`); bare `bg-base` is shade **500** — a
  distinct mid tone, *not* the surface. For readable surface text use `text-default`
  (auto-applied anyway) — never `text-base-content`, which is the ink for base-**500**.
- Bare role tokens (`text-primary`, `bg-accent`, `border-secondary`) are
  **contrast-picked per scheme** — they move to stay legible. Numbered shades
  (`text-primary-600`) always stay the raw brand ramp. That asymmetry is why a
  hand-picked shade that looks right in one scheme breaks under the others —
  and `drush neo:alchemist:validate` warns about numbered role shades in any
  component that declares a `scheme` prop (advisory: deliberate raw-brand decor
  is fine).
- Every color has an adaptive **hover step**: `text-primary hover:text-primary-hover`
  is the scheme-safe replacement for the old `text-primary-500 hover:text-primary-600`
  idiom. The `-hover` token is the next perceptibly different pick in every scheme
  (it keeps stepping even on schemes that pin a role's contrast for brand
  fidelity); `-hover-content` pairs it for fills.
- `text-link` / `hover:text-link-hover` color any element **like a scheme link** —
  the same contrast-picked pair a bare `<a>` gets automatically from the base
  layer. Reach for them when the element isn't an anchor, or an anchor must carry
  other color classes. They're the safest colored-text pair of all: text-grade
  (4.5:1) picked in every scheme, even where the bare role tokens are pinned raw.
- On a hand-painted fill, pair the ink: `bg-primary text-primary-content` (every shade
  has a `-content` partner).
- `white`/`black` are **aliases for `base-0`/`base-950`** and therefore scheme-reactive:
  in a dark scheme `text-white` renders *dark* (base-0 is the dark surface there). For
  genuinely fixed white — e.g. text over a photo that's identical in every scheme — use
  an arbitrary value (`text-[#fff]`).

**Button classes (compose directly in Twig).** A `.btn*` is built from two independent axes: a **style/color** class — `btn` (solid), `btn-outline-{primary|secondary|accent|base}`, `btn-text-{…}` — plus an optional **size** class: `btn-xs`, `btn-sm`, `btn` (default, `md`), `btn-lg`, `btn-xl`, `btn-2xl`, `btn-3xl`. E.g. `class="btn btn-outline-primary btn-lg"`. Prefer a size class over hand-tuning `px-*`/`py-*`/`text-[…]` on a button — it keeps it on the managed, scheme-aware scale (contrast-checked colors + hover states per scheme). The `button_style` / `button_size` style props emit these for `link` props; use the raw classes when you hand-write the `<a>`/`<button>`.

Semantic CSS variables, for component-local CSS or inline styles (all scheme-scoped):
`--text-color-default`, `--background-color-default`, `--color-border-default`, `--link-color` / `--link-color-hover`, `--color-{base|primary|secondary|accent}-{0…950}` (+ `-content`), and `--color-shadow-{0…950}` — a brand-tinted shadow ramp **guaranteed darker than the surface** in every scheme (use it for `box-shadow` colors that won't glow on dark/colorized schemes, e.g. `box-shadow: 0 8px 20px -6px rgb(var(--color-shadow-500) / 0.45)`).

### Finding this site's real colors

Default to the tokens above — they recolor per scheme, so you rarely need a literal hex. But when a decision genuinely needs the resolved value (a gradient stop, an overlay/tint opacity, matching bundled artwork, an SVG fill), don't dig through config or compiled CSS. Three Neo Color commands report it, all `--format=json`-friendly:

- **`drush neo:color:pallets`** — the enabled pallets with their brand anchor hex (the raw 500), content pairing, and which scheme role slots use each. Answers "what color *is* `primary` on this site" in one line.
- **`drush neo:color:schemes`** — every enabled scheme with its role→pallet mapping (base/primary/secondary/accent) plus resolved surface + text hex. Schemes remap the role slots (e.g. the `accent` scheme swaps primary↔accent), so this table is how you pick the scheme that actually gives the look you want rather than one that merely exists.
- **`drush neo:color:scheme <id>`** — one scheme resolved in full: surface/text/border, link + hover, the contrast-picked button fill/content per role, the bare role tokens, and each role's auto-contrast flag — all normalized to hex. Add `--vars` for the complete raw CSS-variable set (every ramp step, under a `vars` object) when you need a specific shade like `--color-primary-300`.

Remember the resolved value is scheme-specific: if you hardcode a hex from one scheme into a component meant to be recolored, it breaks under the others. Read a value to *inform* a token-based or `--vars`-driven choice, not to replace the token.

> **When the question is *why* a token resolves to that value** — why `text-primary`
> shifted shade under the accent scheme, why a button fill picked base-800, how the
> dark/colorize ramp math works, or when tuning the schemes/pallets/contrast engine
> itself — that's the **neo-color-dev** skill (neo_color module internals). This skill
> covers *using* the tokens.

### Rendering props

| Shape | Twig pattern |
|---|---|
| `heading` | `<div{{ heading.size }}>` wrapper with `component-supertitle`/`component-title`/`component-subtitle` children — **all three, each `{% if %}`-guarded**. Full rules and the required guard: "Rendering a `heading`: all three parts, always" below |
| `markup` | `{{ description }}` wrapped in `<div class="prose max-w-none">` |
| `image` | `{{ neo_image_style(img.src, {focal: {width: 1200, height: 575}}, img.alt) }}` or `neo_image()` for responsive |
| `image` (**SVG**, e.g. a logo) | Image styles can't rasterize an SVG, so the original file is emitted and the size op only sets HTML `width`/`height` attributes — which the theme's base reset (`img{height:auto}` in `@layer base`) then overrides. A viewBox-only SVG has no intrinsic size, so it collapses to 0×0. **Size it with a CSS class** (utilities win over the base layer): `{{ neo_image_style(logo.src, {scale: {height: 30}}, logo.alt, '', {class: ['h-7', 'w-auto']}) }}` |
| `icon` | `{{ icon(name) }}` — add modifiers: `|icon_class('text-3xl')`, `|icon_only`, `|icon_library('regular')` |
| `link` | `<a{{ item.button_style }} href="{{ neo_uri(item.link.uri, item.link.options) }}">{{ item.link.title }}</a>` |
| `url` | Same as link — check `item.link.access` for permission-gated links |
| `remote_video` | `{{ neo_oembed(video.src) }}` inline, or `{{ neo_modal(thumb, {video: src}, 'media') }}` |
| `region` | `{{ accordion.region }}` — auto-renders nested components |
| `style` (apply:false) | Render as an attribute — `<div{{ border_top.addClass(['…']) }}>` emits the selected option's `value` classes. `.getValue()` returns the **key** — for `{% if %}` branching only, never to re-type the classes |
| `array` | `{% for item in items %} ... {% endfor %}` |

### Rendering a `heading`: all three parts, always

**A component that declares a `heading` prop MUST render all three text parts —
`supertitle`, `title` and `subtitle`.** They are editor-facing fields on every heading:
if the markup only prints `title`, an editor who fills in a supertitle or subtitle sees
their text silently vanish. Rendering only the title is the single most common defect in
this codebase's components — do not copy a title-only heading from an existing component.

The canonical form (what `drush neo:alchemist:shapes heading` prints):

```twig
{% if heading %}
  <div{{ heading.size }}>
    {% if heading.anchor %}<a name="{{ heading.anchor }}" title="{{ heading.title }}"></a>{% endif %}
    {% if heading.supertitle %}<div class="component-supertitle">{{ heading.supertitle }}</div>{% endif %}
    {% if heading.title %}<h2 class="component-title">{{ heading.title }}</h2>{% endif %}
    {% if heading.subtitle %}<div class="component-subtitle">{{ heading.subtitle }}</div>{% endif %}
  </div>
{% endif %}
```

Four rules, each load-bearing:

1. **All three children present**, each `{% if %}`-guarded so an unfilled part emits nothing.
2. **`heading.size` on the shared wrapper, `component-*` on the children.** `title-*` only
   sets the `--title-size` / `--supertitle-size` / `--subtitle-size` **variables**; the
   `component-supertitle` / `component-title` / `component-subtitle` classes consume them.
   Put `size` on the wrapper and all three parts scale together from one editor control.
   (A hand-rolled `<h2{{ heading.size.addClass(['component-title']) }}>` puts size and
   consumer on the same tag — that works *only* for the title, which is precisely why it
   tends to leave the other two parts unrendered. Prefer the wrapper.)
3. **`{% if heading %}` is the outer guard — plain, no three-way test.** The shape reports
   itself empty when `supertitle`, `title` and `subtitle` are all empty, so the object is
   falsy exactly when there is nothing to render. Don't hand-roll
   `{% if heading.supertitle or heading.title or heading.subtitle %}`; it is equivalent but
   noisier, and the emptiness rule belongs in one place. (`size` and `anchor` are declared
   *presentational* and deliberately don't count — `size` always resolves to `md`, so
   before that contract existed every heading tested as truthy and a textless one emitted a
   phantom `<div class="title-md mb-4"></div>` whose spacing still pushed content down. If
   you meet that symptom, the shape's `getPresentationalValueKeys()` is what governs it.)
4. **Never gate one part on another.** `{% if heading.supertitle %}<h2>{{ heading.title }}</h2>{% endif %}`
   makes the title disappear whenever the supertitle is empty. Each part guards itself.

Spacing and color between the parts is the component's design decision — the usual house
treatment is a small `mb-2` under the supertitle and `mt-2` above the subtitle, with the
outer two in the brand color (`text-primary`, the adaptive bare token — **not**
`text-primary-500`). Layout may sit around them: only the *title row* needs to be a flex
when a trailing arrow or icon rides beside the title, leaving supertitle/subtitle stacked
above and below (see [list_s3](web/themes/front/components/list_s3/list_s3.twig)).

Inline variants are fine where the design calls for a single run of text — hero_s1 and
list_s1 render supertitle and subtitle as colored `<span>`s *inside* the `<h1>`/`<h2>`
around the title. That still satisfies the rule: all three parts render. What is never
acceptable is dropping a part entirely.

**Verify it.** Examples are usually title-only, so a rendered preview won't exercise the
other two. Temporarily add `supertitle`/`subtitle` to the prop's `examples:`, run
`drush neo:alchemist:render <provider>:<name> --html`, confirm all three appear, then
revert the examples.

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

> **Background components (`bg-default component-bg`): don't reveal the root.** `apply: true` puts the reveal on the root, so the whole **colored block** animates in. Instead override `apply: false` on `animate`/`animate_speed`/`animate_delay` in the yml and apply them to an inner content wrapper — and if the component staggers, move `animate_stagger` there too (the enter class and `neo-animate-stagger` must share one element, or the cascade silently no-ops). The reveal props are Attribute objects, so **merge** them onto the wrapper (`getValue()` returns the raw key, not the classes): `<div{{ animate.merge(animate_speed).merge(animate_delay).merge(animate_stagger).addClass(['container-content']) }}>`. Full details + the non-stagger case in the **neo-animate** skill's *Background components* section.

## Workflow for a new component

1. **Pick a machine name** — snake_case, typically `<purpose>_s<n>` (e.g. `testimonial_s1`). Confirm it's not already taken with `drush neo:alchemist:components` (lists every Neo component with its provider, prop, and slot counts).
2. **Find the closest existing component** and read its yml + twig. Copy that pattern — don't invent from scratch. Two exceptions: **don't copy hover-color idioms verbatim** — older components predate the adaptive tokens, so `hover:text-primary-600` / `hover:bg-primary-600` in copied markup should become `hover:text-primary-hover` / `hover:bg-primary-hover` (or `text-link hover:text-link-hover`, or a bare `<a>`); `validate` will warn if one slips through. And **don't copy a title-only `heading` block** — several components historically rendered `heading.title` alone, dropping the editor's supertitle and subtitle. Render all three parts (see "Rendering a `heading`: all three parts, always").
3. **Create the folder** at `web/themes/front/components/<name>/`.
4. **Write `<name>.component.yml`** — always include `$schema`, `name`, `status: stable`, `neo: true`, and both a `spacing` and a `gap` prop. Use existing shapes (`heading`, `markup`, `image`, etc.) rather than raw JSON Schema types.
5. **Provide `examples:`** for every prop — these populate the Alchemist editor's default values and the preview. Arrays with `region` or booleans can use `- TRUE` as placeholder rows.
6. **Write `<name>.twig`** — root div with `{{ attributes.addClass(classes) }}`, wrap optional sections in `{% if ... %}`, use `neo_uri()` for all URLs, `icon()` for icons, `neo_image_style()` for images. If the component has a `heading` prop, render **all three** of its text parts (see "Rendering a `heading`: all three parts, always").
7. **Test interactive elements** with `{% if neoIsPreview %}data-event...{% endif %}` so the editor preview remains clickable.
8. **Clear the cache** (`drush cr`) after adding a new component — SDC registration is cached.
9. **Verify from the CLI before finishing** — run `drush neo:alchemist:validate <provider>:<name>` then `drush neo:alchemist:render <provider>:<name>`. Don't hand off a component you haven't rendered. See "Verify from the CLI" below.
10. **Ask whether to create the library entry** — a finished SDC still isn't usable by editors. Page building runs on `neo_component` **config entities**, and until one exists the component has no manage screen (`/admin/config/neo/alchemist/<id>` returns 404) and never appears in the picker. **Ask; don't assume.** It's a site-builder decision and it isn't 1:1 — one SDC can back several entries, each wired differently (this site carries four apiece for `list_s3` and `hero_s2`), so the entry id is not simply the SDC name. The UI path is **Add component** → `/admin/config/neo/alchemist/add`. On a yes, the equivalent for the default `general` group is:

    ```bash
    ddev drush ev '$e = \Drupal::entityTypeManager()->getStorage("neo_component")->create([
      "id" => "media_s2", "label" => "Media with Text", "component" => "front:media_s2",
      "group" => "general", "status" => TRUE,
    ]); $e->save();'
    ```

    Two constraints on the programmatic route. `ComponentForm::save()` applies group-conditional defaults that a raw `create()` skips — the `special` group gets a `protected` access plugin, and the `entity` group gets every prop locked — so only create `general`-group entries this way and send the other groups through the form. And it writes **active config**, so follow with `drush cex` (or say it needs exporting); a library entry that never gets exported exists only on that one environment.

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

**Testing with a browser (incl. browser MCP): go straight to the `/frame` URL, not the
workspace.** The workspace above is a back-theme admin page that renders the component
inside desktop/tablet/mobile **iframes** — screenshotting it captures the admin chrome
around a small iframe, and driving into the iframe is needless friction. Each iframe's
own URL renders the component as a bare front-theme page:

```
/admin/config/neo/alchemist/preview/{provider}:{machine_name}/frame
```

e.g. `/admin/config/neo/alchemist/preview/front:callout_s1/frame` (needs a logged-in
`administer neo_alchemist` session; the `:` may appear URL-encoded as `%3A`). The query
params the workspace's iframes carry are not needed: `id` is iframe wiring the route never
reads, and `size=desktop` only attaches the screenshot-capture library. The frame does
**not** emulate widths — set the browser viewport yourself to test responsive behavior.
What renders there is the editor-preview branch (`neoIsPreview` true, like
`neo:alchemist:render` without `--live`), and any neighbors picked in the workspace's
Above/Below selectors render too, so spacing/`component-bg` seam checks work in the frame.

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
  to props that aren't declared, dynamically-assembled Tailwind classes that won't
  compile, and (in scheme-bearing components) numbered role shades like
  `text-primary-500` that never adapt to schemes. Exits non-zero on hard errors.
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
> `examples`, unknown prop types, dynamic Tailwind classes, and non-adaptive numbered
> role shades before you ship.


- **Forgetting `neo: true`** — component won't appear in Alchemist's picker.
- **Raw `{{ url }}` instead of `{{ neo_uri(link.uri, link.options) }}`** — breaks internal `internal:/` URIs.
- **Missing `examples`** — editor shows empty previews and broken defaults.
- **Not wrapping in `{% if prop %}`** — component renders empty scaffolding when editor leaves fields blank.
- **Rendering only `heading.title`** — the editor's supertitle and subtitle fields exist on every heading prop, so text typed into them silently disappears. Render all three parts, each `{% if %}`-guarded. See "Rendering a `heading`: all three parts, always".
- **Hand-rolling a heading's emptiness test** — `{% if heading.supertitle or heading.title or heading.subtitle %}` (or a `{% set has_heading = … %}` hoist) duplicates a rule the shape already enforces. Plain `{% if heading %}` is falsy exactly when all three text parts are empty; `size`/`anchor` are declared presentational and don't count.
- **Gating one heading part on another** — e.g. `{% if heading.supertitle %}<h2>{{ heading.title }}</h2>{% endif %}` drops the title whenever the supertitle is empty. Each part guards itself.
- **Using `heading.title` for the `<h2>`** but dropping `heading.size` — the editor's Size selector silently no-ops. And `heading.size` alone isn't enough: `title-*` only sets variables, so the same element (or a child) needs `component-title` to consume them. Put `size` on the shared wrapper and `component-supertitle`/`component-title`/`component-subtitle` on the children, so one Size control scales all three.
- **New style prop with `apply: true` but missing `examples`** — class won't be present on first render.
- **Re-mapping a `style` prop's key back to hand-written classes** — `{% set h = size.getValue() == 'short' ? 'h-72' : 'h-96' %}` duplicates the yml `styles.*.value` strings. `.getValue()` is the **key**, not the classes; the prop is already an `Attribute` carrying the option's `value`, so render it (`<div{{ size.addClass(['relative']) }}>`) and keep the yml the single source of truth. The hand map silently drifts from the yml (which then never renders) — reserve `.getValue()` for `{% if %}` branching.
- **Hand-writing `py-component`/`my-component` as a section carrier** — that's the deprecated pre-`gap` pattern, kept working only for legacy components. The `gap` prop applies `neo-section-y` to the root; a hand-written carrier doubles it. Declare `gap: { type: gap }` instead (or `apply: false` + merge for deep-carrier layouts).
- **Background section without the `component-bg` marker** — two adjacent same-color sections stack double padding. Add `component-bg` (next to `bg-default`) so the seam collapses.
- **Background section painting an off-vocabulary color** — e.g. `bg-base-600` or an arbitrary value. It keeps `component-bg` but never collapses against a neighbor. Either use one of the recognized surfaces or register yours (utility class ⇒ neo_color token) with `hook_neo_alchemist_component_bg_surfaces_alter()`.
- **Dynamic Tailwind class names never compile.** The build only emits classes that appear **literally** in scanned source — `bg-{{ color }}-500`, `'text-' ~ tone`, or classes assembled in JS produce nothing in the CSS. Enumerate full class names (in the yml `styles:` values, a Twig mapping, or a comment), or use inline CSS variables for genuinely data-driven color: `style="background-color: rgb(var(--color-{{ pallet }}-500))"` works because the *variables* always exist.
- **Hardcoding one scheme's colors** (e.g. `bg-base-0`) on a component meant to be recolored — use `bg-default` for the surface and let text/borders adapt automatically so the `scheme` prop can recolor it.
- **Coloring links or buttons by hand** — `text-primary-600` on an `<a>`, or a "button" built from `bg-primary text-white` utilities, breaks under other schemes: numbered shades never adapt (only the bare role tokens are contrast-picked), and `text-white` is really scheme-reactive `base-0` (dark in a dark scheme). Bare `<a>` elements and the `.btn*` classes are contrast-managed per scheme, hover states included — and when you do need explicit classes, `text-primary hover:text-primary-hover` and `text-link hover:text-link-hover` are the adaptive pairs; on a hand-painted fill the legible ink is the `-content` pairing, not `text-white`. See "Token semantics that trip authors".
- **Overloading the component `.css`.** Layout, spacing, color, sizing, and hover states all have Tailwind utilities — put them in the `.twig` (use arbitrary values like `text-[0.62rem]`/`basis-[calc((100%_-_5rem)/5)]` for off-scale numbers, and `group`/`group-hover:` for per-element hover). A `.css` full of `display:flex` / `padding` / `color` / `:hover` is a smell; the file is only for what has no utility (keyframes, gradient overlays, exact shadows, scrollbar-hide, `::after` content, styling a generated `<img>`).
- **Transform dimensions far larger than the slot ever renders** — every number in a `neo_image_style()` call or an `image_size` style is a real file the browser downloads, so derive it from the **widest CSS width the image can occupy**, never from a round number that "looks safe". Do the arithmetic: `container-content` tops out at a **1504px content box** (96rem max-width − 2 × 1rem gutters), so a full-bleed image maxes at 1504 and a column maxes at 1504 × its width fraction — `lg:w-[58%]` → ~872px, `lg:w-[46%]` → ~692px, `lg:max-w-145` → 580px. The house convention for large images is **≈1× that width** (callout_s1 ships 1400 for 1504, list_s2 900 for 872, media_s1 1600 for 1504); 2× is reserved for small marks, where the bytes are cheap (a `size-12` avatar ships 96×96). Two consequences:
  - When several crops share one column the **width is the binding constraint, not the aspect** — keep the width identical across every option and vary only the height (media_s2: `700×700` / `700×525` / `700×933` / `700×394`).
  - A component destined for a **nested region renders far narrower than standalone** — Insight Body's `max-w-4xl` article column puts a 46% image near 412px. Size for the widest realistic placement; don't double it to cover both.
- **Placeholder image dimensions out of sync with the twig transform** — the `placehold.co/WxH.png` URL (and `width`/`height` fields) in the prop's `examples:` should match the dimensions produced by `neo_image_style()` / `neo_image()` in the twig. The right target depends on the size op (see [web/modules/contrib/neo_image/README.md](web/modules/contrib/neo_image/README.md)):
  - Fixed-output ops — `scaleCrop`, `crop`, `focal`, `exact`, and `auto` with both width+height: placeholder must be exactly `{width}x{height}`. E.g. `{scaleCrop: {width: 300, height: 200}}` → `placehold.co/300x200.png`, `width: 300, height: 200`.
  - Width-only ops — `scale`, `focalWidth`, and `auto` with only width (or only height): output keeps the source aspect, so pick a placeholder that matches the *intended display aspect* (e.g. a `scale: {width: 1200}` slot shown in a 4:3 container → `placehold.co/1200x900.png`).
  - Responsive `neo_image()` with multiple breakpoints: use the largest breakpoint's dimensions for the placeholder.
  - Items rendered via a shared include (e.g. `@front/includes/list_s1--items.html.twig` uses `scaleCrop: 75x75`): match the include's dimensions, not the wrapper component.
- **SVG (e.g. a logo) rendered via `neo_image_style` collapses to 0×0 / a tiny square** — image styles are raster ops (GD), so an SVG can't be transformed: the original file is emitted and the size op only sets HTML `width`/`height` attributes. The theme's base reset (`img{height:auto}` in `@layer base`) overrides those attributes, and a viewBox-only SVG has no intrinsic size, so it renders at 0×0 (or a fabricated square if a single-axis op is used). Fix by sizing with a **CSS class** via the 5th `attributes` arg — utilities beat the base layer: `{{ neo_image_style(logo.src, {scale: {height: 30}}, logo.alt, '', {class: ['h-7', 'w-auto']}) }}`. (`w-auto` lets the browser derive width from the SVG's `viewBox` aspect ratio.)
- **Fixed/floating component blank in the Alchemist preview** — a `position: fixed`/`absolute` root has no flow height, so the preview iframe collapses. Render it in-flow (`relative`) behind `{% if neoIsPreview %}`, with a solid background if it's normally transparent. See "Fixed / floating roots and the preview iframe".
- **Fixed/sticky component hidden behind the admin toolbar** — pinning a `fixed`/`sticky` root to `top-0` puts it under the Drupal toolbar for logged-in users. Use `top-displace-t` instead (offsets by the toolbar height, `0px` when absent). See "Fixed / floating roots and the preview iframe".
- **A provider on the final mode plus a configured Default Value** — *Always use its value — final* claims unconditionally, so the fallback `default` plugin never gets a turn and the site builder's Default Value silently never renders. Use *Use its value and stop* when a default is configured.
- **`examples:` on a `media` prop** — media props can't carry examples; previews borrow the most recent published media of an allowed type instead. Don't fight it with placeholder URLs — that's what `image` (with `component://` art) is for.
- **Clearing cache** — after editing `.component.yml`, run `drush cr` or the prop changes won't reflect.
