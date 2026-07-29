# neo_alchemist — Testing

Automated tests for this module, and how to get a host site set up to run them.

The suite exists because of a specific class of failure: in July 2026 a bug in
per-delta child value distribution silently dropped authored media from array
props — 130 images across ~40% of one site's component trees, with no error
logged anywhere. Nothing caught it because nothing tested it. The tests here are
weighted toward that kind of silent, value-level regression rather than toward
coverage percentage.

---

## Quick start

Assuming the host site is already set up (see [Host site setup](#host-site-setup)):

```bash
ddev phpunit                                                # everything
ddev phpunit web/modules/contrib/neo_alchemist/tests/src/Unit   # fast loop, ~0.1s
ddev phpunit --filter=ChildrenShapeDelta                    # one class
ddev phpunit --testdox                                      # readable output
```

Unit tests need no database and run in milliseconds. Kernel tests boot a real
container per test class and take a few seconds each.

---

## Host site setup

The module ships tests but not a test runner — that belongs to the site. These
are the steps that were needed on a Drupal 11.4 / DDEV / PHP 8.3 site.

### 1. Install the test dependencies

```bash
ddev composer require --dev drupal/core-dev:^11 --with-all-dependencies
```

**Run this inside DDEV.** The host PHP and the container PHP usually differ, and
`config.platform` is typically unset, so resolving on the host produces a lock
file for the wrong PHP version.

Two constraints commonly bite here:

- **`drupal/core-dev` requires `drupal/coder ^8.3.30`, which requires
  `squizlabs/php_codesniffer ^3.13`.** A site pinning phpcs `^4.0` gets a hard
  conflict. Relax the pin to `"^3.13 || ^4.0"` and accept the downgrade — coder
  9.x supports phpcs 4 but is excluded by core-dev's constraint. On the site
  this was developed against, phpcs 4 was installed *without* coder, so there
  was no `Drupal` standard at all; the downgrade was a net gain.
- **`--with-all-dependencies` is required.** `sebastian/diff` is a *production*
  dependency of `drupal/core` and is often locked ahead of what PHPUnit 11.5
  accepts, so the lock diff will touch `packages`, not just `packages-dev`.

If the project patches `drupal/core` and sets `composer-exit-on-patch-failure`,
a non-zero exit means a patch failed to reapply — investigate rather than retry.

> **Ordering:** run this *before* authoring test files. `web/modules/contrib/`
> is Composer-managed, and any `composer require`/`install`/`update`
> re-extracts the module archive, deleting uncommitted work inside it.

### 2. Provide the test database

`KernelTestBase::getDatabaseConnectionInfo()` throws without `SIMPLETEST_DB`.

**Check whether it is already set before adding anything:**

```bash
ddev exec 'env | grep -E "SIMPLETEST|BROWSERTEST"'
```

DDEV does *not* set these itself, but several add-ons do. The
[ddev-selenium-standalone-chrome][selenium-addon] add-on writes
`.ddev/config.selenium-standalone-chrome.yaml`, which already supplies
`SIMPLETEST_DB`, `SIMPLETEST_BASE_URL` and `BROWSERTEST_OUTPUT_DIRECTORY` — a
site carrying it for functional-JS testing needs no further work here. That was
the case on the site this suite was developed against, so treat step 2 as a
verification rather than a task.

[selenium-addon]: https://github.com/ddev/ddev-selenium-standalone-chrome

If the grep comes back empty, DDEV merges any `.ddev/config.*.yaml` into its
config, so add `.ddev/config.testing.yaml`:

```yaml
web_environment:
  - SIMPLETEST_DB=mysql://db:db@db/db
  - SIMPLETEST_BASE_URL=http://web
  - BROWSERTEST_OUTPUT_DIRECTORY=/tmp
```

Then `ddev restart` — DDEV does not pick up config changes otherwise.

Kernel tests create prefix-scoped `test<random>_*` tables in that database and
drop them on teardown. A crashed run leaves them behind:

```bash
ddev mysql -e "SHOW TABLES LIKE 'test%'"
```

No Selenium service is needed to *run* this suite — there are no browser tests.
The add-on above is only a convenient source of the environment variables; a
site without it just writes them itself.

### 3. PHPUnit configuration

Put `phpunit.xml` at the **project root**, not in `web/core`. `/web/core/` is
Composer-scaffolded and normally gitignored, so a config file there cannot be
committed and is wiped by the next update.

```xml
<phpunit bootstrap="web/core/tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         beStrictAboutTestsThatDoNotTestAnything="true"
         beStrictAboutOutputDuringTests="true"
         failOnWarning="true" failOnRisky="true">
  <testsuites>
    <testsuite name="neo_alchemist">
      <directory>web/modules/contrib/neo_alchemist/tests/src</directory>
    </testsuite>
  </testsuites>
  <extensions>
    <bootstrap class="Drupal\TestTools\Extension\Dump\DebugDump">
      <parameter name="colors" value="true"/>
      <parameter name="printCaller" value="true"/>
    </bootstrap>
  </extensions>
  <php>
    <ini name="error_reporting" value="32767"/>
    <ini name="memory_limit" value="-1"/>
    <env name="SYMFONY_DEPRECATIONS_HELPER" value="weak"/>
  </php>
</phpunit>
```

The `<extensions>` block mirrors core's own `phpunit.xml.dist`. Without
`DebugDump`, `dump()` inside a test produces nothing and core's
`KernelTestBaseTest::testVarDump` fails — a reliable signal that this block has
drifted from core.

Keep the strictness flags. A stray `dump()` left in a fixture failing the run is
the point.

### 4. A runner command

A `.ddev/commands/web/phpunit` wrapper keeps tests running in the container,
where the right PHP and the database live. Guard on the two things that
actually go wrong — a missing `vendor/bin/phpunit` and a missing
`SIMPLETEST_DB` — then `exec vendor/bin/phpunit -c /var/www/html/phpunit.xml "$@"`.
`ddev restart` registers it.

**Committing it usually needs a `.gitignore` change.** Drupal/Pantheon projects
commonly ignore `/.ddev/commands` wholesale, because the commands there are
scaffolded by a Composer package rather than authored by the site. A plain
negation does not work — Git cannot re-include a file whose parent directory is
excluded — so the ignore has to be re-expressed one level at a time:

```gitignore
/.ddev/commands/*
!/.ddev/commands/web/
/.ddev/commands/web/*
!/.ddev/commands/web/phpunit
```

Verify with `git add -An --dry-run .ddev` and confirm the wrapper is listed and
no scaffolded command is. Also ignore `/.phpunit.cache`.

Leaving the wrapper untracked is a defensible alternative — but then `ddev
phpunit` silently does not exist for anyone who clones the repo, and the failure
looks like a broken test setup rather than a missing file.

### 5. Prove the harness before trusting it

Run core's own tests first. If these fail, the problem is the setup, not this
module:

```bash
ddev phpunit web/core/tests/Drupal/Tests/Component/Utility/HtmlTest.php   # bootstrap, mink alias, prophecy
ddev phpunit web/core/tests/Drupal/KernelTests/KernelTestBaseTest.php     # SIMPLETEST_DB, vfsStream, container
```

---

## Layout

```
tests/
├── modules/neo_alchemist_test/        # fixture module (SDCs + plugins)
│   ├── components/
│   │   ├── na_array_provider/         # array-of-objects with a provider-owning child
│   │   ├── na_array_required/         # required string+number children (branch table, falsy pins)
│   │   ├── na_array_single/           # bare-string items (single-prop collapse)
│   │   ├── na_falsy_object/           # object + link + top-level number (falsy pins)
│   │   ├── na_region_host/            # one region prop (the hybrid anchor host)
│   │   ├── na_two_region/             # two region props (partial un-flag, clone geometry)
│   │   ├── na_leaf/                   # the universal child: one string prop
│   │   ├── na_recorder/               # example-less prop for pipeline observation
│   │   └── na_not_neo/                # valid SDC WITHOUT `neo: true`
│   ├── config/schema/                 # schema for fixture plugin settings (strict checker)
│   ├── src/TestValueCallLog.php       # static call recorder
│   ├── src/Plugin/ComponentShape/     # TestProvidedShape, TestRecordedShape
│   ├── src/Plugin/ComponentValue/     # TestProviderValue + recording/cache-tag plugins
│   ├── src/Plugin/ComponentAccess/    # TestCacheTagAccess
│   └── neo_alchemist_test.neo_component_prop_defs.yml
└── src/
    ├── Unit/                          # no container, no database
    └── Kernel/                        # real container, real config entities
    # Named helpers live beside the tests: TestNeoComponentTreeList,
    # TestHybridComposerList/TestHybridItemStub (Unit) and
    # HybridFieldKernelTestBase/CheckAccessExposedComponent (Kernel).
```

Namespaces follow Drupal convention: `Drupal\Tests\neo_alchemist\{Unit,Kernel}`.
The fixture module autoloads as `Drupal\neo_alchemist_test\`.

---

## The fixture module

`tests/modules/neo_alchemist_test` is discovered automatically. Core's SDC
plugin manager scans every enabled module's `components/` directory, and
`KernelTestBase::bootEnvironment()` calls `drupal_valid_test_ua()`, which makes
`ExtensionDiscovery` include `tests/` directories. A site's
`extension_discovery_scan_tests` setting is irrelevant — Kernel tests bootstrap
their own environment. (Flip it to `TRUE` only if you want to *browse* the
fixtures on the real site.)

### Its info.yml

`neo_alchemist` is the only dependency, and it is sufficient — the plugins
extend Alchemist base classes and the components need Alchemist's SDC handling,
but nothing here reaches for a module the parent does not already pull in.

`package: Testing` is load-bearing rather than cosmetic: `InfoParserDynamic`
exempts that package from requiring `core_version_requirement`, defaulting it to
the running core version. The fixture deliberately relies on that instead of
pinning a range, because a stale pin would make it *core-incompatible* the
moment the parent gains support for a new major — the tests would stop being
discovered rather than fail, which is the worst way to lose coverage.

It also depends on **no core test modules**. That is why `na_not_neo` exists
locally rather than borrowing `sdc_test:my-banner` for the "valid SDC without
`neo: true`" case — core changing its own test fixtures cannot break this suite.

### The provider twin

The delta-distribution bug only triggers when an array's child owns a
`providers`-group value plugin, because
`ChildrenShapeBase::childHasOwnValueProvider()` is a plain
`(bool) $shape->getValueCollection()->getActiveInstances('providers')`.

In production that condition comes from `ImageShape`, which declares
`default_plugins: ['media']`. Testing through `ImageShape` would mean enabling
`media`, `file` and `image`, creating media entities, and working around
`MediaShapeBase::onShapeInit()` rewriting the shape's field type to
`entity_reference` — none of which is relevant to the bug.

So the fixture ships a dependency-free twin:

- **`TestProviderValue`** — `group: 'providers'`, every hook a pass-through,
  never claims. Its only job is to satisfy that predicate.
- **`TestProvidedShape`** — extends `StringShape` with
  `default_plugins: ['na_test_provider']`. Because a non-expanded child returns
  FALSE from `allowConfigurablePlugins()`, `init()` calls `initPlugins()` and the
  provider is active on every delta — exactly the ImageShape mechanism.

**Known limitation.** `MediaValue::provideDefaultValue()` returns `[]` once the
"default" option is off, so in production a dropped image renders as *nothing*.
`TestProviderValue` passes values through, so a dropped value here degrades to
the schema example instead. Tests assert on both the value and on non-emptiness
separately for that reason. If you need the empty-value path covered faithfully,
add a second provider that mirrors MediaValue's behaviour — but expect the
in-code `assert()` in `getChildShapes()` to fire first and turn clean assertion
failures into `AssertionError`s.

### Adding a fixture component

Create `components/<name>/<name>.component.yml` with `neo: true`, plus a minimal
`.twig`. Give example values distinctive sentinels (`SCHEMA EXAMPLE`,
`AUTHORED ZERO`) so an assertion can tell "resolved authored content" from "fell
back to the example" without ambiguity.

Create the `neo_component` config entity in `setUp()`, **not** in
`config/install`. `Component::save()` regenerates `expression` and `schema` from
the live SDC definition, so checked-in config drifts the moment a fixture
changes:

```php
Component::create([
  'id' => 'na_array_provider',
  'label' => 'Array provider fixture',
  // Typed as a non-nullable string; it will NOT default from the SDC.
  'description' => 'Array provider fixture',
  'component' => 'neo_alchemist_test:na_array_provider',
  'status' => TRUE,
])->save();
```

---

## Writing a Kernel test

`KernelTestBase::enableModules()` does **not** resolve declared dependencies —
it enables exactly what you list. Despite `neo_alchemist.info.yml` declaring
ckeditor5, media, options, neo and neo_tooltip, this is enough:

```php
protected static $modules = [
  'system', 'user', 'neo_settings', 'neo_alchemist',
  'neo_alchemist_test',
];
```

`neo_settings` is **not** optional: `neo_alchemist.services.yml` declares
`neo_alchemist.settings` with `parent: neo_settings.repository`, and only
`neo_settings` provides it. `neo` itself ships no services file.

**`field` is not needed**, which is counter-intuitive — the shape system builds
a field item for every prop. The field-item machinery comes from
`plugin.manager.field.field_type`, `…widget` and `…formatter`, all declared in
**core.services.yml**, not by the `field` module. The entire Kernel suite was
verified to pass without it. Add `field` only when a test creates real
`FieldStorageConfig`/`FieldConfig` entities.

The hard floor is narrower still: `['neo_settings', 'neo_alchemist']` alone
boots the container and resolves prop values. `system` and `user` are kept as
the conventional Drupal baseline — they cost nothing measurable, and anything
touching entity access or site config will want them.

Add more only when a failure demands it, in roughly this order: `neo` (settings
plugin discovery) → `neo_icon`, `neo_config_file`, `neo_modal` (anything
touching `IconTrait`) → `text`, `filter`, `link`, `options` (field types) →
`field`, `file`, `image`, `media` (+ `installEntitySchema()`) → `entity_test`
(field modes). Do not add `neo_build`/`neo_color` speculatively — they only
register NeoBuild event subscribers fired by `drush neo:build` and cost nothing
at boot.

### Supplying authored values without an entity

A config-scope `Component` in preview mode is the lightest way to give a shape a
stored override, avoiding a host entity and a `neo_component_tree` field:

```php
$component->setPreview(TRUE);
$component->setPreviewValues([
  'props' => [
    'items' => [
      'ref' => 'array',
      'value' => [0 => ['title' => ['value' => 'AUTHORED']]],
      'options' => ['items~title~0' => ['default' => 0]],
    ],
  ],
]);
```

The value shape mirrors what `ComponentShapePluginManager::getInstancesFromSchema()`
reads: `props.<name>.value` becomes the shape's override, `props.<name>.options`
its per-delta options. Child option keys are `<prop>~<child>~<delta>`.

Set `default => 0` when you want a missing value to resolve to nothing rather
than quietly falling back to the schema example — that makes failures
unambiguous.

Note that shape state is memoised per object, so a test comparing two
resolutions must load two separate instances (`$storage->resetCache()` then
`load()`), not reuse one.

---

## What the tests cover

The suite is 30 classes / 169 tests (705 assertions). The July 2026 expansion
added the "falsy values are values" family, the tree-field mode contracts
(hybrid is **live in production** — `node.project` via `project_full`,
`taxonomy_term.market` via `hero_s2` — so those are regression pins over
shipped data), and the entity/render invariants. Every bug fixed in that pass
carries a regression test proven red against the pre-fix code (or by mutation
where noted in the class docblock).

| Class | Retires |
|---|---|
| `Unit/ComponentShapeOptionTest` | Option precedence (locked > set > default) and first-write-wins locking, so a parent's constraint cannot be escaped by a nested child |
| `Unit/ComponentTreeStructureTest` | Tree algebra; depth-first traversal yielding children before parents, which the hydrated render pass depends on |
| `Unit/HybridTreeAlgebraTest` | Hybrid closure math — anchors, nested descendants, cycle termination, malformed JSON |
| `Unit/ExpandTupleClosureTest` | The descendant walker behind hybrid ownership: cycles, junk seeds, missing sections |
| `Unit/HybridStorageExtractionTest` | The compose/extract pair at the algebra level: un-flagged regions stash as orphans (not silently destroyed), tree/props parity backfill, `'{}'` encoding |
| `Unit/ComponentValueProcessingModeTest` | All three claim modes × empty/non-empty; existing claims never released |
| `Unit/IsProvidedValueEmptyTest` | The value-emptiness contract: 0, '0' and FALSE are values; the seeded `size` key is not content |
| `Unit/EntityFilterMassageValueTest` | The entity filter's form-value round-trip — the single-value array path was a guaranteed TypeError |
| `Kernel/ChildrenShapeDeltaDistributionTest` | **The delta-distribution regression** |
| `Kernel/ChildrenShapeFalsyValueDistributionTest` | Warm/cold parity for falsy child values (invariant pin; see its honest scope note) |
| `Kernel/ArrayShapeBuildValueBranchTest` | The full `ArrayShape::buildValue()` branch table — the `continue 2` no longer drops items whose required child holds 0/'0'/FALSE; single-prop collapse stays homogeneous |
| `Kernel/ObjectShapeFalsyValueTest` | Object/structured-object children keep authored falsy values instead of being unset or refilled with schema examples; a top-level 0 prop reaches `#props` |
| `Kernel/GetDefaultValueOrderTest` | `getDefaultValue()` computes once (NULL memoises), never clobbers an authored field item on re-entry; providers → fallback → modifiers regardless of saved order |
| `Kernel/ShapeCacheabilityMergeOrderTest` | Provider-added cache tags merge AFTER value computation and survive into `#cache` |
| `Kernel/ValueGroupTaxonomyTest` | Golden list of every value plugin's group — groups are behavioral contracts (`childHasOwnValueProvider()`, pipeline order) |
| `Kernel/ShapeInitOrderTest` | Six shape-lifecycle invariants, each proven to fire when violated |
| `Kernel/FieldStorageDefinitionPrototypeTest` | The prototype-clone optimisation in `buildFieldItem()` — no state leaks between shapes or into the cached prototype |
| `Kernel/ComponentPreviewBuilderTest` | The `neo: true` gate and the preview flag |
| `Kernel/ComponentSaveIdempotenceTest` | A resave is byte-identical; `region_custom` flags survive editor saves |
| `Kernel/ComponentAccessCacheabilityTest` | `checkAccess()` folds every consulted plugin's cacheability into neutral AND forbidden results |
| `Kernel/ComponentTreeHydratedTest` | Unpublished parents drop their subtree without orphans; malformed trees fail loudly in dev; forbidden components are skipped with their access cacheability captured |
| `Kernel/CloneComponentSubtreeTest` | Cloning copies the whole subtree, slots intact, grandchildren included |
| `Kernel/HybridSaveRoundTripTest` | The core hybrid contract: seed copy-on-write, explicitly-empty slots, nested round-trips, in-memory restore after save |
| `Kernel/HybridDraftPublishTest` | **The July 2026 publish bug**: detached per-conversion copies, commit value sync, draft cleared |
| `Kernel/HybridUnflaggedRegionTest` | Un-flagging one region preserves its authored content as orphans and re-flagging restores it |
| `Kernel/HybridOrphanPreservationTest` | Orphans survive saves and in-session merged-tree sets, stay render-inert, and are replaced (not resurrected) by a fresh storage subset |
| `Kernel/HybridAccessLockingTest` | Inherited instances refuse every mutating op; creation only inside customizable regions; the root is never a target |
| `Kernel/LockedAndCustomModeTest` | Locked ignores stored values; custom is all-or-nothing; PLUS a characterization of the locked-snapshot behavior (see below) |
| `Kernel/WidgetDoesNotWipeTreeTest` | The widget's one-line no-op guard against total field wipe on entity form saves |
| `Kernel/BootSpikeTest` | The module boots under Kernel with a minimal module set |

Shared Kernel infrastructure: `HybridFieldKernelTestBase` stands up a real
`neo_component_tree` field on `entity_test` with a hybrid-ready default
layout. It encodes two traps so tests don't rediscover them:

- **`region_custom` is enabled by raw config AFTER the component's first
  save** — `Component::preSave()` regenerates `settings.props` from the live
  shapes on save, wiping hand-set plugin configuration:

  ```php
  \Drupal::configFactory()->getEditable('neo_alchemist.neo_component.na_region_host')
    ->set('settings.props.body.plugins.body.region_custom', ['id' => 'region_custom', 'settings' => []])
    ->save();
  ```

  followed by `resetCache()` on the `neo_component` storage AND
  `entity_field.manager` `clearCachedFieldDefinitions()` — the custom-region
  lookup memoises per `ComponentFieldConfig` object.
- **Always reach the field through the entity** (`$entity->get(...)`). A
  directly loaded `FieldConfig` is not a `ComponentFieldConfig` (the wrap
  happens in `neo_alchemist_entity_bundle_field_info_alter()`), and the list's
  `setValue()` silently bails on the unwrapped definition.

Entity props use the canonical wrapper — `status` + `props`, each prop
carrying `ref`/`value`/`options` — raw values silently render the schema
examples instead.

### Not yet covered

- **Functional/browser coverage.** The suite is Unit + Kernel only; the
  editor routes, forms and JS are untested.
- **Slot-plugin access.** `EntitySlot`, `EntityDisplay` and `EntityFieldSlot`
  render entities/fields with no view-access check and no cacheability
  (compare `BlockPluginSlot`, which does it right). A security-adjacent
  follow-up: fixing it changes visible behavior, so it needs a deliberate
  decision, not a drive-by.
- **`ComponentInstanceBase::access()` early returns** (preview-allowed,
  hybrid-forbidden, the create checks) still return without folding prior
  cacheability; only `Component::checkAccess()` folds today.
- **Characterized, deliberately not fixed** (each needs a design decision):
  locked fields persist a frozen default snapshot into entity rows
  (`LockedAndCustomModeTest` documents it — it becomes live content the
  moment the field's mode changes, including when the LAST flagged region is
  un-flagged, which flips the field to locked); a root-only stored value is
  discarded when a field flips custom → hybrid; `ComponentInstanceBase::
  setValues()` writes draft values onto the statically-cached entity;
  `getDraftKey()` omits entity type/langcode/revision, so drafts can collide
  across entity types and translations share one draft.

---

## Verifying a regression test actually works

A test that cannot fail is not coverage. When you add one for a bug, prove it
catches that bug:

1. Get the suite green.
2. Break the fix by hand — e.g. neutralise the delta narrowing in
   `ChildrenShapeBase::loadChildShapes()` by changing the guard to
   `if (FALSE && $delta !== NULL && …)`.
3. Confirm the test goes **red**, and that the failure message is about lost
   content — not an incidental crash.
4. Restore by hand and confirm green again.

For the delta-distribution suite, three tests must fail, including
`testWarmingDoesNotChangeResolvedValues`. That one states the invariant without
depending on knowing the right answer: warming the shape cache must not change
what the component resolves to. If only the value assertions fail and that one
stays green, the fixture is not reaching the cache-hit branch in
`getChildShapes()` — fix the fixture before trusting anything built on it.

---

## Coding standards

`drupal/core-dev` brings `drupal/coder`, so the test files can be linted:

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  --extensions=php,yml,twig web/modules/contrib/neo_alchemist/tests
```

**Do not run `phpcbf` over files containing anonymous classes** — it inserts
malformed docblocks around `@codingStandardsIgnore*` markers and around methods
inside the anonymous class body. Extract such helpers into named classes
instead (see `tests/src/Unit/TestProcessingModeProvider.php` and
`TestNeoComponentTreeList.php`, which also expose protected statics for
testing).

Two sniff quirks worth knowing: method names may not contain consecutive
capitals (`testCloneMatchesAFreshlyBuilt…` is rejected), and doc-comment first
words get auto-capitalised, which mangles sentences starting with a method name.

---

## Gotchas

- **PHPUnit metadata must use attributes**, not doc-comments. Use
  `#[Group('neo_alchemist')]` and `#[DataProvider(...)]`; `@group` is deprecated
  and will break in PHPUnit 12.
- **Assertions are live** (`zend.assertions=1`, `assert.exception=On`), so the
  `assert()` guards in the module throw `AssertionError` rather than warning.
  That makes them directly testable — and means a violated invariant fails a
  test loudly instead of being ignored.
- **Reading `getName()` on an unstamped `FieldStorageDefinition` emits a PHP
  warning** (core indexes a key that was never set), and `failOnWarning="true"`
  turns that into a failure. Stamp both definitions with different values rather
  than asserting one is unset.
- **`getItemDefinition()` is declared as returning `DataDefinitionInterface`**
  but really returns `FieldItemDataDefinition`. Assert the instance before
  reaching for field-item-only methods, or static analysis will flag it.
