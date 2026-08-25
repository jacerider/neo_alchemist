<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist\ComponentPreviewBuilder;
use Drupal\neo_alchemist\ComponentPropDefPluginManager;
use Drupal\neo_alchemist\ComponentTwigLinter;
use Drupal\neo_alchemist\Shape\ComponentShapePluginManager;
use Drupal\neo_alchemist\Slot\ComponentSlotTemplateLocator;
use Drupal\neo_alchemist\ThemeComponentInstaller;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Introspection & verification commands for Neo Alchemist components.
 *
 * These give an author (human or AI) the runtime data that cannot be derived
 * by reading theme files: the enabled color schemes, the valid icon names,
 * the resolved prop-def shapes, and — most importantly — a headless render so
 * a component can be verified before it is handed off.
 */
final class NeoAlchemistCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Native JSON Schema types that are always valid as a prop `type`.
   */
  private const NATIVE_TYPES = ['object', 'array', 'string', 'boolean', 'integer', 'number', 'null'];

  public function __construct(
    #[Autowire(service: 'plugin.manager.sdc')]
    private readonly ComponentPluginManager $componentPluginManager,
    #[Autowire(service: 'neo_alchemist.preview_builder')]
    private readonly ComponentPreviewBuilder $previewBuilder,
    #[Autowire(service: 'plugin.manager.neo_component_prop_def')]
    private readonly ComponentPropDefPluginManager $propDefManager,
    #[Autowire(service: 'plugin.manager.neo_component_shape')]
    private readonly ComponentShapePluginManager $shapeManager,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'renderer')]
    private readonly RendererInterface $renderer,
    #[Autowire(service: 'neo_alchemist.slot_template_locator')]
    private readonly ComponentSlotTemplateLocator $slotTemplateLocator,
    #[Autowire(service: 'neo_alchemist.theme_component_installer')]
    private readonly ThemeComponentInstaller $themeComponentInstaller,
    #[Autowire(service: 'neo_alchemist.twig_linter')]
    private readonly ComponentTwigLinter $twigLinter,
  ) {
    parent::__construct();
  }

  /**
   * Copy module components into a theme so the theme owns their markup.
   */
  #[CLI\Command(name: 'neo:alchemist:eject', aliases: ['neoa-eject'])]
  #[CLI\Argument(name: 'component', description: 'Component id to eject, e.g. neo_search:search_quick. Omit to eject every component declaring `neo_install: true`.')]
  #[CLI\Option(name: 'theme', description: 'Theme that receives the copy (defaults to the site default theme).')]
  #[CLI\Option(name: 'force', description: 'Overwrite an existing theme copy. Destructive — the copy is the site\'s to edit.')]
  #[CLI\Usage(name: 'drush neo:alchemist:eject', description: 'Eject every module component that asks to be ejected.')]
  #[CLI\Usage(name: 'drush neo:alchemist:eject neo_search:search_quick --force', description: 'Restore one component from its module source, discarding local edits.')]
  public function eject(?string $component = NULL, array $options = ['theme' => NULL, 'force' => FALSE]): int {
    $theme = $this->themeComponentInstaller->resolveTheme($options['theme'] ?: NULL);
    if (!$theme) {
      $this->io()->error('No installed theme to eject into. Pass --theme=<installed theme>.');
      return self::EXIT_FAILURE;
    }

    try {
      $results = $component
        ? [$component => $this->themeComponentInstaller->install($component, $theme, (bool) $options['force'])]
        : $this->themeComponentInstaller->installAll($theme, (bool) $options['force']);
    }
    catch (\InvalidArgumentException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    if (!$results) {
      $this->io()->warning('No components declare `neo_install: true`.');
      return self::EXIT_SUCCESS;
    }

    $path = \Drupal::service('extension.list.theme')->getPath($theme);
    foreach ($results as $id => $status) {
      match ($status) {
        'installed' => $this->io()->success(sprintf('%s → %s/components/%s', $id, $path, explode(':', $id)[1] ?? $id)),
        'exists' => $this->io()->text(sprintf('%s: "%s" already has a copy — left untouched (--force to overwrite).', $id, $theme)),
        default => $this->io()->error(sprintf('%s: could not be installed. Check the log.', $id)),
      };
    }

    if (in_array('installed', $results, TRUE)) {
      $this->io()->listing([
        'Rebuild assets so the copy\'s Tailwind classes compile: drush neo:build && npm run deploy',
        'The copy is yours — it is never overwritten again without --force.',
      ]);
    }
    return in_array('failed', $results, TRUE) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Render a component headlessly from its examples to verify it works.
   */
  #[CLI\Command(name: 'neo:alchemist:render', aliases: ['neoa-render'])]
  #[CLI\Argument(name: 'component', description: 'The SDC component id, e.g. front:cards_test.')]
  #[CLI\Option(name: 'scheme', description: 'Wrap the render in a color scheme (id from neo:color:schemes).')]
  #[CLI\Option(name: 'html', description: 'Print the rendered HTML to stdout.')]
  #[CLI\Option(name: 'live', description: 'Render the runtime (non-preview) path — neoIsPreview is FALSE. Default renders as the editor preview does.')]
  #[CLI\Usage(name: 'drush neo:alchemist:render front:cards_test', description: 'Verify the component renders without errors.')]
  #[CLI\Usage(name: 'drush neo:alchemist:render front:cards_test --scheme=dark --html', description: 'Render under the "dark" scheme and print the markup.')]
  #[CLI\Usage(name: 'drush neo:alchemist:render front:header --live --html', description: 'Render the runtime markup (as a live page would), not the editor preview.')]
  public function render(string $component, array $options = ['scheme' => NULL, 'html' => FALSE, 'live' => FALSE]): int {
    $preview = empty($options['live']);
    $entity = $this->previewBuilder->build($component, $preview);
    if (!$entity) {
      $this->io()->error(sprintf('"%s" is not a renderable Neo component. It must exist and declare `neo: true`. Run `drush neo:alchemist:components` to list valid ids.', $component));
      return self::EXIT_FAILURE;
    }

    // Resolve the optional scheme wrapper up front.
    $scheme = $options['scheme'] ?? NULL;
    if ($scheme && !$this->schemeSelector($scheme)) {
      $this->io()->warning(sprintf('Scheme "%s" not found; rendering without a scheme. Run `drush neo:color:schemes`.', $scheme));
      $scheme = NULL;
    }

    // Render the component subtree inside a render context. renderBarePage is
    // deliberately avoided: it invokes page-level attachment hooks that assume
    // an HTTP request/route, which do not exist under Drush. This renders the
    // component itself — enough to surface Twig/render errors.
    try {
      $html = (string) $this->renderer->executeInRenderContext(new RenderContext(), function () use ($entity, $scheme) {
        $build = $entity->toRenderable();
        if ($scheme) {
          $build = [
            '#type' => 'container',
            '#attributes' => ['class' => [$this->schemeSelector($scheme)]],
            'child' => $build,
          ];
        }
        return $this->renderer->render($build);
      });
    }
    catch (\Throwable $e) {
      $this->io()->error('Render FAILED: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }

    if (!empty($options['html'])) {
      $this->output()->writeln($html);
    }

    $this->io()->success(sprintf('Rendered %s [%s] (%d bytes).', $component, $preview ? 'preview' : 'live', strlen($html)));
    return self::EXIT_SUCCESS;
  }

  /**
   * Statically lint a component's .component.yml and .twig for common mistakes.
   */
  #[CLI\Command(name: 'neo:alchemist:validate', aliases: ['neoa-validate'])]
  #[CLI\Argument(name: 'component', description: 'The SDC component id, e.g. front:cards_test. Omit with --all.')]
  #[CLI\Option(name: 'all', description: 'Lint every Neo component instead of one. A site-wide sweep in a single bootstrap.')]
  #[CLI\Option(name: 'theme', description: 'With --all, only components provided by this theme/module machine name.')]
  #[CLI\Usage(name: 'drush neo:alchemist:validate front:cards_test', description: 'Lint the component and report problems.')]
  #[CLI\Usage(name: 'drush neo:alchemist:validate --all', description: 'Sweep every component; exits non-zero if any has an error.')]
  #[CLI\Usage(name: 'drush neo:alchemist:validate --all --theme=front', description: 'Sweep just the front theme\'s components.')]
  public function validate(?string $component = NULL, array $options = ['all' => FALSE, 'theme' => NULL]): int {
    if (!empty($options['all'])) {
      return $this->validateAll($options['theme'] ?: NULL);
    }
    if ($component === NULL) {
      $this->io()->error('Pass a component id, or --all to sweep every component.');
      return self::EXIT_FAILURE;
    }
    if (!$this->componentPluginManager->hasDefinition($component)) {
      $this->io()->error(sprintf('Unknown component "%s". Run `drush neo:alchemist:components`.', $component));
      return self::EXIT_FAILURE;
    }
    [$errors, $warnings, $oks] = $this->lintComponent($component);

    return $this->report($component, $errors, $warnings, $oks);
  }

  /**
   * Lints every Neo component and prints one line each.
   *
   * A sweep in a single bootstrap: on a site with three dozen components,
   * calling the single-component form once each costs a Drupal bootstrap per
   * component and several minutes of wall clock.
   *
   * @param string|null $theme
   *   Restrict to one provider, or NULL for every component.
   *
   * @return int
   *   Non-zero when any component has a hard error, so it gates a deploy.
   */
  private function validateAll(?string $theme): int {
    $ids = [];
    foreach ($this->componentPluginManager->getDefinitions() as $id => $def) {
      // Source templates (`neo: false` + `neo_install: true`) are the module's
      // copy, linted through the theme copy that ejecting produces.
      if (empty($def['neo'])) {
        continue;
      }
      if ($theme && ($def['provider'] ?? NULL) !== $theme) {
        continue;
      }
      $ids[] = $id;
    }
    sort($ids);
    if (!$ids) {
      $this->io()->warning($theme
        ? sprintf('No Neo components provided by "%s".', $theme)
        : 'No Neo components found.');
      return self::EXIT_SUCCESS;
    }

    $totalErrors = 0;
    $totalWarnings = 0;
    $clean = 0;
    foreach ($ids as $id) {
      [$errors, $warnings] = $this->lintComponent($id);
      $totalErrors += count($errors);
      $totalWarnings += count($warnings);
      if (!$errors && !$warnings) {
        $clean++;
        $this->io()->writeln(sprintf('  <info>✓</info> %s', $id));
        continue;
      }
      $this->io()->writeln(sprintf(
        '  %s <options=bold>%s</> — %d error(s), %d warning(s)',
        $errors ? '<error>✗</error>' : '<comment>⚠</comment>',
        $id,
        count($errors),
        count($warnings),
      ));
      foreach ($errors as $line) {
        $this->io()->writeln('      <error>✗</error> ' . $line);
      }
      foreach ($warnings as $line) {
        $this->io()->writeln('      <comment>⚠</comment> ' . $line);
      }
    }
    $this->io()->newLine();

    $summary = sprintf(
      '%d component(s): %d clean, %d error(s), %d warning(s).',
      count($ids), $clean, $totalErrors, $totalWarnings,
    );
    if ($totalErrors) {
      $this->io()->error($summary);
      return self::EXIT_FAILURE;
    }
    $this->io()->success($summary);
    return self::EXIT_SUCCESS;
  }

  /**
   * Collects the lint result for one component.
   *
   * @param string $component
   *   The SDC component id. Must exist.
   *
   * @return array
   *   A [errors, warnings, oks] tuple of message strings.
   */
  private function lintComponent(string $component): array {
    $def = $this->componentPluginManager->getDefinition($component);
    $errors = [];
    $warnings = [];
    $oks = [];

    // Required Alchemist / SDC metadata. A source template is the deliberate
    // exception: `neo: false` keeps the module's copy out of the picker, and
    // the copy installed into the theme has the flag flipped on. Reporting
    // that as an error would invite someone to "fix" it and put a duplicate
    // in the picker.
    if (!empty($def['neo_install']) && empty($def['neo'])) {
      $oks[] = '`neo_install: true` source template — the module copy stays out of the picker; the theme copy gets `neo: true`.';
    }
    elseif (empty($def['neo'])) {
      $errors[] = 'Missing `neo: true` — the component will not appear in Alchemist.';
    }
    else {
      $oks[] = '`neo: true` present.';
      if (!empty($def['neo_install'])) {
        $warnings[] = '`neo_install: true` with `neo: true` — the module copy and its theme copy will both appear in the picker. Source templates should declare `neo: false`.';
      }
    }
    if (empty($def['name'])) {
      $errors[] = 'Missing `name`.';
    }
    $status = $def['status'] ?? NULL;
    if (!$status) {
      $warnings[] = 'No `status` set (expected one of: stable, beta, experimental, deprecated).';
    }
    elseif (!in_array($status, ['stable', 'beta', 'experimental', 'deprecated', 'obsolete'], TRUE)) {
      $warnings[] = sprintf('Unusual `status: %s`.', $status);
    }

    // Read the raw yml/twig from disk for checks the compiled definition drops.
    $rawYml = $this->parseComponentYml($def);
    if (is_array($rawYml) && empty($rawYml['$schema'])) {
      $warnings[] = 'Missing `$schema` line at the top of the .component.yml.';
    }
    $twig = NULL;
    $dir = $def['path'] ?? NULL;
    $machine = $def['machineName'] ?? NULL;
    if ($dir && $machine) {
      $twigPath = $dir . '/' . ($def['template'] ?? ($machine . '.twig'));
      if (is_file($twigPath)) {
        $twig = (string) file_get_contents($twigPath);
      }
    }

    // Per-prop checks: valid type + examples present.
    $props = $def['props']['properties'] ?? [];
    $declared = [];
    foreach ($props as $name => $prop) {
      if ($name === 'attributes') {
        continue;
      }
      $declared[] = $name;
      $types = (array) ($prop['type'] ?? []);
      $unknown = array_filter($types, fn($t) => !$this->isKnownType($t));
      if ($types && $unknown) {
        $errors[] = sprintf('Prop `%s` uses unknown type(s): %s. Run `drush neo:alchemist:shapes`.', $name, implode(', ', $unknown));
      }
      if (!array_key_exists('examples', $prop) || $prop['examples'] === [] || $prop['examples'] === NULL) {
        $warnings[] = sprintf('Prop `%s` has no `examples` — the editor preview and defaults will be empty.', $name);
      }
    }
    if ($declared) {
      $oks[] = sprintf('%d prop(s) declared with known types.', count($declared));
    }

    // Views-context prop ordering. The views_exposed_filter,
    // views_active_filters and views_summary providers read the `views`
    // context, which the views value provider registers while its own prop
    // resolves — and props resolve in schema order. Such a prop declared
    // before every prop that could carry the views binding silently renders
    // empty, so catch the layout statically.
    $rawProps = is_array($rawYml) ? ($rawYml['props']['properties'] ?? []) : [];
    $seenBindable = FALSE;
    foreach ($rawProps as $propName => $prop) {
      $type = $prop['type'] ?? NULL;
      if (in_array($type, ['views_filter', 'views_active_filters', 'views_summary'], TRUE)) {
        if (!$seenBindable) {
          $warnings[] = sprintf('Prop `%s` (%s) is declared before any array/object prop that could hold the views binding. Move it after the views-bound prop, or its provider will find no view.', $propName, $type);
        }
      }
      elseif (in_array($type, ['array', 'object'], TRUE)) {
        $seenBindable = TRUE;
      }
    }

    // Slot templates. A slots/*.twig whose name matches no declared slot is
    // never loaded and never errors — it just silently does nothing, which is
    // the single easiest way to lose an afternoon here.
    $slotDir = $dir ? $dir . '/' . ComponentSlotTemplateLocator::DIRECTORY : NULL;
    if ($slotDir && is_dir($slotDir)) {
      $declaredSlots = array_keys($def['slots'] ?? []);
      foreach ((array) glob($slotDir . '/*.twig') as $file) {
        $name = basename($file, '.twig');
        // Skip .html.twig overrides: those are theme-registry suggestion
        // templates for an item's internals, not slot layout templates.
        if (str_ends_with($name, '.html')) {
          continue;
        }
        if (!in_array($name, $declaredSlots, TRUE)) {
          $warnings[] = sprintf('`%s/%s.twig` matches no declared slot (declared: %s). It will never be loaded.', ComponentSlotTemplateLocator::DIRECTORY, $name, $declaredSlots ? implode(', ', $declaredSlots) : 'none');
        }
        else {
          $oks[] = sprintf('Slot template `%s/%s.twig` matches a declared slot.', ComponentSlotTemplateLocator::DIRECTORY, $name);
        }
      }
    }

    // Twig checks (best effort, advisory). They live in a service because
    // they are regexes over Twig source — subtle enough that the only way to
    // keep them honest is a unit test able to call them directly.
    if ($twig !== NULL) {
      $findings = $this->twigLinter->lint(
        $twig,
        is_array($rawYml) ? $rawYml : NULL,
        $declared,
        array_keys($def['slots'] ?? []),
      );
      foreach ($findings as $finding) {
        $warnings[] = $finding['message'];
      }
    }

    return [$errors, $warnings, $oks];
  }

  /**
   * Prints one component's full lint result.
   *
   * @param string $component
   *   The component id.
   * @param string[] $errors
   *   Hard errors.
   * @param string[] $warnings
   *   Advisory findings.
   * @param string[] $oks
   *   Checks that passed.
   *
   * @return int
   *   The command exit code.
   */
  private function report(string $component, array $errors, array $warnings, array $oks): int {
    foreach ($oks as $line) {
      $this->io()->writeln('  <info>✓</info> ' . $line);
    }
    foreach ($warnings as $line) {
      $this->io()->writeln('  <comment>⚠</comment> ' . $line);
    }
    foreach ($errors as $line) {
      $this->io()->writeln('  <error>✗</error> ' . $line);
    }
    $this->io()->newLine();

    if ($errors) {
      $this->io()->error(sprintf('%s: %d error(s), %d warning(s).', $component, count($errors), count($warnings)));
      return self::EXIT_FAILURE;
    }
    $this->io()->success(sprintf('%s valid (%d warning(s)).', $component, count($warnings)));
    return self::EXIT_SUCCESS;
  }

  /**
   * Show what a component's slots contain and how to theme them.
   */
  #[CLI\Command(name: 'neo:alchemist:slot', aliases: ['neoa-slot'])]
  #[CLI\Argument(name: 'component', description: 'The neo_component entity id, e.g. list_insight.')]
  #[CLI\Argument(name: 'slot', description: 'Optional slot machine name to limit output to.')]
  #[CLI\Usage(name: 'drush neo:alchemist:slot list_insight', description: 'List every slot, its items and the templates that would theme them.')]
  #[CLI\Usage(name: 'drush neo:alchemist:slot list_insight header', description: 'Limit output to the header slot.')]
  public function slot(string $component, ?string $slot = NULL): int {
    $storage = $this->entityTypeManager->getStorage('neo_component');
    /** @var \Drupal\neo_alchemist\ComponentInterface|null $entity */
    $entity = $storage->load($component);
    if (!$entity) {
      $this->io()->error(sprintf('Unknown component "%s". Run `drush neo:alchemist:components` for SDC ids, or list saved components with `drush config:status`.', $component));
      return self::EXIT_FAILURE;
    }

    $slots = $entity->getSlots();
    if ($slot !== NULL) {
      $slots = array_intersect_key($slots, [$slot => TRUE]);
      if (!$slots) {
        $this->io()->error(sprintf('"%s" declares no slot named "%s".', $component, $slot));
        return self::EXIT_FAILURE;
      }
    }
    if (!$slots) {
      $this->io()->warning(sprintf('"%s" declares no slots.', $component));
      return self::EXIT_SUCCESS;
    }

    $componentId = $entity->getComponentId();
    $directory = $this->slotTemplateLocator->getDirectory($componentId);

    $this->io()->newLine();
    $this->io()->writeln(sprintf('<info>%s</info> (%s)', $component, $componentId));
    if ($directory) {
      $this->io()->writeln(sprintf('  <comment>%s</comment>', $directory));
    }

    // Building a slot item runs its plugin, which for the Views slots executes
    // the view. Do that inside a render context so any bubbled cacheability has
    // somewhere to go instead of tripping the leaked-metadata assertion.
    $this->renderer->executeInRenderContext(new RenderContext(), function () use ($slots, $componentId) {
      foreach ($slots as $slotName => $componentSlot) {
        $this->io()->newLine();
        $template = $this->slotTemplateLocator->getTemplate($componentId, $slotName);
        $this->io()->writeln(sprintf('<info>SLOT %s</info> — %s', $slotName, $componentSlot->getTitle()));
        $this->io()->writeln(sprintf('  layout template: %s/%s.twig %s',
          ComponentSlotTemplateLocator::DIRECTORY,
          $slotName,
          $template ? '<info>[found]</info>' : '<comment>[not found]</comment>'
        ));

        $plugins = $componentSlot->getPlugins();
        if (!$plugins) {
          $this->io()->writeln('  <comment>(empty)</comment>');
          continue;
        }
        foreach (array_keys($plugins) as $uuid) {
          $info = $componentSlot->getItemInfo($uuid);
          if (!$info) {
            $this->io()->writeln(sprintf('  <comment>%s renders nothing — check its settings.</comment>', $componentSlot->getKeys()[$uuid] ?? $uuid));
            continue;
          }
          $this->io()->newLine();
          $this->io()->writeln(sprintf('  <info>%s</info>  (%s)', $info['key'], $info['plugin_id']));
          $this->io()->writeln(sprintf('    address in %s/%s.twig as: {{ %s }}', ComponentSlotTemplateLocator::DIRECTORY, $slotName, $info['key']));
          if (!$info['hook']) {
            $this->io()->writeln('    <comment>no #theme hook — its internals cannot be overridden</comment>');
            continue;
          }
          $this->io()->writeln(sprintf('    theme hook: %s', $info['hook']));
          $this->io()->writeln(sprintf('    override with: %s', $info['template']));
          if ($info['render_element']) {
            $this->io()->writeln(sprintf('    variables: {{ %s }} (render element)', $info['render_element']));
          }
          elseif ($info['variables']) {
            $this->io()->writeln(sprintf('    variables: %s', implode(', ', array_map(
              fn($v) => '{{ ' . $v . ' }}',
              $info['variables']
            ))));
          }
          if ($info['children']) {
            $this->io()->writeln(sprintf('    sub-elements: %s', implode(', ', $info['children'])));
          }
        }
      }
    });

    $this->io()->newLine();
    $this->io()->success('Create any template above inside the component directory, then run `drush cr`.');
    return self::EXIT_SUCCESS;
  }

  /**
   * List the prop-def shapes, or dump one shape's schema + snippets.
   */
  #[CLI\Command(name: 'neo:alchemist:shapes', aliases: ['neoa-shapes'])]
  #[CLI\Argument(name: 'name', description: 'Optional shape name to dump in full (e.g. heading).')]
  #[CLI\FieldLabels(labels: [
    'name' => 'Name',
    'title' => 'Title',
    'type' => 'Type',
  ])]
  #[CLI\DefaultFields(fields: ['name', 'title', 'type'])]
  #[CLI\Usage(name: 'drush neo:alchemist:shapes', description: 'List every available prop-def shape.')]
  #[CLI\Usage(name: 'drush neo:alchemist:shapes heading', description: 'Show the heading shape schema, a yml snippet, and its Twig pattern.')]
  public function shapes(?string $name = NULL, array $options = ['format' => 'table']): ?RowsOfFields {
    $defs = $this->propDefManager->getDefinitions();
    ksort($defs);

    // Detail view for a single shape.
    if ($name !== NULL) {
      if (!isset($defs[$name])) {
        $this->io()->error(sprintf('Unknown shape "%s". Run `drush neo:alchemist:shapes` to list them.', $name));
        return NULL;
      }
      $this->dumpShape($name, $defs[$name]);
      return NULL;
    }

    // Also fold in bare style/structural shapes that only exist as
    // ComponentShape plugins (e.g. scheme, spacing, text_align) so the list is
    // complete.
    $rows = [];
    foreach ($defs as $id => $def) {
      $type = $def['type'] ?? '';
      $rows[$id] = [
        'name' => $id,
        'title' => (string) ($def['title'] ?? ''),
        'type' => is_array($type) ? implode('|', $type) : (string) $type,
      ];
    }
    foreach ($this->shapeManager->getDefinitions() as $id => $def) {
      $prop = $def['prop'] ?? $id;
      if (!isset($rows[$prop])) {
        $rows[$prop] = [
          'name' => $prop,
          'title' => (string) ($def['label'] ?? $prop),
          'type' => $prop,
        ];
      }
    }
    ksort($rows);
    return new RowsOfFields($rows);
  }

  /**
   * List local Neo components (SDC with neo: true or neo_install: true).
   */
  #[CLI\Command(name: 'neo:alchemist:components', aliases: ['neoa-components'])]
  #[CLI\Option(name: 'theme', description: 'Only components provided by this theme/module machine name.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'name' => 'Name',
    'status' => 'Status',
    'provider' => 'Provider',
    'install' => 'Ejects',
    'props' => 'Props',
    'slots' => 'Slots',
  ])]
  #[CLI\DefaultFields(fields: ['id', 'name', 'status', 'provider', 'install', 'props', 'slots'])]
  #[CLI\Usage(name: 'drush neo:alchemist:components', description: 'List every Neo component and check which machine names are taken.')]
  public function components(array $options = ['theme' => NULL, 'format' => 'table']): RowsOfFields {
    $rows = [];
    $theme = $options['theme'] ?: NULL;
    foreach ($this->componentPluginManager->getDefinitions() as $id => $def) {
      // Source templates carry `neo: false` so they stay out of the picker,
      // but a machine-name check has to see them — that is what this listing
      // is for.
      if (empty($def['neo']) && empty($def['neo_install'])) {
        continue;
      }
      $provider = $def['provider'] ?? '';
      if ($theme && $provider !== $theme) {
        continue;
      }
      $rows[$id] = [
        'id' => $id,
        'name' => (string) ($def['name'] ?? ''),
        'status' => (string) ($def['status'] ?? ''),
        'provider' => $provider,
        'install' => !empty($def['neo_install']) ? 'yes' : '',
        'props' => (string) count(array_diff_key($def['props']['properties'] ?? [], ['attributes' => TRUE])),
        'slots' => (string) count($def['slots'] ?? []),
      ];
    }
    ksort($rows);
    if (!$rows) {
      $this->io()->warning('No Neo components found. Did you clear cache after adding one? (`drush cr`)');
    }
    return new RowsOfFields($rows);
  }

  /**
   * Dump one component's resolved props, slots, libraries and status.
   */
  #[CLI\Command(name: 'neo:alchemist:info', aliases: ['neoa-info'])]
  #[CLI\Argument(name: 'component', description: 'The SDC component id, e.g. front:cards_test.')]
  #[CLI\Usage(name: 'drush neo:alchemist:info front:cards_test', description: 'Show the resolved definition of a component.')]
  public function info(string $component, array $options = ['format' => 'yaml']): ?array {
    if (!$this->componentPluginManager->hasDefinition($component)) {
      $this->io()->error(sprintf('Unknown component "%s". Run `drush neo:alchemist:components`.', $component));
      return NULL;
    }
    $def = $this->componentPluginManager->getDefinition($component);
    // Prefer the authored .component.yml prop types (e.g. `spacing`, `scheme`,
    // `heading`) over the SDC-normalized base types (`string`, `object`).
    $rawProps = ($this->parseComponentYml($def)['props']['properties'] ?? []);

    $props = [];
    foreach (($def['props']['properties'] ?? []) as $pName => $prop) {
      if ($pName === 'attributes') {
        continue;
      }
      $raw = $rawProps[$pName] ?? [];
      $type = $raw['type'] ?? ($prop['type'] ?? '');
      $title = $raw['title'] ?? ($prop['title'] ?? NULL);
      $props[$pName] = [
        'type' => is_array($type) ? implode('|', $type) : (string) $type,
        'title' => $title !== NULL ? (string) $title : NULL,
        'required' => in_array($pName, $def['props']['required'] ?? [], TRUE),
        'examples' => $raw['examples'] ?? ($prop['examples'] ?? NULL),
      ];
    }

    return [
      'id' => $component,
      'name' => $def['name'] ?? NULL,
      'status' => $def['status'] ?? NULL,
      'provider' => $def['provider'] ?? NULL,
      'neo' => !empty($def['neo']),
      'libraries' => $def['libraryOverrides']['dependencies'] ?? ($def['library']['dependencies'] ?? []),
      'slots' => array_keys($def['slots'] ?? []),
      'props' => $props,
    ];
  }

  /**
   * Prints a single shape's schema, a yml snippet and its Twig pattern.
   */
  private function dumpShape(string $name, array $def): void {
    $this->io()->title('Shape: ' . $name);

    $type = $def['type'] ?? $name;
    $this->io()->writeln('<comment>Type:</comment> ' . (is_array($type) ? implode('|', $type) : (string) $type));
    if (!empty($def['title'])) {
      $this->io()->writeln('<comment>Title:</comment> ' . $def['title']);
    }
    if (!empty($def['description'])) {
      $this->io()->writeln('<comment>Description:</comment> ' . $def['description']);
    }

    // A paste-ready .component.yml prop snippet.
    $snippet = [
      'my_' . $name => array_filter([
        'type' => is_array($type) ? $type : $name,
        'title' => isset($def['title']) ? (string) $def['title'] : ucfirst($name),
        'examples' => $def['examples'] ?? NULL,
      ], fn($v) => $v !== NULL && $v !== ''),
    ];
    $this->io()->section('.component.yml');
    $this->io()->writeln(Yaml::dump($snippet, 6, 2));

    // The Twig render pattern (with %name% substituted).
    if (!empty($def['twig'])) {
      $twig = $def['twig'];
      $lines = [];
      if (!empty($twig['prefix'])) {
        $lines[] = $twig['prefix'];
      }
      foreach ((array) ($twig['content'] ?? []) as $line) {
        $lines[] = $line;
      }
      if (!empty($twig['suffix'])) {
        $lines[] = $twig['suffix'];
      }
      $rendered = str_replace('%name%', 'my_' . $name, implode("\n", $lines));
      $this->io()->section('Twig');
      $this->io()->writeln($rendered);
    }

    // Nested object properties, if any.
    if (!empty($def['properties'])) {
      $this->io()->section('Properties');
      foreach ($def['properties'] as $pName => $prop) {
        $pType = $prop['type'] ?? '';
        $this->io()->writeln(sprintf('  <info>%s</info>: %s', $pName, is_array($pType) ? implode('|', $pType) : (string) $pType));
      }
    }
  }

  /**
   * Parses a component's raw .component.yml from disk, or NULL.
   *
   * @param array $def
   *   The compiled SDC definition (used for `path` and `machineName`).
   *
   * @return array|null
   *   The parsed YAML, or NULL if it could not be read.
   */
  private function parseComponentYml(array $def): ?array {
    $dir = $def['path'] ?? NULL;
    $machine = $def['machineName'] ?? NULL;
    if (!$dir || !$machine) {
      return NULL;
    }
    $path = $dir . '/' . $machine . '.component.yml';
    if (!is_file($path)) {
      return NULL;
    }
    try {
      return Yaml::parseFile($path);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Resolves a scheme id to its CSS selector class, or NULL.
   */
  private function schemeSelector(string $id): ?string {
    try {
      $scheme = $this->entityTypeManager->getStorage('neo_scheme')->load($id);
    }
    catch (\Throwable $e) {
      return NULL;
    }
    return $scheme && method_exists($scheme, 'getSelector') ? $scheme->getSelector() : NULL;
  }

  /**
   * Whether a prop `type` string resolves to a known type or shape.
   */
  private function isKnownType(string $type): bool {
    return in_array($type, self::NATIVE_TYPES, TRUE)
      || $this->propDefManager->hasDefinition($type)
      || $this->shapeManager->hasDefinition($type);
  }

}
