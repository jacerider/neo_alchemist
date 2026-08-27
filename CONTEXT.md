# CONTEXT — neo_alchemist

The component system: `neo_component` config entities, shapes and prop defs, slots, filters,
and the render pipeline behind them. This file names the concepts this module owns, and is
maintained as terms resolve, so it covers what has been specified rather than every corner of
the module. Drupal's own vocabulary — SDC, component, theme, config object — is not repeated
here.

## Ejecting a component into a theme

**Ejectable component** — a module-shipped SDC declaring `neo_install: true`, meaning it is a
template the site is expected to take over rather than markup the module owns.
_Avoid:_ installable component, shipped component.

**Theme copy** — the copy of an ejectable component the installer writes into the site's front
theme. From the moment it lands it belongs to the site and is never overwritten.
_Avoid:_ ejected copy, site copy, forked component.

**Shipped default** — the settings value a module installs naming its own copy of an ejectable
component, and the only value a component claim will overwrite. _Avoid:_ module default,
fallback component.

**Component claim** — repointing a module's settings key from the shipped default to the
theme copy, performed only while the key still holds the shipped default so a site builder's
own choice always stands. _Avoid:_ claiming, component takeover, repointing.
See ADR 0002.

**Claim status** — what one component claim reports: `claimed` when the key was repointed,
`kept` when the key no longer holds the shipped default, `unavailable` when there is no theme
copy to claim yet. _Avoid:_ claim result, claim outcome.
