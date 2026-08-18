<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Drush\Commands;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Drupal\pathauto\PathautoState;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Converts views pages into Alchemist-owned node pages.
 *
 * The suite's convention for a site-builder-editable page is a node whose
 * whole body is a component tree, with views acting as headless queries that
 * components execute. This command automates handing a views page's path to
 * such a node: the node takes the path via an explicit alias, the views page
 * display is removed (a views route must never be shadowed by an alias), and
 * the remaining displays keep serving components. Exposed input and paging
 * keep working automatically because component-bound views read them from
 * the current page URL.
 */
final class NeoAlchemistViewsPageCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'entity_field.manager')]
    private readonly EntityFieldManagerInterface $entityFieldManager,
    #[Autowire(service: 'router.builder')]
    private readonly RouteBuilderInterface $routerBuilder,
    #[Autowire(service: 'config.factory')]
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'uuid')]
    private readonly UuidInterface $uuid,
    #[Autowire(service: 'module_handler')]
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * Convert a views page display into an Alchemist-owned node page.
   */
  #[CLI\Command(name: 'neo:alchemist:views-page', aliases: ['neoa-views-page'])]
  #[CLI\Argument(name: 'display', description: 'The views page display as <view_id>:<display_id>, e.g. search:page_1.')]
  #[CLI\Option(name: 'bundle', description: 'Node bundle to create — must carry a component tree field (defaults to "system").')]
  #[CLI\Option(name: 'title', description: 'Title for the new node (defaults to the view label).')]
  #[CLI\Option(name: 'alias', description: 'Path alias for the node (defaults to the display path).')]
  #[CLI\Option(name: 'component', description: 'A neo_component id to seed the node tree with (e.g. search_list).')]
  #[CLI\Option(name: 'keep-display', description: 'Do not remove the views page display (testing only — the alias will shadow the views route).')]
  #[CLI\Usage(name: 'drush neo:alchemist:views-page search:page_1 --component=search_list', description: 'Hand /search to a system node seeded with the search_list component.')]
  public function convert(
    string $display,
    array $options = [
      'bundle' => 'system',
      'title' => NULL,
      'alias' => NULL,
      'component' => NULL,
      'keep-display' => FALSE,
    ],
  ): int {
    // -- Validate everything before mutating anything.
    if (!str_contains($display, ':')) {
      $this->io()->error('Expected <view_id>:<display_id>, e.g. search:page_1.');
      return self::EXIT_FAILURE;
    }
    [$viewId, $displayId] = explode(':', $display, 2);

    /** @var \Drupal\views\ViewEntityInterface|null $view */
    $view = $this->entityTypeManager->getStorage('view')->load($viewId);
    if (!$view) {
      $this->io()->error(sprintf('View "%s" does not exist.', $viewId));
      return self::EXIT_FAILURE;
    }
    $displays = $view->get('display');
    if (!isset($displays[$displayId])) {
      $this->io()->error(sprintf('View "%s" has no display "%s". Displays: %s', $viewId, $displayId, implode(', ', array_keys($displays))));
      return self::EXIT_FAILURE;
    }
    if (($displays[$displayId]['display_plugin'] ?? '') !== 'page') {
      $this->io()->error(sprintf('Display "%s" is a "%s" display, not a page.', $displayId, $displays[$displayId]['display_plugin'] ?? ''));
      return self::EXIT_FAILURE;
    }
    $path = $displays[$displayId]['display_options']['path'] ?? '';
    if ($path === '' || str_contains($path, '%')) {
      $this->io()->error(sprintf('Display "%s" has no static path ("%s") — only plain page paths can be converted.', $displayId, $path));
      return self::EXIT_FAILURE;
    }

    $alias = $options['alias'] ?: '/' . ltrim($path, '/');
    if (!str_starts_with($alias, '/')) {
      $alias = '/' . $alias;
    }
    $aliasStorage = $this->entityTypeManager->getStorage('path_alias');
    $existing = $aliasStorage->loadByProperties(['alias' => $alias]);
    if ($existing) {
      $this->io()->error(sprintf('The alias "%s" is already in use (path_alias %s).', $alias, implode(', ', array_keys($existing))));
      return self::EXIT_FAILURE;
    }

    $bundle = (string) $options['bundle'];
    if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
      $this->io()->error(sprintf('Node bundle "%s" does not exist.', $bundle));
      return self::EXIT_FAILURE;
    }
    $fieldName = NULL;
    foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $name => $definition) {
      if ($definition->getType() === 'neo_component_tree') {
        $fieldName = $name;
        break;
      }
    }
    if (!$fieldName) {
      $this->io()->error(sprintf('Bundle "%s" has no component tree field — add a neo_component_tree field or pick another bundle.', $bundle));
      return self::EXIT_FAILURE;
    }

    $component = NULL;
    if (!empty($options['component'])) {
      $component = Component::load($options['component']);
      if (!$component) {
        $this->io()->error(sprintf('Neo component "%s" does not exist.', $options['component']));
        return self::EXIT_FAILURE;
      }
    }

    // -- Mutate: node (+ alias) first, so the path keeps working even if the
    // display removal below were to fail.
    $pathValue = ['alias' => $alias];
    if ($this->moduleHandler->moduleExists('pathauto') && class_exists(PathautoState::class)) {
      $pathValue['pathauto'] = PathautoState::SKIP;
    }
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => $bundle,
      'title' => $options['title'] ?: (string) $view->label() ?: ucfirst($path),
      'status' => 1,
      'uid' => 1,
      'path' => $pathValue,
    ]);
    if ($component) {
      /** @var \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item */
      $item = $node->get($fieldName)->appendItem([]);
      $item->addComponent($this->uuid->generate(), $component->id());
    }
    $node->save();
    $this->io()->success(sprintf('Created %s node %d "%s" at %s.', $bundle, $node->id(), $node->label(), $alias));

    if (empty($options['keep-display'])) {
      unset($displays[$displayId]);
      $view->set('display', $displays);
      $view->save();
      $this->routerBuilder->rebuild();
      $this->io()->success(sprintf('Removed page display "%s" from view "%s" and rebuilt routes.', $displayId, $viewId));
    }
    else {
      $this->io()->warning('Display kept (--keep-display): the node alias now shadows the views route. Remove the display before shipping.');
    }

    // -- Diagnostics for the remaining displays.
    $this->diagnose($view, $viewId, $displayId, $alias);

    // -- Next steps.
    $key = ComponentFieldConfig::getKeyFromFieldname((string) $fieldName);
    $this->io()->section('Next steps');
    $this->io()->listing(array_filter([
      sprintf('Page: %s', $node->toUrl()->setAbsolute()->toString()),
      sprintf('Edit layout: %s', $node->toUrl('alchemist.manage')->setRouteParameter('neo_field', $key)->setAbsolute()->toString()),
      $component
        ? sprintf('The tree is seeded with "%s" — its bound view reads exposed input and ?page from the URL automatically.', $component->id())
        : 'Add a component bound to the remaining view display (exposed input and ?page are read from the URL automatically).',
    ]));

    return self::EXIT_SUCCESS;
  }

  /**
   * Prints warnings about the remaining displays and dependent config.
   *
   * @param \Drupal\views\ViewEntityInterface $view
   *   The view config entity.
   * @param string $viewId
   *   The view id.
   * @param string $removedDisplayId
   *   The removed display id.
   * @param string $alias
   *   The alias the node took over.
   */
  protected function diagnose($view, string $viewId, string $removedDisplayId, string $alias): void {
    $displays = $view->get('display');
    $defaultOptions = $displays['default']['display_options'] ?? [];
    foreach ($displays as $id => $display) {
      $options = ($display['display_options'] ?? []) + $defaultOptions;
      $pager = $options['pager']['type'] ?? NULL;
      if ($pager === 'mini') {
        $this->io()->warning(sprintf('Display "%s" uses the mini pager: no count query runs, so views_summary totals are inexact. Switch the pager to "full" for exact counts.', $id));
      }
      $cache = $options['cache']['type'] ?? NULL;
      if (in_array($cache, ['none', 'search_api_none'], TRUE)) {
        $this->io()->warning(sprintf('Display "%s" uses cache plugin "%s" (max-age 0): components bound to it are uncacheable. Use "tag" / "search_api_tag".', $id, $cache));
      }
    }

    // Neo Search "all results" links that referenced the removed display.
    $reference = $viewId . ':' . $removedDisplayId;
    foreach ($this->configFactory->listAll('neo_settings.variation.') as $name) {
      $settings = $this->configFactory->get($name)->get('settings') ?? [];
      if (($settings['all_results_view'] ?? '') === $reference) {
        $this->io()->warning(sprintf(
          'Quick search config "%s" points its all-results link at the removed display. Fix with:%s  drush config:set %s settings.all_results_type url -y%s  drush config:set %s settings.all_results_url \'%s\' -y',
          $name, PHP_EOL, $name, PHP_EOL, $name,
          $alias . '?s=[query]'
        ));
      }
    }

    // Exposed filters + fields available for binding on the default display.
    $filters = array_filter($defaultOptions['filters'] ?? [], fn (array $f) => !empty($f['exposed']));
    if ($filters) {
      $identifiers = array_map(fn (array $f) => $f['expose']['identifier'] ?? $f['id'], $filters);
      $this->io()->note('Exposed filter identifiers for views_filter bindings: ' . implode(', ', $identifiers));
    }
    $fields = array_keys($defaultOptions['fields'] ?? []);
    if ($fields) {
      $this->io()->note('View fields available as _view:<id> mappings: ' . implode(', ', $fields));
    }
  }

}
