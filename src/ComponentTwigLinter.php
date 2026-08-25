<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Static checks over a component's Twig source and its .component.yml.
 *
 * Everything here is a pure function of (twig source, raw yml, declared prop
 * names, declared slot names) — no container, no plugin managers, no disk.
 * That is the point: these are regexes over Twig, they are subtle, and the
 * only way to keep them honest is a unit test that can call them directly.
 *
 * The failure this class guards against is a *quiet* one. `neo:alchemist:
 * validate` reports warnings and still exits 0, so a check that over-matches
 * does not break a build — it trains people to skim past the whole command,
 * and the real finding two lines below goes with it. Every detector here is
 * therefore written to prefer silence over a false positive, and each one
 * documents the case it deliberately declines to catch.
 *
 * @see \Drupal\neo_alchemist\Drush\Commands\NeoAlchemistCommands::validate()
 */
final class ComponentTwigLinter {

  /**
   * Twig identifiers that are always available and never a prop reference.
   */
  public const TWIG_GLOBALS = [
    'attributes',
    'neoIsPreview',
    'loop',
    '_self',
    '_context',
    '_charset',
  ];

  /**
   * Utility prefixes that can carry a color token.
   */
  private const COLOR_UTILS = 'bg|text|border(?:-[trblxyse])?|from|via|to'
    . '|fill|stroke|ring|shadow|divide|outline|decoration|accent|caret'
    . '|placeholder';

  /**
   * The six base-size vertical spacing utilities the deprecated shim covers.
   *
   * `src/css/_utilities.css` makes these read the `--spacing-component-t/-b`
   * channels so pre-`gap` components keep collapsing their seams. That is
   * also why they are unsafe for spacing *inside* a component.
   */
  private const CHANNEL_UTILS = 'py|pt|pb|my|mt|mb';

  /**
   * Surfaces that never participate in seam collapsing.
   *
   * NeoBuildInlineEventSubscriber pins the participating surfaces to
   * bg-default and bg-base-50/100/200/300. `bg-base-0` and `bg-white`
   * resolve to the same pixels as bg-default but are deliberately excluded,
   * so a section painting one of them silently keeps its doubled gap.
   */
  private const NON_COLLAPSING_SURFACES = ['bg-base-0', 'bg-white'];

  /**
   * Runs every check and returns the warnings, in a stable order.
   *
   * @param string $twig
   *   The component's Twig source.
   * @param array|null $rawYml
   *   The parsed .component.yml, or NULL when it could not be read.
   * @param string[] $declaredProps
   *   Declared prop names.
   * @param string[] $declaredSlots
   *   Declared slot names.
   *
   * @return array<int, array{check: string, message: string}>
   *   Each finding carries a machine id (so a caller can filter) and the
   *   human message.
   */
  public function lint(string $twig, ?array $rawYml, array $declaredProps, array $declaredSlots): array {
    $rawProps = is_array($rawYml)
      ? ($rawYml['props']['properties'] ?? [])
      : [];
    $findings = [];

    // Slots and macro parameters are real identifiers the template is
    // entitled to reference; without them every slotted component and every
    // {% macro %} reports phantom "undeclared prop" warnings.
    $safe = array_merge(
      $declaredProps,
      $declaredSlots,
      $this->macroParams($twig),
      self::TWIG_GLOBALS,
    );
    foreach ($this->undeclaredVars($twig, $safe) as $var) {
      $findings[] = [
        'check' => 'undeclared_var',
        'message' => sprintf('Twig references `%s` in an {%% if/for %%} but no such prop or slot is declared (typo, or an intentional local?).', $var),
      ];
    }

    if ($this->hasDynamicClasses($twig)) {
      $findings[] = [
        'check' => 'dynamic_classes',
        'message' => 'Twig appears to build Tailwind class names dynamically (e.g. `bg-{{ x }}-500` or `\'text-\' ~ x`). Those never compile — enumerate full class names or use CSS variables.',
      ];
    }

    // Only components that declare a scheme prop are flagged: they are the
    // ones explicitly designed to be recolored. A numbered shade elsewhere
    // may well be a deliberate raw-brand mark.
    $hasScheme = (bool) array_filter(
      $rawProps,
      static fn ($p) => is_array($p) && (($p['type'] ?? NULL) === 'scheme'),
    );
    if ($hasScheme && ($shades = $this->numberedRoleShadeClasses($twig))) {
      $findings[] = [
        'check' => 'numbered_role_shade',
        'message' => sprintf('Twig uses numbered role shade(s) `%s` — these never adapt to the component\'s scheme(s). Adaptive alternatives: bare tokens (`text-primary`), hover steps (`hover:text-primary-hover`), link tokens (`text-link` / `hover:text-link-hover`), or `.btn*` classes. Keep a numbered shade only for deliberate raw-brand marks (decor).', implode('`, `', $shades)),
      ];
    }

    foreach ($this->unguardedLinkAccess($twig, $rawProps) as $expr) {
      $findings[] = [
        'check' => 'unguarded_link_access',
        'message' => sprintf('Twig builds an href from `%1$s.uri` without checking `%1$s.access` — a visitor who may not reach the target is linked into a 403. Widen the enclosing guard to `{%% if %1$s and %1$s.access %%}` (or fall back to a non-link wrapper); do not drop the link, `access` FALSE still carries a title worth rendering.', $expr),
      ];
    }

    foreach ($this->hrefsWithoutTarget($twig) as $expr) {
      $findings[] = [
        'check' => 'href_without_target',
        'message' => sprintf('Twig builds an href from `%1$s.uri` but the `<a>` prints no `target` — a link set to "open in a new window" renders same-window. Add `target="{{ %1$s.target }}"`; neo_uri() cannot supply it, because the shape strips `options.attributes` before rendering.', $expr),
      ];
    }

    return array_merge(
      $findings,
      $this->spacingFindings($twig, $rawProps),
      $this->headingFindings($twig, $rawProps),
      $this->markupFindings($twig),
      $this->colorFindings($twig),
    );
  }

  /**
   * Findings about the section carrier and channel-aware inner spacing.
   *
   * @param string $twig
   *   The Twig source.
   * @param array $rawProps
   *   The raw `props.properties` map.
   *
   * @return array<int, array{check: string, message: string}>
   *   The findings.
   */
  private function spacingFindings(string $twig, array $rawProps): array {
    $types = array_map(
      static fn ($p) => is_array($p) ? ($p['type'] ?? NULL) : NULL,
      $rawProps,
    );
    if (!in_array('spacing', $types, TRUE)) {
      // Not a stacking section — it has no vertical rhythm to carry.
      return [];
    }

    $carriers = $this->channelSpacingUtilities($twig);
    if (!in_array('gap', $types, TRUE)) {
      $found = $carriers
        ? sprintf(' Twig still hand-writes `%s`.', implode('`, `', $carriers))
        : '';
      return [[
        'check' => 'legacy_section_carrier',
        'message' => sprintf('Declares `spacing` but no `gap` prop — the component is on the deprecated pre-`gap` section carrier, kept working only by a compatibility shim.%s Add `gap: { type: gap }` (it applies `neo-section-y` to the root) and drop the hand-written carrier.', $found),
      ],
      ];
    }

    // Migrated, but a base-size vertical utility is still in the markup. On
    // a `gap` component that is inner spacing, and the shim makes those six
    // classes channel-aware — so a collapsed seam zeroes it too.
    if ($carriers && !str_contains($twig, 'component-spacing-reset')) {
      return [[
        'check' => 'channel_aware_inner_spacing',
        'message' => sprintf('Uses base-size `%s` for spacing inside a `gap` component — the compatibility shim makes those channel-aware, so a collapsed section seam silently zeroes them too. Use a relative variant (`-xs`/`-sm`/`-lg`/`-xl`), a numeric utility, or put `component-spacing-reset` on the content wrapper.', implode('`, `', $carriers)),
      ],
      ];
    }

    return [];
  }

  /**
   * Findings about heading props rendered without guarding each part.
   *
   * @param string $twig
   *   The Twig source.
   * @param array $rawProps
   *   The raw `props.properties` map.
   *
   * @return array<int, array{check: string, message: string}>
   *   The findings.
   */
  private function headingFindings(string $twig, array $rawProps): array {
    $findings = [];
    foreach ($this->unguardedHeadingTitles($twig, $rawProps) as $name) {
      $findings[] = [
        'check' => 'unguarded_heading_title',
        'message' => sprintf('Twig prints `{{ %1$s.title }}` but never guards it with an {%% if %1$s.title %%} — `title` is optional on the heading shape, so a supertitle-only heading emits an empty tag. Guard each part (see "Rendering a heading" in the neo-component skill).', $name),
      ];
    }
    return $findings;
  }

  /**
   * Findings about Twig markup idioms.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return array<int, array{check: string, message: string}>
   *   The findings.
   */
  private function markupFindings(string $twig): array {
    $findings = [];

    if ($this->multiArgAddClass($twig)) {
      $findings[] = [
        'check' => 'addclass_multi_arg',
        'message' => 'Twig calls `addClass()` with several string arguments. It takes ONE array — `addClass([\'a\', \'b\'])`; the extra arguments are silently dropped.',
      ];
    }

    foreach ($this->mismatchedTagConditions($twig) as $pair) {
      $findings[] = [
        'check' => 'mismatched_tag_condition',
        'message' => sprintf('A conditional `<%1$s>` opens on `%2$s` but closes on `%3$s`. When the two disagree the template emits mismatched tags — an opening `<div>` closed by `</%1$s>`. Use the same condition for both.', $pair['tag'], $pair['open'], $pair['close']),
      ];
    }

    return $findings;
  }

  /**
   * Findings about color tokens that cannot resolve as intended.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return array<int, array{check: string, message: string}>
   *   The findings.
   */
  private function colorFindings(string $twig): array {
    $findings = [];

    foreach ($this->nonCollapsingSurfaces($twig) as $class) {
      $findings[] = [
        'check' => 'non_collapsing_surface',
        'message' => sprintf('A `component-bg` root paints `%s`, which is deliberately excluded from seam collapsing — two adjacent sections keep their doubled gap even though the pixels match. Use `bg-default` for a scheme surface.', $class),
      ];
    }

    foreach ($this->orphanContentTokens($twig) as $orphan) {
      $findings[] = [
        'check' => 'orphan_content_token',
        'message' => sprintf('Twig uses `%1$s` but never paints `%2$s`. A `-content` token is the contrast-picked foreground for its OWN surface, so it is only guaranteed readable on `%2$s` — over any other background it is unmanaged.', $orphan['token'], $orphan['background']),
      ];
    }

    return $findings;
  }

  /**
   * Parameter names declared by {% macro %} tags.
   *
   * A macro's parameters are locals, not props. Without them a template that
   * factors its markup into macros reports one phantom warning per
   * parameter — which is what a recursive menu macro looks like.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return string[]
   *   Distinct parameter names.
   */
  public function macroParams(string $twig): array {
    $names = [];
    if (preg_match_all('/\{%-?\s*macro\s+[a-zA-Z_][\w]*\s*\(([^)]*)\)/', $twig, $m)) {
      foreach ($m[1] as $args) {
        foreach (explode(',', $args) as $arg) {
          // Strip a default value, then whitespace.
          $arg = trim(explode('=', $arg)[0]);
          if ($arg !== '' && preg_match('/^[a-zA-Z_][\w]*$/', $arg)) {
            $names[] = $arg;
          }
        }
      }
    }
    return array_values(array_unique($names));
  }

  /**
   * Best-effort: prop-like identifiers referenced in Twig but not declared.
   *
   * @param string $twig
   *   The Twig source.
   * @param string[] $safe
   *   Names that are legitimately available: props, slots, macro params and
   *   Twig globals.
   *
   * @return string[]
   *   Distinct undeclared identifiers, in source order.
   */
  public function undeclaredVars(string $twig, array $safe): array {
    // Local variables introduced by {% for %} and {% set %} are not props.
    $local = [];
    if (preg_match_all('/\{%-?\s*for\s+([a-zA-Z_][\w]*(?:\s*,\s*[a-zA-Z_][\w]*)?)\s+in\s+/', $twig, $lm)) {
      foreach ($lm[1] as $decl) {
        foreach (preg_split('/\s*,\s*/', $decl) as $v) {
          $local[] = $v;
        }
      }
    }
    if (preg_match_all('/\{%-?\s*set\s+([a-zA-Z_][\w]*)/', $twig, $sm)) {
      $local = array_merge($local, $sm[1]);
    }

    $referenced = [];
    if (preg_match_all('/\{%-?\s*if\s+(?:not\s+)?([a-zA-Z_][\w]*)/', $twig, $ifm)) {
      $referenced = array_merge($referenced, $ifm[1]);
    }
    if (preg_match_all('/\{%-?\s*for\s+[a-zA-Z_][\w]*(?:\s*,\s*[a-zA-Z_][\w]*)?\s+in\s+([a-zA-Z_][\w]*)/', $twig, $fm)) {
      $referenced = array_merge($referenced, $fm[1]);
    }

    $safe = array_merge($safe, $local);
    $undeclared = array_diff(array_unique($referenced), $safe);
    // Ignore obvious literals.
    return array_values(array_filter($undeclared, fn($v) => !in_array(strtolower($v), ['true', 'false', 'null'], TRUE)));
  }

  /**
   * Detects Tailwind class names assembled dynamically in Twig.
   */
  public function hasDynamicClasses(string $twig): bool {
    $utils = 'bg|text|border|from|via|to|fill|stroke|ring|shadow|divide|outline|decoration|accent|caret|placeholder';
    // e.g. class="bg-{{ color }}-500" or 'text-{{ tone }}'.
    if (preg_match('/\b(?:' . $utils . ')-[a-z0-9-]*\{\{/', $twig)) {
      return TRUE;
    }
    // e.g. 'text-' ~ tone  or  tone ~ '-500'.
    if (preg_match('/[\'"](?:' . $utils . ')-[a-z0-9-]*[\'"]\s*~/', $twig) || preg_match('/~\s*[\'"]-?[a-z0-9]+[\'"]/', $twig)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Collects numbered primary/secondary/accent shade utilities from Twig.
   *
   * Numbered role shades (text-primary-500, hover:bg-accent-600, …) are the
   * raw brand ramp in every scheme — only the bare tokens, their -hover
   * steps, the link tokens and the .btn* classes are contrast-managed. Base
   * is deliberately not matched: the base ramp is scheme-scoped, so
   * bg-base-100 and friends DO adapt.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return string[]
   *   Distinct offending class strings (variant prefixes included), in
   *   source order.
   */
  public function numberedRoleShadeClasses(string $twig): array {
    // Twig comments cannot apply a class to an element, and docs that *name*
    // these classes (to explain why they are avoided) must not trip the
    // check — unlike the dynamic-class check, whose subject is compilation,
    // where comments do count.
    $twig = $this->stripComments($twig);
    preg_match_all('/(?:[a-z][a-z0-9-]*:)*(?:' . self::COLOR_UTILS . ')-(?:primary|secondary|accent)-\d{1,3}(?:-content)?\b/', $twig, $matches);
    return array_values(array_unique($matches[0]));
  }

  /**
   * Best-effort: link expressions whose <a> builds an href but sets no target.
   *
   * Deliberately advisory and deliberately narrow:
   * - A tag that carries any `target=` attribute is left alone. Hardcoding
   *   `target="_blank"`, or forcing same-window on an in-page anchor, is a
   *   choice the template is entitled to make.
   * - Only the component's own template is read, so a link rendered from a
   *   theme partial (`templates/includes/*.html.twig`) or an {% include %}d
   *   file is out of reach and will not be flagged.
   * - The tag match skips `>` inside {{ }} / {% %} so a comparison in an
   *   attribute does not truncate it, but it remains a regex over Twig
   *   source rather than a parse.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return string[]
   *   Distinct link expressions (`link`, `item.link`, `item.url`, …) whose
   *   href is built without a target, in source order.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\UrlShapeTrait::preRenderValue()
   */
  public function hrefsWithoutTarget(string $twig): array {
    // A commented-out anchor renders nothing, and docs that show the idiom
    // must not trip the check.
    $twig = $this->stripComments($twig);
    if (!preg_match_all('/<a\b(?:\{\{.*?\}\}|\{%.*?%\}|[^>])*>/s', $twig, $tags)) {
      return [];
    }
    $found = [];
    foreach ($tags[0] as $tag) {
      if (preg_match('/\btarget\s*=/', $tag)) {
        continue;
      }
      if (preg_match_all('/neo_uri\(\s*([a-zA-Z_][\w.]*)\.uri\b/', $tag, $uris)) {
        foreach ($uris[1] as $expr) {
          $found[$expr] = TRUE;
        }
      }
    }
    return array_keys($found);
  }

  /**
   * Link expressions turned into an href with no `.access` guard above them.
   *
   * A `link`/`url` value carries `access` — whether this visitor may follow
   * the URI. It is FALSE for an unpublished node, a user profile an anonymous
   * visitor may not see, a media entity with standalone URLs off. A template
   * that builds an href without consulting it links the visitor into a 403.
   *
   * The guard is looked for on the stack of {% if %} conditions open at the
   * point the href is built, not anywhere in the file. A file-wide search
   * gives a template credit for guarding one loop while another goes bare —
   * which is exactly the shape this check exists to catch.
   *
   * Narrow in the same ways ::hrefsWithoutTarget() is: a regex over Twig
   * source, the component's own template only, comments stripped. An
   * {% else %} invalidates the condition it belongs to, so an href in the
   * unguarded branch is still reported. Both the inline `<a href="{{ neo_uri(…)
   * }}">` form and the `{% set href = neo_uri(…) %}` form are read.
   *
   * Menu-derived links are exempt. MenuValue runs the tree through
   * `menu.default_tree_manipulators:checkAccess` before building, so an item
   * the visitor may not reach never arrives in the template at all and its
   * `access` is TRUE by construction — a guard there is dead code, and asking
   * for one is the kind of noise that trains people to skim the whole report.
   *
   * @param string $twig
   *   The Twig source.
   * @param array $rawProps
   *   (optional) The raw `props.properties` map, used to find the `menu`-typed
   *   props whose loop variables are exempt. Omit it and nothing is exempt.
   *
   * @return string[]
   *   Distinct link expressions (`link`, `item.link`, …) whose href is built
   *   unguarded, in source order.
   *
   * @see \Drupal\neo_alchemist\Match\MatcherField::getEntityDefinitionLink()
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\UrlShapeTrait::getFieldItemValue()
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MenuValue::getMenuValue()
   */
  public function unguardedLinkAccess(string $twig, array $rawProps = []): array {
    $twig = $this->stripComments($twig);
    $exempt = $this->menuDerivedVariables($twig, $rawProps);
    // One pass in source order over the three things that matter: the if/else
    // tags that open and close a guard, the {% set %}s that build an href out
    // of band, and the anchors that build one inline.
    $pattern = '/\{%-?\s*(if|elseif|else|endif)\b((?:(?!%\}).)*)%\}'
      . '|\{%-?\s*set\b((?:(?!%\}).)*)%\}'
      . '|<a\b(?:\{\{.*?\}\}|\{%.*?%\}|[^>])*>/s';
    if (!preg_match_all($pattern, $twig, $tokens, PREG_SET_ORDER)) {
      return [];
    }
    $stack = [];
    $found = [];
    foreach ($tokens as $token) {
      $keyword = $token[1] ?? '';
      if ($keyword === 'if') {
        $stack[] = $token[2];
        continue;
      }
      if ($keyword === 'elseif' || $keyword === 'else') {
        // The branch that just opened is reached precisely when the condition
        // above did NOT hold, so that condition guards nothing here.
        array_pop($stack);
        $stack[] = $keyword === 'elseif' ? $token[2] : '';
        continue;
      }
      if ($keyword === 'endif') {
        array_pop($stack);
        continue;
      }
      // A {% set %} or an <a> tag: whichever matched, the whole token text
      // holds any neo_uri() call it makes.
      //
      // The token counts as its own guard alongside the open conditions: a
      // ternary in the same expression — `{% set href = (x.access and x.uri)
      // ? neo_uri(x.uri) : null %}` — guards just as well as an {% if %}
      // wrapped around it, and is the natural way to write the `set` form.
      $guards = implode(' ', $stack) . ' ' . $token[0];
      if (!preg_match_all('/neo_uri\(\s*([a-zA-Z_][\w.]*)\.uri\b/', $token[0], $uris)) {
        continue;
      }
      foreach ($uris[1] as $expr) {
        if (in_array(explode('.', $expr)[0], $exempt, TRUE)) {
          continue;
        }
        $quoted = preg_quote($expr, '/');
        if (!preg_match('/\b' . $quoted . '\.access\b/', $guards)) {
          $found[$expr] = TRUE;
        }
      }
    }
    return array_keys($found);
  }

  /**
   * Variable names that hold menu items, and so need no access guard.
   *
   * Seeded with the `menu`-typed prop names and grown until stable, so the
   * three ways a menu reaches the point of use are all covered:
   * - `{% for item in <menuProp> %}` — the direct loop.
   * - `{% for link in col.below %}` — a nested level of an already-menu var.
   * - `{% macro nav(items, level) %}` called as `menus.nav(<menuVar>, 0)` —
   *   the recursive-macro shape a multi-level menu template takes. Matched
   *   positionally on the call, which is why the loop below repeats: the
   *   recursive call `menus.nav(item.below, level + 1)` only resolves once
   *   `item` is known to be menu-derived.
   *
   * @param string $twig
   *   The Twig source, comments already stripped.
   * @param array $rawProps
   *   The raw `props.properties` map.
   *
   * @return string[]
   *   Variable names to exempt.
   */
  private function menuDerivedVariables(string $twig, array $rawProps): array {
    $vars = [];
    foreach ($rawProps as $name => $prop) {
      if (is_array($prop) && ($prop['type'] ?? NULL) === 'menu') {
        $vars[(string) $name] = TRUE;
      }
    }
    if (!$vars) {
      return [];
    }
    preg_match_all(
      '/\{%-?\s*for\s+(?:\w+\s*,\s*)?(\w+)\s+in\s+([\w.]+)/',
      $twig,
      $loops,
      PREG_SET_ORDER,
    );
    preg_match_all(
      '/\{%-?\s*macro\s+(\w+)\s*\(([^)]*)\)/',
      $twig,
      $macros,
      PREG_SET_ORDER,
    );
    // Bounded by the number of facts that can be learned: every pass either
    // adds a variable or stops.
    do {
      $before = count($vars);
      foreach ($loops as $loop) {
        if (isset($vars[explode('.', $loop[2])[0]])) {
          $vars[$loop[1]] = TRUE;
        }
      }
      foreach ($macros as $macro) {
        $params = array_values(array_filter(array_map(
          static fn (string $p): string => trim(explode('=', $p)[0]),
          explode(',', $macro[2]),
        )));
        if (!$params) {
          continue;
        }
        $call = '/\.' . preg_quote($macro[1], '/') . '\s*\(\s*([\w.]+)/';
        if (!preg_match_all($call, $twig, $args)) {
          continue;
        }
        foreach ($args[1] as $arg) {
          if (isset($vars[explode('.', $arg)[0]])) {
            $vars[$params[0]] = TRUE;
          }
        }
      }
    } while (count($vars) > $before);
    return array_keys($vars);
  }

  /**
   * Base-size vertical `*-component` utilities present in the markup.
   *
   * Matches only the base size: the relative variants (`-xs`, `-sm`, `-lg`,
   * `-xl`) read `--spacing-component` directly and are immune to a collapsed
   * seam, which is exactly why they are the safe choice for inner spacing.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return string[]
   *   Distinct class names, in source order.
   */
  public function channelSpacingUtilities(string $twig): array {
    $twig = $this->stripComments($twig);
    preg_match_all('/\b(?:' . self::CHANNEL_UTILS . ')-component\b(?!-)/', $twig, $m);
    return array_values(array_unique($m[0]));
  }

  /**
   * Heading props whose `.title` is printed but never guarded.
   *
   * The template gets the benefit of the doubt: if it guards the title
   * anywhere — in any {% if %} — the author has considered the empty case,
   * and printing it unguarded elsewhere (an anchor's `title=` attribute, for
   * instance) is harmless.
   *
   * @param string $twig
   *   The Twig source.
   * @param array $rawProps
   *   The raw `props.properties` map.
   *
   * @return string[]
   *   Heading prop names that are printed unguarded.
   */
  public function unguardedHeadingTitles(string $twig, array $rawProps): array {
    $twig = $this->stripComments($twig);
    $found = [];
    foreach ($rawProps as $name => $prop) {
      if (!is_array($prop) || ($prop['type'] ?? NULL) !== 'heading') {
        continue;
      }
      $q = preg_quote((string) $name, '/');
      if (!preg_match('/\{\{-?\s*' . $q . '\.title\b/', $twig)) {
        continue;
      }
      if (!preg_match('/\{%-?\s*if\b[^%]*\b' . $q . '\.title\b/', $twig)) {
        $found[] = (string) $name;
      }
    }
    return $found;
  }

  /**
   * Whether addClass() is called with several arguments instead of one array.
   *
   * Only the unambiguous form is matched — a quoted string immediately
   * followed by a comma. `addClass(['a', 'b'])` and `addClass(classes)` are
   * both correct and both start with something other than a quote.
   */
  public function multiArgAddClass(string $twig): bool {
    $twig = $this->stripComments($twig);
    return (bool) preg_match('/\baddClass\(\s*[\'"][^\'"]*[\'"]\s*,/', $twig);
  }

  /**
   * Conditionally-opened tags whose matching close uses another condition.
   *
   * Narrow on purpose. Only the `{% if C %}<tag …>{% else %}` /
   * `{% if C %}</tag>{% else %}` shape is considered, and only when the
   * template holds the same number of each, so the pairing is positional and
   * unambiguous. Anything more interleaved is left alone.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return array<int, array{tag: string, open: string, close: string}>
   *   One entry per mismatched pair.
   */
  public function mismatchedTagConditions(string $twig): array {
    $twig = $this->stripComments($twig);
    $opens = [];
    if (preg_match_all('/\{%-?\s*if\s+((?:(?!%\}).)+?)\s*-?%\}\s*<([a-z][a-z0-9]*)\b(?:\{\{[^<]*?\}\}|\{%[^<]*?%\}|[^><])*>\s*\{%-?\s*else\s*-?%\}/s', $twig, $m, PREG_SET_ORDER)) {
      foreach ($m as $hit) {
        $opens[] = ['cond' => $this->normalize($hit[1]), 'tag' => $hit[2]];
      }
    }
    if (!$opens) {
      return [];
    }
    $closes = [];
    if (preg_match_all('/\{%-?\s*if\s+((?:(?!%\}).)+?)\s*-?%\}\s*<\/([a-z][a-z0-9]*)>\s*\{%-?\s*else\s*-?%\}/s', $twig, $m, PREG_SET_ORDER)) {
      foreach ($m as $hit) {
        $closes[] = ['cond' => $this->normalize($hit[1]), 'tag' => $hit[2]];
      }
    }
    if (count($closes) !== count($opens)) {
      return [];
    }

    $findings = [];
    foreach ($opens as $i => $open) {
      $close = $closes[$i];
      if ($open['tag'] !== $close['tag'] || $open['cond'] === $close['cond']) {
        continue;
      }
      $findings[] = [
        'tag' => $open['tag'],
        'open' => $open['cond'],
        'close' => $close['cond'],
      ];
    }
    return $findings;
  }

  /**
   * Non-collapsing surfaces painted on a `component-bg` root.
   *
   * The two tokens are only a problem *with* the marker: without it the
   * component is not a background section and never took part in collapsing
   * anyway. Both must therefore appear in the same class group — the
   * contents of one `{% set … = [...] %}`, one `addClass([...])`, or one
   * `class="…"` — so a card painting `bg-base-0` inside a `bg-default`
   * section is not flagged.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return string[]
   *   Distinct offending class names.
   */
  public function nonCollapsingSurfaces(string $twig): array {
    $found = [];
    foreach ($this->classGroups($twig) as $group) {
      if (!str_contains($group, 'component-bg')) {
        continue;
      }
      foreach (self::NON_COLLAPSING_SURFACES as $class) {
        if (preg_match('/\b' . preg_quote($class, '/') . '\b(?!-)/', $group)) {
          $found[$class] = TRUE;
        }
      }
    }
    return array_keys($found);
  }

  /**
   * Foreground `-content` tokens whose background is never painted.
   *
   * Such a token is the contrast-picked foreground for one specific surface,
   * so `text-base-900-content` is only guaranteed readable on `bg-base-900`.
   * Using it over a different background — the mistake this catches —
   * silently gives up that guarantee.
   *
   * Scoped to the whole template rather than to one element, because the
   * background is usually painted on an ancestor. That means it reports only
   * the unambiguous case: the paired background appears nowhere at all.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return array<int, array{token: string, background: string}>
   *   One entry per orphaned token.
   */
  public function orphanContentTokens(string $twig): array {
    $twig = $this->stripComments($twig);
    $pattern = '/(?:[a-z][a-z0-9-]*:)*text-((?:base|primary|secondary|accent)(?:-\d{1,3})?)-content\b/';
    if (!preg_match_all($pattern, $twig, $m, PREG_SET_ORDER)) {
      return [];
    }
    $found = [];
    foreach ($m as $hit) {
      $token = $hit[0];
      if (isset($found[$token])) {
        continue;
      }
      $bg = 'bg-' . $hit[1];
      if (preg_match('/\b' . preg_quote($bg, '/') . '\b(?!-)/', $twig)) {
        continue;
      }
      $found[$token] = ['token' => $token, 'background' => $bg];
    }
    return array_values($found);
  }

  /**
   * Extracts the class lists a template builds, as raw strings.
   *
   * @param string $twig
   *   The Twig source.
   *
   * @return string[]
   *   The inner text of each `{% set … = [...] %}`, `addClass([...])` and
   *   `class="…"` found.
   */
  private function classGroups(string $twig): array {
    $twig = $this->stripComments($twig);
    $groups = [];
    $patterns = [
      // {% set classes = ['a', 'b'] %}
      '/\{%-?\s*set\s+[a-zA-Z_][\w]*\s*=\s*\[(.*?)\]/s',
      // addClass(['a', 'b'])
      '/addClass\(\s*\[(.*?)\]/s',
      // class="a b".
      '/\bclass=["\'](.*?)["\']/s',
    ];
    foreach ($patterns as $pattern) {
      if (preg_match_all($pattern, $twig, $m)) {
        $groups = array_merge($groups, $m[1]);
      }
    }
    return $groups;
  }

  /**
   * Removes Twig comments so documentation never trips a check.
   */
  private function stripComments(string $twig): string {
    return (string) preg_replace('/\{#.*?#\}/s', '', $twig);
  }

  /**
   * Collapses whitespace so two conditions compare on their text.
   */
  private function normalize(string $expr): string {
    return trim((string) preg_replace('/\s+/', ' ', $expr));
  }

}
