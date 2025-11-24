<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapeInterablePluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\MatcherField;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'views',
  label: new TranslatableMarkup('Views'),
  description: new TranslatableMarkup('Use the results of an entity-based view to provide values from the queried entity fields.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
  provider: 'views',
)]
final class ViewsValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
  use ComponentValueChildrenMatchTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * The view executable.
   *
   * @var \Drupal\views\ViewExecutable|null
   */
  protected ?ViewExecutable $view = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    MatcherField $matcher_field,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->matcherField = $matcher_field;
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
      $container->get('neo_alchemist.matcher_field')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'view_id' => '',
      'view_display_id' => '',
      'view_items_per_page' => $this->shape->getType() === ComponentShapePluginInterface::OBJECT ? 1 : NULL,
      'view_items_offset' => 0,
      'view_arguments' => [],
      'view_arguments_sort' => FALSE,
      'continue' => FALSE,
    ] + $this->childrenMatchDefaultConfiguration();
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $viewId = $this->configuration['view_id'];

    $options = [];
    foreach (Views::getEnabledViews() as $view) {
      $options[$view->id()] = $view->label() . ' (' . $view->id() . ')';
    }
    asort($options);

    $form['view_id'] = [
      '#type' => 'select',
      '#title' => $this->t('View'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $viewId,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($viewId) {
      $view = Views::getView($viewId);
      if (!$view) {
        return $form;
      }
      $viewEntityType = $this->getViewEntityType($view);
      if (!$viewEntityType) {
        $form['markup'] = [
          '#type' => 'markup',
          '#markup' => $this->t('The view does not have a corresponding entity type.'),
        ];
        return $form;
      }
      $viewDisplayId = $this->configuration['view_display_id'];
      $displayOptions = [];
      foreach ($view->storage->get('display') as $display) {
        $displayOptions[$display['id']] = $display['display_title'];
      }

      $form['view_display_id'] = [
        '#type' => 'select',
        '#title' => $this->t('View Display'),
        '#options' => $displayOptions,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $viewDisplayId,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $wrapperId,
        ],
      ];

      if ($viewDisplayId) {
        $view->setDisplay($viewDisplayId);
        $display = $view->getDisplay();
        $viewFilters = $display->getHandlers('filter');
        $viewEntityBundle = NULL;
        if (isset($viewFilters['type']) && isset($viewFilters['type']->options['value']) && count($viewFilters['type']->options['value']) === 1) {
          $viewEntityBundle = reset($viewFilters['type']->options['value']);
        }
        if ($this->shape->getType() !== ComponentShapePluginInterface::OBJECT && $view->getDisplay()->usesPager()) {
          $form['view_items_per_page'] = [
            '#type' => 'number',
            '#title' => $this->t('Override items per page'),
            '#default_value' => $this->configuration['view_items_per_page'] ?? NULL,
            '#min' => 1,
          ];
          if ($this->shape instanceof ComponentShapeInterablePluginInterface) {
            $form['view_items_per_page']['#min'] = $this->shape->getMinItems() ?: 1;
            $form['view_items_per_page']['#max'] = $this->shape->getMaxItems() ?: NULL;
            $form['view_items_per_page']['#default_value'] = $this->configuration['view_items_per_page'] ?? $form['view_items_per_page']['#max'];
          }
        }

        $form['view_items_offset'] = [
          '#type' => 'number',
          '#title' => $this->t('Offset items'),
          '#default_value' => $this->configuration['view_items_offset'] ?? NULL,
          '#min' => 0,
        ];

        $arguments = $view->getHandlers('argument');
        if (!empty($arguments)) {
          $filters = $this->shape->getComponent()->getFilters();
          $options = [
            '_entity' => $this->t('- Component Entity -'),
          ];
          if ($this->shape->getTargetEntityType() === 'taxonomy_term') {
            $options['_taxonomy_term_children'] = $this->t('- Component Term and Children (argument must allow multiple values) -');
          }
          if ($filters) {
            $options = [
              'all' => $this->t('- Ignore -'),
            ] + $options + array_map(fn($filter) => $filter->label(), $filters);
            asort($options);
          }
          if ($options) {
            $form['view_arguments'] = [
              '#type' => 'fieldset',
              '#title' => $this->t('View Arguments'),
              '#access' => FALSE,
            ];
            $form['view_arguments_sort'] = [
              '#type' => 'checkbox',
              '#title' => $this->t('Sort results by argument values'),
              '#description' => $this->t('This is useful if your argument will be entity ids and you want the results to be sorted accordingly.'),
              '#default_value' => $this->configuration['view_arguments_sort'] ?? FALSE,
              '#access' => FALSE,
            ];
            foreach ($arguments as $id => $argument) {
              $handler = $display->getHandler('argument', $id);
              if ($handler) {
                $form['view_arguments']['#access'] = TRUE;
                $form['view_arguments_sort']['#access'] = TRUE;
                $form['view_arguments'][$id] = [
                  '#type' => 'select',
                  '#title' => $handler->adminLabel(),
                  '#options' => $options,
                  '#empty_option' => $this->t('- Select -'),
                  '#default_value' => $this->configuration['view_arguments'][$id] ?? NULL,
                ];
              }
            }
          }
        }

        $form_state->set('view', $view);
        // Add shape fields.
        $form += $this->buildChildrenMatchConfigurationForm($this->shape, $form, $form_state, $viewEntityType->id(), $viewEntityBundle, $this->configuration);

      }
      $form['continue'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Continue when no results'),
        '#description' => $this->t('This will allow any following value providers to be processed if the view returns no results.'),
        '#default_value' => $this->configuration['continue'],
      ];
    }

    return $form;
  }

  /**
   * Alter the configuration form for a child match.
   */
  protected function alterChildMatchConfigurationForm(ComponentShapePluginInterface $shape, &$form, FormStateInterface $form_state, $entityTypeId, $bundle = NULL, array $configuration = []) {
    if ($shape->getType() !== 'string') {
      return;
    }
    $view = $form_state->get('view');
    $display = $view->getDisplay();
    // Support fields directly rendered by views.
    /** @var \Drupal\views\Plugin\views\field\FieldPluginBase[] $fields */
    $fields = $display->getHandlers('field');
    $viewFieldOptions = [];
    foreach ($fields as $fieldName => $field) {
      $viewFieldOptions['- Views -']['_view:' . $fieldName] = $field->adminLabel();
    }

    $form['field']['#options'] = [key($form['field']['#options']) => reset($form['field']['#options'])] + $viewFieldOptions + $form['field']['#options'];
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    if ($this->shape->getType() !== ComponentShapePluginInterface::OBJECT) {
      $itemsPerPage = $form_state->getValue('view_items_per_page');
      $form_state->setValue('view_items_per_page', $itemsPerPage ? (int) $itemsPerPage : NULL);
    }
    $offset = $form_state->getValue('view_items_offset');
    $form_state->setValue('view_items_offset', $offset ? (int) $offset : NULL);
    $form_state->setValue('view_arguments', array_filter($form_state->getValue('view_arguments', [])));
    $form_state->setValue('view_arguments_sort', !empty($form_state->getValue('view_arguments_sort')));

    $form_state->setValue('continue', (bool) $form_state->getValue('continue'));
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * Gets the view executable.
   */
  protected function getView(): ?ViewExecutable {
    if (!isset($this->view)) {
      $this->view = NULL;
      if ($this->configuration['view_id'] && $this->configuration['view_display_id']) {
        $view = Views::getView($this->configuration['view_id']);
        if ($view) {
          $view->setDisplay($this->configuration['view_display_id']);
          // Add cache metadata from the display handler.
          $this->shape->addCacheableDependency($view->display_handler->getCacheMetadata());
          /** @var \Drupal\views\Plugin\views\cache\CachePluginBase $cache_plugin */
          $cache_plugin = $view->display_handler->getPlugin('cache');
          $cacheableMetadata = $this->shape->getCacheableMetadata();
          $cacheableMetadata->setCacheMaxAge($cache_plugin->getCacheMaxAge());
          $cacheableMetadata->addCacheTags($cache_plugin->getCacheTags());

          if ($this->configuration['view_items_per_page'] ?? NULL) {
            $view->setItemsPerPage($this->configuration['view_items_per_page']);
          }
          if ($this->configuration['view_items_offset'] ?? NULL) {
            $view->setOffset($this->configuration['view_items_offset']);
          }
          if ($this->configuration['view_arguments']) {
            $arguments = $view->getHandlers('argument');
            if ($arguments) {
              $args = [];
              foreach ($arguments as $id => $argument) {
                $argValue = NULL;
                $argKey = $this->configuration['view_arguments'][$id] ?? NULL;
                if ($argKey) {
                  $argValue = 'all';
                  switch ($argKey) {
                    case '_entity':
                      $argValue = $this->shape->getEntity()->id();
                      break;

                    case '_taxonomy_term_children':
                      $entity = $this->shape->getEntity();
                      $argValue = [
                        $entity->id(),
                      ];
                      /** @var \Drupal\taxonomy\TermStorageInterface $storage */
                      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
                      foreach ($storage->loadTree($entity->bundle(), $entity->id()) as $term) {
                        $argValue[] = $term->tid;
                      }
                      $argValue = implode(',', $argValue);
                      break;

                    default:
                      if ($filter = $this->shape->getComponent()->getFilter($argKey)) {
                        $argValue = $filter->getProcessedValue();
                      }
                      break;
                  }
                }
                $args[] = $argValue;
              }
              $view->setArguments($args);
            }
          }
          $view->preExecute();
          $view->execute();
          $this->view = $view;
          // Set a context for use by slots.
          $this->shape->getComponent()->setPropShapeContext('views', $this->shape, $view);
        }
      }
    }
    return $this->view;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    if (!$this->shape instanceof ComponentShapeChildrenPluginInterface) {
      return $value;
    }
    if ($view = $this->getView()) {

      // Get entities.
      $entities = [];
      foreach (array_map(fn($row) => $row->_entity, $view->result) as $entity) {
        $entities[$entity->id()] = $entity;
      }

      $args = $view->args;
      if (!empty($this->configuration['view_arguments_sort']) && !empty($args[0])) {
        $ids = explode('+', $args[0]);
        if (count($ids) > 1) {
          $orderedEntities = [];
          foreach ($ids as $id) {
            if (isset($entities[$id])) {
              $orderedEntities[$id] = $entities[$id];
            }
          }
          $entities = $orderedEntities;
        }
      }
      if (!$entities && empty($this->configuration['continue'])) {
        $this->stopFurtherProcessing();
        $value = [];
      }
      else {
        $results = $this->getChildrenMatchValues($this->shape, array_values($entities), $this->configuration);
        if (!empty($results) || empty($this->configuration['continue'])) {
          $this->stopFurtherProcessing();
          // Merge any views-generated values.
          foreach ($results as $delta => $result) {
            $results[$delta] = $result;
          }
          $value = $results;
        }
      }
    }
    return $value;
  }

  /**
   * Fetches the matching values for child components from a Views result.
   */
  protected function fetchChildrenMatchValuesView(string $shapeId, string $shapeName, int $delta, ComponentShapeChildrenPluginInterface $shape, ContentEntityInterface $entity, array $configuration): mixed {
    $fieldName = substr($configuration['field'], 6);
    $view = $this->getView();
    if (isset($view->result[$delta])) {
      return $view->style_plugin->getField($view->result[$delta]->index, $fieldName);
    }
    return NULL;
  }

  /**
   * Retrieves the entity type associated with the given view.
   *
   * This method determines the entity type by comparing the base table of the
   * view with the base and data tables of all defined entity types.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view entity for which to retrieve the entity type.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface|null
   *   The entity type associated with the view, or NULL if no matching entity
   *   type is found.
   */
  protected function getViewEntityType(ViewExecutable $view): ?EntityTypeInterface {
    $baseTable = $view->storage->get('base_table');
    foreach ($this->entityTypeManager->getDefinitions() as $entityType) {
      if (in_array($baseTable, [
        $entityType->getBaseTable(),
        $entityType->getDataTable(),
      ])) {
        return $entityType;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    if ($shape->isIterable()) {
      return TRUE;
    }
    return $shape->isExpandable();
  }

}
