# Changelog

## A row's delta reaches every shape beneath it

Shape ids joined the flat name path and appended only the shape's *own* delta. A shape two
levels under an iterable holds none — an object builds its children without one — so the title
inside every row of a list was the same `items~heading~title`. Everything keyed by id collapsed
with it: `getAllShapes()` merges with `+=` and kept row 0 alone, `prepForm()` gave every row one
`previous_value` slot, and the editor stamped one `data-neo-prop` across every row's field, so
hovering the third card in the preview focused the first card's input.

Ids are now composed from the parent's, which leaves each ancestor's delta at its own depth —
`items~heading~1~title`. That is the key `NestedOptionMap::childKey()` has always written, so the
read side finally agrees with the write side; it is also what keeps a shape's id a prefix of its
descendants', which the preview's coarse-to-fine highlight depends on.

`getNestedTitle()` walks the ancestors the same way, so a sub-prop of a row is labelled
"Items 3 …: Title: Title" rather than being one of five identical "Items: Title: Title".

**The delta-free id — `id(TRUE)` — is byte-identical to before.** That is what addresses the
config side: the `expression` string, `settings.props.*.plugins` and `.expanded`, the prop form's
tabs, `FieldSelect`'s `#shape`. No component config moves. Three lookups that compared the
*full* id against those delta-free keys now compare the delta-free one, which they always meant:
`isExpanded()`, `allowConfigurablePlugins()`, and the stored plugin list in
`getValueCollection()` — the last of which, as a consequence, also lets a shape rendering one of
an iterable's rows find the value plugins configured for it, which it silently missed before.

### Options saved under the old key still apply

Per-shape empty/default/access options live in content (the component tree field's `props`
column) and in config (field defaults, the `default` value provider), keyed by shape id. Anything
saved for a sub-prop inside a row landed on the delta-free key. `initOptions()` reads that key as
a fallback whenever a delta is in play, so every row resolves to the value it had when they
shared one — no update hook, and nothing to migrate. The next save writes per-row keys and leaves
the old one behind as an orphan; that is expected rather than a bug.

Row reordering now carries a row's descendants with it. `ArrayShape::massageFinalValues()`
remapped option keys for direct children only, so a heading's per-sub-prop options would have
stayed pinned to the position a dragged row left behind.

## Preview hover targets follow content, not style

Hovering the preview highlights the form field behind what you are pointing at. Style props
used to take part in that, and on any component that merges its reveal onto a content
wrapper — the documented `apply: false` animation pattern — the result was that one wrapper
swallowed the whole component: every hover landed on an animation field rather than the
heading, image or link under the cursor. Worse, it landed on the *wrong* animation field.
`Attribute::merge()` deep-merges `toArray()` and the annotation is a scalar, so a chained
`animate.merge(animate_speed).merge(animate_delay).merge(animate_stagger)` kept only the last
id, leaving the other three unreachable from the preview in either direction.

**Style shapes are no longer annotated.** A style attribute decorates an element rather than
owning it, and Twig prints it wherever the classes are needed, so it is not a reliable
statement about what that element *is*. Preview DOM now maps to fields through
`PreviewPropMapBuilder`'s content hints — an element's text, an image's source, a link's href
— which point at the prop an editor actually means. Hovering a card selects its Image, Title
or Link; hovering section padding or a grid gutter selects nothing, and the animation fields
stay where they belong, in the form.

This applies to every `ComponentShapeStylePluginInterface` implementation — `StyleShape`
(the `animate*`, `spacing`, `gap`, `button_style` and heading `size` props among them),
`SchemeShape` and `ImageSize`. The annotation seam itself is unchanged for any other
`Attribute`-carrying shape.

### The SDC preview workspace gets a prop map

`/admin/config/neo/alchemist/preview/{component}` never attached one, so it had no content
hints and no hover labels at all — only the server-annotated elements were targets, which is
why a wrapper annotation dominated it so completely. `SdcPreviewController` now attaches the
same `drupalSettings.neoAlchemist.propMap` the field-editor preview has always had.

That workspace can also render neighbor components above and below the previewed one, and the
iframe's index was document-wide: it scoped hints to the *first* `[data-neo-component]` it
found, which is the neighbor above, and marked annotated elements without scoping at all. It
now scopes both to the uuid the prop map names, so neighbors are inert and cannot claim a
hint from the component the form is editing.

Editor state — the collaborative layout draft, the per-user live form buffer, and the SDC
preview workspace's prop overrides — used to be reachable directly through public methods on
the component tree field item and the `neo_component` config entity. A caller could construct
a draft key, read or write draft state, and — because invalidating the draft's cache tag was
a *separate* call the writer had to remember — ship a permanently stale preview by forgetting
one line: Dynamic Page Cache keeps serving the pre-edit render without re-running the
controller, with nothing in the logs.

That storage now lives behind stores in `src/EditorState/` — an `EditorStateStoreInterface`
with per-user, durable, disposable and in-memory adapters — and the public methods that
exposed it are gone. Each store owns its own key derivation and folds cache-tag invalidation
into the write, so the sharing semantics of a piece of editor state are now a property of the
store you reach for rather than of the arguments you pass, and no path can ship a stale
preview by omitting a line. The collaborative draft also became **visibly** shared: it carries
a version and a last editor, a stale write is refused rather than silently winning, and
publish names the other contributors. The [Draft model](ARCHITECTURE.md) section of
`ARCHITECTURE.md` describes the whole arrangement.

Most of this is internal, but three surfaces are published interfaces that external callers
could bind to, so they lead. **Anything outside this repository performing its own draft I/O,
or reading preview state off the component entity, breaks — which is the intent, since doing
so is how a caller shipped a permanently stale preview.**

### BREAKING (PHP): draft storage methods removed from the component tree field item

`ComponentTreeItem` no longer exposes its draft storage. These methods are **gone**; the
storage moved to `SharedDraftStore` (service `neo_alchemist.shared_draft_store`):

- `getDraftValue()` → `SharedDraftStore::get($item)`
- `setDraftValue()` → `SharedDraftStore::set($item, $value)` (invalidates the cache tag inside the write)
- `deleteDraft()` → `SharedDraftStore::delete($item)` (invalidates inside the write)
- `getDraftCacheTag()` → `SharedDraftStore::cacheTag($item)`
- `getDraftKey()` → **removed with no public replacement** — the key derivation is now private to the store (see below)
- `getState()` → **removed** (its only callers were the draft methods above)

`hasDraft()` **stays** on the item as a thin delegate to `SharedDraftStore::has($item)`, and
the draft-mode flag (`enforceAsDraft()` / `isDraft()`) is unchanged. A PHP caller that read or
wrote draft state through the item must call the store instead.

### BREAKING (PHP): the draft key derivation is private to the store

The draft key is no longer constructable by any external caller — `getDraftKey()` is gone and
`SharedDraftStore` derives the key privately. The key now folds in the **entity type** and
**langcode** (`<entityTypeId>.<entityId|targetEntityTypeId>.<fieldName>.<langcode>`), fixing a
latent collision where drafts collided across entity types and translations shared one draft;
the revision is deliberately excluded (a draft is pre-publish). `cacheTag()` remains public
(the preview controller must tag its response), but because the key is private there is no way
to write a draft without invalidating the tag that makes the preview notice — invalidation is
a postcondition of the store's `set()`/`delete()`, not a line a caller can forget.

### BREAKING (PHP): preview-state methods removed from the `neo_component` entity interface

Twelve cache-backed preview-storage methods left `ComponentInterface` and `Component`; they
moved to the per-user `SdcPreviewStore` (service `neo_alchemist.sdc_preview_store`), whose key
folds in the current user so one developer's overrides and their Reset are their own:

- values — `hasPreviewValues()`, `getPreviewValues()`, `setPreviewValues()`, `resetPreviewValues()`
- styles — `hasPreviewStyles()`, `getPreviewStyles()`, `getPreviewStyle()`, `setPreviewStyle()`, `resetPreviewStyle()`
- context — `getPreviewContext()`, `setPreviewContext()`, `resetPreviewContext()`

The entity keeps the in-memory flags that say "this render is a preview", but two signatures
changed so the entity no longer reads the ambient request:

- `isComponentPreview()` now takes an optional route match — `isComponentPreview(?RouteMatchInterface $routeMatch = NULL)` — instead of reading `\Drupal::routeMatch()`. With no route it reads the cached flag.
- `toRenderable($isFirst, $isLast)` gained a trailing optional route argument — `toRenderable($isFirst, $isLast, ?RouteMatchInterface $routeMatch = NULL)` — which primes that flag at the render boundary.

`invalidateDerivedSettings()` is now **public** and on the interface (additive), because the
preview store calls it as the postcondition of a value write.

### New: the shared draft's collaboration model

Additive, no interface removed. `SharedDraftStore::set()`/`delete()` accept an optional
`?int $expectedVersion`; a write whose carried version is strictly behind the stored one is
refused with a `DraftConflictException` (new, `src/EditorState/`) rather than silently
overwriting a colleague's work. Presence ("@editor edited this 2 minutes ago") and publish
attribution (the confirmation names the other contributors) are thin reads of the same draft
record. A lock was considered and rejected in favour of this optimistic detection.

### In-flight drafts are preserved

**Drafts written under the old key scheme are re-keyed, not dropped.** `neo_alchemist_update_11008()`
walks the `state` collection, moves each old whole-tree draft entry
(`neo_alchemist.<entity id>.<field>`) to its new key under the shared draft store's prefix —
recovering the entity type and langcode the old key lacked, which also resolves the
cross-entity-type / cross-translation collision — seeds the version counter and the rest of
the record metadata a pre-migration draft lacks, and reports the migrated count. A draft whose entity no longer
exists is left in place and reported rather than dropped. **Preview values need no migration:**
they are cache-backed disposable scratch by design, and losing them on update is correct
behaviour, not data loss.

### Before you update a site

Two things are invisible to PHP static analysis, so audit them by hand before updating:

- **Custom PHP performing its own draft I/O** through `ComponentTreeItem` — `getDraftValue`,
  `setDraftValue`, `deleteDraft`, `getDraftKey` or `getDraftCacheTag`. Move it to
  `SharedDraftStore`. `grep -rn 'getDraftValue\|setDraftValue\|deleteDraft\|getDraftKey\|getDraftCacheTag'`
  over a site's custom modules and themes surfaces it.
- **Anything reading preview state off the component entity** — the twelve preview methods
  listed above. Move it to `SdcPreviewStore`, or pass a route to `isComponentPreview()`.

On this site there are none.

## Editor ops and routes become one table — BREAKING for custom editor JavaScript

The eight operations the editor offers on a component — edit, delete, sort,
clone, add before/after, move up/down — and the routes, link templates and op
vocabulary behind them used to be written four times across PHP, Twig and
TypeScript, with nothing checking the four derivations still agreed. They are now
**one table**: a route-family builder each host scope calls, an op inventory the
chrome and the component both read, and a link-template set derived from the same
source. Adding an operation is one row; adding a host scope is one call.

Almost all of it is an internal refactor with no outward effect. The **one thing
a site can act on is the editor's `data-component` payload**, so it leads.

### BREAKING (editor JavaScript): the component emits editor ops as records, not booleans

**This concerns only sites with custom JavaScript that reads the editor's
`data-component` attribute, or that builds editor URLs from
`drupalSettings.neoAlchemist.baseUrl`. A PHP-only consumer is unaffected — no PHP
signature, route name, path or stored data changes.**

The component used to stamp the editor's eight ops into `data-component` as a
flat map of booleans (`{"edit": true, "move-up": false, …}`) and let the client
infer everything else. Each op now crosses that seam as a **record**:

```json
{"edit": {"id": "edit", "permitted": true, "url": "/…/edit/…",
          "label": "Edit", "verb": "edit", "position": null}, …}
```

The server resolves each op's URL through its own generator (so path processing,
language prefixes and aliases apply) and copies the op's label/verb/position from
the one op vocabulary (`EditorOpInventory`). Custom JS that tested `ops[op]` for
truthiness will now treat **every** op as permitted, because a record is always
truthy — read `ops[op].permitted` instead. The module's own client is updated in
step: its show/hide pass and its op-execute gate read `permitted`.

**The attribute keeps its name and location**; only the structure inside it
changes. **Access decisions are identical** — the same access calls in the same
order — and move up / move down keep their strict position comparison, so an
unknown position still withholds the op.

### Before you update a site

Two things are invisible to PHP static analysis and to every test, so audit them
by hand before updating:

- **Custom JavaScript reading the `data-component` attribute.** It now finds a map
  of records where it used to find a map of booleans. A truthiness test
  (`if (ops[op])`) now passes for every op — read `ops[op].permitted`, and read the
  op's URL off `ops[op].url` rather than building it.
- **Anything reading `drupalSettings.neoAlchemist.baseUrl` to build editor URLs.**
  Each op now carries its own server-generated URL; the base URL is no longer how
  you construct one.

`grep -r data-component` and `grep -r drupalSettings.neoAlchemist` over a site's
custom modules and themes surfaces both. On this site there are none.

### The editor client reads each op's URL instead of building it

The editor's TypeScript (`src/js/components-parent.ts`) no longer constructs its
nine request URLs by string-concatenation off
`drupalSettings.neoAlchemist.baseUrl`, and no longer infers a verb/position by
splitting an op id on a hyphen at runtime. Every per-component op now reads the
`url` the server emits on its record (above); the toolbar Add/Sort actions read
their action link's own server-generated `href`; a seam insertion point reads its
sibling component's add-before/add-after record URL. A genuinely client-side
parameter — the container an add/move lands in, the region and component a sort is
scoped to — is appended as a **query** parameter through the URL API, never as a
path segment.

Because the paths are now generated server-side, a site with a **non-standard
base path, a language prefix or a path alias** stops being a source of broken
editor requests — the case the old concatenation got wrong. `baseUrl` survives
only as the layers-panel storage key, which builds no URL.

### The `move` and library-position editor paths gain server-side URL generation

Two of the editor's paths had no server-side URL generator: **move** (append a
component in a direction) and **library with a position** (add before/after a
sibling, optionally within a parent). They existed only as client string
concatenation off a base URL. Both are now addressable through the URL generator
in every host scope, which is what lets each op carry its own URL for the client
to read:

- **`move`** is a component-instance rel — `$instance->toUrl('move', ['direction'
  => 'up'])` (`ComponentEntity` in the entity scope; `ComponentField` in the
  field-UI and block scopes) — carrying the direction as a path parameter and an
  optional `parent` through the query.
- **`library`** now passes its options through, so a `before`/`after` position and
  an optional `parent` ride as query parameters on the generated URL
  (`ComponentTreeItem`/`ComponentFieldConfig`, the block scope deferring to the
  latter).

Additive alongside these: the entity-scope `library` and field-UI/block-scope
`clone` rels gained the server-side URL arms the emission needs to address every
op in every host scope.

### The editor's `alchemist.region` link template and rel are removed

The entity-scope editor link templates now derive from the same table as the
routes (`EditorRouteFamily`), so a path lives in one place. Two mismatches that
table exposed are resolved:

- **`alchemist.region` is removed** — both the link template and the
  `ComponentEntity::toUrl('region')` generator arm are gone. No route was ever
  registered for it, so asking an entity for that URL
  (`$entity->toUrl('alchemist.region')`) raised a route-not-found error rather
  than returning one. Nothing in this repository called that rel; custom code that
  did was already erroring, and should drop the call.
- **`alchemist.move` is added** — the move route already existed but had no link
  template, so it was previously only reachable by a hand-built path. It is now a
  first-class rel like every other op.

### Internal: one table behind the routes, the ops and the chrome

No outward change, but the shape a maintainer edits is different:

- The **route family** (`EditorRouteFamily`) replaces 24 hand-written route
  registrations in the module's route subscriber and the neo_alchemist_block
  submodule's 160-line mirrored `neo_alchemist_block.routing.yml` (now deleted).
  Each host scope — entity, field-UI, block — is one `build()` call.
- The **block scope's purge opt-out is now a declared decision, not a silent
  omission.** The field-UI scope has a purge route and the block scope does not —
  the deleted YAML left that unexplained. The table records it with its reason
  (purge clears field-wide per-entity layout rows, which a null-storage block host
  never owns), and a cross-scope parity test (`CrossScopeOpParityTest`) fails if any
  scope's route set drifts from what the table declares. **No route was added to the
  block scope**; the decision was to keep the opt-out, now on the record.
- The **op vocabulary** (`EditorOpInventory`) declares each op's verb, position or
  direction, route rel, label and icon once. The editor chrome
  (`template_preprocess_neo_alchemist_overlay()`) and the per-component emission
  both read it instead of restating the eight ops; the client-side hyphen-split
  that used to infer a verb/position from an op id is gone.

**Route names, paths and link templates other than those named above are
byte-identical to before**, so routing, URL generation and any configuration
referencing a route are unaffected. **No stored data changes meaning; no update
hook.**

## "Only use published entities" now reaches every level of a mapping

**This is a content-visibility change. Check it before you update.**

A children-match provider — `entity_query`, `entity_reference`, `views` and the
rest that map an entity's fields onto a component's child shapes — carries an
"Only use published entities" setting, ticked by default. It filtered only the
**first** level of the mapping. A child mapped through one of the pseudo-fields
that walks on to further entities kept none of that filtering:

- **`_reference`** (follow a reference field and map the entities it points at) —
  the referenced entities were never filtered.
- **`_expand`** (map the same entity onto a child's own children) — filtering
  stopped at its level and below, so any `_reference` nested under it walked on
  unfiltered too.
- **`_render`** (run a field through a formatter) — read its field with
  filtering switched off, so a chained render key that passed over an
  intermediate entity followed unpublished intermediates.

Unpublished content reached through any of these rendered on the page with the
box ticked. After this change the setting is resolved once, from where it is
stored on the provider, and applied at every level the mapping reaches. Unticking
it still turns filtering off everywhere — the site builder's choice is threaded
down, not hard-coded on.

**What a site owner sees change:** an unpublished entity that was leaking onto a
page through a followed reference, an expanded child, or a chained render key
stops rendering.

**How to find affected components before updating:** look in
`neo_alchemist.neo_component.*` config for a provider whose stored `shape_fields`
mapping uses a `_reference~…` or `_expand` child, or a `render_field` (the
`_render` pseudo-field), on a provider where "Only use published entities" is on
(it is on by default — the key is `shape_published`, and its absence means the
default TRUE). Those mappings are the ones whose output can change. A component
that maps only plain fields, or that unticked the setting, is unaffected.

**No update hook, no stored-data change.** The setting already exists at the
provider root and already defaults to TRUE; only the reach of the value changes.

## Producers return an outcome, and the children-match trait becomes a service

**Breaking — for code outside this module that writes ComponentValue plugins.**
This is the largest change to the module's published interfaces in its history.
It touches no stored data except one provider (`read_time`, below). If nothing
outside `neo_alchemist` implements a ComponentValue plugin, there is nothing to
change; read "Before you update" for how to be sure.

A prop's value is resolved by running its providers in order and deciding which
one's value wins. That decision used to be a mutable `claimed` boolean each
provider flipped on itself — a flag living on a plugin instance that outlives
the call, exposed as five of the methods on the ComponentValue plugin interface.
A stale flag from one prop could in principle reach another, and a developer
writing a provider could get the claim wrong in ways nothing caught.

**A producer now returns an outcome and holds no state between phases.** Its
contract is `provide(mixed $value): ComponentValueProvision` — `offer($value)`
("here is my value; let the site builder's Processing mode decide its fate") or
`claim($value)` ("this is authoritative; stop here, keep it even if empty"). A
producer that came up empty abstains by offering the value it was handed, so
enabling an empty provider never leaves a prop worse off than not enabling one.
A new `ValueProviderSearch` collaborator threads the seed, applies each
Processing mode, keeps an empty non-claiming result from destroying the threaded
value, and stops at the first claim — the decision that used to be smeared
across the provider instances and the shape base now lives in one testable
place.

**Removed from `ComponentValuePluginInterface`:** `allowFurtherProcessing()`,
`stopFurtherProcessing()`, `shouldContinueProcessing()`, `claimValue()` and
`hasClaimedValue()`. A custom plugin that called or implemented any of them will
not load. Replace a hand-rolled claim with the return value — a veto returns
`ComponentValueProvision::claim(…)` from `provide()`; a plain source needs
nothing beyond the base's `provide()`, which offers whatever
`provideDefaultValue()` produced. On `ComponentValueProcessingModeInterface` the
mutating `applyProcessingMode(mixed): void` is replaced by the pure
`claimsValue(mixed): bool`, and the interface now also declares
`processingModeDefaultConfiguration()` and `buildProcessingModeForm()` (a
producer using `ComponentValueProcessingModeTrait` gets both for free).

**The children-match trait becomes a service and a source interface.** The
915-line trait that seven providers mixed in — a *documented extension point*
the architecture guide told developers to use — no longer exists. In its place:
`neo_alchemist.children_match_mapper`, a container-constructed service that owns
the mapping, the pseudo-field handlers and the published-entity filter; and
`ChildrenMatchSourceInterface`, the two-method seam a provider implements to say
only "here is how I find my entities". A provider outside this repository that
mixed in the trait must move to the source interface — there is no trait left to
mix in. Because the mapper's three collaborators are wired by the container, the
class of defect behind the reported `views` white screen — a provider that
forgot to assign one of the trait's undeclared collaborators, so the form
offered a `_reference~` mapping the render path then fataled on — is now
impossible to write. Each pseudo-field is one `ChildrenMatchHandlerInterface`
class owning its option, its form branch and its fetch together, so those three
can no longer drift apart.

**A producer's role is a type, not a group string.** A provider declares
`ComponentValueProducerInterface`; the provider search and the nested-value
pushdown check select on that interface rather than on the literal group
`'providers'`. A compatibility shim keeps the old rule alive — a plugin still
counts as a producer if it declares the interface *or* is in the `'providers'`
group — so an external provider that has not yet adopted the marker keeps
working; adopt the interface when convenient. Choosing a plugin's `group` for
where it reads best in the form can no longer silently change whether nested
props discard authored content — the failure the architecture guide warned was
silent. `group` keeps sort weight
and form placement only, and is still never persisted, so re-grouping needs no
update hook. A new `late` group sits between modifiers and settings so the two
`views_*` providers can declare that they source their value after the view has
executed; this is a declaration change and their runtime behaviour is unchanged.

**The shape accessor return type narrowed.**
`ComponentValuePluginInterface::getShape()` and the base's `$shape` property are
now typed `ComponentValueShapeInterface` — the Context + Value + cacheability
handle a producer is entitled to — rather than the full
`ComponentShapePluginInterface` union. A custom plugin that reached schema, tree
or form methods through `$this->shape` will not compile until it overrides
`getShape(): ComponentShapePluginInterface` and reaches through
`$this->getShape()`. A subclass declaring its own `getShape()` that returns the
union keeps working; one returning a type wider than the union will not load.

### read_time can now claim its value

**A stored-data change with an update hook — the one item on this list a site
actually sees.** The `read_time` provider produced a value but had no Processing
mode, so it could never claim it: any provider ordered after it silently
overwrote what the site builder had configured. It now carries the mode,
defaulting to "Use its value and stop", so it claims like every other source.

`neo_alchemist_update_11007()` writes that explicit mode onto existing
`read_time` provider instances in `neo_alchemist.neo_component.*`, following the
`neo_alchemist_update_11003()` pattern: walk the config, rewrite in place,
report what changed. A mode a site builder set by hand (block, continue) is left
alone, so the hook is idempotent. The new behaviour arrives with the code
whether or not the hook runs — an unset mode resolves to the plugin default —
and the hook exists to make the change visible in `git diff config/` rather than
silent.

**What a site sees:** a prop where a `read_time` provider was ordered above
another source, expecting `read_time` to win, now behaves as configured —
`read_time` claims and the later source no longer overwrites it.

### Before you update

Audit for **ComponentValue plugins defined outside this module**, and especially
for any that mixed in the old children-match trait or called the removed claim
methods — those are the code that will not load against the new interfaces. **On
this site there are no such custom plugins:** nothing here mixes in the
children-match trait or touches the removed methods, so no site-specific code
needs to change. Sibling `jacerider/*` packages that ship their own providers
(neo_site_settings) are released together against these interfaces — update them
in the same pass, not one at a time. The group re-declarations, the `late` group
and the producer interface are declaration changes that alter nothing a
currently shipped plugin resolves to; the one intentional behaviour change is
`read_time`, above.

## The shape interface is fourteen roles, and a child option means the same on both bases

`ComponentShapePluginInterface` declared 133 methods and one class implemented
them. A developer writing a ComponentValue plugin accepted a shape and had, in
principle, to learn all 133 signatures plus a lifecycle written down nowhere. It
is now 93 methods across **fourteen role interfaces**, each named for what a
caller wants from a shape, with the big interface redefined as their union — so
it keeps its name and every existing type hint keeps working.

A caller accepts the smallest role that covers what it uses. Resolving a value
is `ComponentShapeValueInterface`: eight signatures, twelve with the identity it
extends. The boundaries came from measuring the call sites, not from grouping
the implementation — of the module's 218 shape consumers, the median reaches
into one role and 82% reach into two or fewer.

### Link children now honour the hide, default and lock flags

**This is the one change on a rendered page, and it is worth checking before you
update.**

Two shape bases build child shapes. Only `ChildrenShapeBase` read the hide,
default and lock flags a children-match producer sets on a child;
`StructuredObjectShapeBase` built its children by a different routine and read
none of them. The same producer configuration therefore behaved differently
depending on which base a prop's shape happened to extend, and nothing warned.
`ChildOptionPolicy` is the single owner now, and both bases call it.

In practice this is visible when a producer is attached **directly to a link
prop** — of the children-match providers only `entity_load` can be, since the
query, reference and views providers all require an iterable or expandable shape
and a link prop is neither.

What you may see change:

- **An author's example text stops rendering.** A child left unmapped was
  flagged hidden, the flag was ignored, the schema examples backfilled the gap,
  and the component author's placeholder — `EXAMPLE LINK TITLE` and the like —
  rendered on the page where the site builder had asked for nothing. That gap
  now renders empty, or renders the child's default where one is configured.
  This is the correct behaviour and still a visible difference.
- **A link can stop rendering entirely, and this is the sharper case.** A link's
  `access`, `target` and `options` children are computed rather than authored,
  but the children-match form offers them as mappable alongside `uri`, `title`
  and `icon`. A site that mapped only `uri` now has `access` flagged hidden, and
  link templates guard on it — `{% if cta_link.uri and cta_link.access %}` is the
  shipped pattern — so the link disappears. Grep your components for
  `.access` to find them. That is the consistent reading of "unmapped means
  hidden", and the
  form does label those children "Not mapped", but it is sharper than a
  behaviour change usually gets. If you have a producer on a link prop, map the
  children you need or check the rendered output.

Audited on the site this landed from: all 39 children-match mappings in `config/`
are on `array`, `menu` and `object` refs — every one already on the base that
consumed the flags. No `link`-ref prop carries a mapping, so no rendered output
there changes.

### Fourteen roles, and a union that keeps its name

Every method lands on exactly one role, so nothing is reachable twice and nothing
is lost. Every role extends `ComponentShapeIdentityInterface`, so a caller that
resolves a value can still name the prop it was resolving without widening back
to the union. `ShapeRoleInterfaceTest` pins the arrangement, including a
twelve-method ceiling no role may grow through — a role that grows back toward
thirty has failed even if the implementation behind it shrank.

The tree role deliberately does not narrow: it hands back whole shapes, because
arriving at a parent or the root shape is normally the prelude to asking it
something a tree role could not answer.

Narrowing is also what makes a test double honest. Every test that mocked the
full interface got a hundred-method double of which one to five methods were
stubbed, so a stub naming the wrong role was accepted and quietly did nothing.
All 21 of those full-interface mock sites now go through `ShapeDoubleTrait`, where
a misspelled method fails the test instead of returning NULL and passing.

### The nested-option grid collapses to one map

Fifteen methods — get/set × saved/fallback × three option names — sat over two
protected arrays, serving 20 call sites of which 13 were in one file. Two had no
callers at all, and that is the only reason a latent bug stayed invisible: three
getters took a parameter named `$value`, forwarded it into a key-prefixing slot,
and disagreed about its default, so a write and a read of the same nested option
could land on different keys.

`NestedOptionMap` is the store now, and the only place a child key is built. One
accessor, `getNestedOptionMap()`, replaces the fifteen; the prefixing flag is
gone rather than renamed, because a view already scoped to the calling shape has
nothing to prefix twice. The seven "if I am the root, store; otherwise delegate
to the root" branches become one.

Two sharp edges are preserved deliberately and pinned rather than fixed: reads
see the saved layer alone, and the two layers union by top-level key, so one
saved option discards a shape's whole fallback entry.

### The lifecycle is a type, not an assertion

Roughly thirteen setters had to run before `init()`, and only a minority carried
the `assert(!$this->isInitialized(), …)` that said so — an assertion that compiles
out in production anyway.
`ComponentShapeSetupInterface` now holds the seven things that must happen first,
and the union does **not** extend it, so calling one on an initialised shape is a
compile error. Setup extends the union rather than the reverse: a shape under
construction is a shape with more available, and `init()` returns the union,
which is the handoff.

Two stores could not follow, because they are reached through an accessor that is
still *read* after init — so `NestedOptionMap` and `ChildShapeState` carry their
deadline as a **seal** set at `init()` instead. The seal is per shape, not per
store, since children initialize strictly after their root.

Render mode stops being a hidden flag. `getPropValue()` used to set the
attributes on `$this` while the predicate that read them read the **root**
shape's — so called on anything below a root it wrote a flag nobody read, the
pre-render stage was skipped, and the un-rendered value came back with nothing
said. The attributes are threaded as an argument now, which also means
`getPropValue()` works on any shape: a caller can render one prop of a subtree
without going through `Component`.

### Breaking changes

The shape type went from 133 methods to 100 (93 on the union, 7 on setup).
Because the union keeps its name, **every existing type hint still compiles**.
What breaks is code outside this repository that *implements* the interface
directly, or calls one of the removed methods.

| Removed | Replacement |
|---|---|
| `getNestedOption()`, `…Empty()`, `…Default()`, `…Access()`, `getNestedOptions()`, `setNestedOption()`, `…Empty()`, `…Default()`, `…Access()`, `setNestedOptions()`, `setDefaultNestedOption()`, `…Empty()`, `…Default()`, `setDefaultNestedOptions()`, `setDefaultOptions()` | `getNestedOptionMap()` |
| `hideChildShape()`, `isHiddenChildShape()`, `defaultChildShape()`, `isDefaultChildShape()`, `lockChildShape()`, `isLockedChildShape()`, `enableChildShapePlugin()`, `disableChildShapePlugin()` | `getChildShapeState()`, `getChildShapePlugins()` |
| `isRendering()`, `getRenderAttributes()` | the threaded `?Attribute $renderAttributes` argument |
| `ids()`, `belongsToExpanded()`, `setWidgetSetting()`, `getConfigShape()`, `isTraversable()`, `isScalar()` | none — no call site anywhere |
| `getDelta()`, `getStructure()`, `getNestedPath()`, `isExpanded()`, `isEnforcedRequired()`, `enforceLocked()`, `getFieldStorageSettings()`, `getFieldInstanceSettings()`, `getDefaultFieldItemValue()`, `getParentValue()`, `getWidgetTypeOptions()` | still on `ComponentShapePluginBase`, now `protected` |
| `addParentShape()`, `setDelta()`, `setParentValue()`, `setOverrideValue()`, `allowInitPlugins()`, `addPlugin()`, `init()` | moved to `ComponentShapeSetupInterface` |

- **A custom shape that overrides `getValue()`, `buildValue()` or
  `buildDefaultValue()` is a fatal until its signature is updated.** All three
  take a new optional `?Attribute $renderAttributes` parameter. Shapes that
  extend `ComponentShapePluginBase` without overriding them need no change.
- `ComponentShapePluginManager::getInstance()` returns
  `?ComponentShapeSetupInterface`, being the only source of an uninitialised
  shape.
- The six JSON-schema constants moved from `ComponentShapePluginInterface` to
  `ComponentShapeSchemaInterface`, where `getType()` returns one of them. They
  resolve through inheritance, so `ComponentShapePluginInterface::STRING` still
  reads the same.
- `isSingleProp()` and `getChildShapePlugins()` moved from
  `ComponentShapeChildrenPluginInterface` up onto
  `ComponentShapeChildrenMatchPluginInterface`, so `StructuredObjectShapeBase` —
  which declares only the latter — gets them too. Nothing loses them: the former
  extends the latter.
- **No stored configuration changes and no update hook.** Prop settings, nested
  option storage and plugin keys keep their current format.

### Before updating a site

- **Custom `ComponentShape` plugins that implement `ComponentShapePluginInterface`
  directly** rather than extending `ComponentShapePluginBase` — these must drop
  the removed methods. Shapes extending the base are unaffected.
- **Any use of the removed nested-option methods**, in a custom shape or a custom
  ComponentValue plugin. Move to `getNestedOptionMap()` — but **read the default
  before you do**. `setNestedOptionAccess()` defaulted its value to `FALSE`, so a
  bare `setNestedOptionAccess($name)` *withdrew* access; `NestedOptionMap::set()`
  defaults to `TRUE`. Translating the call without passing the value explicitly
  silently flips a denial into a grant. Pass the flag at every migrated call site.
- **Custom shapes overriding `getValue()`, `buildValue()` or
  `buildDefaultValue()`** — update the signature.
- **Any producer mapped directly onto a link prop's children** — see the
  rendering change above. On this site there are none.

### Coverage

The parent-constrains-child rules were only reachable through kernel round-trips
before; `ChildOptionPolicyTest` and `ChildShapeStateTest` assert them without a
container, and `ChildOptionPolicyCrossBaseTest` applies one producer
configuration to a child of each base and asserts one resolved value — the
comparison whose absence let the divergence ship. `NestedOptionMapTest` and
`HeadingValueOptionChoreographyTest` do the same for the option map; the heading
provider's option choreography needed a kernel boot to assert and no longer does.
`ShapeRoleInterfaceTest`, `ShapeSetupInterfaceTest` and `ShapeDoubleTest` pin the
roles, the setup handoff and the doubles. `ShapeRenderAttributeThreadTest` pins
the threading, including that a container passes the same `Attribute` object
rather than a copy.

Extraction is only behaviour-preserving if ordering is part of what you preserve:
`ChildOptionPolicy`'s branch order is load-bearing — `setAccess()` is
last-write-wins while `setLockedValue()` is first-write-wins — and grouping the
branches by kind silently opened per-child access on every media prop's
configuration form while the entire suite passed. The order is restored, the reason
is written at the call site, and a test now fails when the two are swapped.

## One mold for the component admin forms

A site builder opened the Filters tab on a component and was offered filter
plugins that component cannot use. Picking one produced a filter that does
nothing.

The plugin manager method that narrows the offered list to what a component
supports existed on the access manager and on the slot manager. It did not
exist on the filter manager, so the filter form fell back to listing every
definition. The access manager's own docblock said it "mirrors" the slot
manager's — a copy that documented that it was a copy.

**The narrowing moved to a manager base all three share.** A family can no
longer ship without it. Filter plugins gained `isApplicable()` (defaulting to
TRUE, so no shipped filter changes what it offers), and the slot plugin
interface now declares the method its manager was already calling.

### Three seams

Everything above that method was owned twice, and the copies were made by
find-and-replace: the access and filter add controllers were byte-identical
after a rename, so were the factories, and the edit controllers differed by two
lines.

**A configured-plugin kind.** `ConfiguredPluginKindInterface` declares what
actually differs between access rules and filters — the manager, the entity
accessors, the form mode, the label, and any fields the family carries of its
own. One controller and one form replace four controllers and two forms.
`ConfiguredPluginInterface` and `ConfiguredPluginWrapperInterface` name the
plugin and the stored pair the families share. Adding a third kind is one
implementation plus a service, not four classes.

**A staged plugin list mold.** The list↔edit state machine the prop form and
the slot form each re-derived is now `StagedPluginListInterface` (the op
vocabulary, named once) plus `StagedPluginListTrait` (the op buttons, the
weight column, the add select, the edit-pane actions). Both forms are its
adapters. How an item is addressed stays with each form, because the two
genuinely differ: a slot may hold two of the same plugin, a shape holds each
provider once.

**A route access checker base.** Six checkers each wrote out the same parse,
parameter resolution and neutral fallback, with the arity varying between two
and three segments and one of them padding its requirement string to fit. Each
is now a single decision method over a declared segment format, and the formats
are tabulated in one docblock.

### Fixes that fall out

- **The limited-submission rule is stated once.** Drupal's Button element
  defaults `#limit_validation_errors` to FALSE — meaning "do not limit" — so
  detecting a genuinely limited submission requires testing for an *array*. A
  presence check classifies Save as limited and skips the commit while
  reporting success. `LimitedSubmissionTrait` owns that rule and the three
  forms that branch on it inherit it. The slot form had no guard at all and
  survived only because the value set its commit path iterates happens to be
  empty whenever Cancel is on screen.
- The slot form's edit pane said "Edit" while adding and "Add" while editing.
- Its cancel submit handler had no callers, and one of its handlers was wired
  with different capitalisation from its four siblings. Both are gone.
- **The slot form says when your changes are staged**, as the prop form
  already did. Nothing on that screen persists until Save, so a site builder
  who added a plugin and navigated away lost it with no warning. The message
  renders inside the AJAX-replaced subtree, since that is the only part of the
  form an op ever redraws.
- **Route access checkers attach cacheability by default.** Four of the six
  attached none, so their results varied by nothing and were invalidated by
  nothing. They are now correctly varied and invalidated. The field checker
  names what its decision was made from — the entity per-entity, the field
  config in the shared-layout scope — rather than the field item, which is not
  cacheable and would have dropped the result to max-age 0.
- Two access checker services passed a constructor argument to classes that
  have no constructor.

### Compatibility

- **Breaking for custom access and filter plugins outside this repository.**
  `ComponentAccessPluginInterface` and `ComponentFilterPluginInterface` now
  extend `ConfiguredPluginInterface`; a plugin extending the shipped base
  classes is unaffected, one implementing the interfaces directly must supply
  `isApplicable()`. `ComponentSlotPluginBase::isApplicable()` gained a `: bool`
  return type, which every override must match.
- **Breaking for custom route access checkers** built on the old per-checker
  pattern: they still work as `AccessInterface` implementations, but nothing
  shares the parse with them.
- `ComponentAccessForm`, `ComponentFilterForm` and the four add/edit
  controllers are removed. They were referenced only by this module's routing.
- **A caching change on editor routes**, from the checkers that previously
  attached nothing. This is a fix, and it can surface as different caching on
  admin routes.
- **No stored configuration changes and no update hook.** Access, filter and
  slot settings keep their shape. An already-configured filter keeps its entry
  and keeps running even if its plugin later declines the component; only the
  add list narrows, and the plugin select on that filter's own edit screen
  still offers the plugin it is configured with, so the screen stays saveable.
- Audited on the site this landed from: 53 components, 5 configured filters,
  20 access rules, none of them unsupported — so there is nothing to report or
  remove. No shipped filter plugin declines a component today; the narrowing
  is an extension point that had been missing, not a change to what the
  shipped set offers.
- Before updating a site, audit for custom `ComponentAccess`/`ComponentFilter`
  plugins and custom route access checkers following the module's pattern.

### Coverage

The slot, access and filter forms had no test at all. They have one each now
(`ComponentSlotFormUxTest`, `ComponentConfiguredPluginFormTest`), driven the way
`ComponentPropFormUxTest` drives the prop form: through form state, asserting
what was staged and what was persisted. `ComponentRouteAccessCheckTest`
constructs all six checkers, where two were constructed before, and asserts
cacheability. `LimitedSubmissionTest` pins the rule itself, including the
premise that core really does default the key to FALSE.

## The component tree has one owner, and hybrid layout sits behind it

A site builder reordered components in a layout and one of them silently
disappeared.

`ComponentTreeStructure::sortComponents()` rebuilt a section from the list of
UUIDs it was handed and discarded everything else, and its callers built that
list from `ComponentTreeItem::toOptions()` — a labelling helper, which can only
offer a row for an instance whose `neo_component` config still loads. So a
section holding `[A, B, C]` where `A`'s component was missing became `[C, B]`
after one "move down" on `B`. If `A` had a slot, its subtree and every
descendant's props stayed in storage in exactly the dangling state the module's
own structure validator rejects. Nothing warned, nothing logged, the entity
saved cleanly.

**Reorder replaces sort.** `reorderComponents()` refills only the positions the
listed UUIDs occupy and leaves everything else at its own index, so no list a
caller can pass is capable of removing anything. `getPlacedUuids()` is the new
sibling reorder callers use; `toOptions()` stays a labelling concern. The unit
test that pinned the destructive behaviour was inverted — it documented the
defect, not a requirement.

### The seam

That defect was a symptom: the decoded `(tree, props)` pair is where component
usage scanning, dependency detachment, hybrid merge and strip, anchor
resolution, structure validation and the Drush integrity command all meet, and
nothing satisfied an interface there. Descendant-closure expansion existed four
times; the section-walk idiom three times across two classes; parity was
maintained by hand in six places.

`ComponentTreeStructure` is that seam now. It owns the pair (`bindProps()`, so
parity is a postcondition rather than a rule), the one closure walker, the
collectors, dependency detachment, and the hybrid compose/extract algebra —
which were already pure functions of a default layout, a stored subset and a set
of anchors, just entangled with a field item. The field list keeps the Field API
lifecycle and nothing else; anchor resolution stays on the field config, which
needs entity storage.

### Two data-loss paths closed

- **Detaching a deleted component no longer resurrects seed content.** "A
  section that has become empty" meant *collapse it* to config-scope dependency
  removal and *preserve it, it means explicitly emptied* to hybrid storage. Both
  are correct in isolation; together they were a data-loss path, because
  `drush neo:alchemist:integrity --detach` rewrites entity rows and a hybrid row
  is a storage subset. Collapsing one left `{root: []}`, which the next load
  reads as "never customized" and answers by repopulating the region with the
  site builder's seeds. `EmptySectionPolicy` is now a named argument at every
  call site, and the integrity command picks per row from the tree's own shape.
- **An emptied region stays empty through a second draft save.** The merge used
  to *drop* an emptied flagged slot. But "absent" already means "this anchor
  postdates the stored value, apply its seed children" — so composing a merged
  value a second time, which is what a second draft save does, brought the seeds
  back into a region a creator had cleared. The slot now stays
  present-and-empty. This also makes the merge genuinely idempotent, the
  property `ARCHITECTURE.md` claimed and nothing enforced.

### Tests

`HybridRoundTripTest` expresses the properties as properties: extract∘compose
returns the subset it started from, compose∘compose changes nothing, extraction
always satisfies parity. `ComponentTreeReorderTest` and `EmptySectionPolicyTest`
pin the two regressions. The three test-only classes that existed purely to
reach protected methods — including one needing a mock that had to contradict
itself to construct — are deleted; the tests call what production calls.

### Breaking changes

Published interfaces move. Anything outside this repository calling these breaks
on update; there are no such callers on this site.

| Removed | Replacement |
|---|---|
| `ComponentTreeStructure::sortComponents()` | `::reorderComponents()` (non-destructive) |
| `ComponentTreeItem::sortComponents()` | `::reorderComponents()` |
| `ComponentUsage::detachComponents()` | `ComponentTreeStructure::detachComponents()`, with a policy |
| `ComponentUsage::extractComponentIds()` | `ComponentTreeStructure::collectComponentIds()` |
| `NeoComponentTreeList::getSectionClosureUuids()` | `ComponentTreeStructure::collectAnchorClosure()` |
| `NeoComponentTreeList::{expandTupleClosure,getTreeUuids,getTreeTupleUuids,decodeHybridItemValue}()` | `ComponentTreeStructure::{expandClosure,collectUuids,collectInstanceUuids,decodeValue}()` |

`ComponentTreeStructure::removeComponent()` takes a required
`EmptySectionPolicy`. `ComponentTreeItem` gains `isHybridScope()` — the
predicate was hand-rolled as `!belongsToFieldConfig() && …->isHybrid()` in five
places, each of which would fatal on a field definition that is not a
`ComponentFieldConfig`.

**Stored data does not change shape**, so there is no config sweep and no
migration. But rows damaged by the old reorder already exist in the wild, so
`neo_alchemist_update_11006()` scans entity tree storage and **reports**
dangling subtrees and unattributable prop entries without rewriting them — the
damage is historical, the rows are content, and the choice between re-placing
and purging is a maintainer's.

Ownership of an instance is also memoised per value now: the editor chrome asks
several access questions per instance while rendering a layout, and each one
used to re-decode the tree JSON and re-walk the whole ownership closure.

### Lint sweep

The module's `Drupal,DrupalPractice` warnings are cleared, which moves a few
constructor signatures. All of these are services or forms built by the
container, so only code constructing them by hand is affected:

- `MatcherField` takes a fifth argument, `@router.route_provider`.
- `ComponentManageForm` takes a fifth argument, `@user.permissions`.
- `ComponentPreviewController` and `SdcPreviewController` take the `Request` as
  their first route argument.
- `NeoComponentGenerator` takes `@plugin.cache_clearer`.

Two categories of warning are suppressed rather than fixed, because fixing them
would make the code worse:

- **`\Drupal::getContainer()` in the five plugin managers' `createInstance()`.**
  Each family's plugins take a bespoke constructor that `DefaultFactory` cannot
  produce, so the managers build them by hand and must hand a container to any
  plugin implementing `ContainerFactoryPluginInterface`. Core's
  `ContainerFactory::createInstance()` makes the identical call for the
  identical reason. Injecting `@service_container` instead would be a service
  locator — and would break three public constructor signatures to satisfy a
  sniff that cannot tell a plugin factory from ordinary code.
- **`Remove "version" from the info file` (six files).** Neo reads that field to
  decide whether compiled assets are stale, so removing it would give every
  developer a false "assets need rebuilding" state.

## The media provider no longer starves providers placed after it

The auto-attached `media` plugin on image/media props is infrastructure —
the media-library widget, the media-to-image conversion, the optional
fallback image — but in the provider search its old `stop_when_found`
default claimed the THREADED schema example (non-empty, so the claim fired
even though the plugin contributed nothing). Any provider a site builder
added after it silently never ran: "I added an Entity Field provider and
nothing happened until I dragged it above Media." Site config showed every
mode hand-set somewhere in self-defense.

- **`media` now defaults to `continue`.** For a media-only provider list
  (the common case) the modes are outcome-identical — nothing follows that
  could overwrite — so this only un-breaks the chained case. Explicitly
  saved modes are untouched; existing `stop_when_found` media instances in
  this site's config were swept to `continue` (media_s1, hero_s5).
- **`media` is `status_lock`ed** — no Remove button. Removing it destroys
  the prop's authoring UI (onShapeInit() is what makes the prop a media
  reference field with the media-library widget).
- The plugin now says what it is: a settings summary ("Media picker & value
  converter", "Fallback image configured") and an honest description in the
  provider list.

Pinned by `MediaProviderChainTest`: an entity provider *below* media wins
without reordering; an empty chain keeps the schema example; an explicit
`stop_when_found` still claims (configured beats default).

## The prop form, rebuilt for site builders

The per-prop Customize form rendered every applicable value plugin as a table
row — status checkbox, weight select and full settings inline — times every
value group, times every expanded sub-prop. On callout_s1's `_aggregate` that
was ~68 provider rows, four stacked vertical-tab sets and 1.1 MB of HTML.
Rebuilt on the slot form's list↔edit pattern:

- **Only active providers list**, as summary rows (what it's wired to, plus a
  chain badge), with an *Add provider* select for the rest; Edit opens one
  provider's settings at a time; changes stage on the unsaved entity until
  Save, with a status message once anything diverges. Same page, ~80% fewer
  HTML bytes and ~90% fewer controls.
- **Prop-first tabs**: one vertical tab per shape (the prop, then each
  expanded sub-prop), the four value groups stacked inside as collapsed
  sections badged with their active count. The aggregate root is titled "All
  properties", not "Base".
- **`settingsSummary()` on value plugins** (the one plugin type that had
  none): entity_reference/entity_query/default/entity/entity_load and the
  prefix/suffix/token modifiers say what they're configured to do wherever
  they're listed — including the props table on the component manage screen.
- **Processing mode is now "When this provider runs"** — three radios with
  one-line descriptions at the top of each provider's settings (*Use its
  value and stop* / *Always use its value — final* / *Add its value and
  continue*), replacing a select buried at the bottom behind a 120-word
  description. Machine values are unchanged.
- **The children-match mapping is a table** — one Property → Source row per
  child, "Not mapped" states, the per-child Value Plugins details badged with
  its enabled count — with `shape_published` and copy-mapping behind a
  collapsed Advanced section. Explicit `#parents` keep the stored
  `shape_fields` tree byte-identical; opening and saving a component without
  edits round-trips its config unchanged.

One regression found and pinned on the way: `Button::getInfo()` defaults
`#limit_validation_errors` to FALSE, so limited-submission detection must test
for an array — a presence check classifies Update and Save as limited and
silently skips the commit path.

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
