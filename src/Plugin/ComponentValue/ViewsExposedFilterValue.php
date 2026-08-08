<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Fills a views_filter prop from one of a bound view's exposed filters.
 *
 * The counterpart of the views_exposed_filters SLOT plugin for themes that
 * want to design the filter themselves. Where the slot hands Twig Drupal's
 * rendered form, this hands it pure data — label, current state, and an
 * options tree whose every entry carries a ready-made URL — because an
 * exposed filter is ultimately just a GET parameter, and a designed link (or
 * a hand-written GET form of checkboxes) is as valid a submission surface as
 * the form Views renders.
 *
 * Reads the same `views` prop-shape context the Views slot plugins use, which
 * the views value provider registers while executing its view. That execution
 * happens as the views-bound prop's VALUE renders — not at shape init — so
 * this provider resolves in modifyValue(), the pipeline stage that runs
 * during each prop's render-value build. Props build in schema order, so a
 * views_filter prop must be declared AFTER the views-bound prop; when the
 * context is missing, the prop renders empty on a live page and keeps its
 * example scaffolding in the editor preview.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentSlot\ViewsExposedFiltersSlot
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue
 */
#[ComponentValue(
  id: 'views_exposed_filter',
  label: new TranslatableMarkup('Views | Exposed Filter'),
  description: new TranslatableMarkup('Provide one exposed views filter as data for a designed filter UI.'),
  group: 'providers',
  ref_types: [
    'views_filter',
  ],
)]
final class ViewsExposedFilterValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
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
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'context' => '',
      'filter' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::configurationForm($form, $form_state, $complete_form);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    if ($options = $this->getContextOptions()) {
      $form['context'] = [
        '#type' => 'select',
        '#title' => $this->t('Views Context'),
        '#description' => $this->t('The context key provided by a value plugin that contains the views object.'),
        '#options' => $options,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $this->configuration['context'],
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $wrapperId,
        ],
      ];
    }

    $context = $form_state->getValue([...$form['#parents'], 'context'], $this->configuration['context']);
    if ($context && ($filters = $this->getExposedFilterOptions($context))) {
      $form['filter'] = [
        '#type' => 'select',
        '#title' => $this->t('Exposed filter'),
        '#description' => $this->t('The exposed filter to provide as data, by its query identifier.'),
        '#options' => $filters,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $this->configuration['filter'],
        '#required' => TRUE,
      ];
    }

    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#array_parents'], 0, -1);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Get the available options for the views context.
   *
   * At form time only the context KEYS exist — the executed view value is
   * registered at render — which is all the select needs.
   *
   * @return array
   *   Context shape titles keyed by context id.
   */
  protected function getContextOptions(): array {
    $options = [];
    if ($viewsContexts = $this->shape->getComponent()->getPropShapeContexts('views')) {
      foreach ($viewsContexts as $context => $contextInfo) {
        $options[$context] = $contextInfo['shape']->getTitle();
      }
    }
    return $options;
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
   * {@inheritdoc}
   *
   * Resolution happens here — the modify stage — and not in
   * provideDefaultValue(), deliberately. Defaults resolve at shape init,
   * inside loadPropShapes(), where two things go wrong: the views value
   * provider has not executed its view yet (it does so while its own prop's
   * render value builds, later), so the context is always empty there; and
   * anything that forces a shape build from init recurses fatally. The modify
   * stage runs during each prop's render-value build, in schema order — by
   * the time a views_filter prop (declared after the views-bound prop)
   * reaches it, the view is executed and the context registered.
   */
  public function modifyValue(mixed $value): mixed {
    $resolved = $this->resolveFilter();
    if ($resolved !== NULL) {
      return $resolved;
    }
    // Unresolvable: no binding, no view, no such filter. The threaded $value
    // is the schema-example scaffolding — keep it for the editor preview,
    // never for a visitor.
    return $this->shape->getComponent()->isPreview() ? $value : [];
  }

  /**
   * Resolves the configured exposed filter into the views_filter value.
   *
   * @return array|null
   *   The value, or NULL when the filter cannot be resolved.
   */
  protected function resolveFilter(): ?array {
    $context = $this->configuration['context'];
    $identifier = $this->configuration['filter'];
    if (!$context || !$identifier) {
      return NULL;
    }
    // $build MUST stay FALSE: forcing a shape build from inside the value
    // pipeline re-runs every shape's init — including the instance currently
    // executing — and recurses until memory runs out. Non-forcing is also
    // sufficient here (see modifyValue()).
    $viewsContexts = $this->shape->getComponent()->getPropShapeContexts('views', FALSE);
    $view = $viewsContexts[$context]['value'] ?? NULL;
    if (!$view instanceof ViewExecutable) {
      return NULL;
    }

    $handler = $this->getExposedFilterHandler($view, $identifier);
    $flat = $this->getFlatOptions($view, $identifier, $handler);
    if (!$flat) {
      return NULL;
    }

    // The rendered options depend on the query string in both directions:
    // which option is active, and the URL every option links to.
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['url.query_args']);
    $this->shape->addCacheableDependency($cacheability);

    $multiple = !empty($handler?->options['expose']['multiple']);
    $current = $this->getCurrentValues($view, $identifier);

    // URL building. Everything is derived from the raw request rather than
    // the route system so a headless render (drush, kernel test) degrades to
    // NULL urls instead of throwing; the examples cover previews anyway.
    $request = $this->requestStack->getCurrentRequest();
    $basePath = $request ? $request->getBaseUrl() . $request->getPathInfo() : NULL;
    $query = $request ? $request->query->all() : [];
    // A changed filter always resets paging.
    unset($query['page']);
    $buildUrl = function (array $q) use ($basePath): ?string {
      if ($basePath === NULL) {
        return NULL;
      }
      $qs = http_build_query($q);
      return $basePath . ($qs !== '' ? '?' . $qs : '');
    };

    $activeLabels = [];
    foreach ($flat as $i => $item) {
      $active = in_array($item['value'], $current, TRUE);
      if ($active) {
        $activeLabels[] = $item['label'];
      }
      $optionQuery = $query;
      if ($multiple) {
        // Toggle membership.
        $values = $active
          ? array_values(array_diff($current, [$item['value']]))
          : array_merge($current, [$item['value']]);
        if ($values) {
          $optionQuery[$identifier] = $values;
        }
        else {
          unset($optionQuery[$identifier]);
        }
      }
      else {
        $optionQuery[$identifier] = $item['value'];
      }
      $flat[$i]['active'] = $active;
      $flat[$i]['url'] = $buildUrl($optionQuery);
    }

    $resetQuery = $query;
    unset($resetQuery[$identifier]);

    // Hidden inputs a hand-written GET form needs so submitting this filter
    // preserves every other query arg (search text, sibling filters, ...).
    $carry = [];
    foreach ($resetQuery as $name => $carryValue) {
      if (is_array($carryValue)) {
        foreach ($carryValue as $one) {
          if (is_scalar($one)) {
            $carry[] = ['name' => $name . '[]', 'value' => (string) $one];
          }
        }
      }
      elseif (is_scalar($carryValue)) {
        $carry[] = ['name' => $name, 'value' => (string) $carryValue];
      }
    }

    return [
      'label' => (string) ($handler?->options['expose']['label'] ?? $identifier),
      'param' => $identifier,
      'multiple' => $multiple,
      'active' => (bool) $current,
      'active_count' => count($current),
      'active_labels' => $activeLabels,
      'value' => $current,
      'reset_url' => $buildUrl($resetQuery),
      'action' => $basePath,
      'carry' => $carry,
      'options' => $this->nestOptions($flat),
    ];
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
   *   Items of {label, value, depth}, tree order.
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
   * Nests a depth-annotated flat list into the below[] tree.
   *
   * @param array $flat
   *   Items of {label, value, depth, url, active}, tree order.
   *
   * @return array
   *   Nested options; each item carries label, value, url, active, below.
   */
  protected function nestOptions(array $flat): array {
    $byParent = [];
    $lastAtDepth = [];
    foreach ($flat as $i => $item) {
      $depth = $item['depth'];
      // A depth gap (parent filtered out of the options) attaches to the
      // nearest surviving ancestor rather than being dropped.
      while ($depth > 0 && !isset($lastAtDepth[$depth - 1])) {
        $depth--;
      }
      $parent = $depth > 0 ? $lastAtDepth[$depth - 1] : -1;
      $byParent[$parent][] = $i;
      $lastAtDepth[$depth] = $i;
      foreach (array_keys($lastAtDepth) as $d) {
        if ($d > $depth) {
          unset($lastAtDepth[$d]);
        }
      }
    }
    $build = function (int $parent) use (&$build, $byParent, $flat): array {
      $out = [];
      foreach ($byParent[$parent] ?? [] as $i) {
        $out[] = [
          'label' => $flat[$i]['label'],
          'value' => $flat[$i]['value'],
          'url' => $flat[$i]['url'],
          'active' => $flat[$i]['active'],
          'below' => $build($i),
        ];
      }
      return $out;
    };
    return $build(-1);
  }

  /**
   * Normalizes the filter's current exposed input to a list of strings.
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
