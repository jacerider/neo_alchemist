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
