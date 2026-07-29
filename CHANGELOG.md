# Changelog

## Falsy values are values — and other silent-data-loss fixes

A test-coverage expansion over the value pipeline, the tree-field modes and the
entity/render surfaces (the suite grew from 79 to 169 tests) surfaced and fixed a
family of silent-data-loss bugs. Every fix carries a regression test proven red
against the pre-fix code.

### The falsy family: 0, '0' and FALSE are values

PHP's `empty()` was used in several places where the module's own value-emptiness
contract (`isProvidedValueEmpty()`) applies. Authored content equal to `0`, `'0'`
or `FALSE` was silently dropped or replaced by the component's schema examples:

- **`ArrayShape::buildValue()`** — the required-child guard dropped the ENTIRE
  array item (a menu item titled "0" vanished from the rendered menu); an unusable
  default clobbered the raw authored slice; single-prop items skipped the wrapper
  collapse and produced heterogeneous lists.
- **`ObjectShape::buildValue()`** — falsy children were unset from the object.
- **`StructuredObjectShapeBase`** (links) — falsy children were filtered out and
  refilled with the schema examples; the warm child-shape path skipped pushing
  falsy slices.
- **`StringShape::preRenderValue()`** — flattened an authored `'0'` to `''`.
- **`Component::isPropValueEmpty()`** — a top-level prop resolving to `0` was
  dropped from `#props`. *Render-visible*: templates now receive the 0 (twig's
  `|default()` still substitutes for falsy, so most templates are unaffected), and
  a `prop_value` access gate now counts 0 as "has a value".

### getDefaultValue() memoises NULL and stops clobbering authored values

A computed-NULL default (any provider chain ending in NULL) never memoised, so
every post-init re-entry re-ran the pipeline — including the field-item side
effect, which overwrote authored values with the recomputed NULL.

### Hybrid: un-flagging a region no longer destroys entity content

Un-ticking "Entity Customizable" on a region whose component stayed in the
default layout dropped every entity's authored region content on the next save —
neither merged nor preserved. Orphan stashing is now slot-granular: un-flagged
region content rides along in storage (render-inert) and re-flagging restores it.
**Caveat:** un-flagging the LAST flagged region flips the whole field to locked
mode, where the (characterized, unfixed) default-snapshot behavior applies — see
TESTING.md's residual list. Orphans are also now replaced (not accumulated) when
a fresh storage subset arrives, so stale entries can no longer resurrect deleted
content, while in-session editor commits keep them.

### Hybrid: tree/props parity guard fixed

The storage-subset parity backfill excluded exactly the container uuids it was
meant to protect; a props-less container inside a custom region threw a
`LogicException` on save instead of being backfilled.

### Cloning copies the whole subtree

`cloneComponent()` read the source's children using the slot the source itself
sat in — throwing whenever its own slot names differed, and never recursing, so
grandchildren were silently dropped from every clone. Clones are now deep, slot
assignments intact, with fresh uuids and copied props throughout.

### Access results keep their cacheability

`Component::checkAccess()` returned bare results that discarded the consulted
plugins' cache metadata (e.g. `prop_value`'s resolved-value dependencies), so a
component hidden/shown by a condition could stay cached that way after the
condition changed. Both the neutral and first-forbidden paths now fold every
consulted plugin's cacheability.

### Smaller fixes

- `ComponentTreeHydrated::getValue()` no longer emits an undefined-array-key
  warning when a stored tree references a slot the component no longer declares
  (in dev, the loud shape-must-exist assert still fires first).
- `EntityFilter::massageFormValue()` no longer TypeErrors when a widget left in
  multi/tags mode submits an array for a single-value filter.

## Value groups state a plugin's role

A ComponentValue plugin's `group` used to be a loose label for the prop form's tabs, with
`providers` doubling as a catch-all. It now states what role the plugin plays in producing the
prop's value, and that declaration is what the render pipeline queries.

| Group | Role | Members |
| --- | --- | --- |
| `providers` | source a value | unchanged, minus the five below |
| `fallback` *(new)* | fill it when nothing sourced one | `default` |
| `modifiers` | transform it | unchanged, plus `formatted_text` |
| `settings` *(new)* | don't touch the value — configure the prop | `widget`, `region_size`, `region_custom` |

### For site builders

- The prop form now has **four** sections instead of two. **Default Value** is its own section
  rather than a row inside the Value Providers table; **Prop Settings** collects Widget, Region
  Size and Entity Customizable; Formatted Text moved to **Value Modifiers**.
- The component props table shows one column per group, so Widget and Formatted Text are no
  longer listed under "Value Providers".
- **Bug fix:** a nested prop whose only plugins were `widget`, `region_size` or `region_custom`
  (e.g. a `scheme` child inside an object or array prop, which gets `widget` by default) refused
  the value pushed down from its parent and rendered the component's `examples` placeholder
  instead of the authored value. Those plugins source no value and no longer block the pushdown.
- **No migration.** Group is never persisted — stored settings are keyed by plugin id
  (`plugins.<shapeId>.<pluginId>`) — so existing components are unaffected and no update hook
  runs. Pipeline order is unchanged: group is not a sort key, and ordering stays weight-then-label
  across all groups (`default` at weight 1000 still runs last).

### For plugin authors

- **Pick `group:` by role.** Putting a non-sourcing plugin in `providers` makes
  `ChildrenShapeBase::childHasOwnValueProvider()` block a parent's pushdown, which silently
  replaces authored content with the schema's `examples` in every *nested* prop of that type.
- `ComponentShapePluginCollection::getActiveInstances($groupId)` now implements its long-declared
  group filter, so a shape can be asked a behavioral question without naming plugin ids —
  `getActiveInstances('providers')` means "does this shape source its own value?".
  `ChildrenShapeBase::childHasOwnValueProvider()` is now exactly that one call, replacing a
  hardcoded `default`/`formatted_text` id blacklist that had already fallen out of date.
- `ComponentShapePluginBase::onUpdate()` previously called `getActiveInstances('update')`;
  `'update'` was never a group id and was silently ignored while the filter was unimplemented.
  It now correctly passes no argument.
- Plugins with no `group` still default to `providers`, so third-party value plugins keep their
  current behavior with no changes.

## Configurable value-provider processing model

Value providers now expose a standard, site-builder-configurable **Processing**
select that controls what happens after the provider runs, replacing hard-coded
stop/continue behavior.

### For site builders

Each value provider on a prop now has a **Processing** setting with three modes:

- **Stop when a value is found** (default) — if the provider produces a value it
  becomes authoritative (later providers are skipped); if it produces nothing,
  processing falls through to the next provider.
- **Provide, allow later changes** — the provider's value is used but later
  providers may still replace it (and it survives the `default` provider).
- **Always stop (block if empty)** — the provider halts processing even when it
  produced nothing, so the prop renders empty (this provider is the sole source).

In all modes, modifiers (prefix/suffix/format/size/…) still run on the result.

### For plugin authors

- New `ComponentValuePluginInterface::claimValue()` / `hasClaimedValue()` — a
  semantic alias over `stopFurtherProcessing()`/`shouldContinueProcessing()`
  (both retained, fully backward compatible). "Claiming" halts the provider
  search; modifiers still run.
- New `ComponentValueProcessingModeInterface` + `ComponentValueProcessingModeTrait`
  — a provider `implements` the interface and `use`s the trait, appends
  `processingModeDefaultConfiguration()` to its defaults, and calls
  `buildProcessingModeForm()` in its config form. The pipeline then decides
  whether to claim from the chosen mode + `ComponentShapePluginInterface::isProvidedValueEmpty()`.
  Providers no longer hard-code the claim decision. Override
  `processingModeDefault()` to change a provider's default mode.
- `ComponentShapePluginInterface::isProvidedValueEmpty()` — shared "found vs
  empty" test that ignores the `size` sentinel seeded by the media image size
  modifier.
- External plugins that implement `ComponentValuePluginInterface` **directly**
  (rather than extending `ComponentValuePluginBase`) must add `claimValue()` and
  `hasClaimedValue()`.

### Behavior changes

- The `default` value provider is now a true **fallback**: it only fills when the
  value is still empty, instead of overwriting last. A provider that produces a
  value without claiming now survives to render.
- Value providers that previously stopped unconditionally (`entity_reference`,
  `taxonomy_children`) now stop only when they actually produced a value, so an
  empty result falls through to the next provider.
- `entity_reference` weight changed `5` → `4` so it runs before `entity_query`
  when both are enabled on a prop.
- The bespoke `continue` flag on `entity_query`/`entity_filter`/`views` is
  replaced by the standard Processing mode (`continue: true` → "Stop when a value
  is found"; the old `continue: false` default → "Always stop (block if empty)").
  Update hook `neo_alchemist_update_11002()` migrates existing configuration.
