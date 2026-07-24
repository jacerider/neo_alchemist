---
name: neo-alchemist-dev
description: Understand and modify the neo_alchemist MODULE internals (PHP) — the neo_component config entity, the ComponentShape/prop-def plugin system, slots/filters/access plugins, the render pipeline, services, and Drush commands. Use when editing files under web/modules/contrib/neo_alchemist/src, adding a ComponentShape/prop-def/slot/filter/Drush command, or debugging how Alchemist renders/previews a component. NOT for authoring page-building components in a theme (*.component.yml / *.twig) — use the neo-component skill for that.
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Developing the neo_alchemist module

This skill is for working on **neo_alchemist's own code**. If you're building a
component in a theme (`web/themes/*/components/*`), stop — that's the **neo-component**
skill.

> **Authoritative deep reference:** [web/modules/contrib/neo_alchemist/ARCHITECTURE.md](web/modules/contrib/neo_alchemist/ARCHITECTURE.md)
> covers the entity, plugin systems, render pipeline, services, routing, and every
> extension point in full. Read it before non-trivial work. This skill is the fast map.

## Mental model

Alchemist is a thin layer over **Drupal SDC**. A **`neo_component` config entity**
([src/Entity/Component.php](web/modules/contrib/neo_alchemist/src/Entity/Component.php))
wraps an SDC that declares `neo: true` and stores the editor config (props, slots,
filters, access, values) layered on top. `Component::toRenderable()` turns it into a
`#type: component` render array (`#component` / `#props` / `#slots`), adding the
`neoId` / `neoUuid` / `neoIsPreview` props. Two worlds share the entity: **saved**
components and **transient previews** built from the SDC's `examples` via
`neo_alchemist.preview_builder` (`ComponentPreviewBuilder::build($id, $preview)`).

## Where things live (`src/`)

| Area | Location / service |
|---|---|
| Config entity | `src/Entity/Component.php` (type `neo_component`) |
| Prop shapes | `src/Plugin/ComponentShape/` + base `src/ComponentShapePluginBase.php` + `#[ComponentShape]` (`src/Attribute/`) → `plugin.manager.neo_component_shape` |
| Prop-defs (declarative) | `neo_alchemist.neo_component_prop_defs.yml` → `plugin.manager.neo_component_prop_def` |
| Slots / Filters / Access / Value | `src/Plugin/Component{Slot,Filter,Access,Value}/` → matching `plugin.manager.neo_component_*` |
| Field embedding | `src/Plugin/Field/{FieldType/NeoComponentTreeList,FieldWidget/ComponentTreeWidget,FieldFormatter/ComponentTreeFormatter}` |
| Render | `src/Render/ComponentPageRenderer.php` (`neo_component_page_renderer`) |
| Preview | `src/ComponentPreviewBuilder.php`, `src/Controller/SdcPreviewController.php` |
| Drush | `src/Drush/Commands/NeoAlchemistCommands.php`, `src/Drush/Generators/` |
| Services | `neo_alchemist.services.yml` |
| Submodules | `modules/` — `neo_alchemist_block` (config-entity trees as blocks), `neo_alchemist_menu` (mega menu component-region items — see the **neo-alchemist-menu** skill), `neo_alchemist_taxonomy` (per-hierarchy-level term layouts via a `level` third-party setting on tree fields), `neo_alchemist_examples`, `neo_alchemist_library` |

## The shape system (the main extension surface)

A prop `type:` in a `.component.yml` (`heading`, `image`, `scheme`, …) is backed by a
**prop-def** (declarative: schema + `examples` + `twig` in `*.neo_component_prop_defs.yml`)
and usually a **ComponentShape plugin** (PHP: value coercion, field type/widget defaults,
render transform). The `#[ComponentShape(prop: 'string', default_field_type: …, default_field_widget: …, formats: …)]`
attribute keys the plugin by `prop`. Canonical example: `StringShape`. See ARCHITECTURE.md
§"Prop-def + ComponentShape system" for the full field reference.

## Tree fields: locked / custom / hybrid

A `neo_component_tree` field resolves what renders in
[src/Plugin/Field/NeoComponentTreeList.php](web/modules/contrib/neo_alchemist/src/Plugin/Field/NeoComponentTreeList.php)
from the **field default layout** (the `defaults` field setting, edited via Field-UI
Alchemist in config scope) and the per-entity stored value:

- `allow_custom` off — **locked**: the default always renders; entity values are ignored.
- `allow_custom` on — **custom**: a saved entity tree replaces the default wholesale
  (all-or-nothing; site builders lose control after the first entity save).
- `allow_custom` off + a region prop with the **`region_custom`** value plugin enabled
  (`src/Plugin/ComponentValue/RegionCustomValue.php`, no settings — enabled = flagged) —
  **hybrid**: creators manage only the flagged regions' content per entity; the default
  stays authoritative for structure, so header/footer changes propagate to existing
  entities. Entities store just the region subtrees: merge-on-load / strip-on-save in
  `NeoComponentTreeList::setValue()/preSave()/postSave()`, anchors from
  `ComponentFieldConfig::getCustomRegions()/isHybrid()`, inherited instances locked
  server-side in `ComponentInstanceBase::checkHybridAccess()` ("Inherited layout"
  badge). Semantics (seed copy-on-write, explicitly-empty slots, orphan preservation) →
  ARCHITECTURE.md §"Field modes: locked, custom, hybrid".

## Where to add X

- **ComponentShape** → `src/Plugin/ComponentShape/MyShape.php` with `#[ComponentShape(prop:'my_type', …)]` extends `ComponentShapePluginBase`; implement `preRenderValue()` (+ optional `getGenerationExamples()`/`onGenerateTwig()`); add a `my_type:` entry to `neo_alchemist.neo_component_prop_defs.yml`; `drush cr`.
- **Pure prop-def** (no PHP) → add an entry to any `*.neo_component_prop_defs.yml`.
- **Slot / Filter / Access plugin** → class in `src/Plugin/Component*/` with the matching `#[Component*]` attribute; auto-discovered.
- **ComponentValue plugin** → `src/Plugin/ComponentValue/*` + `#[ComponentValue]`. A **producer** (yields a value: entity/query/media/…) also `implements ComponentValueProcessingModeInterface; use ComponentValueProcessingModeTrait;`, appends `processingModeDefaultConfiguration()` to defaults, and calls `buildProcessingModeForm()` in its form — then just produces the value (return the incoming `$value` when it can't act). The pipeline claims per the site-builder **"Processing"** mode (stop-when-found / allow-changes / block-if-empty) + `isProvidedValueEmpty()`; **never call `stopFurtherProcessing()`/`claimValue()` yourself** (vetoes like `user_has_role` are the exception). `default` is a fallback (fills only when empty). A **modifier** implements `modifyValue()`/`alterValue()` and never claims. Full model → ARCHITECTURE.md "ComponentValue processing model".
- **Per-item data on the `menu` value provider** (badges, mega menu regions, …) → implement `hook_neo_alchemist_menu_value_item_alter()` (documented in `neo_alchemist.api.php`); extra `$entry` keys flow through to twig, `$entry = NULL` drops an item, and cacheability goes through `$shape->addCacheableDependency()`.
- **Drush command** → method on `NeoAlchemistCommands` with `#[CLI\Command]`; inject via `#[Autowire(service:'…')]` (`AutowireTrait`).

## Introspect at runtime instead of reading plugins

Prefer these over grepping the shape/definition code:
`drush neo:alchemist:shapes [name]` · `neo:alchemist:info <id>` ·
`neo:alchemist:components` · `neo:alchemist:validate <id>` ·
`neo:alchemist:render <id> [--live] [--scheme=<id>] [--html]`. (Icons/schemes live in
their owning modules: `drush neo:icon:list`, `drush neo:color:schemes`.)

## Dev workflow gotchas

- Edit the **running site contrib copy** (`web/modules/contrib/neo_alchemist/…`); the
  source in `/Projects` is synced separately.
- Run **`drush cr`** after any plugin/attribute/service/prop-def change — discovery is cached.
- Run **`drush neo:build <scope>`** (front and/or back) if you changed Tailwind-scanned output.
- `neoIsPreview` is a prop set in `toRenderable()`; it's TRUE in the editor preview and in
  `neo:alchemist:render` by default, FALSE under `--live`. The CLI render deliberately
  avoids `renderBarePage` (page attachment hooks need an HTTP request/route).
- **`neo:alchemist:render` always renders from the SDC `examples`** — even with `--live`,
  which only flips `neoIsPreview`. ComponentValue providers configured on a saved
  `neo_component` (menu, media, …) run only on the real site; verify provider-driven
  output by loading actual pages.
- Value-provider cacheability: dependencies added during `getPropValue()` (via
  `$shape->addCacheableDependency()`) are merged into the component build **after** the
  value is computed (`Component::getPropValues()`) — never merge shape metadata before
  the providers have run, or their tags are silently lost.
