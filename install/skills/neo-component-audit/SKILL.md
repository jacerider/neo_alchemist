---
name: neo-component-audit
description: Audit and repair a site's existing Neo Alchemist components — sweep every *.component.yml / *.twig for drift from current conventions, migrate pre-`gap` section carriers, and check saved neo_component config for post-upgrade breakage. Use when asked to validate, audit or fix a site's components, after upgrading neo_alchemist, when `drush neo:alchemist:validate` reports warnings that need triaging, or when components render wrong after a module update. NOT for authoring a new component (use neo-component) or changing the module's own PHP (use neo-alchemist-dev).
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Auditing existing Neo components

A site's components are written once and then left alone, while the module keeps
moving. This skill is the sweep that closes that gap: find where a site's
components have drifted from current conventions, decide what is drift and what
is deliberate, fix what should be fixed, and prove the result still renders.

> Authoring a **new** component? That's the **neo-component** skill. Changing
> neo_alchemist's own PHP? That's **neo-alchemist-dev**. This skill only ever
> edits files under a theme's `components/` directory — plus, at Step 5, saved
> `neo_alchemist.neo_component.*` config.

## Mental model

Three kinds of drift, and only the first is mechanical:

1. **Lint-catchable.** `drush neo:alchemist:validate --all` finds these. Run it
   first and let it do the work — it is cheap and exhaustive.
2. **Needs judgment.** A numbered role shade may be a contrast bug or a
   deliberate raw-brand mark; only reading the design tells you which. The lint
   reports, you decide.
3. **Invisible to a Twig sweep.** A `link` prop that stopped resolving, a
   provider that now filters unpublished content. These live in saved config
   and in the module's behaviour, not in the component files, and they are the
   ones that silently drop content from a live page.

**`validate` warnings never fail the command.** It exits 0 with warnings, so a
clean exit code proves only that there were no *hard* errors. Read the output.

## Step 0 — get on the current conventions

Do this before reading anything else, or you will audit against an API that has
moved.

```bash
drush neo:build:install     # re-aggregate module skills into .claude/skills
```

The skill copies under `.claude/skills/` are a **snapshot from the last
install**, not a live view of the module. A version bump leaves them silently
stale, and you will author against superseded guidance. Symptoms: a class the
site's components use is missing from the compiled CSS, or module source
describes a system the skill never mentions.

Then check whether the compiled assets predate the code:

```bash
grep -A30 'versions:' config/neo_build.info.yml    # what the assets were built against
grep -m1 '^version:' web/modules/contrib/neo_alchemist/neo_alchemist.info.yml
```

A mismatch means every Tailwind class added since that build is missing from
`dist/`. Rebuild before judging anything visual (Step 6).

**Do not derive "what changed" from CHANGELOG.md.** It carries no version
numbers and omits component-facing changes entirely — the `gap` prop and the
heading shape's optional `title` appear nowhere in it. The sources that are
actually current:

- [STYLING.md](web/modules/contrib/neo_alchemist/STYLING.md) — spacing, seams,
  colors, the `gap` prop table.
- `web/modules/contrib/neo_alchemist/src/css/_utilities.css` — its `DEPRECATED`
  block names what replaced what, and is the only place a removal condition is
  stated.
- The shipped **neo-component** skill.

## Step 1 — inventory

```bash
drush neo:alchemist:components            # every component, provider, prop/slot counts
```

Then find out which are actually **placed**. A component nothing uses is a
different priority from one on the front page, and knowing the difference stops
you spending an afternoon on a leftover scaffold:

```bash
drush sql:query "SELECT field_full_tree FROM node__field_full" \
  | grep -o '"component":"[a-z_0-9]*"' | sort | uniq -c
```

Adjust the table/column to the site's component-tree field — `neo:alchemist:integrity`
discovers them all if you need the list. Header/footer-style components are
usually placed through config rather than a node, so absence here is not proof
a component is unused.

## Step 2 — sweep

```bash
drush neo:alchemist:validate --all              # every component, one bootstrap
drush neo:alchemist:validate --all --theme=front
```

Use `--all`. The single-component form costs a full Drupal bootstrap each time;
on three dozen components that is minutes instead of under a second.

Then prove they still render, on **both** paths:

```bash
drush neo:alchemist:render <id>          # editor preview
drush neo:alchemist:render <id> --live   # runtime, neoIsPreview FALSE
```

Both matter. `{% if neoIsPreview %}` branches are common — a fixed header
renders in-flow for the preview and fixed at runtime — so one path passing says
nothing about the other.

```bash
drush neo:alchemist:integrity            # placements whose component is gone
```

## Step 3 — triage

The lint reports; you decide. The judgment calls, in the order they come up:

**Numbered role shades** (`text-accent-500`, `border-primary-600`) are reported
on any component declaring a `scheme` prop. They are **warnings, not defects**.
The shade tracks the scheme's pallet remapping but is not contrast-checked
against the surface, so it *may* be unreadable on a dark or colorized scheme —
or it may be exactly the raw-brand mark the design wants. Look at the component
before touching it, and never convert a site's whole palette because the lint
listed it; that is a redesign, not a fix. When in doubt, render under a dark and
a colorized scheme (`--scheme=<id>`, ids from `drush neo:color:schemes`) and
judge the result.

**Everything else the lint reports is a defect** and should be fixed:
`legacy_section_carrier`, `channel_aware_inner_spacing`, `unguarded_heading_title`,
`mismatched_tag_condition`, `addclass_multi_arg`, `non_collapsing_surface`,
`orphan_content_token`, `href_without_target`, `unguarded_link_access`,
`dynamic_classes`, `undeclared_var`.

Three worth expanding, because the fix is not obvious from the message:

- **`orphan_content_token`** — a `-content` token is the contrast-picked
  foreground for *its own* surface. `text-base-900-content` over `bg-accent-900`
  has no contrast guarantee; the fix is to match the pairing
  (`text-accent-900-content`), not to delete the token.
- **`non_collapsing_surface`** — `bg-base-0` and `bg-white` resolve to the same
  pixels as `bg-default` but are deliberately excluded from seam collapsing, so
  two adjacent sections keep a doubled gap that looks like a spacing bug. Use
  `bg-default`.
- **`unguarded_link_access`** — a `link`/`url` value carries `access`: whether
  *this* visitor may follow the URI. It is FALSE for an unpublished node, a user
  profile an anonymous visitor may not see, a media entity with standalone URLs
  off. A dynamic entity link (`_entity:link:canonical`) **reports** that denial
  rather than resolving to nothing, so a template that builds an href on `.uri`
  alone links the visitor into a 403 where it once rendered nothing at all.
  The fix widens the guard — `{% if x and x.access %}`, with a non-link wrapper
  in the `{% else %}` — it is never to drop the link, because `access` FALSE
  still carries a title worth rendering. That is the whole point: the item
  shows, the anchor does not. Menu-fed props are exempt and not reported;
  `MenuValue` access-filters the tree before it reaches Twig.

## Step 4 — migrate pre-`gap` carriers

`legacy_section_carrier` is the big one, and on an un-migrated site it fires on
every stacking component. The `gap` prop applies `neo-section neo-section-y` to
the root; hand-written `py-component` / `my-component` carriers are deprecated
and survive only through a shim `_utilities.css` says it will remove.

Add the prop next to `spacing` in the yml:

```yaml
    spacing:
      type: spacing
    gap:
      type: gap
```

Then remove the hand-written carrier from the Twig. Which shape applies depends
on where the padding has to land:

**1. Standard background section** — the common case. The carrier moves from the
inner wrapper to the root. The root paints the background, so root padding still
fills it: same pixels, new system.

```twig
{% set classes = ['bg-default', 'component-bg'] %}
<div {{ attributes.addClass(classes) }}>          {# gap puts neo-section-y here #}
  <div class="container-content">                  {# was: container-content py-component #}
```

**2. Deep carrier** — a full-bleed band sits above the padded area, so root
padding would push it out of place. Declare the prop `apply: false` and merge it
onto the inner wrapper yourself; the editor's picker keeps working and seam
collapsing still reaches it, because the zeroing variable is set on the root and
inherits.

```yaml
    gap:
      type: gap
      apply: false
```
```twig
<div{{ gap.removeClass('neo-section').addClass('container-content') }}>
```

**3. Flush band** — a full-bleed element that must sit flush against the
section's edge by design. Keep the root carrier and pin the side:

```twig
{% set classes = ['bg-default', 'component-bg', 'component-flush-t'] %}
```

**Inner spacing needs care.** The shim makes the six base-size vertical
utilities (`py`/`pt`/`pb`/`my`/`mt`/`mb-component`) channel-aware, so once a
seam collapses they zero too — including ones used for spacing *inside* the
component. `channel_aware_inner_spacing` reports these. Put
`component-spacing-reset` on the content wrapper: it restores the natural size
for the whole subtree and keeps the spacing byte-for-byte what it was. The
relative variants (`-xs`/`-sm`/`-lg`/`-xl`) are immune by construction but are
÷/×1.5 of the base, so swapping to one *changes the design*; only do that
deliberately.

After migrating, re-render and compare. Root classes should now carry
`neo-section neo-section-y`:

```bash
drush neo:alchemist:render <id> --live --html | head -3
```

## Step 5 — config-level upgrade checks

None of these show up in a Twig sweep, and they are the ones that silently drop
content from a live page. Highest risk first.

**A link that stopped rendering.** A `link` prop's `access`, `target` and
`options` children are computed, but the children-match form offers them as
mappable. A placement that mapped only `uri` now has `access` flagged hidden,
and the shipped guard makes the link vanish:

```bash
grep -rn '\.access' web/themes/*/components/
```

For each hit, confirm the component's saved config maps `access`, or drop the
guard.

**Unpublished content that stopped resolving.** The "Only use published
entities" setting now filters every nesting level, not just the first:

```bash
grep -rln '_reference~\|_expand\|render_field' config/neo_alchemist.neo_component.*.yml
```

Check `shape_published` on those providers.

**Slot keys in a build alter.** Slot render-array keys changed from UUIDs to
names:

```bash
grep -rn "#slots" web/themes/*/  web/modules/custom/ 2>/dev/null
```

An implementation indexing `$build['#slots'][$slot][$uuid]` needs the item's
Twig key instead.

**Editor JS reading op records.** `data-component` ops are records now, and a
record is always truthy — code testing `ops[op]` treats every op as permitted:

```bash
grep -rn 'drupalSettings.neoAlchemist\|data-component' web/themes/*/src/ 2>/dev/null
```

Read `ops[op].permitted`.

## Step 6 — rebuild and verify

Any class the audit introduced does not exist until the assets are rebuilt.

```bash
npm run deploy      # or: drush neo:build <scope> per scope
drush cr
```

Then re-run the sweep, load a real page, and read the log:

```bash
drush neo:alchemist:validate --all
drush watchdog:show --severity=Error
```

**Export config afterwards.** The build writes the compiled versions into
*active* config; the committed `config/neo_build.info.yml` stays behind until:

```bash
drush config:export
```

That file is shared with the team, so leaving it stale gives every other
developer a false "assets need rebuilding" error.

## Common pitfalls

- **Treating a clean exit code as a pass.** `validate` exits 0 with warnings.
  Only hard errors fail it.
- **Converting every numbered shade.** The lint lists them; the design decides.
  A raw-brand decor mark is a legitimate use.
- **Auditing before `neo:build:install`.** Stale skill copies describe a
  superseded API, and you will "fix" components toward the wrong pattern.
- **Migrating a carrier without moving the padding.** The `gap` prop applies
  `neo-section-y` to the root; leaving `py-component` on the inner wrapper
  doubles the spacing rather than replacing it.
- **Swapping inner `mt-component` for `mt-component-sm` to silence a warning.**
  The variants are ÷/×1.5 of the base, so that changes the design.
  `component-spacing-reset` preserves it.
- **The shim's six classes are unlayered**, so they outrank numeric overrides
  and variant forms: on a legacy carrier, `pb-0` and `md:py-component` lose.
- **Rendering only the preview path.** `--live` exercises the runtime branch,
  and a `{% if neoIsPreview %}` component behaves differently there.
- **Assuming an unplaced component is unused.** Header/footer-style components
  are usually placed through config, not a node tree.

---

- **Edited this skill** → source is
  `neo_alchemist/install/skills/neo-component-audit/SKILL.md`; the active copy at
  `.claude/skills/neo-component-audit/SKILL.md` is regenerated by
  `drush neo:build:install`.
