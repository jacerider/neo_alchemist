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
render transform). Resolution (`ComponentPluginManager::alterProp()` →
`ComponentShapePluginManager::getInstance()`): the prop-def sets `ref: <type>` and merges
its schema over the author's prop, **transitively** (`containment` → `style` → `string`);
then the shape plugin whose id equals `ref` is instantiated, else the plugin for the
resolved JSON type. Consequences: a pure prop-def with no PHP works (`menu`,
`views_summary`, every style prop-def); a shape plugin with no prop-def is a raw JSON type
(`string`, `integer`, `number`, `boolean`, `array`, `object`); an unknown `type:` is
logged and downgraded to `string`, never a fatal.

`#[ComponentShape]` fields: `prop` (the id — what authors write as `type:`), `label`,
`default_field_type` / `default_field_widget` (+ `*_with_options` variants used when the
prop declares an `enum`), `default_plugins` (ComponentValue ids auto-attached — e.g.
`formatted_text` on markup, `media` on the media shapes, `breadcrumb`, `widget` on
scheme), `supports_field_types` / `supports_field_props` (entity-field matching — compared
by **plugin id**, so `timestamp` must be listed even though it subclasses integer),
`formats` (alternate field/widget configs selected with `format:` on the prop),
`provider`, `deriver`. Canonical example: `StringShape`. The load-bearing bases:
`ChildrenShapeBase` (object/array child machinery + `childHasOwnValueProvider()`),
`StructuredObjectShapeBase` (link), `MediaShapeBase` (media/image/file/videos —
`theme://` / `component://` src resolution; previews borrow the newest published media),
`StyleShapeBase`/`StyleShape` (option map → `ComponentShapeStyleAttribute`, whose
`getValue()` is the option **key**), `UrlShapeBase` + `UrlShapeTrait` (url/link). Naming
traps: `ImageSize.php` **is** a shape plugin (`prop: 'image_size'`, a style shape whose
option values are dynamic-style arrays) despite lacking the `*Shape` suffix; there is
**no** `MenuShape` — `menu` is a prop-def filled by the `MenuValue` value plugin. Full
field reference: ARCHITECTURE.md §"Prop-def + ComponentShape system".

## The other four families — inventories and contracts

All are attribute-discovered classes in `src/Plugin/Component{Value,Slot,Filter,Access}/`.
⚠ A plugin **id must equal its group or be prefixed `group:sub`** (attribute docblock — a
discovery quirk, not a convention). `src/Attribute/` also holds `ComponentStyle`,
`ComponentValueProvider` and `ComponentValueModifier`: **inert** — no manager, no plugin
type, zero users anywhere. Never cite or use them; value plugins of every role use
`#[ComponentValue]` with the right `group:`.

### ComponentValue (39 production plugins)

| group | ids |
|---|---|
| `providers` | `entity` · `entity_reference` · `entity_query` · `entity_load` · `entity_filter` · `views` · `views_exposed_filter` · `views_active_filters` · `views_summary` · `menu` · `breadcrumb` · `page_title` · `heading` · `media` · `share` · `read_time` · `event` · `entity_has_value` · `user_has_role` — plus neo_alchemist_taxonomy's `taxonomy_children`/`taxonomy_siblings`/`taxonomy_menu` and neo_site_settings' `site_settings`/`site_settings_field`/`site_settings_links`/`site_settings_fallback_media` |
| `fallback` | `default` (weight 1000 — terminal by construction, never claims) |
| `modifiers` | `prefix` · `suffix` · `token` · `date` · `number` · `link_title` · `link_uri` · `formatted_text` · `media_image_size` |
| `settings` | `widget` · `region_size` · `region_custom` |

`ValueGroupTaxonomyTest` pins the full `id => group` map with `assertSame` — adding,
removing or re-grouping any plugin means updating it in the same change.

Attribute targeting: **`prop_types` filters on the JSON type** (`$shape->getType()`),
**`ref_types` on the prop-def name** (`$shape->getRef()` — `heading`, `media`, `link`…),
`entity_types` on the target entity (`*`, `node.*`, `node.article`); all three take a `!`
prefix for exclusion, and confusing the first two ships a plugin that never appears.
`inline: TRUE` surfaces the plugin on *child* props inside a provider's "Shape Fields" UI;
`allow_on_default` offers it on non-entity-bound shapes; `status_default`/`status_lock`
pre-enable/freeze it. The vetoes (`entity_has_value`, `user_has_role`) are the only
plugins allowed to call `claimValue()` by hand.

Mechanics the "Where to add X" bullet doesn't cover:

- **Modes** (`processing_mode`): `stop_when_found` claims on non-empty; `continue` never
  claims (a later provider can overwrite); `block` claims unconditionally — including on
  empty, which also starves `default` (never pair `block` with a configured Default
  Value). `applyProcessingMode()` has exactly **one** call site — the provider search in
  `getDefaultValue()`; it governs neither `modifyValue()` nor either `alterValue()` pass.
- **Emptiness is `isProvidedValueEmpty()`, not `empty()`**: scalars are empty only when
  `NULL`/`''` (`0`, `'0'`, `FALSE` are values); arrays discount whatever
  `getPresentationalValueKeys()` names — **nothing on the base**; `ImageShape` adds `size`
  (the key `media_image_size` seeds), `HeadingShape` adds `size` + `anchor`. Override that
  method on a shape whose schema always resolves some child regardless of authored input,
  or that child alone keeps the whole value looking non-empty and starves the `fallback`
  plugin. Name the keys on the shape that has them, never on the base — a shared default
  made every prop discount `size`, so an author's object prop whose only child was called
  `size` resolved empty and vanished. Ask the shape that **owns** the value: a parent
  testing a child's value calls `$child->isProvidedValueEmpty()`, not `$this->`.
  `HeadingShape` also
  **collapses its render value to `[]`** when the contract says it is empty, so a template
  can guard the block with a plain `{% if heading %}` — `size` resolves to `md` unasked, so
  without it every heading was truthy and a textless one rendered an empty sized wrapper
  whose spacing still applied (`HeadingEmptyValueTest`).
- After the provider search: a field default can override the result, `alterValue($value,
  'default')` runs on a **fresh** instance list (a claim can't truncate it), and a
  required-but-empty result reverts to the schema-example seed so SDC never sees a
  missing required prop.
- `default` fills only when the value is *the untouched schema example* or empty — a
  builder's default supersedes the author's placeholder but never a provider's value.
- `provideOverrideValue()` carries authored content; **no shipped plugin implements it**,
  and `ComponentValueProcessingModeScopeTest` fails if one appears.
- A plugin that stores configuration must declare
  `neo_alchemist.neo_component_value.<id>` in `config/schema/neo_alchemist.schema.yml`
  (the `.*` fallback is a keyless mapping — every stored key is unschema'd without an
  entry). The nested/inline path never runs your validate/submit handlers — coerce in an
  `#element_validate` on the element, re-listing the element's default validator
  (`#element_validate` replaces, not appends).
- Lifecycle: `onShapeInit()` (field type / widget / sizes setup) at shape init;
  `onAdd()/onUpdate()/onRemove()` fire from `Component::preSave()/preDelete()` —
  `MediaValue::onRemove()` deletes the `neo_config_file` behind a config-hosted image.

### ComponentSlot (12 plugins)

`block` · `block_plugin` · `entity` · `entity_display` · `entity_field` ·
`entity_query_pager` · `form` · `product_variation_field` · `views` · `views_header` ·
`views_pager` · `views_exposed_filters`. Stored as
`settings.slots.<slot>.plugins.<uuid> = {plugin, key?, settings}`. Availability is
PHP-side only (the info.yml declares none of views/block/commerce): static
`isApplicable(ComponentInterface)` gates `entity_display`/`entity_field` (needs a target
entity type), `entity_query_pager` (`hasPropShapeWithPlugin('entity_query')`), the
`ViewsSlotBase` three (`hasPropShapeWithPlugin('views')` — they consume the executed-view
context the `views` **value** provider registers, via `getPropShapeContexts('views')`),
and `product_variation_field` (`commerce_product` target; the class simply fails to load
without commerce). Cacheability trap: `getCacheableMetadata()` returns the component's
**shared** metadata object — always `mergeCacheMaxAge()`, never set (a permissive view
would raise a max-age someone else lowered to 0), and call
`addViewAsCacheableDependency()` once per render, not per item (it walks Search API
results on every call).

### ComponentFilter (typed per-placement parameters, not visibility)

`string` · `number` · `entity` · `options:<set>` (one derivative per set declared in a
`MODULE.neo_component_filter_options.yml` — schema `<set id>: {title, options: {key:
label}}`; no sets ship enabled on purpose, so the family is empty until a module declares
one). A filter is
`settings.filters.<uuid>` = title/description/plugin/default value/`editable`/`required`;
per-instance overrides land via `Component::getFilters()` → `setOverrideValue()`, and
consumers read `getProcessedValue()` (= `$plugin->getValue($override ?? $default)`).
Values persist as **strings** — `entity` multi-values join with `,`/`+` (the Views
argument convention) and `EntityFilter` keeps its preview entity in **state**
(`neo_alchemist.<id>.filter.<uuid>.preview_entity`), not config. Consumers: `ViewsSlot`
contextual args, `FormSlot` args, `EntitySlot` dynamic pick, `EntityFilterValue`, and
`EntityQueryValue`'s `length_filter`.

### ComponentAccess (deny-only)

`role` · `permission` · `protected` · `prop_value` · `entity_field_value`. Stored as
`settings.access.<uuid> = {plugin_id, plugin_settings}`. `Component::checkAccess($op)`
iterates instances and short-circuits on the **first forbidden**, else neutral — plugins
can deny, never grant. Ops: `view` (frontend render — captured in `ComponentTreeHydrated`
and `RegionShape`), `update` / `create` (backend management). Admin bypass is
`administer neo_alchemist` + `$plugin->bypassAdminAccess($op)`; `prop_value` returns
FALSE for `view` **on purpose** (an empty component hides for admins too), resolves the
whole value pipeline to test the props, and attaches `$component->getCacheableMetadata()`
so provider list tags invalidate the decision. Cacheability of consulted plugins is
folded manually because core's neutral∧neutral `orIf()` drops the second operand's
cacheability. Access plugins support static `isApplicable(ComponentInterface)` like
slots (`getFilteredDefinitionsFromComponent()` filters the Add Access picker) —
`entity_field_value` uses it to appear only on entity-bound components; a saved rule
whose plugin later turns non-applicable still executes, so plugins must degrade to
neutral in that case. `entity_field_value` is the whole-component counterpart of the
`entity_has_value` value veto: forbids `view` unless every selected target-entity field
is non-empty (neutral on new/placeholder entities so builder previews stay visible;
admins do NOT bypass, mirroring `prop_value`). ⚠ Field emptiness anywhere in the module
is `FieldItemList::isEmpty()`, never truthiness of `getValue()` — an empty
text-with-format field returns a phantom `[{value: NULL, format: NULL}]` item that
reads as "has a value" (`entity_has_value` had exactly this bug).

## Aggregate mode (`aggregate: TRUE`)

A `neo_component` can wrap its **whole** props schema in one synthetic object prop named
**`_aggregate`** (`Component::getAggregateSchema()`; toggled by `ComponentAggregateForm`
at `/admin/config/neo/alchemist/{id}/aggregate`). It exists so a listing component whose
every prop comes from the same iterated entity can be bound by **one** children-match
provider instead of the same provider configured on eight props.

Consequences you will trip over while debugging:

- `getPropShapes()` returns exactly one shape (`_aggregate`, an `ObjectShape`); the real
  props are its **children**, configured through the children-match mapping (a
  Property → Source table; the stored key is still `shape_fields`).
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
- **Exposed filter as data** → `views_filter` prop def + `ViewsExposedFilterValue`, and `views_active_filters` + `ViewsActiveFiltersValue` (removable chips), both extending `ViewsExposedFilterValueBase` (`src/Plugin/ComponentValue/` — shared context read, config-time filter listing, request URL builder, option normalization). At render each prop value is a helper object (`src/ViewsFilterTwig.php` / `src/ViewsActiveFiltersTwig.php`): ArrayAccess for data + `get*()` attribute methods (the Twig sandbox only allows get/has/is-prefixed method calls on objects — same constraint as SwiperTwig). The objects are `JsonSerializable` so SDC prop validation sees the wrapped data — core's class-typed-prop support is unusable with mixed `[object, FQCN]` types (it nullifies the value but keeps `object` required). Wrapping happens in BOTH the shape (`ViewsFilterShape`/`ViewsActiveFiltersShape` `preRenderValue()` — covers example/preview data) and the provider's `modifyValue()` (covers resolved data; preRender runs before modify). **Resolves in `modifyValue()`, never `provideDefaultValue()`** — defaults run at shape init inside `loadPropShapes()`, where the views provider hasn't executed its view yet AND where forcing a shape build recurses fatally; the modify stage runs during each prop's render-value build, in schema order, so a views_filter prop declared after the views-bound prop sees the registered context. It reads that context with `getPropShapeContexts('views', FALSE)` (the non-forcing `$build` param exists precisely for in-pipeline callers) and returns `[]` live / keeps examples in preview when unresolvable. Options come from `$view->exposed_widgets[<identifier>]['#options']` — **unwrapping the `(object) ['option' => [id => label]]` entries hierarchical taxonomy selects use** — with handler `getValueOptions()` as fallback; hierarchy/clean labels from `loadTree($vid)`; URLs hand-built from the raw request (route-free so headless renders degrade to NULL urls instead of throwing). Interaction style (links vs form, auto-submit or Apply) is deliberately NOT config — it is the template's hardcoded design decision. AJAX swapping is the `neo_alchemist/swap` library (`src/js/neo-swap.ts`, front scope): one document-level delegated click/submit listener (bound via `once` on html, so it survives swaps); same-origin same-path URLs inside a `[data-neo-swap][data-neo-uuid]` boundary are fetched and the matching subtree swapped (`detachBehaviors` → `replaceWith` → `attachBehaviors`; Alpine self-heals via its observer); pushState/popstate for history; ANY pipeline failure falls back to `location.assign` — but post-swap decoration (focus restore, aria-live announce, scroll) is isolated in its own try/catch so a cosmetic error can never trigger the navigation fallback (focus restore also excludes `[type="hidden"]`: sibling mini-forms carry same-named hidden inputs). Related hardening: `ArrayShape::buildValue()` skips scalar deltas (an `items: {type: string}` array used to fatal on `unset($values[$delta][$name])`), and `neo:alchemist:validate` lints views_filter prop ordering.
- **Slot render keys / slot templates** → all in `ComponentSlot`. `getKeys()` resolves each plugin's Twig key (configured `key`, else plugin id, `_2`/`_3` on collision, seeded against `RESERVED_KEYS`); `toRenderable()` keys children by it, then wraps them in an `inline_template` when `neo_alchemist.slot_template_locator` finds `slots/<slot>.twig` in the component directory. `prepareChild()` prepends the `<base hook>__<component>__<slot>__<key>` theme suggestions that let a component override one item's internals, and adds the dev-mode HTML annotation. **`toRenderable()` must keep returning `[]` for an empty slot** — `Component::toRenderable()` filters empty slots out so core emits no `{% block %}`, which is what preserves a component's own fallback content. `getItemInfo()` is the single source of truth behind `drush neo:alchemist:slot`.
- **ComponentValue plugin** → `src/Plugin/ComponentValue/*` + `#[ComponentValue]`. **Pick `group:` by role — it is a behavioral contract other code queries, not a form tab**: `providers` = sources a value, `fallback` = fills an empty one (`default`), `modifiers` = transforms an existing one (`prefix`, `token`, `formatted_text`), `settings` = never touches the value, just configures the prop (`widget`, `region_size`, `region_custom`). Mislabelling a non-sourcing plugin as `providers` makes `ChildrenShapeBase::childHasOwnValueProvider()` block the parent's pushdown, so nested props of that type silently render the schema's `examples` instead of authored content. Group **is** the pipeline's primary sort key (`getValueCollection()`: group weight, then the saved drag-and-drop order within the group, then remaining plugins by weight/label) — this is what guarantees `default` runs after every provider — but it is never persisted, so re-grouping needs no update hook. A **producer** also `implements ComponentValueProcessingModeInterface; use ComponentValueProcessingModeTrait;`, appends `processingModeDefaultConfiguration()` to defaults, and calls `buildProcessingModeForm()` in its form — then just produces the value (return the incoming `$value` when it can't act). The pipeline claims per the site-builder **"Processing"** mode (stop-when-found / allow-changes / block-if-empty) + `isProvidedValueEmpty()`; **never call `stopFurtherProcessing()`/`claimValue()` yourself** (vetoes like `user_has_role` are the exception). **An empty return from a non-claiming producer does not overwrite the threaded value** — the pipeline seeds from the schema `examples`, so a producer whose source is empty leaves the component author's example standing rather than blanking the prop. If your producer fills a prop whose examples are scaffolding (lists, menus, trails, placeholder images) — or names a bound source that must not fall back to placeholders — override `processingModeDefault()` to `MODE_BLOCK` so empty means empty, as `entity`/`entity_query`/`menu`/`entity_reference`/`breadcrumb` do (`ComponentValueProcessingModeIntegrationTest` pins the set). A **modifier** implements `modifyValue()`/`alterValue()` and never claims. Full model → ARCHITECTURE.md "ComponentValue processing model".
- **Per-item data on the `menu` value provider** (badges, mega menu regions, …) → implement `hook_neo_alchemist_menu_value_item_alter()` (documented in `neo_alchemist.api.php`); extra `$entry` keys flow through to twig, `$entry = NULL` drops an item, and cacheability goes through `$shape->addCacheableDependency()`.
- **Drush command** → method on `NeoAlchemistCommands` with `#[CLI\Command]`; inject via `#[Autowire(service:'…')]` (`AutowireTrait`).

## Introspect at runtime instead of reading plugins

Prefer these over grepping the shape/definition code:
`drush neo:alchemist:shapes [name]` · `neo:alchemist:info <id>` ·
`neo:alchemist:components` · `neo:alchemist:validate <id>` ·
`neo:alchemist:render <id> [--live] [--scheme=<id>] [--html]` ·
`neo:alchemist:slot <neo_component id> [<slot>]` (per slot item: Twig key, theme hook,
the template filename that overrides it, and that template's variables) ·
`neo:alchemist:views-page <view>:<display> [--component=<id>]` (hand a views page to an
Alchemist-owned node: creates the node + alias, seeds the tree, removes the page display,
warns about mini pagers / uncacheable cache plugins / dangling quick-search links —
`Drush/Commands/NeoAlchemistViewsPageCommands.php`).
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
  which only flips `neoIsPreview`. The preview builder creates a **fresh unsaved**
  `neo_component`, so nothing a site builder configured on the saved entity runs; verify
  provider-driven output (menu, entity, views, …) by loading actual pages. What *does*
  run there is shape-level `default_plugins` — props whose shape declares one
  (breadcrumb, image, media, markup, scheme, file, local and remote video) still resolve
  through that provider, so say that rather than "nothing resolves on the CLI".
- Value-provider cacheability: dependencies added during `getPropValue()` (via
  `$shape->addCacheableDependency()`) are merged into the component build **after** the
  value is computed (`Component::getPropValues()`) — never merge shape metadata before
  the providers have run, or their tags are silently lost.
