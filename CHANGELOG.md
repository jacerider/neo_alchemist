# Changelog

## Aggregate components: primary reference with a query fallback

`entity_reference` now serves object/aggregate shapes as well as arrays (an
object takes the first published referenced entity), so the chain every list
prop already used — `entity_reference` on *stop when found* above
`entity_query` on *block* — works on an aggregated component's `_aggregate`
prop. A filled reference claims; an empty or dangling one falls through to the
query; an empty query claims emptiness so schema examples never leak.

Around it, three edges found on the way:

- The Shape Fields form offers **"Copy field mapping from"** when a sibling
  children-match provider on the same shape already carries a mapping — every
  chained pair in real config had the same mapping duplicated verbatim, by
  hand.
- **`prop_value` access rules are aggregate-aware**: the rule form now offers
  the aggregate's child props (the keys `access()` actually finds in the
  unwrapped `getPropValues()`) instead of the synthetic `_aggregate` prop,
  which never appears there and could only produce a rule that always
  forbids. A stored `_aggregate` selection is read as "any prop has a value".
- `EntityReferenceValue` guards against feeding zero entities to the children
  matcher (which on a non-iterable shape produces a claimable map of
  empties). Unreachable today — `MatcherReference::getReferenceField()`
  returns NULL when the first target fails to load — but pinned so a matcher
  change cannot silently starve a fallback.

## Result counts from the view: the views_summary prop

A listing that renders `{{ items|length }}` next to its filters is showing the
size of one page, not the number of matches — and with filters live that is the
number a visitor uses to judge what they just did. The new `views_summary` prop
reads the counts off the same executed view the `views` provider binds: `total`,
`count` (this page), the `start`/`end` window, `page`, `pages`, `per_page`.

Deliberately plain numbers with no twig helper object. Unlike `views_filter`,
where the helper carries wiring a template can silently get wrong, there is no
hazard here — so the wording stays the template's decision, and
`{% trans %}…{% plural %}…{% endtrans %}` gives real per-language pluralization
that a prebuilt string could not. `{{ summary.total }} results` and
`Showing {{ summary.start }}–{{ summary.end }} of {{ summary.total }}` come off
the identical prop and the identical provider config. The field set mirrors
core's views `result` area handler tokens, as data rather than a format string.

The one field with no core equivalent is `exact`, and it is load-bearing.
Core's area handler forces a count query (`$view->get_total_rows = TRUE` in
`query()`); this provider runs long after the view executed, so it cannot — and
forcing it inside `ViewsValue` would add a count query to every views-bound prop
on the site, a second search execution on Search API. Instead the provider asks
whether a count query *ran*. That distinction is the whole risk of the feature:
core's `Sql::execute()` assigns `$view->total_rows` **unconditionally**, outside
the guard that decides whether to count, so on a SQL view the property is never
NULL — it is `0` under a `none`/`some` pager and a plausible-looking lower bound
under `mini`. Only Search API leaves it NULL. A `total_rows !== NULL` check would
therefore report "0 results" on an unlimited view displaying five rows. When no
count ran, `total` is the rows-seen-so-far lower bound and `exact` is FALSE so a
template can render "142+" or hide the number; with a full pager, or with paging
off (where the tally *is* the total), it is TRUE.

Alongside it, all three views providers now document themselves. Their config
forms carry an "Available in twig" reference listing every variable and helper
method against the prop's real name, with copy-pasteable snippets — because what
a template can reach is not visible from the prop schema, and for `views_filter`
in particular the difference between `getHidden()` present and absent is a
filter UI that composes with its siblings versus one that silently clears them.

Internally, the views-context plumbing (the context select, `getContextView()`,
the query cache context) moved from `ViewsExposedFilterValueBase` up to a new
service-free `ViewsContextValueBase`, so a views-backed provider needing no
services of its own carries no container wiring. The static ordering lint in
`drush neo:alchemist:validate` now covers `views_summary` too — a prop declared
before the views-bound prop is still the single most likely way to get nothing.

## AJAX result swapping for designed filter UIs

Filtering now updates results in place. Because every views_filter interaction
is a real URL (option links, chips, GET mini-forms — and the views_pager
slot's links), the `neo_alchemist/swap` library can intercept same-path
navigation inside a component boundary, fetch the destination page, and swap
the same component's subtree by its neoUuid — with pushState/popstate history,
focus restoration (a visitor typing in search keeps their caret), an aria-live
announcement fed by a data-neo-swap-announce marker, and behavior re-attach so
component JS re-initializes (Alpine picks the new tree up via its own
observer). Core views AJAX can't apply here — value-mapped items render
through the component's twig, not a views container — which is exactly why the
URLs-first design was chosen: this enhancement is zero PHP and zero contract
change.

Opt-in per component: `data-neo-swap` next to the `data-neo-uuid` root
attribute plus a `libraryOverrides` dependency on `neo_alchemist/swap`;
`data-no-swap` opts individual controls out. Card links point at other paths
and are never intercepted. Any pipeline failure — non-OK response, boundary
missing from the reply — falls back to the navigation that would have happened
anyway; post-swap decoration (focus, announce, scroll) is isolated so a
cosmetic error can never trigger that fallback. Paging is AJAX for free.

## Filter twig helpers, text filters, and the views_active_filters prop

Three additions on top of the views_filter prop, aimed at making a fully
designed filter UI the path of least resistance:

- **Helper objects** (the neo_swiper SwiperTwig pattern). A views_filter prop
  value is now a `ViewsFilterTwig`: ArrayAccess keeps every existing template
  working, and `get*()` methods hand over the wiring a hand-written GET form
  can silently get wrong — `getForm()` (method/action), `getHidden()` (the
  carry inputs; forgetting them clears sibling filters on submit),
  `getCheckbox()`/`getRadio()`/`getTextfield()`/`getLink()` (attribute
  clusters, chainable Attributes). Method names use the get prefix because
  Drupal's Twig sandbox only allows get/has/is-prefixed method calls on
  objects. The objects are JsonSerializable so SDC prop validation sees the
  wrapped data; core's class-typed-prop support turned out unusable for mixed
  `[object, FQCN]` types (it nullifies the value but keeps `object` required).
  Wrapping happens both in the new ViewsFilterShape (preRenderValue — covers
  example/preview data) and in the provider's modifyValue (resolved data).
- **Text filters.** A filter whose widget has no options (fulltext search)
  now resolves too — empty `options`, plus a `placeholder` key — so a search
  box becomes a designed mini-form like everything else. With every filter
  designed and each mini-form printing getHidden(), no native exposed form is
  needed on the page at all; the hide-the-native-widget rule now applies only
  to mixed-mode pages.
- **views_active_filters prop + Views | Active Filters provider.** The
  designed replacement for the active_filters module's views area: every
  applied exposed-filter value becomes a chip item with a resolved label
  (clean term names on hierarchical filters, the entered text on search) and
  a remove_url toggling just that value; clear_url drops everything. The
  value is a ViewsActiveFiltersTwig with getLink()/getClearLink() adding
  aria-labels and rel="nofollow". Both providers share
  ViewsExposedFilterValueBase; the validate() ordering lint covers both prop
  types.

## Exposed views filters as data: the views_filter prop

Styling an exposed filter meant styling Drupal's form markup — the opposite of
how every other designed surface here works (the menu prop: pure data, mocked
via examples, markup owned by the theme). The new `views_filter` prop def and
`views_exposed_filter` value provider extend that pattern to filters, resting
on one fact: an exposed filter is a GET parameter, so a designed link — or a
hand-written GET form of native checkboxes — is a fully valid submission
surface. No Form API, no JS required.

The provider reads the same `views` prop-shape context the Views slot plugins
use, plucks one exposed filter, and emits: `label`, `param`, `multiple`,
`active`/`active_count`/`active_labels`, `value` (always an
array), `reset_url`, `action` + `carry[]` (hidden-input pairs so a mini-form
preserves every other query arg), and `options[]` — a `{label, value, url,
active, below[]}` tree. Taxonomy-backed filters get real hierarchy from term
storage, and the `(object) ['option' => [id => label]]` wrappers hierarchical
selects put in `#options` are unwrapped; every option URL applies or toggles
its value and resets paging.

Resolution happens at the **modify stage** of the value pipeline, not at
default time: defaults resolve during shape init inside `loadPropShapes()`,
where the views provider has not executed its view yet — and where forcing a
shape build recurses fatally, which is why
`ComponentInterface::getPropShapeContexts()` grew a `$build = TRUE` parameter
that in-pipeline callers pass FALSE. When the filter cannot be resolved the
prop renders empty on a live page and keeps its example scaffolding in the
editor preview.

Interaction style is deliberately NOT config. A template picks ONE markup per
filter — links for single-select, a GET form for multi — and hardcodes
auto-submit if the design wants it. (A `behavior` plugin setting existed
briefly and was removed: a config switch that only works when the template
opted in reads as a toggle that silently does nothing.)

Constraints, by design: views_filter props must be declared after the
views-bound prop (props build in schema order; `neo:alchemist:validate` now
lints this), the filter must stay exposed on the view, and the exposed-form
override template should hide — not omit — the native widget so a search
submit keeps the designed filters' query args. Text filters stay in the real
exposed form. AJAX result swapping is a planned enhancement (fetch-and-swap on
the component boundary; components can mark it with `data-neo-uuid` now);
core's views AJAX cannot apply, since value-mapped items render through the
component's own twig, not a views container.

Hardening that fell out of it: `ArrayShape::buildValue()` no longer fatals on
a scalar-items array (`items: {type: string}` — `active_labels` was the first
real one; the child-shape loop now skips scalar deltas instead of unsetting a
string offset).

## Slot contents are now themeable from the component directory

A component's Twig had no say in what a site builder dropped into a slot. Slot
children were keyed by their config UUID, so `{{ header.filters }}` was
impossible; there was nowhere to put slot markup, because core's generated
`{% embed %}` overrides `{% block header %}`; and reaching an item's internals
meant a preprocess function or a hand-wired template override.

Three additions, each opt-in by dropping a file into the component directory —
components that ship neither file behave exactly as before:

- **Stable Twig keys.** `ComponentSlot::toRenderable()` keys children by the
  resolved key from `getKeys()` — an optional per-item `key` in config, else the
  plugin id, suffixed `_2`/`_3` on collision and seeded against the reserved
  context names. A **Twig key** column on the slot's Customize form sets it, and
  `toArray()` persists it only when it differs from the plugin id, so existing
  exported config is untouched until somebody sets one.
- **`slots/<slot>.twig`** — arranges the items. When present, the slot renders
  through an `inline_template` including that file, with each item available by
  its key plus `items`, `slot` and `neoIsPreview`.
- **`slots/<hook>--<component>--<slot>--<key>.html.twig`** — controls one item's
  internals. Ordinary theme suggestions, added automatically to each child's
  `#theme`, so the filename is the whole wiring. Because `#theme_wrappers` is
  applied *around* `#theme` output, a form keeps its `<form>` tag and `#action`
  while the template places the individual widgets.

Discoverability, since a mis-named template fails silently:
`drush neo:alchemist:slot <component> [<slot>]` prints each item's key, theme
hook, the exact filename to create and that file's variables;
`neo:alchemist:validate` warns about a `slots/*.twig` matching no declared slot;
development environments wrap each item in an HTML comment carrying the same
facts; and `{{ neo_inspect() }}` (neo_twig, gated on `twig.config.debug`) lists every
variable in scope, or walks one value's children when passed an argument.

**Upgrading:** slot render-array keys changed from UUIDs to names. Nothing can
have depended on the UUID form portably — they are minted per site — but a
`hook_neo_component_build_alter()` implementation indexing
`$build['#slots'][$slot][$uuid]` needs updating to the item's Twig key.

## A view's cache max-age no longer overwrites the component's

`ViewsSlotBase::addViewAsCacheableDependency()` and `ViewsValue::getView()` both
called `setCacheMaxAge()` on cacheable metadata they do not own — a slot's
`getCacheableMetadata()` hands back the *component's* single shared object, and
`setCacheMaxAge()` overwrites rather than merges. Three Views slots and the
value provider all write to it, so whichever ran last won: a permissive view
could raise a max-age an earlier contributor had lowered to 0 and quietly
overcache the whole component. Both now `mergeCacheMaxAge()`, which keeps the
stricter value.

This was invisible while every Views-backed component happened to use a
zero-max-age cache plugin, and became reachable the moment one didn't.

### Views slots do less work

- **Exposed filters** reuse `$view->exposed_widgets`. `ViewExecutable::build()`
  already builds the exposed form during `execute()` and memoizes it there, and
  core reads that memo back rather than re-calling. The slot was calling
  `renderExposedForm()` a second time — a full `FormBuilder` run, so every
  `hook_form_alter` and every exposed handler's build/validate/submit fired
  twice. The old call remains as a fallback, since the memo is legitimately
  empty when the display renders its exposed form as a block.
- **Header** adds the view's cacheability once instead of once per rendered
  header handler. On a Search API view that is not a cheap thing to repeat:
  `CachePluginBase::getCacheTags()`/`getCacheMaxAge()` are unmemoized, and
  `SearchApiQuery` overrides both to walk the result rows and merge tags per
  row — so the old shape was O(handlers × rows).
- **Pager** now matches core's own call: it honours the display-level
  `renderPager()` boolean, and passes `$view->getExposedInput()` instead of an
  empty array. For filters carried in the URL this changes nothing — Drupal's
  pager re-merges the current request query into every link — but the exposed
  input is the only carrier when it is not in the query string: filters
  remembered in the session, input set via `setExposedInput()`, a Views AJAX
  request, or two views on one page with different exposed state.
- `ViewsValue::getView()` memoizes a *failed* view lookup. The guard tested a
  property whose default is `NULL`, so a configured-but-unloadable view re-ran
  `Views::getView()` on every call for the rest of the request.

## The Views value provider works with Search API views

Pointing the **Views** provider at a Search API view used to dead-end on "the
view does not have a corresponding entity type" — no display select, no field
mapping, nothing. The provider resolved the entity type by comparing the view's
base table against each entity type's base and data table, which only ever
matches a core entity view. A Search API view's base table is
`search_api_index_<id>`, and Search API declares the entity type on its
per-datasource sub-tables rather than on the index base table, so
`ViewExecutable::getBaseEntityType()` did not rescue it either.

Resolution is now a fallback chain: base/data table, then the Views data's
`entity type`, then the index's datasources. Because an index can carry several
datasources, the form no longer guesses — it shows **Result entity type** and
**Result bundle** selects, pre-filled from what was detected and disabled when
there is nothing to choose. Bundle detection was generalised along the way: it
used to key on a filter literally named `type`, and now resolves an index field
(`node_type`) through the index to the entity's bundle key, honours the
datasource's own bundle restriction, and ignores negated filters, which say what
the bundle is *not*.

Getting the bundle right is the part that matters in practice: without it the
field matcher offers base fields only, so every `field_*` quietly disappears
from the mapping UI.

The provider keeps its `views` plugin ID, so the Views pager, exposed filters
and header slots keep working on a search view — which is the point, since an
exposed fulltext filter and a pager are most of why you'd use one.

### Fixes that came with it

- Rows whose entity could not be loaded are skipped instead of producing null
  entries. A Search API index returns a row per indexed item and only attaches
  an entity when the item's original object is loadable.
- Values bound to a **rendered Views field** (`_view:`) read the correct row.
  The delta handed to the fetch handler counts the entities that survived
  filtering, not the rows, so it drifted whenever anything was dropped — an
  unpublished entity with "published only" on was enough. The row is now
  resolved from the entity itself.
- **Sort results by argument values** no longer emits every entity twice. The
  reordered groups were appended to the untouched list instead of replacing it.

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
