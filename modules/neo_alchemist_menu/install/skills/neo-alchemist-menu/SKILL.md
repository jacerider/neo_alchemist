---
name: neo-alchemist-menu
description: Build and maintain mega menus with neo_alchemist_menu — "component region" menu items that carry an Alchemist component tree rendered inside a parent item's dropdown panel. Use when working on mega menus, region menu items, header dropdown panels, the neo_region link option, hook_neo_alchemist_menu_value_item_alter, or programmatically writing component trees on menu links. NOT for authoring the panel components themselves (use neo-component) or general neo_alchemist internals (use neo-alchemist-dev).
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Mega menus via component region menu items

Module: [web/modules/contrib/neo_alchemist/modules/neo_alchemist_menu/](web/modules/contrib/neo_alchemist/modules/neo_alchemist_menu/)

## Mental model

A **component region item** is a `menu_link_content` entity flagged with
`options[neo_region] = TRUE` on its link field (the same options-blob storage
`neo_menu_link` uses), nested under a parent item **alongside real link
children**. It renders not as a link but as its **Alchemist component tree**,
stored in the `field_components` (`neo_component_tree`) field this module ships
on `menu_link_content`. A mega menu dropdown is therefore: link columns built
from real child menu links + each region item's rendered components. Menu
structure stays native (active trail, access, breadcrumbs); only the editorial
extras live in component trees. Trees are **content** on the menu link entity —
they do not move through config (unlike `neo_alchemist_block`).

Shared constants/helpers: [src/MenuRegion.php](web/modules/contrib/neo_alchemist/modules/neo_alchemist_menu/src/MenuRegion.php)
(`FIELD_NAME`, `FIELD_KEY`, `OPTION_KEY`, `isRegionItem()`, `isRegionOptions()`,
`loadFromPluginItem()`).

## Editor flow

- `/admin/structure/menu/manage/{menu}` → **"Add component region"** local
  action → add-link form with the region checkbox pre-checked (`?neo_region=1`);
  link/description/expanded are hidden and the uri is forced to
  `route:<nolink>` on save. Region items must sit under a parent (validated).
- Region rows are badged *"(Component region)"* in the overview tree and get a
  **Components** operation → the standard Alchemist editor at
  `/admin/structure/menu/item/{id}/edit/alchemist/components` (fully auto-wired
  from the field; drafts/publish/revert work as on any entity tree).
- Region items must stay **enabled** — disabled links are filtered by the
  `checkAccess` tree manipulator before the value provider ever sees them.

## How the data flows

1. A component's `menu` prop is bound to the `menu` ComponentValue provider
   (per-component config, e.g. `settings.props.menu.plugins.menu.menu.settings`
   — `menu_id`, `level`, **`depth`** must reach the region's level: 3 for
   top-level → columns → column links).
2. `MenuValue::buildItems()` dispatches
   `hook_neo_alchemist_menu_value_item_alter(&$entry, $item, $shape)` per item
   (documented in `neo_alchemist.api.php`). This module's implementation turns
   flagged items into `entry.region = TRUE` + `entry.content = <render array of
   the tree>` (NULL while empty), drops `entry.below`, and vetoes items it
   cannot resolve. Extra keys ride through the `menu` prop schema untouched
   (same precedent as `in_active_trail`).
3. Cacheability: the implementation adds the region entity as a dependency via
   `$shape->addCacheableDependency()` — publishing a region tree or editing
   menu links invalidates cached pages. Verified end-to-end; do not bypass it.
4. Access: core gates `menu_link_content` **view** behind `administer menu`, so
   this module grants view on enabled region items
   (`neo_alchemist_menu_menu_link_content_access()`). Without that grant the
   tree render **silently skips** the components for anonymous users
   (`ComponentTreeHydrated::renderify()` checks instance view access).
5. Classic menu renders (`menu.html.twig`) never show region items — a
   `hook_preprocess_menu` strips them recursively.
6. **Mobile / slide menus**: neo's `slide_menu` element (used by the
   neo_modal "Slide Menu" block) dispatches `hook_neo_slide_menu_item_alter()`
   per item (documented in `neo.api.php`); this module's implementation swaps
   region items for a content-only row rendering their component tree, so the
   mega menu translates to mobile automatically (style hooks:
   `.neo-slide-menu--region` on the content wrapper,
   `li.neo-slide-menu--region-item` on the row). The slide menu also renders
   `<nolink>` items *with children* as real `<button>` triggers (core renders
   such urls as an unclickable `<span>`), and drops "View All" rows for
   non-linking parents. To mirror the mega menu's grouped columns on mobile,
   set the slide menu's **expand depth** to 2 (`#expand_depth` on the
   `slide_menu` element / "Expand children from level" on the neo_modal
   block): second-level items then render as group headings with their
   children listed inline instead of opening a third slide level.

## Rendering in a component (twig)

```twig
{% for child in item.below %}
  {% if child.region %}
    {% if child.content %}{{ child.content }}
    {% elseif neoIsPreview %}<div class="border border-dashed">{{ child.title }}</div>{% endif %}
  {% else %}
    {# link column from child.title / child.url / child.below #}
  {% endif %}
{% endfor %}
```

Reference implementations — both use click-to-open Alpine panels
(`neo/library.alpine` via `libraryOverrides`) with `aria-expanded` on the
trigger, outside-click/Escape close, an `[x-cloak]` pre-init guard in the
component CSS, a `group-aria-expanded/link:` underline state, and in-flow
panels under `neoIsPreview` (an absolute panel opens clipped/invisible in the
preview iframe):

- Generic, ships with the module: `example_header` in
  [neo_alchemist_examples](web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/example_header/)
  (see its README).
- This site's production header: [web/themes/front/components/header_s1/header_s1.twig](web/themes/front/components/header_s1/header_s1.twig).

Panel widgets like
`menu_feature_card` / `menu_insights` are ordinary theme components — author
them with the **neo-component** skill (they intentionally omit
`spacing`/containment; they size to the region they're dropped into). Because
the same region renders in a wide mega panel on desktop *and* a ~400px slide
menu on mobile, style panel widgets with **container queries** (`@container`
on the root, `@md:`/`@lg:` variants), never viewport breakpoints (`sm:` would
match the desktop viewport even inside the narrow slide menu) — see
`menu_insights` for the pattern (compact thumbnail rows in narrow containers,
stacked two-column cards from `@md`).

## Writing trees programmatically (the format trap)

`ComponentTreeItem::addComponent()/updateComponent()` accept any array, but
shapes only read the **canonical wrapper** — raw `['title' => 'x']` maps are
stored without error and every prop then silently **renders the component's
examples instead**, which looks right with demo data and is deeply misleading:

```php
$item->updateComponent($uuid, [
  'status' => 1,
  'props' => [
    'title' => ['ref' => 'string', 'value' => ['value' => 'Text']],
    'image' => ['ref' => 'image',  'value' => ['src' => '…', 'alt' => '…', 'width' => 480, 'height' => 320]],
    'link'  => ['ref' => 'url',    'value' => ['uri' => 'internal:/', 'title' => 'CTA', 'options' => []]],
    'items' => ['ref' => 'array',  'value' => [/* nested props in the same field structure */]],
  ],
]);
$entity->save();
```

Reference for the stored shape: `components.props` in
[config/neo_alchemist_block.block.header.yml](config/neo_alchemist_block.block.header.yml).

## Gotchas

- **`drush neo:alchemist:render` (even `--live`) renders from the SDC
  `examples`** — ComponentValue providers and region content are only exercised
  on the real site. Verify mega menus by loading actual pages.
- `Component::create()` in PHP requires `'description' => ''` (typed
  non-nullable property; omitting it corrupts the saved config so it fatals on
  the next load).
- The Alchemist editor auto-wiring only detects **config fields**
  (`field.field.*`) of type `neo_component_tree` — never base fields. This
  module's field ships in its `config/install/`.
- Region flag storage means only entity-backed (`menu_link_content`) links can
  be regions; static/plugin links are skipped by design.
