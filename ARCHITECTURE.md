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
   `HeadingShape`, `ImageShape`, `LinkShape`, `MenuShape`, `RegionShape`, `SchemeShape`,
   `StyleShape`, …). Discovered by [ComponentShapePluginManager](src/ComponentShapePluginManager.php)
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
| ComponentValue | `src/Plugin/ComponentValue/` | `plugin.manager.neo_component_value` | Transform a prop value (prefix/suffix, formatting, media processing). |
| ComponentFilter | `src/Plugin/ComponentFilter/` | `plugin.manager.neo_component_filter` | Conditionally include/adjust components (`StringFilter`, `OptionFilter`, `EntityFilter`, `NumberFilter`). |
| ComponentAccess | `src/Plugin/ComponentAccess/` | `plugin.manager.neo_component_access` | Access/visibility rules for a component or prop. |

Supporting managers: `plugin.manager.neo_component_group`, `…_value_group`, `…_size`,
`…_filter_options`. Each family also has a `#[Component*]` attribute in
[src/Attribute/](src/Attribute/) and (for slot/filter/access) a small factory service
(`neo_component.slot.factory`, `.filter.factory`, `.access.factory`).

**Field integration** — components can be embedded in content entities via a field:
[src/Plugin/Field/FieldType/NeoComponentTreeList.php](src/Plugin/Field/FieldType/) (storage) +
`ComponentTreeWidget` (edit) + `ComponentTreeFormatter` (render), backed by the
`ComponentTreeStructure` data type. This is how a node/paragraph holds a tree of
components.

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
- **Per-item data on the `menu` value provider** — implement
  `hook_neo_alchemist_menu_value_item_alter(&$entry, $item, $shape)` (documented in
  [neo_alchemist.api.php](neo_alchemist.api.php)). Extra `$entry` keys flow through the
  `menu` prop schema to twig (precedent: `in_active_trail`); set `$entry = NULL` to drop
  an item; add cacheability via `$shape->addCacheableDependency()` — provider-added
  dependencies are merged into the component build after `getPropValue()` runs.
  Canonical consumer: `modules/neo_alchemist_menu/`.
- **A Drush command** — add a method to `NeoAlchemistCommands` with `#[CLI\Command]`;
  inject services via `#[Autowire(service: '…')]` (the class uses `AutowireTrait`).
- After any plugin/attribute/service change: **`drush cr`** (discovery is cached), and run
  `drush neo:build <scope>` if you touched Tailwind-scanned output.
