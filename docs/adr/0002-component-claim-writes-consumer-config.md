# ADR 0002 — The component claim writes the consuming module's own config

**Status:** accepted
**Date:** 2026-08-26

## Context

Two modules — `neo_commerce` and `neo_search` — arrived independently at the same six-step
algorithm for pointing a settings key at a theme copy: sweep the ejectable components into
the theme, resolve the theme, read the module's settings, bail unless the key still holds the
shipped default, build the theme copy's plugin id, and write it if the theme actually has
that component. The two implementations differ in three literals and in nothing else about
the algorithm. A third consumer would have written a third copy.

`ThemeComponentInstaller` already owns everything the algorithm needs — the `neo_install`
flag, `installAll()`, `resolveTheme()` and the SDC plugin manager — but it has never written
config. Giving it the claim means a service in `neo_alchemist` opens
`neo_commerce.settings` or `neo_search.settings` by name and saves it, which is not what the
word *installer* leads a reader to expect.

## Decision

The claim lives on `ThemeComponentInstaller` as a public method taking the shipped default,
the config name and the config key, and it writes that config object itself. The consuming
modules keep their own hook wiring and their own `$isSyncing` guards; only the algorithm
moves.

Two alternatives were rejected. A shared trait or base hook class would leave each module
running its own copy of the algorithm — the duplication survives, merely inherited, and it
does not work for `neo_search`, whose implementation is procedural. A registry where modules
declare what they want claimed, resolved by `neo_alchemist` on its own hook, would invert
control the other way: two consumers do not justify a plugin type, and it would take the
`$isSyncing` and hook-ordering decisions away from the modules that have reason to differ
about them.

## Consequences

`neo_alchemist` now knows how to write a settings key it does not own, and the safety of that
rests entirely on the shipped-default check: the claim overwrites exactly one value, the one
the consuming module installed itself, and never a value a site builder chose. That check is
the contract, and it is pinned by tests in this module rather than in each consumer.

Because the method is public API on a package installed across many sites, a consumer that
calls it must require the release that carries it. That ordering — release `neo_alchemist`,
then the consumers — is the price of the seam, and there is no runtime guard against getting
it wrong; a missing method is a fatal error rather than a silent no-op, which is the correct
direction for a mistake this cheap to avoid.
