# Changelog

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
