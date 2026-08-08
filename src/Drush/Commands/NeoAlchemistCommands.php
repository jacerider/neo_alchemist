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
use Drupal\neo_alchemist\ComponentShapePluginManager;
use Drupal\neo_alchemist\ComponentSlotTemplateLocator;
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

  /**
   * Twig identifiers that are always available and never a prop reference.
   */
  private const TWIG_GLOBALS = ['attributes', 'neoIsPreview', 'loop', '_self', '_context', '_charset'];

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
  ) {
    parent::__construct();
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
  #[CLI\Argument(name: 'component', description: 'The SDC component id, e.g. front:cards_test.')]
  #[CLI\Usage(name: 'drush neo:alchemist:validate front:cards_test', description: 'Lint the component and report problems.')]
  public function validate(string $component): int {
    if (!$this->componentPluginManager->hasDefinition($component)) {
      $this->io()->error(sprintf('Unknown component "%s". Run `drush neo:alchemist:components`.', $component));
      return self::EXIT_FAILURE;
    }
    $def = $this->componentPluginManager->getDefinition($component);

    $errors = [];
    $warnings = [];
    $oks = [];

    // Required Alchemist / SDC metadata.
    if (empty($def['neo'])) {
      $errors[] = 'Missing `neo: true` — the component will not appear in Alchemist.';
    }
    else {
      $oks[] = '`neo: true` present.';
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

    // Views-context prop ordering. The views_exposed_filter and
    // views_active_filters providers read the `views` context, which the views
    // value provider registers while its own prop resolves — and props resolve
    // in schema order. Such a prop declared before every prop that could carry
    // the views binding silently renders empty, so catch the layout
    // statically.
    $rawProps = is_array($rawYml) ? ($rawYml['props']['properties'] ?? []) : [];
    $seenBindable = FALSE;
    foreach ($rawProps as $propName => $prop) {
      $type = $prop['type'] ?? NULL;
      if (in_array($type, ['views_filter', 'views_active_filters'], TRUE)) {
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

    // Twig checks (best effort, advisory).
    if ($twig !== NULL) {
      foreach ($this->undeclaredTwigVars($twig, $declared) as $var) {
        $warnings[] = sprintf('Twig references `%s` in an {%% if/for %%} but no such prop is declared (typo, or an intentional local?).', $var);
      }
      if ($this->hasDynamicClasses($twig)) {
        $warnings[] = 'Twig appears to build Tailwind class names dynamically (e.g. `bg-{{ x }}-500` or `\'text-\' ~ x`). Those never compile — enumerate full class names or use CSS variables.';
      }
    }

    // Report.
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
   * List local Neo components (SDC with neo: true).
   */
  #[CLI\Command(name: 'neo:alchemist:components', aliases: ['neoa-components'])]
  #[CLI\Option(name: 'theme', description: 'Only components provided by this theme/module machine name.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'name' => 'Name',
    'status' => 'Status',
    'provider' => 'Provider',
    'props' => 'Props',
    'slots' => 'Slots',
  ])]
  #[CLI\DefaultFields(fields: ['id', 'name', 'status', 'provider', 'props', 'slots'])]
  #[CLI\Usage(name: 'drush neo:alchemist:components', description: 'List every Neo component and check which machine names are taken.')]
  public function components(array $options = ['theme' => NULL, 'format' => 'table']): RowsOfFields {
    $rows = [];
    $theme = $options['theme'] ?: NULL;
    foreach ($this->componentPluginManager->getDefinitions() as $id => $def) {
      if (empty($def['neo'])) {
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

  /**
   * Best-effort: prop-like identifiers referenced in Twig but not declared.
   */
  private function undeclaredTwigVars(string $twig, array $declared): array {
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

    $safe = array_merge($declared, $local, self::TWIG_GLOBALS);
    $undeclared = array_diff(array_unique($referenced), $safe);
    // Ignore obvious literals.
    return array_values(array_filter($undeclared, fn($v) => !in_array(strtolower($v), ['true', 'false', 'null'], TRUE)));
  }

  /**
   * Detects Tailwind class names assembled dynamically in Twig.
   */
  private function hasDynamicClasses(string $twig): bool {
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

}
