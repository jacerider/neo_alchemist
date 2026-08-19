# neo_alchemist — Architecture

Developer reference for the **neo_alchemist module internals** (its PHP). If you are
authoring page-building components in a theme (`*.component.yml` / `*.twig`), that is a
different job — see the `neo-component` skill and [STYLING.md](STYLING.md). This document
is about extending and maintaining the module itself.

Paths below are relative to this module directory
(`web/modules/contrib/neo_alchemist/`).

---

## Mental model

Alchemist is a thin, editor-facing layer over **Drupal SDC** (Single Directory
Components). An SDC is a folder with a `.component.yml` + `.twig` (id = `provider:machine_name`,
e.g. `front:cards_test`). Alchemist manages any SDC that declares `neo: true`.

A **`neo_component` config entity** wraps one SDC and stores the editor configuration
layered on top of it: which props are active/editable, their field types & widgets,
slots, value transforms, filters, and access rules. At render time the entity turns the
SDC + its configured/preview values into a render array.

```
SDC (.component.yml + .twig, neo:true)
        │  wrapped by
        ▼
neo_component config entity  ──►  toRenderable()  ──►  #type: component  ──►  Twig
   (props · slots · filters · access · values)
```

Two "worlds" use the same entity:
- **Saved components** — a real `neo_component` config entity, rendered on pages.
- **Transient previews** — an unsaved entity built on the fly from the SDC's `examples`,
  used by the editor preview iframe and the `neo:alchemist:render` Drush command
  (see [ComponentPreviewBuilder](src/ComponentPreviewBuilder.php)).

---

## The `neo_component` entity

Config entity type id: **`neo_component`**. Class: [src/Entity/Component.php](src/Entity/Component.php)
(implements `ComponentInterface`). Key methods:

| Method | Purpose |
|---|---|
| `toRenderable($isFirst, $isLast)` | Build the render array (see [Render pipeline](#render-pipeline)). |
| `getPropValues()` | Iterate the active shapes, call `getPropValue()` on each, assemble the `#props` array (adds `attributes`). |
| `getPropShapes()` | The `ComponentShapePluginInterface[]` for every prop, parsed from the SDC schema. |
| `getComponentDefinition()` | The SDC plugin definition (from `plugin.manager.sdc`) for this component. |
| `getComponentSchema()` | The `schema` (props) block from the SDC metadata. |
| `getComponentSlots()` / `getSlots()` | The SDC slots / the resolved `ComponentSlot` instances. |
| `setPreview(bool)` / `isPreview()` | Toggle/read preview mode — drives `neoIsPreview` and preview-only admin UI. |
| `getPreviewContext()` / `setPreviewContext($above,$below)` | Neighbor components to render around this one in the preview (cache-backed). |
| `getPreviewValues()` / `setPreviewValues($v)` | Cache-backed prop overrides used while editing in the preview workspace. |

**Transient preview entity** — do not `create()` by hand; use the service:

```php
/** @var \Drupal\neo_alchemist\ComponentInterface|null $entity */
$entity = \Drupal::service('neo_alchemist.preview_builder')->build('front:cards_test', $preview = TRUE);
// id = 'sdc_preview_<crc32b>', status = TRUE, preview flag = $preview,
// props seeded from the SDC's `examples`. Returns NULL if the SDC is missing or not neo:true.
```

`build()` takes a `$preview` flag: `TRUE` (default) exposes `neoIsPreview` and preview
behavior; `FALSE` renders the runtime path. Both the preview controller and
`neo:alchemist:render --live` route through this one method.

### Aggregate mode

A component can set `aggregate: TRUE` (the "Enable Aggregation" action on the manage
form, [ComponentAggregateForm](src/Form/ComponentAggregateForm.php), route
`/admin/config/neo/alchemist/{neo_component}/aggregate`). It exists for the case where
every prop should be filled from **one** source — a listing component whose heading,
image, link and body all come from the same iterated node — instead of attaching the same
`entity_query`/`views` provider to eight props and keeping eight copies of its
configuration in sync.

The whole props schema is wrapped in one synthetic object prop named `_aggregate`
(`getAggregateSchema()`), so:

| | Normal | Aggregate |
|---|---|---|
| `getPropShapes()` | one shape per schema prop | exactly one: `_aggregate` (an `ObjectShape`) |
| `settings.props` keys | `heading`, `image`, … | `_aggregate` only |
| Prop config route | `/prop/heading` | `/prop/_aggregate` |
| What the SDC receives | unchanged | unchanged — `getPropValues()` unwraps `$values['_aggregate']` |

The real props become **children** of that object, which is why they are configured
through the children-match "Shape Fields" UI (`_expand`, `_reference~…`, `_raw:*`, …)
rather than each having its own prop form. It is also the only way an `array` prop is
reached as a *child* rather than as a root — the case the iterability contract above is
about.

Three places special-case the name:

- `Component::getPropValues()` unwraps `_aggregate` so SDC still gets the flat prop set.
- `Component::setPropShapeSettings()` refuses to persist any shape other than `_aggregate`
  while aggregating, so a stray save cannot reintroduce per-prop settings.
- [ComponentPropAccessCheck](src/Access/ComponentPropAccessCheck.php) resolves `_aggregate`
  directly, because it is not a member of `getComponentSchema()['properties']` and the
  normal lookup would 404 the prop form.

> ⚠ **Toggling aggregation discards prop value settings, in both directions.** Flipping the
> flag changes the prop set, so the generated expression changes, so `preSave()` takes its
> `setSetting('props', [])` rebuild branch. Enabling drops every per-prop provider
> configuration; disabling drops the whole `_aggregate` configuration and rebuilds the
> per-prop keys from schema defaults. There is no merge and no undo — the confirm form
> warns, and `AggregateModeTest` pins the behavior so a future change to it is deliberate.

---

## Prop-def + ComponentShape system

This is the core extension surface: how a prop `type` in a `.component.yml` (e.g.
`type: heading`, `type: image`, `type: scheme`) becomes an editor field + render value.

Two layers:

1. **Prop-defs** — declarative shape definitions in `*.neo_component_prop_defs.yml`
   (this module's is [neo_alchemist.neo_component_prop_defs.yml](neo_alchemist.neo_component_prop_defs.yml)).
   Each entry carries `title`, `type` (JSON-schema type), `properties`, `required`,
   `examples`, and a `twig` render pattern (`prefix`/`content`/`suffix` using `%name%`).
   Discovered by [ComponentPropDefPluginManager](src/ComponentPropDefPluginManager.php)
   (`plugin.manager.neo_component_prop_def`).

2. **ComponentShape plugins** — the PHP behind a prop type: value coercion, field
   type/widget defaults, and render transforms. Base class
   [ComponentShapePluginBase](src/ComponentShapePluginBase.php); plugins live in
   [src/Plugin/ComponentShape/](src/Plugin/ComponentShape/) (30+: `StringShape`,
   `HeadingShape`, `ImageShape`, `LinkShape`, `RegionShape`, `SchemeShape`,
   `StyleShape`, …; note `menu` has no shape class — it is a prop-def filled by the
   `MenuValue` value plugin, and `ImageSize.php` is the `image_size` shape despite
   lacking the `*Shape` suffix). Discovered by [ComponentShapePluginManager](src/ComponentShapePluginManager.php)
   (`plugin.manager.neo_component_shape`); `getInstancesFromSchema()` builds the shape
   tree for a component.

The `#[ComponentShape]` attribute ([src/Attribute/ComponentShape.php](src/Attribute/ComponentShape.php))
— id is `prop`:

| Field | Meaning |
|---|---|
| `prop` | The shape id / the `type:` value authors write (e.g. `string`). |
| `label` | Human name (`TranslatableMarkup`). |
| `default_field_type` / `_with_options` | Drupal field type when Alchemist stores this prop (the `_with_options` variant is used when the prop is an enum, e.g. `list_string`). |
| `default_field_widget` / `_with_options` | Edit widget (e.g. `string_textfield` / `options_select`). |
| `supports_field_types`, `supports_field_props` | Which Drupal field types/props this shape can map from. |
| `formats` | Alternate configs keyed by name (e.g. `textarea` → `string_long`/`string_textarea`). |

Canonical example — [src/Plugin/ComponentShape/StringShape.php](src/Plugin/ComponentShape/StringShape.php):

```php
#[ComponentShape(
  prop: 'string',
  label: new TranslatableMarkup('String'),
  default_field_type: 'string',
  default_field_type_with_options: 'list_string',
  default_field_widget: 'string_textfield',
  default_field_widget_with_options: 'options_select',
  formats: ['textarea' => ['default_field_type' => 'string_long', 'default_field_widget' => 'string_textarea']],
)]
class StringShape extends ComponentShapePluginBase {
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed { … } // stored → render value
  public static function getGenerationExamples(array $prop) { … }                     // Drush scaffolding defaults
  public static function onGenerateTwig(NeoComponentTwig $twig) { … }                 // suggested twig on generate
}
```

To **inspect** shapes at runtime without reading the plugins, use
`drush neo:alchemist:shapes [name]` (see [Drush surface](#drush-surface)).

---

## Plugin families

| Family | Dir | Manager service | Purpose |
|---|---|---|---|
| ComponentShape | `src/Plugin/ComponentShape/` | `plugin.manager.neo_component_shape` | Map a prop `type` → field + render value (the shape system above). |
| ComponentSlot | `src/Plugin/ComponentSlot/` | `plugin.manager.neo_component_slot` | Fill a named SDC slot from a source (`EntitySlot`, `ViewsSlot`, `BlockSlot`, `FormSlot`, `EntityFieldSlot`, …). |
| ComponentValue | `src/Plugin/ComponentValue/` | `plugin.manager.neo_component_value` | Provide or transform a prop value — producers (`entity_reference`, `entity_query`, `media`, `default`, …) and modifiers (`prefix`/`suffix`/`format`/`size`). Run in a claim-based pipeline; see "ComponentValue processing model". |
| ComponentFilter | `src/Plugin/ComponentFilter/` | `plugin.manager.neo_component_filter` | Conditionally include/adjust components (`StringFilter`, `OptionFilter`, `EntityFilter`, `NumberFilter`). |
| ComponentAccess | `src/Plugin/ComponentAccess/` | `plugin.manager.neo_component_access` | Access/visibility rules for a component or prop. |

Supporting managers: `plugin.manager.neo_component_group`, `…_value_group`, `…_size`,
`…_filter_options`. Each family also has a `#[Component*]` attribute in
[src/Attribute/](src/Attribute/) and (for slot/filter/access) a small factory service
(`neo_component.slot.factory`, `.filter.factory`, `.access.factory`).

### ComponentValue processing model

A prop's value is built by running its enabled ComponentValue plugins across phases:
**provide** (`provideDefaultValue`) → **alter** (`alterValue`, default + override passes)
→ **modify** at render (`modifyValue`), driven by
`ComponentShapePluginBase::getDefaultValue()` / `buildRenderValue()`.

**Groups declare a plugin's role**, and are the source of truth other code queries — they are
not just prop-form tabs. Defined in
[neo_alchemist.neo_component_value_groups.yml](neo_alchemist.neo_component_value_groups.yml):

| Group | Role | Members |
| --- | --- | --- |
| `providers` | **source** a value | `entity*`, `media`, `menu`, `views`, `heading`, `breadcrumb`, `page_title`, `event`, the vetoes, `site_settings_*`, `taxonomy_*` |
| `fallback` | **fill** it when nothing sourced one | `default` |
| `modifiers` | **transform** it | `prefix`, `suffix`, `token`, `number`, `link_*`, `media_image_size`, `formatted_text` |
| `settings` | **don't touch it** — configure the prop | `widget`, `region_size`, `region_custom` |

Ask a shape a behavioral question with `getValueCollection()->getActiveInstances($groupId)`
rather than by naming plugin ids — `getActiveInstances('providers')` answers "does this shape
source its own value?", which is exactly how `ChildrenShapeBase::childHasOwnValueProvider()`
decides whether a parent may push its value down into a child. **A new plugin picks its group by
role**; putting a non-sourcing plugin in `providers` silently makes nested props of that type
discard authored content in favour of the schema's `examples`.

**Group is the primary sort key of the pipeline.** `ComponentShapePluginBase::getValueCollection()`
orders plugins by the group's own `weight` (`providers` -5 → `fallback` -3 → `modifiers` 0 →
`settings` 5) and only then within each group: the site builder's saved drag-and-drop order first
(that is the only order the prop form can express — each group lists its active plugins as one
draggable list), followed by the remaining available plugins in definition order (plugin
`weight`, then label).

**The prop form** (`ComponentPropForm`) is a list↔edit state machine in the mold of
`ComponentSlotForm`: one vertical tab per plugin-bearing shape (the prop, then each expanded
child), the four value groups stacked inside as badged sections, and — for multi-plugin groups —
only the ACTIVE plugins listed as summary rows (`settingsSummary()` + the processing-mode badge)
with an *Add provider* select for the rest; one plugin's settings form is open at a time.
Single-plugin groups (`fallback`'s `default`, `settings`' `widget`) stay inline. All mutations
run through `validateForm()` and are staged on the form object's own (cached, unsaved) entity via
`setPropShapeSettings()` — nothing persists until Save, and a status message says so once the
staged settings diverge. One sharp edge lives in the limited-validation detection:
`Button::getInfo()` defaults `#limit_validation_errors` to FALSE, so "is this a limited
submission" must test for an *array* — a presence check classifies every button (Update and Save
included) as limited and silently skips the commit path
(`ComponentPropFormUxTest::testUpdateTriggerCommitsTheOpenPane` pins this). So
re-grouping a plugin *does* move it in the pipeline, and `default` — group `fallback`, weight
1000 — is guaranteed to run after every provider no matter which one the site builder enabled
first. Ordering the saved plugins flat instead is what let a `fallback` run ahead of a
`providers` plugin, whereupon the provider overwrote the default it had just supplied with its
own empty result and the configured default silently vanished. Group is still **never
persisted**: stored settings are keyed by plugin id (`plugins.<shapeId>.<pluginId>`) and the
order is recomputed on load, so changing a plugin's group needs no update hook.

The site builder picks each producer's behavior via a standard **"Processing"** select:

- **Stop when found** (`MODE_STOP_WHEN_FOUND`, default) — a non-empty value is
  authoritative (later producers skip); an empty result falls through to the next.
- **Provide, allow changes** (`MODE_CONTINUE`) — never claims; the value survives and
  later producers may replace it.
- **Block if empty** (`MODE_BLOCK`) — claim always, so an empty result renders nothing
  and no later producer runs.

**A producer that comes up empty without claiming does not destroy the value threaded
into it.** The pipeline seeds each prop from its schema `examples` and threads that seed
through the producer search, so "falls through to the next" means the seed (or an earlier
producer's answer) is still in hand when the search ends: attaching a provider to a prop
cannot leave it worse off than never having attached one. That is why `default`
(`fallback`) tests for *the untouched example* rather than only for emptiness — it is
routinely handed the seed. The counterweight is the claim: **block** and the vetoes exist
to say "nothing IS the answer". Choose block for any prop whose examples are editor
scaffolding — placeholder cards, placehold.co images, invented menu links — rather than a
usable default; it is already the shipped default for the producers that fill those props
(`entity_query`, `entity_filter`, `views`, `menu`, `taxonomy_menu`, `taxonomy_children`,
`taxonomy_siblings`, `entity_reference`, `breadcrumb`), and
`neo_alchemist_update_11003()` migrated existing components onto it.

Modifiers always run afterward regardless. Mechanics:

- **Claim** = halt the producer search. `ComponentValuePluginInterface::claimValue()`
  (semantic alias of `stopFurtherProcessing()`; check with `hasClaimedValue()`).
- The claim decision is centralized: a producer `implements
  ComponentValueProcessingModeInterface` + `use ComponentValueProcessingModeTrait`, and
  the pipeline calls `applyProcessingMode($value)`, claiming per the chosen mode +
  `ComponentShapePluginInterface::isProvidedValueEmpty()` (shared "found vs empty" test:
  an array is empty once the `size` sentinel seeded by `media_image_size` is discounted;
  a scalar is empty only when `NULL` or `''`, so a legitimate `0`/`'0'`/`FALSE` still
  counts as found). Producers no longer hard-code the claim.
- **`default`** (weight 1000, group `fallback`) only fills when the value is still
  empty *or is still the untouched schema example*, so a non-claiming producer's value
  survives to render while a site builder's configured default still supersedes the
  component author's placeholder. Note that blocking claims before it runs: a prop with a
  configured Default Value must not put its producer on block, or the default never gets
  a turn.
- Three kinds of producer claim for themselves, and they are the whole list. **Veto**
  producers (`user_has_role`, `entity_has_value`) opt out of the mode; they
  `claimValue()` explicitly and return `FALSE`. **`default`** claims once it has filled,
  so clearing a configured default cannot fall back to the component author's example.
  **`event`** does use the mode, and additionally raises the claim when a subscriber
  called `$event->stopFurtherProcessing()` — a veto from code that has already seen the
  value outranks the mode a site builder picked in the form.

**Entity-field matching** — the `entity` producer sources a prop straight from an entity
field via `ComponentValueMatchTrait`. Its `field` select takes a matcher key (a dotted
reference path, plus the `_render` pseudo-field for markup props), and an optional
`field_fallback` names a second field consulted **only when the first resolves to
nothing**. Emptiness is `ComponentShapePluginBase::isProvidedValueEmpty()`, not
`empty()`, so a legitimate `0`/`'0'`/`FALSE` does not fall through. The fallback derives
its own child-property mapping from its own field definition — a manual
`field_properties` map describes the primary field's properties and would misread a
different one — and `_render` is deliberately not offered for it, since it is a
rendering mode carrying its own formatter config rather than a field. When both fields
are empty the primary's value is returned, so the pipeline sees exactly the emptiness it
would have without a fallback configured.

**Children-match pseudo-fields** — producers that map entity fields onto child shapes
(`entity_reference`, `entity_query`, `entity_load`, `entity_filter`, `views`, …) share
`ComponentValueChildrenMatchTrait`. `entity_reference` serves array **and**
object/aggregate shapes (an object takes the first published referenced entity); with
that, the canonical **primary-source-with-fallback recipe** works on an aggregated
component exactly as it long has on list props: `entity_reference` (mode
`stop_when_found`) ordered above `entity_query` (mode `block`) — a filled reference
claims, an empty one falls through to the query, and an empty query claims emptiness so
schema examples never leak. The trait renders the mapping as a table — one Property →
Source row per child, explicit `#parents` keeping the stored `shape_fields` tree exactly
as before the layout — with the rarely-used controls (`shape_published`, the "Copy field
mapping from" convenience that clones a sibling provider's mapping) behind a collapsed
**Advanced** section, their `#parents` likewise unmoved. Its per-child source select offers, besides real
fields, `_`-prefixed pseudo-fields dispatched to `fetchChildrenMatchValues<Name>()`:
`_default` (use the child's default), `_event` (ComponentValueEvent), `_expand`
(configure grandchild shapes), `_reference~<key>` (follow a reference and recurse),
`_render` (render a field with a formatter), `_raw:*` (literal boolean/string), and
`_self` (use the **iterated entity itself** as a media-shape child's value — offered
when iterating media entities, e.g. a media reference field as the iteration source;
bundle support is checked strictly at fetch time and the value comes from the shape's
`getValueFromMedia()`). The trait honors the "Only use published entities" setting by
skipping unpublished iterated entities. Handlers resolve child shapes via
`getValueResolverShape()` — never `getChildShapes()`, which routes through
`getDefaultValue()` and recurses when called mid-pipeline.

Each level of that recursion builds either a **delta-keyed list** (an `array` shape) or a
**flat property map** (anything else), and picks between them from the shape it is
filling — not from the `$shape` argument, which stays the ROOT children-match shape all
the way down because the child-state calls are keyed by a chained shape id the root owns.
`fetchChildrenMatchValues()` therefore takes an explicit `$iterable`, and `_expand` /
`_reference` pass the *child's* iterability (`isChildMatchShapeIterable()`). Getting this
from the root collapses an array child to the property map of its first item — which
`ArrayShape` cannot read at all, since it keeps integer deltas only.

**Field integration** — components can be embedded in content entities via a field:
[src/Plugin/Field/FieldType/ComponentTreeItem.php](src/Plugin/Field/FieldType/) (the field
type/storage) with its list class
[src/Plugin/Field/NeoComponentTreeList.php](src/Plugin/Field/) (mode resolution + hybrid
merge/strip) + `ComponentTreeWidget` (edit) + `ComponentTreeFormatter` (render), backed by
the `ComponentTreeStructure` data type. This is how a node/paragraph holds a tree of
components.

### Field modes: locked, custom, hybrid

A `neo_component_tree` field resolves its rendered tree in
[src/Plugin/Field/NeoComponentTreeList.php](src/Plugin/Field/NeoComponentTreeList.php)
from two sources: the **field default layout** (the `defaults` field setting, edited via
the Field-UI Alchemist routes in config scope) and the **per-entity stored value**.

- **Locked** (`allow_custom` off): the stored entity value is ignored; the field default
  always renders. Nothing is persisted on insert either — otherwise the constructor's
  default seed would be written into every row, where anything reading the column
  directly (the component usage report) reads it as authored content. On *update* a
  locked field still writes nothing at all, which is what keeps content authored while
  the field was hybrid alive across a trip through locked mode.

  Rows written **before** the field was locked stay put — turning `allow_custom` off is
  silent, and nothing migrates the data. Those layouts stop rendering but remain
  recoverable: turning customization back on renders them again.
  [src/InertComponentData.php](src/InertComponentData.php) reports them (a "Stored but
  not rendered" section on the component usage page, never counted as usage) and purges
  them per field, from the field's Layout page. It only removes rows whose tree **root
  is populated**; an empty root is a hybrid storage subset, which re-flagging a region
  legitimately restores.
- **Custom** (`allow_custom` on): a saved entity value *replaces* the default wholesale
  (all-or-nothing; the default is only a starting point).
- **Hybrid** (`allow_custom` off + the default layout contains at least one
  **entity-customizable region**): the default stays authoritative for the structure,
  but entities own the *content* of flagged regions. This is the mode for
  header/body/footer pages where creators build only the body.

Hybrid mechanics:

- **The flag** is the `region_custom` ComponentValue plugin
  ([src/Plugin/ComponentValue/RegionCustomValue.php](src/Plugin/ComponentValue/RegionCustomValue.php)),
  enabled on a region prop of a `neo_component` (it has no settings — enabling it *is*
  the flag). `ComponentFieldConfig::getCustomRegions()` derives the **anchors**
  (`ownerUuid` → flagged slot ids) from the default tree; `isHybrid()` is the predicate
  used by every gate.
- **Merge on load** (`ComponentTreeStructure::composeHybrid()`, called from
  `NeoComponentTreeList::setValue()`): the entity stores only the anchor sections +
  their descendant closure + props. Loading composes the merged tree: field default
  structure, with each anchor slot *present in storage* replacing the default seed
  children (present-but-empty = explicitly emptied; absent = the anchor postdates the
  last save, seeds render). **Both answers survive into the merged tree**: an emptied
  flagged slot stays present-and-empty rather than being dropped, because "absent" is
  already spoken for and a merged value that dropped the key re-seeded the region the
  next time it was composed. Inherited instances always render field default props. The
  merge is idempotent — stored subsets, in-session merged values and stashed drafts all
  normalize to the same result — and `HybridRoundTripTest` enforces that rather than
  leaving it as a claim.
- **Strip on save** (`ComponentTreeStructure::extractHybridStorage()`, called from
  `preSave()`/`postSave()`): before storage write the item value is replaced by the
  storage subset (every flagged slot is always written once the entity is customized),
  and restored in place after. Extraction is the inverse of the merge and satisfies
  tree↔props parity as a postcondition. A pristine (`isDefault()`) entity persists
  nothing. **Orphan sections** — content whose anchor was removed from the default — are
  detected by the merge, stashed on the list and re-emitted on save (render-inert,
  restored if a config revert brings the anchor back).
- **Which module owns what.** The tree algebra is the seam's (see below); the field list
  keeps only the Field API lifecycle — *when* to merge, *when* to strip, what to persist
  on insert versus update, the constructor's default seed, the runtime-value stash
  across save, and the empty-tree contract that lets a pristine entity persist nothing.
  Anchor resolution stays on `ComponentFieldConfig` because it has to load component
  config entities to ask which region props are flagged.
- **Locking** is ops-driven and server-side only:
  `ComponentInstanceBase::checkHybridAccess()` forbids `update`/`delete`/`clone`/`sort`
  on inherited instances (they get an "Inherited layout" badge) and `create` outside a
  custom target (`ComponentTreeItem::isCustomTarget()`); the library/add/move/sort
  routes enforce the same target checks. No JS changes — the editor chrome reads the
  per-op access booleans.
- Since the rendered tree depends on the field config, `ComponentTreeHydrated`
  adds the field config as a cacheable dependency of every entity render.

### The component tree seam

Every subsystem that touches a layout operates on the same decoded pair —
`['tree' => …, 'props' => …]`. [ComponentTreeStructure](src/Plugin/DataType/ComponentTreeStructure.php)
is the one module that knows the shape of it. It is a TypedData class but it is pure
PHP: no container, no entities, constructible in one line, which is why the whole
algebra is unit-tested at full speed.

**It owns the pair, not just the tree.** `bindProps()` attaches the props companion, and
every operation that adds or removes an instance then maintains tree↔props parity as a
postcondition — no props entry outlives its instance, no instance outlives its entry.
`ComponentTreeItem` binds on every access. Unbound (config scope, read-only callers,
unit fixtures) it behaves exactly as it always did. The save-time `LogicException` in
`ComponentTreeItem::preSave()` is retained as belt-and-braces: reaching it now means a
caller assembled a value by hand instead of going through the seam.

**Reorder is a permutation, never a deletion.** `reorderComponents()` refills only the
positions the listed UUIDs occupy and leaves everything else at its own index. Its
destructive predecessor `sortComponents()` rebuilt the section from the supplied list,
and its callers built that list from `ComponentTreeItem::toOptions()` — a *labelling*
helper, which can only offer a row for an instance whose `neo_component` config still
loads. One "move down" next to a broken component deleted it. `getPlacedUuids()` is the
sibling reorder callers use; `toOptions()` stays a labelling concern.

**One closure walker.** `expandClosure()` replaced four independent descendant walks.
The collectors — `collectUuids()`, `collectInstanceUuids()`, `collectInstances()`,
`collectComponentIds()`, `collectChildTuples()`, `collectAnchorClosure()` — are all
built on a single private section walk, replacing three verbatim copies of the
root-versus-slot idiom across two classes.

**The empty-section policy is an argument, never a default.**
[EmptySectionPolicy](src/EmptySectionPolicy.php) is passed to `removeComponent()` and
`detachComponents()`:

- `Collapse` — drop an emptied slot and a section left with no slot. What config scope
  needs: `ComponentTreeStructureConstraintValidator` rejects both, and the config
  dependency system *deletes* any dependent it cannot fix.
- `Preserve` — keep an emptied slot as `[]`. What hybrid entity storage needs: that
  empty slot is the marker saying a creator deliberately emptied the region.

Both readings are individually correct, and the divergence is invisible until the two
subsystems meet — `drush neo:alchemist:integrity --detach` rewrites entity rows, and a
hybrid row is a storage subset. Collapsing one leaves `{root: []}`, which the next load
reads as "never customized" and answers with the site builder's seed components. The
command therefore picks per row via `isStorageSubset()`, the same discriminant the load
path branches on.

**Who calls what:**

| Caller | Uses |
|---|---|
| `ComponentTreeItem` | the bound instance API — add/reorder/remove/clone |
| `NeoComponentTreeList` | `composeHybrid()` / `extractHybridStorage()` / `decodeValue()` / `isStorageSubset()` |
| `ComponentUsage`, `InertComponentData`, `AlchemistBlock` | `collectComponentIds()` |
| `NeoFieldConfig`, `AlchemistBlock` | `detachComponents(…, Collapse)` |
| `NeoAlchemistIntegrityCommands` | `detachComponents(…, Preserve|Collapse)` per row |
| `ComponentFieldConfig::getCustomRegions()` | `collectInstances()` |
| `ComponentTreeItem::cloneComponentChildren()` | `collectChildTuples()` |
| `neo_alchemist_update_11006()` | `collectInstanceUuids()` / `isStorageSubset()` |

### Draft model: detached copies

Editor routes carry `neo_draft => TRUE`, and
[src/Routing/FieldParamConverter.php](src/Routing/FieldParamConverter.php) loads the
state-stashed draft into the `{neo_field}` item via `enforceAsDraft()`. That draft state
lives on a **detached clone** of the entity's field item list — never on the statically
cached entity itself. Param conversion re-runs re-entrantly within a single request
(access checks via `checkNamedRoute`, path validation, sub-requests), so mutating the
shared item would clobber a publish flow's `enforceAsDraft(FALSE)` mid-save (the layout
would silently stay in draft) and could bleed draft values into canonical renders. Each
conversion instead gets its own copy (core `ItemList::__clone()` deep-clones items; the
same isolation config scope uses in `ComponentFieldConfig::getFieldItem()`).

The counterpart lives in `ComponentTreeItem::saveComponents()`: when committing (not in
draft mode) on a detached item, the copy's value is synced onto the entity before
`$entity->save()` — as NULL when a hybrid list is still flagged default (reset), so the
entity returns to the field default instead of copy-on-writing the seeds.

---

## Render pipeline

`Component::toRenderable()` ([src/Entity/Component.php:1277](src/Entity/Component.php)) returns:

```php
[
  '#type' => 'component',                 // core SDC render element
  '#component' => 'front:cards_test',     // getComponentId()
  '#props' => [ …getPropValues()…,        // per-shape values + 'attributes'
                'neoId' => …, 'neoUuid' => …, 'neoIsPreview' => $this->isPreview() ],
  '#slots' => [ name => $slot->toRenderable(), … ],
]
```

- **`neoIsPreview` is set here, as a prop** — that's the flag Twig reads
  (`{% if neoIsPreview %}`). It is TRUE in the editor preview and under
  `neo:alchemist:render` (default), FALSE under `--live`.
- Core's SDC renderer resolves `#type: component` to the component's Twig template by
  provider, injecting `#props`/`#slots` into the Twig context.
- **A slot's renderable has two shapes.** `ComponentSlot::toRenderable()` keys its
  children by their resolved Twig key (`getKeys()`: the configured `key`, else the
  plugin id, `_2`/`_3` on collision) rather than by UUID. If the component ships a
  `slots/<slot>.twig` — found by `neo_alchemist.slot_template_locator`, cached in
  `cache.discovery` — the whole thing is wrapped in an `inline_template` that includes
  it, with each child, `items`, `slot` and `neoIsPreview` in its context. Otherwise the
  flat keyed array is returned as-is.
- **An empty slot must return `[]`**, which is why `toRenderable()` bails before
  wrapping. `Component::toRenderable()` filters empty slots out of `#slots` so core
  generates no `{% block %}` override for them — that is exactly what lets a component
  keep its own fallback content inside `{% block name %}…{% endblock %}`. An
  `inline_template` array is always truthy and would suppress every fallback.
- Each child also gets `<base hook>__<component>__<slot>__<key>` theme suggestions
  prepended to its `#theme` (`prepareChild()`), so a component can override one item's
  internals with an ordinary suggestion template while `#theme_wrappers` still wraps it.
- In preview mode `toRenderable()` also wraps the build with admin chrome
  (`prepareRenderableForPreview()` — disabled/limited-access badges, drag handles).
- **Full-page rendering** goes through [src/Render/ComponentPageRenderer.php](src/Render/ComponentPageRenderer.php)
  (`neo_component_page_renderer`), a `BareHtmlPageRenderer` whose `renderBarePage($content, $title, $scope)`
  wraps content in a `scope-front`/`scope-back` shell and runs neo_build attachments
  (CSS variables). This is used by the preview iframe controller.
- **CLI caution:** `neo:alchemist:render` renders the component subtree directly (via
  `renderer` in a `RenderContext`), *not* through `renderBarePage`, because the page path
  invokes `hook_page_attachments`/`neo_build_page_top` which assume an HTTP request/route
  that doesn't exist under Drush.

---

## Services

From [neo_alchemist.services.yml](neo_alchemist.services.yml):

- **`neo_alchemist.settings`** — module config repository (extends `neo_settings.repository`).
- **`neo_component_page_renderer`** → `ComponentPageRenderer` — scoped bare-page renderer
  (also aliased to `BareHtmlPageRendererInterface`).
- **`neo_alchemist.preview_builder`** → `ComponentPreviewBuilder` — transient entity builder.
- **`neo_alchemist.value_panel_builder`** → `ComponentValuePanelBuilder` — the prop panel both
  value editors present (styles accordion, values container, per-prop shape forms, the hidden
  refresh button), and the two DOM ids the editor client matches on.
- **`neo_alchemist.prop_value_harvester`** → `ComponentPropValueHarvester` — reads a submission
  back out of that panel and returns the props structure. The instance editor feeds the result
  to the placed instance's stored values, the SDC preview workspace to its preview overrides;
  everything before that point is shared.
- **Plugin managers** — `plugin.manager.neo_component_{prop_def,shape,value,value_group,group,size,slot,filter,filter_options,access}`.
- **Factories** — `neo_component.{slot,filter,access}.factory`.
- **Access checkers** (tagged `access_check`) — `neo_alchemist.{entity_access,field_access,neo_field_access,neo_component_access,prop_access,slot_access}_checker`.
- **Event subscribers** — `neo_alchemist.route_subscriber` (dynamic entity/field routes),
  `neo_alchemist.kernel_subscriber`, and two `NeoBuild*EventSubscriber`s (Tailwind scanning).

---

## Config & routing

- Component config entities are `neo_alchemist.neo_component.<id>`; schema in
  [config/schema/neo_alchemist.schema.yml](config/schema/neo_alchemist.schema.yml)
  (props, slots, filters, access, settings).
- Preview routes/controllers: [src/Controller/SdcPreviewController.php](src/Controller/SdcPreviewController.php)
  (renders a transient SDC preview via `neo_alchemist.preview_builder`) and
  `ComponentPreviewController` (renders a saved component). Component management UI is
  under `/admin/config/neo/alchemist` (see [neo_alchemist.routing.yml](neo_alchemist.routing.yml)
  and `src/Form/` / `src/Controller/`).

---

## Submodules

- **`modules/neo_alchemist_library/`** — component **registry**: browse/install starter
  components from an external (shadcn-style) registry into a theme. Ships the
  `neo:alchemist:list` / `neo:alchemist:add` Drush commands.
- **`modules/neo_alchemist_examples/`** — example components (`neo: true`) used as
  reference/starters.
- **`modules/neo_alchemist_block/`** — expose components through Drupal's block system.
- **`modules/neo_alchemist_menu/`** — mega menus via **component region** menu items: a
  `menu_link_content` item flagged with `options[neo_region]` carries its own component
  tree (a `field_components` config field shipped by the module) that the `menu` value
  provider exposes to components as runtime `region`/`content` item keys via
  `hook_neo_alchemist_menu_value_item_alter()`. Full guide: the `neo-alchemist-menu`
  skill (`modules/neo_alchemist_menu/install/skills/`).
- **`modules/neo_alchemist_taxonomy/`** — per-**level** term layouts: a taxonomy
  vocabulary gets one component tree field per hierarchy level (a `level` third-party
  setting on the field config, exposed on the field edit form), and the term's canonical
  page renders only the field matching the term's level (terms deeper than the deepest
  configured level fall back to it). Implemented as field `view` access denial for
  non-matching fields plus a `hook_taxonomy_term_view()` cleanup so single-field
  full-page theming still fires; term level walks the first-parent chain
  (`src/TermLevelResolver.php`) and all level-dependent output carries the
  `taxonomy_term_list:{vid}` cache tag. Editor surfaces (Layout tab redirect, local
  tasks, token image field lookup) follow via
  `hook_neo_alchemist_entity_component_fields_alter()`.

---

## Drush surface

- **Generators** — `src/Drush/Generators/`: `neo-component` (`neoac`) scaffolds a new
  component, `neo-component-prop` (`neoacp`) adds a prop to one.
- **Introspection/verification** — [src/Drush/Commands/NeoAlchemistCommands.php](src/Drush/Commands/NeoAlchemistCommands.php):
  `neo:alchemist:components`, `:info`, `:shapes`, `:validate`, `:render` (`--scheme`,
  `--html`, `--live`). These are the **runtime complement to this doc** — prefer them over
  reading plugin files when you just need the current shapes/props/definitions. (Icon and
  scheme lookups live in their owning modules: `drush neo:icon:list`, `drush neo:color:schemes`.)

---

## Extension points — "where do I add X"

- **A new ComponentShape** — add `src/Plugin/ComponentShape/MyShape.php` with
  `#[ComponentShape(prop: 'my_type', …)]` extending `ComponentShapePluginBase`; implement
  `preRenderValue()` (and optionally `getGenerationExamples()`/`onGenerateTwig()`); add a
  matching entry to `neo_alchemist.neo_component_prop_defs.yml` (schema + `examples` +
  `twig`); `drush cr`.
- **A pure prop-def** (no custom PHP) — add an entry to a `*.neo_component_prop_defs.yml`
  in any module; reference it as `type: my_type` in a `.component.yml`.
- **A ComponentValue / Slot / Filter / Access plugin** — add a class in the matching
  `src/Plugin/Component*/` dir with the matching `#[Component*]` attribute; it's
  auto-discovered by its manager. Slots/filters/access surface in the component edit UI.
- **A ComponentValue *producer*** (yields a prop value from an entity/query/etc.) — as
  above, plus `implements ComponentValueProcessingModeInterface; use
  ComponentValueProcessingModeTrait;`, append `processingModeDefaultConfiguration()` to
  `defaultConfiguration()`, and call `buildProcessingModeForm()` in the config form. Then
  just produce the value (return the incoming `$value` when you can't act — a producer
  on the mode does not `claimValue()`/`stopFurtherProcessing()` itself; the pipeline
  claims per the mode). The producers that do claim for themselves are inventoried under
  "ComponentValue processing model"; adding a fourth means saying why there.
  Override `processingModeDefault()` to change the default mode. See "ComponentValue
  processing model". A *modifier* instead implements `modifyValue()`/`alterValue()` and
  never claims.
- **Per-item data on the `menu` value provider** — implement
  `hook_neo_alchemist_menu_value_item_alter(&$entry, $item, $shape)` (documented in
  [neo_alchemist.api.php](neo_alchemist.api.php)). Extra `$entry` keys flow through the
  `menu` prop schema to twig (precedent: `in_active_trail`); set `$entry = NULL` to drop
  an item; add cacheability via `$shape->addCacheableDependency()` — provider-added
  dependencies are merged into the component build after `getPropValue()` runs.
  Canonical consumer: `modules/neo_alchemist_menu/`.
- **Per-entity narrowing of which component tree fields apply** — implement
  `hook_neo_alchemist_entity_component_fields_alter(&$fieldDefinitions, $entity)`
  (documented in [neo_alchemist.api.php](neo_alchemist.api.php)). Invoked from
  `neo_alchemist_entity_component_field_definitions()` wherever Alchemist enumerates an
  entity's tree fields (the Layout route redirect/picker and the token image field
  lookup). Remove entries only; pair with `hook_entity_field_access()` to hide the same
  fields from rendering. Canonical consumer: `modules/neo_alchemist_taxonomy/`.
- **A Drush command** — add a method to `NeoAlchemistCommands` with `#[CLI\Command]`;
  inject services via `#[Autowire(service: '…')]` (the class uses `AutowireTrait`).
- After any plugin/attribute/service change: **`drush cr`** (discovery is cached), and run
  `drush neo:build <scope>` if you touched Tailwind-scanned output.
