<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\neo_alchemist\Ajax\InstanceIframeHelper;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueGroupPluginManager;
use Drupal\neo_alchemist\ComponentValuePluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentPropForm extends EntityForm {

  use InstanceIframeHelper;

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The group manager.
   *
   * @var \Drupal\neo_alchemist\ComponentValueGroupPluginManager
   */
  protected $groupManager;

  /**
   * The shape.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface
   */
  protected $shape;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.neo_component_value_group'),
    );
  }

  /**
   * PatternEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager service.
   * @param \Drupal\neo_alchemist\ComponentValueGroupPluginManager $groupManager
   *   The group manager.
   */
  public function __construct(EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityTypeManagerInterface $entity_type_manager, ComponentValueGroupPluginManager $groupManager) {
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
    $this->groupManager = $groupManager;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $prop = NULL) {
    $this->shape = $this->entity->getPropShape($prop);
    $form['#title'] = $this->t('Edit %prop_label from %label', [
      '%prop_label' => $this->shape->getTitle(),
      '%label' => $this->entity->label(),
    ]);
    return parent::buildForm($form, $form_state);
  }

  /**
   * Get plugin shapes.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The plugin shapes.
   */
  protected function getPluginShapes() {
    $expanded = $this->shape->getExpanded();
    if ($expanded) {
      return $this->shape->getPluginShapes(TRUE);
    }
    if ($this->shape->allowPlugins()) {
      return [$this->shape->getNestedId() => $this->shape];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $shape = $this->shape;
    $expanded = $shape->getExpanded();
    $isExpanded = !empty($shape->getExpanded());
    $pluginShapes = $this->getPluginShapes();
    $expandableShapes = $shape->getExpandedableShapes(TRUE);

    if (!$form_state->get('original_prop')) {
      $props = $this->entity->getSetting('props', []);
      $form_state->set('original_prop', $props[$this->shape->getName()] ?? []);
    }

    $groups = $this->groupManager->getDefinitions();
    uasort($groups, [SortArray::class, 'sortByWeightElement']);
    if (!$isExpanded) {
      $form['tabs'] = [
        '#type' => 'vertical_tabs',
      ];
    }
    else {
      foreach ($groups as $group) {
        $form[$group['id']] = [
          '#type' => 'vertical_tabs',
          '#title' => $group['label'],
        ];
      }
    }

    foreach ($groups as $group) {
      $tab = match(TRUE) {
        $isExpanded => $group['id'],
        default => 'tabs',
      };
      foreach ($pluginShapes as $pluginShape) {
        $form = $this->buildPluginForm($form, $form_state, $pluginShape, $group['id'], $tab);
      }
    }

    if ($expandableShapes) {
      $parentShapeOptions = array_map(fn (ComponentShapePluginInterface $shape) => ($shape->isNested() ? $shape->getNestedTitle(TRUE) : $this->t('Root')), $expandableShapes);
      $form['expanded'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Expand Properties'),
        '#description' => $this->t('These props contain nested properties. Expanding these properties will allow you to configure value providers and modifiers for each nested property.'),
        '#default_value' => $expanded,
        '#options' => $parentShapeOptions,
      ];
    }

    $form['editable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow Edit'),
      '#description' => $this->t('Allow the default value of this property to be changed per component instance.'),
      '#default_value' => $this->shape->isEditable(),
    ];

    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required'),
      '#description' => $this->t('Require this property to be set for all component instances.'),
      '#default_value' => $this->shape->isRequired(),
      '#disabled' => $this->shape->isEnforcedRequired(),
    ];

    return $form;
  }

  /**
   * Build value provider form.
   */
  public function buildPluginForm(array $form, FormStateInterface $form_state, ComponentShapePluginInterface $shape, string $groupId, string $tab = 'tabs'): array {
    $collection = $shape->getValueCollection();
    $instances = $collection->getInstancesByGroup($groupId);

    if ($instances) {
      $nestedId = $shape->getNestedId();
      $key = $groupId . '_' . $nestedId;
      $tableId = Html::getId($key);
      $form[$key] = [
        '#type' => 'details',
        '#title' => $shape->isNested() ? $shape->getNestedTitle(FALSE) : ($tab === 'tabs' ? $this->groupManager->getDefinition($groupId)['label'] : $this->t('Base')),
        '#group' => $tab,
      ];
      $form[$key]['values'] = [
        '#type' => 'table',
        '#header' => [
          'status' => $this->t('Status'),
          'settings' => $this->t('Provider'),
          'weight' => $this->t('Weight'),
        ],
        '#tabledrag' => [
          [
            'action' => 'order',
            'relationship' => 'sibling',
            'group' => 'table-sort-weight',
          ],
        ],
        '#prefix' => '<div id="' . $tableId . '">',
        '#suffix' => '</div>',
      ];
      foreach ($instances as $instanceId => $instance) {
        if (!$instance->isAllowed('manage')) {
          continue;
        }
        $form[$key]['values'][$instanceId] = [
          '#table_id' => $tableId,
          '#attributes' => [
            'class' => [
              'draggable',
            ],
          ],
          '#parents' => [
            $key,
            $instanceId,
          ],
        ];
        $form[$key]['values'][$instanceId] = $this->buildPluginInstanceForm($form[$key]['values'][$instanceId], $form_state, $instance, $collection->getStatus($instanceId));
      }

    }
    return $form;
  }

  /**
   * Build value provider definition form.
   */
  public function buildPluginInstanceForm(array $form, FormStateInterface $form_state, ComponentValuePluginInterface $instance, bool $status): array {
    $definition = $instance->getPluginDefinition();
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active'),
      '#title_display' => 'invisible',
      '#default_value' => $status,
      '#disabled' => !empty($definition['status_lock']),
      '#ajax' => [
        'callback' => '::refreshAjax',
        'wrapper' => $form['#table_id'],
      ],
    ];

    if ($status) {
      $form['settings'] = [
        '#type' => 'fieldset',
        '#title' => $definition['label'],
        '#description' => $definition['description'],
        '#description_display' => 'before',
        '#parents' => array_merge($form['#parents'], ['settings']),
      ];
      $subform_state = SubformState::createForSubform($form['settings'], $form, $form_state);
      $form['settings'] = $instance->buildConfigurationForm($form['settings'], $subform_state, $form);
    }
    else {
      $form['settings']['#markup'] = '<div><span class="font-bold text-base">' . $definition['label'] . '</span>' . ($definition['description'] ? '<br><small class="description">' . $definition['description'] . '</small>' : '') . '</div>';
    }

    $form['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight for @title', ['@title' => $definition['label']]),
      '#title_display' => 'invisible',
      '#attributes' => [
        'class' => [
          'table-sort-weight',
        ],
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $pluginShapes = $this->getPluginShapes();

    $shape = $this->shape;
    $shape->setExpanded(array_values(array_filter($form_state->getValue(['expanded'], []))));
    $shape->setEditable(!empty($form_state->getValue(['editable'])));
    $shape->setRequired(!empty($form_state->getValue(['required'])));

    foreach ($pluginShapes as $pluginShape) {
      $nestedId = $pluginShape->getNestedId();
      $collection = $pluginShape->getValueCollection();
      foreach ($collection->getInstances() as $instanceId => $instance) {
        $groupId = $instance->getGroup();
        $key = $groupId . '_' . $nestedId;
        if (empty($form[$key]['values'][$instanceId]['settings'])) {
          continue;
        }
        $value = $form_state->getValue([$key, $instanceId], []);
        $subform_state = SubformState::createForSubform($form[$key]['values'][$instanceId]['settings'], $form, $form_state);
        $instance->validateConfigurationForm($form[$key]['values'][$instanceId]['settings'], $subform_state);
        $originalPluginSettingsParents = [
          'original_prop',
          'plugins',
          $nestedId,
          $instanceId,
          'settings',
        ];
        $originalPluginSettings = $form_state->get($originalPluginSettingsParents);
        $settings = $subform_state->getValues() ?: $originalPluginSettings ?? [];
        $collection->setStatus($instanceId, !empty($value['status']));
        $instance->setConfiguration($settings);
        $form_state->set($originalPluginSettingsParents, $settings);
      }
    }

    if (!$form_state->getErrors()) {
      $this->entity->setPropShapeSettings($shape);
    }
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#submit' => ['::submitForm', '::save'],
    ];
    if ($this->isAjax()) {
      $actions['#attached']['library'][] = 'neo_alchemist/instance.ajax';
      $actions['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    // $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
