---
name: neo-alchemist-dev
description: Understand and modify the neo_alchemist MODULE internals (PHP) — the neo_component config entity, prop shapes / prop-defs, ComponentValue plugins and the value-resolution pipeline, slots/filters/access plugins, field embedding, previews, and Drush commands. Use when editing files under web/modules/contrib/neo_alchemist/, adding a shape / value plugin / slot / filter / access plugin / Drush command, or diagnosing why a prop resolved to the value it did, why the editor preview differs from the live page, or why an Alchemist screen errors. NOT for authoring or styling page-building components in a theme (*.component.yml / *.twig) — that is the neo-component skill.
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Working on the neo_alchemist module

Paths are relative to `web/modules/contrib/neo_alchemist/`. The module ships `ARCHITECTURE.md` and
`TESTING.md`; read the relevant section before non-trivial work. This file is the fast map plus
what is easy to get confidently wrong.

## Scope check

The module's concern is **how a prop gets its value and how a component is assembled**, never how a
component looks. Not this skill: theme component authoring/styling, mega menus, asset compilation,
color/font/modal/animation. The two boundaries that actually get crossed:

- *"The image shows in the editor but not on the live page."* Sounds like component authoring; it is
  **module work** — preview and live run different plugins.
- *"This component's layout is wrong"* / *"give editors a control for how it looks."* Often framed as
  if a shape handed the template a default; it is **theme work**. Layout lives in the component's own
  Twig, and a per-component editor knob is a component-local `type: style` prop with its own
  `styles: {key: {label, value}}` map in that `.component.yml`. Do **not** add an entry to
  `neo_alchemist.neo_component_prop_defs.yml` to serve one component — that file is site-wide shared
  vocabulary; a prop-def/shape belongs in the module only when the *type* is reusable.

If the module has no clean seam for what was asked, say so and propose changing the extension
model — do not repurpose a seam to make the answer tidy.

## Two rules that outrank everything else here

Both are about losing while being correct.

### 1. Answer every distinct thing they raised

List what the message asks before writing. Requests here routinely carry several: a change *plus*
"and make it readable", a diagnosis *plus* "tell me what I should be looking at", a proposal *plus*
"is there a simpler option I'm missing" *plus* "is anything we already have at risk". The soft ones —
legibility, ergonomics, "can we make this nicer" — are usually the reason the request exists, and
being right about the mechanism does not cover them. An unanswered ask is a loss even when
everything you did write is true.

**A refusal is not an exemption.** If the mechanism they proposed is wrong you still owe the goal
behind it: the safe subset of the change, a smaller thing that gets part of the value, the place the
real fix belongs, or an explicit "nothing here serves that, and here is why". Declining every edit
and offering nothing for the concern that prompted the request is a half-answer. Same for a
correction: if checking their premise contradicts them, quote the exported YAML or the line you are
contradicting them with — and then still answer the question they asked. "Tell me which one you
meant and I'll decide" is not an answer.

Conversely, answer nothing nobody asked: no tour of the pipeline, no CHANGELOG essay, no adjacent
refactor, no internals excursion that does not change what the reader does next. The best answer
here is nearly always the shortest correct one that covers every ask.

### 2. Separate what you read from what you expect

This pipeline has enough deliberate special cases that a plausible inference is usually wrong. Mark
each load-bearing statement as **read it** (cite file + method), **inferred** (from what), or **not
checked**. A confident, specific, checkable claim that is false is the worst outcome available —
worse than silence, because it gets acted on. A preview or CLI render is never evidence about a live
page (below), and a config file you have not opened is not a config file you know.

The claim that goes wrong most often is about a **test**:

- **Never say an existing test goes red (or stays green) under a proposed change unless you have
  read both the assertion and the fixture value it turns on.** State them together — "`testX` asserts
  *A*; its fixture sets prop *P* to *V*" — because that sentence cannot be written from plausibility.
  Branches here are usually reachable only with a particular fixture shape (empty vs non-empty,
  required vs not, preview vs live, dev-mode on vs off), so a test that covers the *method* very
  often does not cover the *branch*. Unopened fixture ⇒ the honest sentence is "there is coverage in
  this area; check whether its fixture reaches the branch you are changing".
- For a test you are **writing**, "goes red at the wrong placement" is a design target, not an
  observation. Name the mutation it is meant to catch, confirm it would not also pass without the
  change, and say plainly whether you ran it. Unrun is fine; unrun-and-described-as-verified is not,
  and a red/green claim never goes in a docblock.

### Shapes of request

**"Why did this resolve to that?"** Quote the deciding line and annotate **both** branches — showing
what the other branch would have done is what refutes the reporter's own theory, which is usually
the real question. Name the stage, the position in the ordered list, and whether an empty result was
a claim or a pass-through; then the fix, and the one or two checks that split the remaining suspects.

**"Make this change."** Show where the edit goes *relative to the guard that makes it correct*, and
what the plausible-but-wrong placement would break. When the requester proposes a spot, check
whether their mechanism can even attach there before adopting or rejecting it, and say which.

**"What's the best path?"** Options anchored in real seams, each with its cost, and a pick. For
anything that shares or resolves configuration the deciding question is the **write** side, not the
read side (below). Ask of each option *who ends up controlling what* — a design that quietly moves a
decision from the site builder to the content creator, or back, is not the same feature however
elegant.

The rest of this file is background to draw on, not material to reproduce.

## How a prop's value resolves

The module's core, most asked about and most often guessed wrong. All of it is
`ComponentShapePluginBase`.

Every pass walks **one flat instance list** from `getValueCollection()`: definitions filtered for the
shape (`ComponentValuePluginManager::getFilteredDefinitionsFromShape()`), ordered **by group weight
first** — `providers` (−5), `fallback` (−3), `modifiers` (0), `settings` (5), from
`neo_alchemist.neo_component_value_groups.yml` via `getGroupOrder()` — and *within* a group by the
builder's saved drag order, then the remaining plugins by weight, then label.

Saved order is real but **only orders within a group**. The prop form renders one table per group
(`Form/ComponentPropForm`, `getInstancesByGroup()`), so there is no cross-group drag to make — the
bottom of the providers table is still above the whole fallback group, which is what guarantees the
`default` plugin (`fallback`) runs after every provider. Group order is derived at read time, never
persisted, so re-grouping a plugin needs no update hook.

`getAllowedInstances($op)` filters that list by `isAllowed($op)` and **resets each instance's
continue flag**, so a claim never leaks between passes. Ops: `init`, `default`, `value`, `edit`,
`modify`, `form`. `init()` runs `default_plugins` attach → `onShapeInit()` broadcast →
`getDefaultValue()` → the override overlay (the parent's pushed-down value if any, else
`getOverrideValue()`, which is nulled when the prop's "use default" option is on or the prop is not
editable, then the `alterValue($value, 'override')` chain).

`getDefaultValue()` — the part people ask about:

```php
$value = $originalValue = $this->resolveValue($this->getDefaultSchemaValue());   // SEED
foreach ($this->getValueCollection()->getAllowedInstances('default') as $instance) {
  $provided = $instance->provideDefaultValue($value);
  if ($instance instanceof ComponentValueProcessingModeInterface) {
    $instance->applyProcessingMode($provided);
  }
  $value = $this->isProvidedValueEmpty($provided) && !$instance->hasClaimedValue()
    ? $value      // empty and UNCLAIMED → the incoming value survives untouched
    : $provided;  // non-empty, OR an empty that was CLAIMED → written through
  if (!$instance->shouldContinueProcessing()) { break; }
}
```

Still inside `getDefaultValue()`: a field default (`getFieldDefaultValue()`) overrides whatever the
search produced; `setFieldItemValue($value, FALSE)`; a second loop runs `alterValue($value,
'default')` on a **fresh** instance list (so a claim above does not truncate it); finally, if the
result is empty **and the prop is required**, the value reverts to `$originalValue` — the seed — so
SDC is never handed a missing required prop.

Consequences worth stating when diagnosing:

- **The seed is the prop's schema `examples`** (`getDefaultSchemaValue()`; `ArrayShape` and
  `UrlShapeTrait` override it). "The example disappeared" is normally a claim overwriting the seed,
  not a provider failing.
- **A provider that finds nothing and does not claim leaves the incoming value standing.** Attaching
  a provider can never make a prop worse than not attaching it — unless it claims.
- **A claim writes an empty result through, over the seed.** The deliberate "nothing IS the answer".
- **`isProvidedValueEmpty()` is the emptiness contract, not PHP truthiness**: only `NULL` and `''`
  are empty for scalars; `0`, `'0'`, `FALSE` are values. For arrays the key `size` is stripped
  before the test (seeded by the media image-size modifier, not content).

### Processing mode governs the provider search and nothing else

`ComponentValueProcessingModeInterface` + `Plugin/ComponentValue/ComponentValueProcessingModeTrait`,
config key `processing_mode`. `applyProcessingMode()` has exactly **one** call site, the loop above.

| Constant | Value | Label in the UI | Claims when… |
|---|---|---|---|
| `MODE_STOP_WHEN_FOUND` | `stop_when_found` (default) | "Stop when a value is found" | the produced value is non-empty |
| `MODE_CONTINUE` | `continue` | "Provide, allow later changes" | never |
| `MODE_BLOCK` | `block` | "Always stop (block if empty)" | unconditionally — including on empty |

`claimValue()` *is* `stopFurtherProcessing()`; `hasClaimedValue()` is `!shouldContinueProcessing()`.
The mode does not govern `modifyValue()`, either `alterValue()` loop, `onShapeInit()`, `isEditable()`
or the authored-override pass — those are chains where every plugin gets a turn. `continue` is not a
safe substitute for `stop_when_found`: it never claims, so a later provider can still overwrite what
it found. `block` is for props whose `examples` are editor scaffolding (placeholder cards, stock
images, menu links) that must never reach a visitor, not for a prop with a genuine human-readable
fallback — and it suppresses every plugin below it in the list, not just the fallback group.

### At render time

`getValue()` → `buildValue()`; when rendering, `buildRenderValue()`: `resolveValue()` → the shape's
`preRenderValue($value, $attributes)` → the `modifyValue()` chain (op `modify`, same list).
`Entity/Component::getPropValues()` then drops every empty-valued prop, so an empty prop is simply
absent from `#props`. It merges each shape's cacheable metadata **after**
`getPropValue()`/`getPreviewPlaceholder()` have run — dependencies a provider registers via
`$shape->addCacheableDependency()` exist only by then, and merging earlier snapshots an empty set
and loses the tags silently (stale pages, no error). The method carries an in-code comment saying
so; read it before rearranging that loop.

## Groups are behavioral declarations, not form tabs

`providers` sources a value · `fallback` fills one in when no provider sourced one · `modifiers`
transform an existing one · `settings` never touch the value, they configure how the prop is
edited/rendered (`widget`, `region_size`, `region_custom`). Besides driving order, group membership
is read by
`Plugin/ComponentShape/ChildrenShapeBase::childHasOwnValueProvider()` — literally "does this child
shape have an enabled `providers`-group instance". If it does, the parent **refuses to push its
value down** into that child. Mislabelling a non-sourcing plugin as `providers` therefore makes
nested props of that type silently render the schema `examples` instead of authored content, in
components that have nothing to do with the new plugin.

The `default` plugin ("Default" in the UI) is `group: 'fallback', weight: 1000` — terminal, never
claims, and its `provideDefaultValue()` treats a value still equal to the seeded schema example as
*untouched*, so a builder's configured default supersedes the component author's placeholder but not
a real provider value.

`tests/src/Kernel/ValueGroupTaxonomyTest` pins the complete `id => group` map with `assertSame`, so
adding or removing any ComponentValue plugin means updating it in the same change.

## Adding a ComponentValue plugin

Class in `src/Plugin/ComponentValue/`, `#[ComponentValue]`, extends `ComponentValuePluginBase`.
Attribute parameters (all of them): `id`, `label`, `description`, `group`, `inline`,
`status_default`, `status_lock`, `prop_types`, `ref_types`, `entity_types`, `allow_on_default`,
`weight`, `deriver`.

- `prop_types` filters on `$shape->getType()` — the JSON-schema type (`string`, `integer`, `array`,
  `object`…). `ref_types` filters on `$shape->getRef()` — the prop-def name (`heading`, `media`,
  `link`…). `entity_types` accepts `*`, `node.*`, `node.article`. All three support a `!` prefix for
  exclusion. Confusing the first two ships a plugin that never appears.
- `weight` orders **within** the group — reason a modifier's weight against the shipped modifiers
  rather than defaulting to 0, since a transform that must see the finished string sorts after the
  ones that extend it. `inline: TRUE` surfaces the plugin inside another plugin's nested-field UI
  (`ComponentShapePluginCollection::getInlineInstances()`), i.e. on a child prop of an array.
- **A plugin that stores configuration must declare `neo_alchemist.neo_component_value.<id>` in
  `config/schema/neo_alchemist.schema.yml`.** The `…neo_component_value.*` fallback is a keyless
  `mapping`, so without an explicit entry every stored key is unschema'd. One entry covers both
  storage paths — root props resolve `[%parent.id]`, the nested path `[%parent.plugin_id]`.
- **The nested-field path never runs your form handlers.** Root prop settings go through
  `validateConfigurationForm()`/`submitConfigurationForm()`; the inline path
  (`ComponentValueChildrenMatchTrait::validateChildMatchConfigurationForm()`) writes raw form values
  straight to config after only stamping `plugin_id`/status, so a `#type: number` posts a string and
  violates a `type: integer` schema there. Put coercion in an `#element_validate` callback on the
  element — and re-list the element's own default validator (e.g. `Number::validateNumber`), since
  `#element_validate` replaces the element-info default rather than appending to it.
- A **producer** additionally `implements ComponentValueProcessingModeInterface; use
  ComponentValueProcessingModeTrait;`, merges `processingModeDefaultConfiguration()` into its
  defaults, calls `buildProcessingModeForm()` in its form, then just returns a value (return the
  incoming `$value` when it cannot act) — never call `claimValue()`/`stopFurtherProcessing()` by
  hand, the vetoes `user_has_role` / `entity_has_value` being the deliberate exception. Override
  `processingModeDefault()` to `MODE_BLOCK` when the prop's examples are scaffolding, as
  `entity_query`, `menu`, `entity_reference` and `breadcrumb` do. A **modifier** implements
  `modifyValue()` and/or `alterValue()`, never claims, and returns a value shape it cannot safely
  handle unchanged rather than mangling it.
- Lifecycle: `onAdd()`/`onUpdate()`/`onRemove()` fire from `Component::preSave()`/`preDelete()` via
  the shape's `onPluginAdd()`/`onPluginRemove()`, and can own state outside config —
  `MediaValue::onRemove()` deletes the `neo_config_file` entity behind a config-hosted image.
  Discovery is cached: `drush cr`.

## Plugin families, prop types, and what does not exist

Five live families — **ComponentShape**, **ComponentValue**, **ComponentSlot**, **ComponentFilter**,
**ComponentAccess** — each with a namespace under `src/Plugin/`, an attribute in `src/Attribute/`,
and a `plugin.manager.neo_component_*` service. Slot, filter and access plugins are plain
attribute-discovered classes; `#[ComponentSlot]` takes only `id`, `label`, `description`, `deriver`.

A slot plugin needing a service uses `ContainerFactoryPluginInterface` with a `create()` reading
`$configuration['component' | 'uuid' | 'settings']` into the base constructor, plus
`DependencySerializationTrait`. For the entity type manager the family already ships
`Plugin/ComponentSlot/EntityManagerDependentSlotTrait` (used by `BlockSlot`) doing exactly that —
reuse it rather than re-deriving the constructor.

`src/Attribute/` also contains `ComponentStyle.php`, `ComponentValueProvider.php` and
`ComponentValueModifier.php`. **These are inert** — no plugin manager, no plugin type, no
`src/Plugin/` namespace, zero classes anywhere on the site carry them. Value plugins of every role,
provider and modifier alike, use `#[ComponentValue]` with the right `group:`. Never cite or use the
inert three.

A prop `type:` is a **prop-def** (declarative — schema, `examples`, `twig`, `styles` — in a
`*.neo_component_prop_defs.yml`) plus, usually, a **ComponentShape** PHP plugin keyed by the `prop`
parameter of `#[ComponentShape]`; a pure prop-def with no PHP shape gets no shape plugin at all. The
module's own prop-defs file declares 30 top-level types — check it before asserting a type does or
does not exist. A prop value handed to Twig as an **object** must expose `get*()`/`has*()`/`is*()`
accessors (the Twig sandbox permits no other method calls), implement `JsonSerializable` so SDC prop
validation sees the wrapped data, and add `ArrayAccess` (`src/ViewsFilterTwig.php` is the shipped
example, wrapped in **both** the shape's `preRenderValue()` and the provider's `modifyValue()`).

Two landmarks, since services, routes, submodule remits and the seven alter hooks are already
documented in `ARCHITECTURE.md` and `neo_alchemist.api.php`: component trees embed in entities
through field type `neo_component_tree` (`ComponentTreeItem`, with
`src/Plugin/Field/NeoComponentTreeList.php`, widget and formatter alongside); and in `src/Form/`
services are injected as **non-promoted `protected`** properties, because forms are serialized into
the form cache and `DependencySerializationTrait::__sleep()` cannot see a `private` property declared
in a subclass — so the whole service graph gets serialized with the form. Controllers, never
serialized, may use promoted `private readonly`.

## How slot output actually reaches the DOM

`Component::toRenderable()` builds `#slots` as
`array_filter(array_map(fn($slot) => $slot->toRenderable(), $slots))`. Core then generates, per
surviving key, `{% block <slot> %}{{ <slot> }}{% endblock %}` inside an `{% embed %}` of the
component template (`ComponentElement::generateComponentTemplate()`). Two consequences:

- **There is no wrapper element around a slot.** The value is printed by `{{ }}`, so an
  `#attributes` key added at the `#slots` line has nothing to consume it. Marking slot output in the
  DOM means `#prefix`/`#suffix`, or giving the value a `#type`/`#theme` that emits an element.
- **An empty slot must keep returning `[]`.** That is what `array_filter` drops, which is what stops
  core emitting a `{% block %}` override, which is what lets the component's own
  `{% block name %}…{% endblock %}` fallback render. Anything truthy applied *before* emptiness is
  decided makes every unfilled slot on the site look filled and kills its fallback. An individual
  slot *plugin* with nothing to contribute returns falsy — `ComponentSlotPluginBase::toRenderable()`
  returns `NULL` — and `ComponentSlot::toRenderable()` `array_filter`s the children before deciding
  the slot is empty.

Slot-level output work therefore belongs **below the empty early-return**, inside `ComponentSlot`.
`annotateSlot()` already sits exactly there on both return paths, already gated on
`templateLocator->isDevMode()` and already composing with any existing affix via
`Markup::create($open . self::filterExisting(...))` — load bearing, because `Renderer::doRender()`
runs affixes through `Xss::filterAdmin()` (which strips HTML comments) and `filterExisting()`
re-applies exactly that filtering so wrapping cannot widen it. Reuse that gate and that composition
rather than inventing a parallel one.

## Preview is not live

`neo_alchemist.preview_builder` → `ComponentPreviewBuilder::build(string $component, bool $preview = TRUE)`
creates a **fresh unsaved** `neo_component` from the SDC definition; it never loads the saved
component, so nothing a builder configured there runs. `drush neo:alchemist:render` always renders
that transient entity — `--live` only flips `$preview` (and the injected `neoIsPreview` prop), it
does not switch to the saved component's configuration. What *does* run there is shape-level
`default_plugins`, so props whose shape declares one (`breadcrumb`, `image`, `media`, `markup`,
`scheme`, `file`, local and remote video) still resolve through that provider — say that, rather
than "nothing resolves on the CLI".

So provider-driven output (menu, media, entity field, views…) can only be verified on a real page.
Never present a preview or CLI render as evidence about a live page or vice versa; when the symptom
is "works in one, not the other", explain which plugins execute in each.

`Component::toRenderable()` also has a preview-only branch: when required props are still unsatisfied
after `getPreviewPlaceholder()` had its chance, the SDC build is replaced by a notice naming the
missing props rather than failing SDC's schema assertion. Live renders are untouched — extend this
rather than adding a second such notice.

## Designing something shared, inherited or reusable across components

"Define it once, attach it in N places" recurs. The constraint is the **write** side, and it is the
first question, not the last:

- `settings.props` is a **resolved snapshot**. `Component::preSave()` calls `setPropShapeSettings()`
  for every unchanged root shape on **every** save (rebuilding all of them from scratch when the
  generated expression changed), and saves happen without a human —
  `ComponentPluginManager::getDefinitions()` re-saves a component whose expression drifted, so a
  cache rebuild is a save. An overlay resolved at read time (`getValueCollection()`, `getPlugins()`)
  and not *also* excluded at `setPropShapeSettings()` is therefore **baked into all N components'
  stored config on the next `drush cr`**, after which nothing is shared.
  `tests/src/Kernel/ComponentSaveIdempotenceTest` pins that a load/save round-trip is byte-identical,
  and is the natural place to pin that a preset is *not* written.
- Two seams look better than they are. A "bundle" plugin occupying one instance slot **cannot
  control cross-group execution order** (one plugin sits in one group) and is invisible to
  `childHasOwnValueProvider()`. And giving the prop a new `type`/`ref` that carries the plugins
  changes the generated expression, discarding stored settings for **every** prop on that component
  and firing the departing shape's `onRemove()`.
- A `#[ComponentShape]`'s **`default_plugins`** is the nearest existing layer, and the only one with
  a live route: on shapes a builder cannot configure (`!allowConfigurablePlugins()` — non-expanded
  children, an expanded root's children) they attach in `init()` on every build and
  `setPropShapeSettings()` skips exactly those shapes, so they are resolved live and **never written
  into config**. On root props and expanded *iterable* roots they attach in `onAdd()`/`onUpdate()`
  during `preSave()` and **are** written into `settings.props` — and since `addPlugin()` no-ops only
  when the instance is already active, an entry a builder disabled comes back on the next save. But
  it is keyed by prop *type*, not by a name a builder attaches — usually the gap between it and what
  was asked. Name the gap.

The honest answer is sometimes that the module has no representation of *unresolved* prop
configuration: everything a component stores is already resolved. Saying so, with what it would take
to add one, beats bolting a mechanism onto a snapshot.

## Things that silently destroy authored config

- **The `settings.props` rebuild** above — triggered by a prop added/removed, a prop's `ref` changed,
  or aggregate mode toggled, and regenerated from the live SDC on a save nobody initiated, so a flag
  hand-placed in raw config can be wiped by a `drush cr`. Say up front what a diverged component loses.
- **A new component entity's id is re-derived on first save** — `Component::save()` replaces it with
  `getUniqueId()`, so a second component on the same SDC becomes `<sdc>_2`. Read `$component->id()`
  back; writing `getEditable('neo_alchemist.neo_component.<assumed id>')` mints an id-less config
  object and the next `load()` dies with `EntityMalformedException`.
- **Field modes must keep their meaning** (`Plugin/Field/NeoComponentTreeList`,
  `Entity/ComponentFieldConfig`): `allow_custom` off = **locked** (the field's default layout always
  wins); on = **custom** (a saved entity tree replaces the default wholesale, so those entities stop
  tracking the default); off **plus** an instance placed in the stored default layout whose component
  enables the `settings`-group `region_custom` plugin on a `region` prop = **hybrid**. `isHybrid()`
  is `!allowCustom() && hasCustomRegions()`, and `getCustomRegions()` also needs a stored default
  layout — enabling `region_custom` alone changes nothing. Hybrid entities store only the flagged
  subtrees, merged on load and stripped on save: everything outside a flagged region keeps inheriting
  the default on every load, while a region an editor has saved is frozen at what they saved.
  Inherited instances are locked server-side by `ComponentInstanceBase::checkHybridAccess()`. Never
  convert one mode into another as a side effect, and never strand stored subtrees.
- **Aggregate mode** wraps the whole prop schema in one synthetic `object` prop named `_aggregate`,
  unwrapped before SDC sees it, toggled at `/admin/config/neo/alchemist/{neo_component}/aggregate`.
  It is not a real schema property, hence the special case in `Access/ComponentPropAccessCheck`, and
  toggling it changes the expression, discarding prop settings in both directions.

## Tests that can actually fail

`tests/src/{Unit,Kernel}`, fixture module `tests/modules/neo_alchemist_test/`; `TESTING.md` is the
full guide. The site's `phpunit.xml` declares one suite pointing at this module's `tests/src`, so a
bare run is already scoped here. Making a pin non-vacuous is most of the value:

- **Assert the premise in the same test.** If it asserts an unfilled slot is absent from `#slots`,
  also assert the filled one is present; if it depends on dev mode, assert the gate reads open. A
  broken fixture must fail loudly, not pass by producing nothing.
- **Name the mutation it is meant to catch** — the specific wrong-but-plausible placement, not
  "remove the fix" — and confirm the fixture actually reaches the branch that mutation changes. A
  fixture that never produces an empty value cannot pin empty-value behavior whatever it asserts.
- **Build fixtures through the production API** (`Component::create()->save()`, `addPlugin()`,
  `setSlotSettings()`, `setPropShapeSettings()`) where you can. A raw `getEditable()` write skips
  `preSave()`'s regeneration and can pin a config shape the module never produces.
- **Shape state is memoised per object** — a test comparing two resolutions must `resetCache()` and
  re-`load()`, not reuse one instance.

Setup and conventions:

- Kernel baseline is `['system', 'user', 'neo_settings', 'neo_alchemist']`. **`neo_settings` is
  mandatory and must be listed explicitly** — `neo_alchemist.settings` declares
  `parent: neo_settings.repository`, yet `neo_settings` is not a declared dependency of
  `neo_alchemist` (it arrives transitively through `neo`) and `enableModules()` does not resolve
  declared dependencies anyway. `field` is *not* needed; never add
  `neo_build`/`neo_color` speculatively (`@?neo_build` is optional precisely so the container
  compiles without it).
- PHPUnit **attributes** (`#[Group('neo_alchemist')]`, `#[DataProvider]`), never doc-comment
  `@group`; **named helper classes, never anonymous classes** (and never run `phpcbf` over a file
  containing one — it inserts malformed docblocks). Extend the shared bases where they exist
  (`HybridFieldKernelTestBase`, `FieldMatchKernelTestBase`).
- Create `neo_component` entities in `setUp()`, not `config/install`: `save()` regenerates
  `expression`/`schema` from the live SDC, so checked-in config drifts. `description` is a
  non-nullable string with no SDC default. To exercise an authored value with no host entity, use a
  config-scope `Component` with `setPreview(TRUE)` + `setPreviewValues()` — copy the exact shape from
  an existing Kernel test rather than reconstructing it.
- Dev-mode-gated behavior: `ComponentSlotTemplateLocator::isDevMode()` is `neo_build`'s dev mode
  **or** `config_split.config_split.dev`'s `status` read through the config factory, so
  `$GLOBALS['config']['config_split.config_split.dev']['status'] = TRUE` switches it on without
  installing `config_split`.

## Environment and verification

- The site runs in **DDEV**: `ddev drush …`, `ddev phpunit …`, `ddev exec "<cmd>"`. A host
  `drush`/`php` proves nothing about the site.
- Edit the copy the site loads: `web/modules/contrib/neo_alchemist/`. A separate canonical checkout
  exists at `~/Projects/neo_alchemist`; editing there changes nothing on the site.
- The site copy is an extracted composer package: `composer require/install/update` re-extracts it
  and **deletes uncommitted work under it**. Run composer before starting, or after handing off.
- `drush cr` after any plugin/attribute/service/prop-def/schema change — discovery is cached.
- If you cannot execute (no shell, no containers), say exactly what you could not run and give the
  command, once, plainly. Do not describe unrun tests as verified.

The Drush surface is complete at six commands on `NeoAlchemistCommands`; cite no others.
`neo:alchemist:render <id> [--live] [--scheme=<id>] [--html]` · `neo:alchemist:validate <id>` ·
`neo:alchemist:slot <neo_component id> [<slot>]` · `neo:alchemist:shapes [name]` ·
`neo:alchemist:components [--theme]` · `neo:alchemist:info <id>`. Generators live in
`src/Drush/Generators/`; new commands are methods with `#[CLI\Command]`, injected via
`#[Autowire(service: '…')]`.

Deliver the smallest change that fully satisfies the request, then stop. Touch the module's
`ARCHITECTURE.md`/`TESTING.md`/`CHANGELOG.md` when a change alters what they document, not as a
matter of course.
