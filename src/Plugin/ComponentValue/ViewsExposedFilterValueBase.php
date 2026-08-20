<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shared machinery for value providers built on a view's exposed filters.
 *
 * Both concrete providers (one filter as designed-UI data; the applied
 * filters as removable chips) need the same three capabilities on top of the
 * views-context reading ViewsContextValueBase provides: listing a context
 * view's exposed filters from CONFIG at form time (no execution), building
 * URLs from the raw request (route-free, so headless renders degrade to NULL
 * urls instead of throwing), and normalizing widget options — including
 * unwrapping the (object) ['option' => [id => label]] entries hierarchical
 * taxonomy selects use, with clean labels and depth from term storage.
 *
 * The context plumbing itself (the context select, getContextView(), the
 * query cache context) lives on ViewsContextValueBase, which is service-free
 * so a views-backed provider needing no services carries no container wiring.
 * This class adds the two services the filter machinery above needs.
 *
 * Everything resolves at the MODIFY stage of the value pipeline, never at
 * default time: defaults run at shape init inside loadPropShapes(), where the
 * views provider has not executed its view yet and where forcing a shape
 * build recurses fatally. Hence every context read here passes $build FALSE.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsContextValueBase
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsExposedFilterValue
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsActiveFiltersValue
 */
abstract class ViewsExposedFilterValueBase extends ViewsContextValueBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
    );
  }

  /**
   * Lists a context view's exposed filters without executing the view.
   *
   * Resolves the view id from the context shape's own views value plugin
   * settings, then reads the view CONFIG entity: display filters merged over
   * the default display's (display inheritance), keeping only exposed ones.
   *
   * @param string $context
   *   The views context key.
   *
   * @return array
   *   Labels keyed by exposed filter identifier.
   */
  protected function getExposedFilterOptions(string $context): array {
    $contextInfo = $this->shape->getComponent()->getPropShapeContexts('views')[$context] ?? NULL;
    if (!$contextInfo) {
      return [];
    }
    $viewId = '';
    $displayId = '';
    foreach ($contextInfo['shape']->getValueCollection()->getActiveInstances() as $instance) {
      if ($instance instanceof ViewsValue) {
        $viewId = $instance->getConfiguration()['view_id'] ?? '';
        $displayId = $instance->getConfiguration()['view_display_id'] ?? '';
        break;
      }
    }
    if (!$viewId) {
      return [];
    }
    /** @var \Drupal\views\ViewEntityInterface|null $view */
    $view = $this->entityTypeManager->getStorage('view')->load($viewId);
    if (!$view) {
      return [];
    }
    $displays = $view->get('display');
    $filters = ($displays['default']['display_options']['filters'] ?? []);
    if ($displayId && $displayId !== 'default') {
      $filters = ($displays[$displayId]['display_options']['filters'] ?? []) + $filters;
    }
    $options = [];
    foreach ($filters as $filter) {
      if (empty($filter['exposed']) || empty($filter['expose']['identifier'])) {
        continue;
      }
      $identifier = $filter['expose']['identifier'];
      $label = $filter['expose']['label'] ?? $identifier;
      $options[$identifier] = $label . ' (' . $identifier . ')';
    }
    return $options;
  }

  /**
   * Returns the current request's path, base-prefixed.
   *
   * @return string|null
   *   The path, or NULL outside a request (headless drush, kernel test).
   */
  protected function getRequestBasePath(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    return $request ? $request->getBaseUrl() . $request->getPathInfo() : NULL;
  }

  /**
   * Returns the current request's query, with paging dropped.
   *
   * @return array
   *   The query args. A changed filter always resets paging.
   */
  protected function getRequestQuery(): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request ? $request->query->all() : [];
    unset($query['page']);
    return $query;
  }

  /**
   * Builds a URL on the current path from a query array.
   *
   * @param array $query
   *   The query args.
   *
   * @return string|null
   *   The URL, or NULL outside a request.
   */
  protected function buildUrl(array $query): ?string {
    $basePath = $this->getRequestBasePath();
    if ($basePath === NULL) {
      return NULL;
    }
    $qs = http_build_query($query);
    return $basePath . ($qs !== '' ? '?' . $qs : '');
  }

  /**
   * Finds the exposed filter handler by its query identifier.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The executed view.
   * @param string $identifier
   *   The exposed filter identifier.
   *
   * @return \Drupal\views\Plugin\views\filter\FilterPluginBase|null
   *   The handler, or NULL.
   */
  protected function getExposedFilterHandler(ViewExecutable $view, string $identifier) {
    foreach ($view->filter ?? [] as $handler) {
      if ($handler->isExposed() && ($handler->options['expose']['identifier'] ?? NULL) === $identifier) {
        return $handler;
      }
    }
    return NULL;
  }

  /**
   * Lists every exposed filter handler, keyed by query identifier.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The executed view.
   *
   * @return \Drupal\views\Plugin\views\filter\FilterPluginBase[]
   *   Exposed handlers keyed by identifier.
   */
  protected function getExposedFilterHandlers(ViewExecutable $view): array {
    $handlers = [];
    foreach ($view->filter ?? [] as $handler) {
      $identifier = $handler->options['expose']['identifier'] ?? NULL;
      if ($identifier && $handler->isExposed()) {
        $handlers[$identifier] = $handler;
      }
    }
    return $handlers;
  }

  /**
   * Builds the flat, ordered option list with depth.
   *
   * Membership and order come from the exposed widget's #options — the
   * post-alter truth, honoring any option limiting — with the handler's value
   * options as fallback for displays that render their exposed form as a
   * block and leave the memo empty. Depth (and clean labels: the widget
   * dash-prefixes hierarchical terms) comes from term storage when the filter
   * is taxonomy-backed.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The executed view.
   * @param string $identifier
   *   The exposed filter identifier.
   * @param object|null $handler
   *   The filter handler, if found.
   *
   * @return array
   *   Items of {label, value, depth}, tree order. Empty for filters with no
   *   options (text filters).
   */
  protected function getFlatOptions(ViewExecutable $view, string $identifier, ?object $handler): array {
    $options = $view->exposed_widgets[$identifier]['#options'] ?? NULL;
    if (!is_array($options) && $handler && method_exists($handler, 'getValueOptions')) {
      $options = $handler->getValueOptions() ?? NULL;
    }
    if (!is_array($options)) {
      return [];
    }
    // Views' "- Any -" entry; the designed markup has reset_url for that.
    unset($options['All']);

    // Depth + clean labels for taxonomy-backed filters.
    $termInfo = [];
    if ($vid = $handler?->options['vid'] ?? NULL) {
      foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree($vid) as $term) {
        $termInfo[(string) $term->tid] = [
          'depth' => (int) $term->depth,
          'label' => (string) $term->name,
        ];
      }
    }

    $flat = [];
    foreach ($options as $optionValue => $label) {
      // A hierarchical taxonomy select does not use a value => label map: core
      // wraps every option as (object) ['option' => [id => '--Label']] and
      // keys the list numerically (TaxonomyIndexTid::valueForm()). Unwrap
      // those; skip anything else that is not printable.
      if (is_object($label) && isset($label->option) && is_array($label->option)) {
        $optionValue = array_key_first($label->option);
        $label = reset($label->option);
      }
      if (is_array($label) || (is_object($label) && !method_exists($label, '__toString'))) {
        continue;
      }
      $optionValue = (string) $optionValue;
      if ($optionValue === 'All') {
        continue;
      }
      $info = $termInfo[$optionValue] ?? NULL;
      $flat[] = [
        'label' => $info['label'] ?? ltrim((string) $label, '-'),
        'value' => $optionValue,
        'depth' => $info['depth'] ?? 0,
      ];
    }
    return $flat;
  }

  /**
   * Normalizes a filter's current exposed input to a list of strings.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The executed view.
   * @param string $identifier
   *   The exposed filter identifier.
   *
   * @return string[]
   *   The current values; empty when the filter is inactive.
   */
  protected function getCurrentValues(ViewExecutable $view, string $identifier): array {
    $input = $view->getExposedInput()[$identifier] ?? NULL;
    if ($input === NULL || $input === '' || $input === 'All') {
      return [];
    }
    $values = [];
    foreach ((array) $input as $one) {
      if (is_scalar($one) && (string) $one !== '' && (string) $one !== 'All') {
        $values[] = (string) $one;
      }
    }
    return array_values(array_unique($values));
  }

}
