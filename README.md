INTRODUCTION
------------

Provides component creation and management utilizing Drupal single directory
components.


REQUIREMENTS
------------

This module requires the Neo suite of modules.


INSTALLATION
------------

Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/node/1897420 for further information.


DRUSH COMMANDS
----

Alchemist ships introspection and verification commands to make authoring
components easier — list what already exists, look up the available shapes,
icons and color schemes, and confirm a component renders before shipping it.

In every command below, `<id>` is the SDC component id in `provider:machine_name`
form (e.g. `front:cards_test`). Run `drush neo:alchemist:components` to see the
valid ids. All tabular commands accept `--format=json` (and the other standard
Drush formats) for machine parsing.

## Discover & inspect

`neo:alchemist:components` (alias `neoa-components`) — list every Neo component
(SDC with `neo: true`) with its provider, status, and prop/slot counts. Use it
to check whether a machine name is already taken.

```bash
drush neo:alchemist:components
drush neo:alchemist:components --theme=front
```

`neo:alchemist:info <id>` (alias `neoa-info`) — dump one component's resolved
definition: props (authored type, title, required, examples), slots, libraries,
and status. Defaults to YAML output.

```bash
drush neo:alchemist:info front:cards_test
```

`neo:alchemist:shapes [name]` (alias `neoa-shapes`) — with no argument, list
every prop-def shape (`heading`, `image`, `link`, `scheme`, `spacing`, …). With
a name, dump that shape's schema, a paste-ready `.component.yml` prop snippet,
and its Twig render pattern.

```bash
drush neo:alchemist:shapes
drush neo:alchemist:shapes heading
```

Two related lookups live in the modules that own the data:

- **Icons** — `drush neo:icon:list [search]` (Neo Icon) searches the icon names
  for the `icon()` Twig function.
- **Color schemes** — `drush neo:color:schemes` (Neo Color) lists the enabled
  schemes (id, label, selector, dark/colorized) to verify components against, and
  supplies the id for `neo:alchemist:render --scheme`.

## Verify

`neo:alchemist:validate <id>` (alias `neoa-validate`) — statically lint a
component. Flags missing `neo: true`, props with no `examples`, unknown prop
types, `{% if/for %}` references to props that aren't declared, and Tailwind
class names assembled dynamically (which never compile). Exits non-zero on hard
errors.

```bash
drush neo:alchemist:validate front:cards_test
```

`neo:alchemist:render <id>` (alias `neoa-render`) — render a component
headlessly from its `examples` and report PASS/FAIL, surfacing Twig/render
errors as a message instead of a broken page. `--html` prints the rendered
markup; `--scheme=<id>` wraps the render in a color scheme selector; `--live`
renders the runtime path (`neoIsPreview` false) instead of the editor preview.

```bash
drush neo:alchemist:render front:cards_test
drush neo:alchemist:render front:cards_test --html
drush neo:alchemist:render front:cards_test --scheme=dark
drush neo:alchemist:render front:header --live --html
```


TWIG EVENTS
----

Sometimes a component needs to allow mouse events to be able to be triggered
while in the management interface (tabs, accordions, etc.). To tell Alchemist
about this, the following can be added in twig to the element that needs to be
exposed:

## Basic event

A simple event that will just allow mouse interfaction with an element.

```twig
<div
{% if neoIsPreview %}
  data-event
{% endif %}
>
```

## Toggle event

For elements that can be toggled. This is ideal for an accordion that allows
multiple elements to be visible at once and each one can be shown or hidden.

```twig
<div
{% if neoIsPreview %}
  data-event='{"action": "toggle"}'
{% endif %}
>
```

## Grouped event

For elements that belong together. This is ideal for tabs or accordion elements
that only allow a single visible element. The group name can be any string but
should be unique per grouping.

```twig
<div
{% if neoIsPreview %}
  data-event='{"group": "tabs"}'
{% endif %}
>
```
