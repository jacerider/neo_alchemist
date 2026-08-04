# Changelog

## Raw SDCs can capture their own thumbnail.png

The component listings show a thumbnail per component, but a raw SDC had no way
to produce one — only saved components could capture a preview, and they stored
it as a `neo_config_file`. So in practice almost nothing shipped a thumbnail and
the listings were a wall of the same placeholder, which is precisely where being
able to tell components apart matters most.

The SDC preview workspace now offers a **Capture thumbnail** button that writes
the rasterized preview to `thumbnail.png` **inside the component's own
directory**, beside the `.component.yml`. That is the file core already looks
for, so it needs no configuration, travels with the component in git, and works
on any site the component is copied to.

Writing into the codebase is only defensible on a working checkout, so the
button is gated on the environment looking like one: the Neo dev server running,
or the dev config split being enabled. It does not render at all otherwise, and
renders disabled — naming the directory — when the environment qualifies but the
folder is not writable. The endpoint enforces the same gate rather than trusting
the button.

Most of the machinery already existed. The preview iframe was already loading
the rasterizer, and the framing toolbar already worked here; what was missing
was a button and somewhere to put the result.

### For site builders

- The button appears on a local environment either way, but the config split is
  the steadier signal — `$config['config_split.config_split.dev']['status'] = TRUE;`
  in `settings.local.php` holds for the whole environment, where the dev-server
  signal comes and goes with `npm start`.
- A captured `thumbnail.png` also becomes the fallback for **every saved
  component wrapping that SDC** that has not had its own thumbnail uploaded, via
  the existing `getDefaultThumbnail()` tier.
- Thumbnails in all three listings are now cache-busted by the file's modified
  time. Re-capturing overwrites the same filename, so without this the browser
  kept serving the previous image and a capture looked like it had failed.

### For developers

- New `neo_alchemist.sdc_thumbnail_writer` service owns the gate, the path
  resolution and the write; the form and the endpoint both defer to it so they
  cannot disagree about what is possible.
- The three near-identical thumbnail cells in `SdcPreviewListController`,
  `ComponentLibraryController` and `ComponentListBuilder` are now one
  `ComponentManageHelper::buildThumbnailCell()`.

## A required prop keeps a resolved zero

After the value providers on a prop have been searched, a **required** prop
falls back to the component's schema example rather than resolving to nothing,
so SDC is never handed a missing required prop. That guard used PHP truthiness,
so a provider that legitimately resolved `0`, `'0'`, `FALSE` or `[]` had its
answer thrown away and the component's placeholder rendered in its place — the
same silent substitution fixed across the rest of the value pipeline, surviving
in the one spot the earlier sweep did not reach. It now uses the pipeline's own
emptiness contract.

### For site builders

- A required prop whose value resolves to zero now renders **zero** instead of
  the component's example text. Render-visible, and the reason this is its own
  entry.
- Unchanged: a required prop that resolves to genuinely nothing still falls back
  to the example.

### The "Processing" mode's scope is now stated, not left open

No behavior change — the trait's docblock previously flagged its own scope as an
open question, and the question is now answered in the negative. The mode
governs the **provider search** and nothing else, because that is the only pass
where "which plugin wins" is a decision at all. Two things are now documented
and pinned by tests rather than left to inference:

- Widening it to the modifier pass would be destructive, not an improvement.
  Every pass walks one instance list sorted providers → fallback → modifiers, and
  a provider takes part in the modifier loop too, so a provider in the **default**
  mode that found a value would break the loop before `prefix`, `suffix`, `token`
  or `formatted_text` ever ran.
- The override pass carries the value a person authored, so a provider's mode has
  no business claiming there. "Always stop" cannot suppress authored content.

The **Processing** description on the prop form was rewritten to match: it used
to promise that "Always stop" meant nothing renders, which was never true for a
required prop.

## Single-prop arrays can be matched by property type

A shape can redirect its support checks at a different field definition —
`ArrayShape` does exactly that, deferring to its single child so an array of
strings is matched as a *string* rather than as the `map` field the array
stores into.

That redirect only reached half the decision. The four support predicates
followed it, but the accepted-type lists behind them
(`getSupportedFieldPropertyTypes()` / `getSupportedFieldTypes()`) read the raw
field item instead — describing the `map` storage, which exposes no properties
at all. The accepted list came back empty, so every property-type check
returned FALSE.

Both now read the same definition the predicates use.

### For site builders

- A field whose FIELD type differs from the array child's, but whose single
  PROPERTY type matches, can now be bound to a single-prop array. `uuid` is
  the clearest case: field type `uuid`, property type `string` — previously
  unreachable for an array of strings, while an ordinary string prop could
  use it.
- **Nothing was removed**, and non-array shapes are entirely unaffected: for
  them the two definitions were already the same object.
- Unchanged: an array still claims a *multi-property* field whole (so the
  field's deltas feed the items) rather than offering per-property matches.
  That is a separate, deliberate strategy.

## Password fields are no longer offered as component prop sources

MatcherField matches entity fields to component props by **data type**, and
data types are deliberately coarse — a password field's `value` property is a
plain `string`, indistinguishable from a title. Nothing filtered sensitive
fields, so every string-accepting prop was offered the user's password hash as
a content source.

This was not limited to user-targeted components: entity types routinely carry
a base reference to a user (`entity_test.user_id`, `node.uid`), and the matcher
recurses one level into references — so `user_id.pass:value` appeared in the
match list for ordinary components. A plain text prop on this fixture was
offered 70 matches, four of which reached a password.

### For site builders

- Password fields no longer appear in the "match" picker for any prop.
- **Nothing else was removed.** Identifiers (`id`, `uuid`, `target_id`) and
  system fields (`langcode`, `timezone`, `roles`) are still offered — they are
  noisy rather than dangerous, and excluding them could remove matches real
  components rely on.
- **No migration, no breakage.** Only the offer list is filtered; resolution is
  untouched, so a component already configured against an excluded field keeps
  working exactly as before. It simply cannot be re-selected from the picker.

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
- **The `token` modifier no longer renders the literal "Array".** Its `[value]`
  placeholder cast the incoming value to a string unconditionally. Because that
  value comes from whatever ran earlier in the pipeline, an array (a provider
  handing structured data to a string prop) produced an "Array to string
  conversion" warning and put `Array` on the page, and an object without
  `__toString()` threw outright. Non-scalars now contribute nothing to the
  template.

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
