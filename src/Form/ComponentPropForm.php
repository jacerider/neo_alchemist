<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentPropForm extends EntityForm {

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
      $container->get('entity_type.manager')
    );
  }

  /**
   * PatternEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager service.
   */
  public function __construct(EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
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
    return $expanded ? array_filter($this->shape->getAllChildShapes(TRUE), function (ComponentShapePluginInterface $shape) use ($expanded) {
      if (in_array($shape->getNestedId(), $expanded)) {
        return FALSE;
      }
      return array_intersect($shape->getNestedIds(), $expanded);
    }) : [$this->shape->getNestedId() => $this->shape];
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
    $expandedShapes = $shape->getAllExpandedableShapes(TRUE);
    assert(!empty($pluginShapes), 'No shapes found.');

    if (!$form_state->get('original_prop')) {
      $props = $this->entity->getSetting('props', []);
      $form_state->set('original_prop', $props[$this->shape->getName()] ?? []);
    }

    // $pluginManagers = $this->shape->getPluginManagers();
    // if (!$isExpanded) {
    //   $form['tabs'] = [
    //     '#type' => 'vertical_tabs',
    //   ];
    // }
    // else {
    //   foreach ($pluginManagers as $pluginType => $manager) {
    //     $form[$pluginType] = [
    //       '#type' => 'vertical_tabs',
    //       '#title' => $manager->label(),
    //     ];
    //   }
    // }

    // foreach ($pluginManagers as $pluginType => $manager) {
    //   $group = match(TRUE) {
    //     $isExpanded => $pluginType,
    //     default => 'tabs',
    //   };
    //   foreach ($pluginShapes as $pluginShape) {
    //     $form = $this->buildPluginForm($form, $form_state, $pluginShape, $pluginType, $group);
    //   }
    // }

    if ($expandedShapes) {
      $parentShapeOptions = array_map(fn (ComponentShapePluginInterface $shape) => ($shape->isNested() ? $shape->getNestedTitle() : $this->t('All Properties')), $expandedShapes);
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
  public function buildPluginForm(array $form, FormStateInterface $form_state, ComponentShapePluginInterface $shape, string $pluginType, $group = 'tabs'): array {
    $pluginCollection = $shape->getPluginCollection($pluginType);
    if (count($pluginCollection)) {
      $manager = $shape->getPluginManager($pluginType);
      $nestedId = $shape->getNestedId();
      $key = $pluginType . '_' . $nestedId;
      $tableId = Html::getId($key);
      $form[$key] = [
        '#type' => 'details',
        '#title' => $shape->isNested() ?
        $this->t('@title', [
          '@classes' => 'badge bg-primary-500 text-primary-content-500 inline',
          '@title' => $shape->getNestedTitle(FALSE),
        ]) :
        ($group === 'tabs' ? $manager->label() : $this->t('Base')),
        '#group' => $group,
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
      foreach ($pluginCollection as $pluginId => $plugin) {
        $form[$key]['values'][$pluginId] = [
          '#table_id' => $tableId,
          '#attributes' => [
            'class' => [
              'draggable',
            ],
          ],
          '#parents' => [
            $key,
            $pluginId,
          ],
        ];
        $form[$key]['values'][$pluginId] = $this->buildPluginInstanceForm($form[$key]['values'][$pluginId], $form_state, $plugin);
      }
    }
    return $form;
  }

  /**
   * Build value provider definition form.
   */
  public function buildPluginInstanceForm(array $form, FormStateInterface $form_state, ComponentValuePluginInterface $plugin): array {
    $definition = $plugin->getPluginDefinition();
    $settings = $plugin->getConfiguration();
    $status = !empty($settings['status']);
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
      $form['settings'] = $plugin->buildConfigurationForm($form['settings'], $subform_state, $form);
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

    $pluginManager = $shape->getPluginManagers();
    foreach ($pluginManager as $pluginType => $manager) {
      foreach ($pluginShapes as $pluginShape) {
        $collection = $pluginShape->getPluginCollection($pluginType);
        $nestedId = $pluginShape->getNestedId();
        $key = $pluginType . '_' . $nestedId;
        foreach ($form_state->getValue([$key], []) as $pluginId => $value) {
          $plugin = $collection->get($pluginId);
          $subform_state = SubformState::createForSubform($form[$key]['values'][$pluginId]['settings'], $form, $form_state);
          $plugin->validateConfigurationForm($form[$key]['values'][$pluginId]['settings'], $subform_state);
          $originalPluginSettingsParents = [
            'original_prop',
            'plugins',
            $nestedId,
            $pluginType,
            $pluginId,
            'settings',
          ];
          $originalPluginSettings = $form_state->get($originalPluginSettingsParents);
          $settings = $subform_state->getValues() ?: $originalPluginSettings ?? [];
          if (!empty($value['status'])) {
            $settings['status'] = TRUE;
            $plugin->setConfiguration($settings);
          }
          else {
            $settings['status'] = FALSE;
            $plugin->setConfiguration($settings);
          }
          $form_state->set($originalPluginSettingsParents, $settings);
        }
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
