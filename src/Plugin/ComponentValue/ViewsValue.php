<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
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
use Drupal\neo_alchemist\FieldMatcher;
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

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\FieldMatcher
   */
  protected FieldMatcher $fieldMatcher;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    FieldMatcher $field_matcher
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldMatcher = $field_matcher;
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
      $container->get('neo_alchemist.field_matcher')
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
      'shape_fields' => [],
      'continue' => FALSE,
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenPluginInterface);
    $options = [];
    foreach (Views::getEnabledViews() as $view) {
      $options[$view->id()] = $view->label() . ' (' . $view->id() . ')';
    }
    asort($options);
    $wrapperId = Html::getId(implode('-', $form['#parents']));
    $form['#id'] = $wrapperId;
    $viewId = $form_state->get(implode('_', array_merge($form['#parents'], ['view_id']))) ?? $this->configuration['view_id'];

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
      $viewDisplayId = $form_state->get(implode('_', array_merge($form['#parents'], ['view_display_id']))) ?? $this->configuration['view_display_id'];
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
        $viewFilters = $view->getDisplay()->getHandlers('filter');
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

        $childShapes = $this->shape->getChildShapes();
        if ($childShapes) {
          $form['shape_fields'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Shape Fields'),
          ];
          foreach ($childShapes as $shapeName => $childShape) {
            if ($childShape->getType() === ComponentShapePluginInterface::ARRAY) {
              // Arrays are not currently support for field binding.
              continue;
            }
            $form['shape_fields'][$shapeName] = [
              '#type' => 'select',
              '#title' => $childShape->getTitle() . ' <small>(' . $childShape->label() . ')</small>',
              '#description' => $childShape->getDescription(),
              '#required' => $childShape->isRequired(),
              '#options' => [
                '- Shape -' => [
                  '_default' => $this->t('Use Default'),
                ],
                // '_default' => $this->t('Use Default'),
              ] + $this->fieldMatcher->getMatchesAsOptions($childShape, $viewEntityType->id(), $viewEntityBundle),
              '#empty_option' => $childShape->isRequired() ? $this->t('- Select -') : $this->t('- None -'),
              '#default_value' => $this->configuration['shape_fields'][$shapeName] ?? NULL,
            ];
          }
        }
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
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $form_state->set(implode('_', array_merge($form['#parents'], ['view_id'])), $form_state->getValue('view_id'));
    $form_state->set(implode('_', array_merge($form['#parents'], ['view_display_id'])), $form_state->getValue('view_id'));
    if ($this->shape->getType() !== ComponentShapePluginInterface::OBJECT) {
      $itemsPerPage = $form_state->getValue('view_items_per_page');
      $form_state->setValue('view_items_per_page', $itemsPerPage ? (int) $itemsPerPage : NULL);
    }
    $form_state->setValue('continue', (bool) $form_state->getValue('continue'));
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
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
  public function provideOverrideValue(mixed $value): mixed {
    if (!$this->shape instanceof ComponentShapeChildrenPluginInterface) {
      return $value;
    }
    if ($this->configuration['view_id'] && $this->configuration['view_display_id']) {
      $shapeNames = $this->shape->getChildShapeNames();
      $view = Views::getView($this->configuration['view_id']);
      if ($this->configuration['view_items_per_page'] ?? NULL) {
        $view->setItemsPerPage($this->configuration['view_items_per_page']);
      }
      $view->execute($this->configuration['view_display_id']);
      $results = [];
      foreach ($view->result as $delta => $result) {
        $entity = $result->_entity ?? NULL;
        if ($entity) {
          foreach ($shapeNames as $shapeName) {
            $results[$delta][$shapeName] = [];
            $field = $this->configuration['shape_fields'][$shapeName] ?? NULL;
            if ($field) {
              switch ($field) {
                case '_default':
                  $this->shape->defaultChildShape($shapeName);
                  break;

                default:
                  $results[$delta][$shapeName] = $this->fieldMatcher->getEntityValue($entity, $field);
                  break;
              }
            }
            else {
              // Hide the shape if no field is selected.
              $this->shape->hideChildShape($shapeName);
            }
          }
        }
      }
      if ($this->shape->getType() === ComponentShapePluginInterface::OBJECT) {
        $results = reset($results) ?: [];
      }
      if (!empty($results) || empty($this->configuration['continue'])) {
        $value = $results;
        $this->stopFurtherProcessing();
      }
    }
    return $value;
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

}
