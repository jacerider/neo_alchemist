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
| Field embedding | `src/Plugin/Field/FieldType/ComponentTreeItem` (field type) + `src/Plugin/Field/NeoComponentTreeList` (list class: mode resolution, hybrid merge/strip) + `src/Plugin/Field/{FieldWidget/ComponentTreeWidget,FieldFormatter/ComponentTreeFormatter}` |
| Render | `src/Render/ComponentPageRenderer.php` (`neo_component_page_renderer`) |
| Preview | `src/ComponentPreviewBuilder.php`, `src/Controller/SdcPreviewController.php` |
| SDC thumbnails | `src/SdcThumbnailWriter.php` (`neo_alchemist.sdc_thumbnail_writer`) + `src/Controller/SdcThumbnailCaptureController.php` — writes `thumbnail.png` into the component dir; gated on (`NeoBuild::isDevMode()` **or** the `config_split.config_split.dev` status override) + `is_writable()`. Both signals are soft: `@?neo_build` is an **optional** service reference (neo_alchemist does not declare neo_build, so a hard `@neo_build` breaks container compilation in every Kernel test), and the split is read through the config factory so an absent config_split simply reads as FALSE. |
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

## Aggregate mode (`aggregate: TRUE`)

A `neo_component` can wrap its **whole** props schema in one synthetic object prop named
**`_aggregate`** (`Component::getAggregateSchema()`; toggled by `ComponentAggregateForm`
at `/admin/config/neo/alchemist/{id}/aggregate`). It exists so a listing component whose
every prop comes from the same iterated entity can be bound by **one** children-match
provider instead of the same provider configured on eight props.

Consequences you will trip over while debugging:

- `getPropShapes()` returns exactly one shape (`_aggregate`, an `ObjectShape`); the real
  props are its **children**, configured through the children-match "Shape Fields" UI.
- `settings.props` has a single `_aggregate` key. Per-prop keys are not written —
  `setPropShapeSettings()` refuses any other shape while aggregating.
- The prop route is `/prop/_aggregate`. `_aggregate` is **not** in
  `getComponentSchema()['properties']`, which is why `ComponentPropAccessCheck`
  special-cases the name.
- SDC is unaffected: `getPropValues()` unwraps `$values['_aggregate']`, so the component
  still receives the flat prop set it declared.
- It is the **only** way an `array` prop is reached as a *child* rather than a root — so
  aggregate components are where children-match list-vs-map bugs surface first.

> ⚠ Toggling the flag **discards prop value settings in both directions** (the prop set
> changes → the expression changes → `preSave()` takes its `setSetting('props', [])`
> rebuild branch). No merge, no undo. Pinned by `AggregateModeTest`.

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
- **Exposed filter as data** → `views_filter` prop def + `ViewsExposedFilterValue` (`src/Plugin/ComponentValue/`, `ref_types: ['views_filter']`). **Resolves in `modifyValue()`, never `provideDefaultValue()`** — defaults run at shape init inside `loadPropShapes()`, where the views provider hasn't executed its view yet AND where forcing a shape build recurses fatally; the modify stage runs during each prop's render-value build, in schema order, so a views_filter prop declared after the views-bound prop sees the registered context. It reads that context with `getPropShapeContexts('views', FALSE)` (the non-forcing `$build` param exists precisely for in-pipeline callers) and returns `[]` live / keeps examples in preview when unresolvable. Options come from `$view->exposed_widgets[<identifier>]['#options']` — **unwrapping the `(object) ['option' => [id => label]]` entries hierarchical taxonomy selects use** — with handler `getValueOptions()` as fallback; hierarchy/clean labels from `loadTree($vid)`; URLs hand-built from the raw request (route-free so headless renders degrade to NULL urls instead of throwing). Interaction style (links vs form, auto-submit or Apply) is deliberately NOT config — it is the template's hardcoded design decision. Related hardening: `ArrayShape::buildValue()` skips scalar deltas (an `items: {type: string}` array used to fatal on `unset($values[$delta][$name])`), and `neo:alchemist:validate` lints views_filter prop ordering.
- **Slot render keys / slot templates** → all in `ComponentSlot`. `getKeys()` resolves each plugin's Twig key (configured `key`, else plugin id, `_2`/`_3` on collision, seeded against `RESERVED_KEYS`); `toRenderable()` keys children by it, then wraps them in an `inline_template` when `neo_alchemist.slot_template_locator` finds `slots/<slot>.twig` in the component directory. `prepareChild()` prepends the `<base hook>__<component>__<slot>__<key>` theme suggestions that let a component override one item's internals, and adds the dev-mode HTML annotation. **`toRenderable()` must keep returning `[]` for an empty slot** — `Component::toRenderable()` filters empty slots out so core emits no `{% block %}`, which is what preserves a component's own fallback content. `getItemInfo()` is the single source of truth behind `drush neo:alchemist:slot`.
- **ComponentValue plugin** → `src/Plugin/ComponentValue/*` + `#[ComponentValue]`. **Pick `group:` by role — it is a behavioral contract other code queries, not a form tab**: `providers` = sources a value, `fallback` = fills an empty one (`default`), `modifiers` = transforms an existing one (`prefix`, `token`, `formatted_text`), `settings` = never touches the value, just configures the prop (`widget`, `region_size`, `region_custom`). Mislabelling a non-sourcing plugin as `providers` makes `ChildrenShapeBase::childHasOwnValueProvider()` block the parent's pushdown, so nested props of that type silently render the schema's `examples` instead of authored content. Group **is** the pipeline's primary sort key (`getValueCollection()`: group weight, then the saved drag-and-drop order within the group, then remaining plugins by weight/label) — this is what guarantees `default` runs after every provider — but it is never persisted, so re-grouping needs no update hook. A **producer** also `implements ComponentValueProcessingModeInterface; use ComponentValueProcessingModeTrait;`, appends `processingModeDefaultConfiguration()` to defaults, and calls `buildProcessingModeForm()` in its form — then just produces the value (return the incoming `$value` when it can't act). The pipeline claims per the site-builder **"Processing"** mode (stop-when-found / allow-changes / block-if-empty) + `isProvidedValueEmpty()`; **never call `stopFurtherProcessing()`/`claimValue()` yourself** (vetoes like `user_has_role` are the exception). **An empty return from a non-claiming producer does not overwrite the threaded value** — the pipeline seeds from the schema `examples`, so a producer whose source is empty leaves the component author's example standing rather than blanking the prop. If your producer fills a prop whose examples are scaffolding (lists, menus, trails, placeholder images), override `processingModeDefault()` to `MODE_BLOCK` so empty means empty, as `entity_query`/`menu`/`entity_reference`/`breadcrumb` do. A **modifier** implements `modifyValue()`/`alterValue()` and never claims. Full model → ARCHITECTURE.md "ComponentValue processing model".
- **Per-item data on the `menu` value provider** (badges, mega menu regions, …) → implement `hook_neo_alchemist_menu_value_item_alter()` (documented in `neo_alchemist.api.php`); extra `$entry` keys flow through to twig, `$entry = NULL` drops an item, and cacheability goes through `$shape->addCacheableDependency()`.
- **Drush command** → method on `NeoAlchemistCommands` with `#[CLI\Command]`; inject via `#[Autowire(service:'…')]` (`AutowireTrait`).

## Introspect at runtime instead of reading plugins

Prefer these over grepping the shape/definition code:
`drush neo:alchemist:shapes [name]` · `neo:alchemist:info <id>` ·
`neo:alchemist:components` · `neo:alchemist:validate <id>` ·
`neo:alchemist:render <id> [--live] [--scheme=<id>] [--html]` ·
`neo:alchemist:slot <neo_component id> [<slot>]` (per slot item: Twig key, theme hook,
the template filename that overrides it, and that template's variables).
(Icons/schemes live in their owning modules: `drush neo:icon:list`, `drush neo:color:schemes`.)

## Tests

> **Full guide:** [web/modules/contrib/neo_alchemist/TESTING.md](web/modules/contrib/neo_alchemist/TESTING.md)
> — host-site setup, the fixture module, and how to write a Kernel test. Read it
> before adding tests. This is the fast map.

`ddev phpunit` runs everything; `tests/src/Unit` needs no database and runs in
milliseconds; `--filter=<Class>` for one class.

- **Kernel `$modules` is short.** `enableModules()` does *not* resolve declared
  dependencies, so despite `neo_alchemist.info.yml`, this is the working baseline:
  `['system','user','neo_settings','neo_alchemist']`. **`neo_settings` is
  mandatory** — `neo_alchemist.settings` has `parent: neo_settings.repository`, and
  `neo` ships no services file. The hard floor is just `neo_settings` +
  `neo_alchemist`; `system`/`user` are conventional baseline.
- **`field` is NOT needed**, despite every prop building a field item —
  `plugin.manager.field.*` are declared in `core.services.yml`, not by the `field`
  module. Add it only for real `FieldStorageConfig`/`FieldConfig` entities. Never add
  `neo_build`/`neo_color` speculatively.
- **Fixtures live in `tests/modules/neo_alchemist_test/`**, discovered because core's
  SDC manager scans every enabled module's `components/` dir. It ships a
  **dependency-free provider twin** (`TestProvidedShape` + `TestProviderValue`,
  `group: 'providers'`) that reproduces the `childHasOwnValueProvider()` condition
  without pulling in `media`/`file`/`image`.
- **The fixture's only dependency is `neo_alchemist`, and it omits
  `core_version_requirement` on purpose** — `package: Testing` is exempt, so it tracks
  the running core rather than going silently core-incompatible (and undiscovered)
  when the parent gains a new major. It depends on no core test modules either.
- **Create `neo_component` entities in `setUp()`, not `config/install`** —
  `Component::save()` regenerates `expression`/`schema` from the live SDC, so
  checked-in config drifts. `description` is a non-nullable string and will not
  default from the SDC definition.
- **`Component::save()` re-derives a NEW entity's id** from its SDC id
  (`getUniqueId()`), so the `'id' => …` you passed to `create()` is *not* the id it
  lands on when something already owns that name — a second component on the same SDC
  becomes `<sdc>_2`. Read `$component->id()` back before addressing its config. Writing
  `getEditable('neo_alchemist.neo_component.<assumed id>')` instead mints a config
  object with no `id` key, and the next `$storage->load()` dies with
  `EntityMalformedException: The entity does not have an ID.` — which reads as storage
  corruption, not as a wrong name.
- **Authored values without a host entity:** a config-scope `Component` with
  `setPreview(TRUE)` + `setPreviewValues(['props' => [<prop> => ['ref','value','options']]])`.
  Child option keys are `<prop>~<child>~<delta>`; set `default => 0` so a missing
  value resolves to nothing rather than silently falling back to the schema example.
- **Shape state is memoised per object** — a test comparing two resolutions must
  `resetCache()` and re-`load()`, not reuse one instance.
- **Prove a regression test can fail.** Break the fix by hand, confirm red *with a
  failure message about lost content*, restore, confirm green. For the delta suite,
  `testWarmingDoesNotChangeResolvedValues` must also go red — if it stays green the
  fixture never reached the cache-hit branch and the test is worthless.
- Use PHPUnit **attributes** (`#[Group]`, `#[DataProvider]`), not `@group`.
- **Never run `phpcbf` over files with anonymous classes** — it inserts malformed
  docblocks. Extract to named helper classes instead.

## Dev workflow gotchas

- Edit the **running site contrib copy** (`web/modules/contrib/neo_alchemist/…`); the
  source in `/Projects` is synced separately.
- **Inject services into forms as `protected` non-promoted properties**, never
  `private readonly`. Form objects are serialized into the form cache, and
  `DependencySerializationTrait::__sleep()` swaps services for their IDs using
  `get_object_vars($this)` from `FormBase`'s scope — which cannot see a private
  property declared in your subclass. The service is then serialized whole,
  dragging its object graph (a plugin manager pulls in its cache backend and
  discovery) into every cached form. Controllers are not serialized, so
  `private readonly` is fine there.
- **Composer re-extracts the module.** Any `composer require`/`install`/`update`
  deletes uncommitted work under `web/modules/contrib/neo_alchemist/` — sync to
  `/Projects` first, and run composer *before* starting new work there.
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
