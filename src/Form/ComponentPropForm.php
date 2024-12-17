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
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapeExpandedPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueBasePluginInterface;
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
   * The child shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   */
  protected $childShapes;

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
   * Retrieves all child shapes recursively.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape plugin interface.
   *
   * @return array
   *   An array of child shapes.
   */
  public function getChildShapes(ComponentShapePluginInterface $shape): array {
    if ($shape instanceof ComponentShapeExpandedPluginInterface && !$shape->allowExpanded()) {
      return [];
    }
    $shapes = [
      $shape->getNestedId() => $shape,
    ];
    if ($shape instanceof ComponentShapeChildrenPluginInterface) {
      foreach ($shape->getChildShapes() as $childShape) {
        $shapes[$childShape->getNestedId()] = $childShape;
        $shapes += $this->getChildShapes($childShape);
      }
    }
    return $shapes;
  }

  /**
   * Retrieves all parent shapes recursively.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape plugin interface.
   *
   * @return array
   *   An array of parent shapes.
   */
  public function getParentShapes(ComponentShapePluginInterface $shape): array {
    $shapes = [];
    if ($shape instanceof ComponentShapeChildrenPluginInterface) {
      if ($shape instanceof ComponentShapeExpandedPluginInterface && $shape->allowExpanded()) {
        $shapes[$shape->getNestedId()] = $shape;
      }
      foreach ($shape->getChildShapes() as $childShape) {
        $shapes += $this->getParentShapes($childShape);
      }
    }
    return $shapes;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $expanded = $this->shape->getExpanded();
    $parentShapes = $this->getParentShapes($this->shape);
    $childShapes = array_filter($this->getChildShapes($this->shape), function (ComponentShapePluginInterface $shape) use ($expanded) {
      if (in_array($shape->getNestedId(), $expanded)) {
        return FALSE;
      }
      return array_intersect($shape->getNestedIds(), $expanded);
    });

    $providerGroup = $modifierGroup = 'tabs';
    if (!$expanded) {
      $form['tabs'] = [
        '#type' => 'vertical_tabs',
      ];
    }
    else {
      $providerGroup = 'tabs_providers';
      $form[$providerGroup] = [
        '#title' => $this->t('Value Providers'),
        '#type' => 'vertical_tabs',
      ];
      $modifierGroup = 'tabs_modifiers';
      $form[$modifierGroup] = [
        '#title' => $this->t('Value Modifiers'),
        '#type' => 'vertical_tabs',
      ];
    }

    if (!in_array($this->shape->getNestedId(), $expanded)) {
      $form = $this->buildValueProviderForm($form, $form_state, $this->shape, $providerGroup);
    }
    foreach ($childShapes as $childShape) {
      $form = $this->buildValueProviderForm($form, $form_state, $childShape, $providerGroup);
    }

    if (!in_array($this->shape->getNestedId(), $expanded)) {
      $form = $this->buildValueModifierForm($form, $form_state, $this->shape, $modifierGroup);
    }
    foreach ($childShapes as $childShape) {
      $form = $this->buildValueModifierForm($form, $form_state, $childShape, $modifierGroup);
    }

    if ($parentShapes) {
      $parentShapeOptions = array_map(fn (ComponentShapePluginInterface $shape) => ($shape->isNested() ? $shape->getNestedTitle() : $this->t('All Properties')), $parentShapes);
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
  public function buildValueProviderForm(array $form, FormStateInterface $form_state, ComponentShapePluginInterface $shape, $group = 'tabs'): array {
    $valueProviderDefinitions = $shape->getValueProviderDefinitions();
    if (!empty($valueProviderDefinitions)) {
      $key = $shape->isNested() ? 'providers_' . $shape->getNestedId() : 'providers';
      $tableId = Html::getId($key);
      $form[$key] = [
        '#type' => 'details',
        '#title' => $shape->isNested() ?
        $this->t('@title', [
          '@classes' => 'badge bg-primary-500 text-primary-content-500 inline',
          '@title' => $shape->getNestedTitle(FALSE),
        ]) :
        ($group === 'tabs' ? $this->t('Value Providers') : $this->t('Base')),
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

      foreach ($valueProviderDefinitions as $providerId => $definition) {
        $instance = $shape->getValueProvider($providerId);
        $form[$key]['values'][$providerId] = [
          '#table_id' => $tableId,
          '#attributes' => [
            'class' => [
              'draggable',
            ],
          ],
          '#parents' => [
            $key,
            $providerId,
          ],
        ];
        $form[$key]['values'][$providerId] = $this->buildValueDefinitionForm($form[$key]['values'][$providerId], $form_state, $definition, $instance);
      }
    }
    return $form;
  }

  /**
   * Build value modifier form.
   */
  public function buildValueModifierForm(array $form, FormStateInterface $form_state, ComponentShapePluginInterface $shape, $group = 'tabs'): array {
    $valueModifierDefinitions = $shape->getValueModifierDefinitions();
    if (!empty($valueModifierDefinitions)) {
      $key = $shape->isNested() ? 'modifiers_' . $shape->getNestedId() : 'modifiers';
      $tableId = Html::getId($key);
      $form[$key] = [
        '#type' => 'details',
        '#title' => $shape->isNested() ?
        $this->t('@title', [
          '@title' => $shape->getNestedTitle(FALSE),
        ]) :
        ($group === 'tabs' ? $this->t('Value Modifiers') : $this->t('Base')),
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
      foreach ($valueModifierDefinitions as $modifierId => $definition) {
        $instance = $shape->getValueModifier($modifierId);
        $form[$key]['values'][$modifierId] = [
          '#table_id' => $tableId,
          '#attributes' => [
            'class' => [
              'draggable',
            ],
          ],
          '#parents' => [
            $key,
            $modifierId,
          ],
        ];
        $form[$key]['values'][$modifierId] = $this->buildValueDefinitionForm($form[$key]['values'][$modifierId], $form_state, $definition, $instance);
      }
    }
    return $form;
  }

  /**
   * Build value provider definition form.
   */
  public function buildValueDefinitionForm(array $form, FormStateInterface $form_state, array $definition, ComponentValueBasePluginInterface $instance = NULL): array {
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active'),
      '#title_display' => 'invisible',
      '#default_value' => !empty($instance),
      '#disabled' => !empty($definition['status_lock']),
      '#ajax' => [
        'callback' => '::refreshAjax',
        'wrapper' => $form['#table_id'],
      ],
    ];

    if ($instance) {
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }

    $childShapes = $this->getChildShapes($this->shape);
    $props = $this->entity->getSetting('props', []);
    $propName = $this->shape->getName();
    $props[$propName] = [
      'prop' => $propName,
      'shape' => $this->shape->getPluginId(),
      'field_type' => $this->shape->getFieldType(),
      'expanded' => array_values(array_filter($form_state->getValue(['expanded'], []))),
      'editable' => !empty($form_state->getValue(['editable'])),
      'required' => !empty($form_state->getValue(['required'])),
    ];

    // Providers.
    foreach ($form_state->getValue(['providers'], []) as $providerId => $providerValue) {
      $form_state->set(['providers', $providerId, 'status'], !empty($providerValue['status']));
      if (!empty($providerValue['status'])) {
        $instance = $this->shape->getValueProvider($providerId, FALSE);
        $subform_state = SubformState::createForSubform($form['providers']['values'][$providerId]['settings'], $form, $form_state);
        $instance->validateConfigurationForm($form['providers']['values'][$providerId]['settings'], $subform_state);
        $props[$propName]['providers'][$providerId] = [
          'plugin' => $providerId,
          'settings' => $subform_state->getValues(),
        ];
      }
    }

    // Modifiers.
    foreach ($form_state->getValue(['modifiers'], []) as $modifierId => $modifierValue) {
      $form_state->set(['modifiers', $modifierId, 'status'], !empty($modifierValue['status']));
      if (!empty($modifierValue['status'])) {
        $instance = $this->shape->getValueModifier($modifierId, FALSE);
        $subform_state = SubformState::createForSubform($form['modifiers']['values'][$modifierId]['settings'], $form, $form_state);
        $instance->validateConfigurationForm($form['modifiers']['values'][$modifierId]['settings'], $subform_state);
        $props[$propName]['modifiers'][$modifierId] = [
          'plugin' => $modifierId,
          'settings' => $subform_state->getValues(),
        ];
      }
    }

    // Nested.
    foreach ($childShapes as $childShape) {
      $childKey = 'providers_' . $childShape->getNestedId();
      foreach ($form_state->getValue([$childKey], []) as $childProviderId => $childProviderValue) {
        $form_state->set([$childKey, $childProviderId, 'status'], !empty($childProviderValue['status']));
        if (!empty($childProviderValue['status'])) {
          $childInstance = $childShape->getValueProvider($childProviderId, FALSE);
          $subform_state = SubformState::createForSubform($form[$childKey]['values'][$childProviderId]['settings'], $form, $form_state);
          $childInstance->validateConfigurationForm($form[$childKey]['values'][$childProviderId]['settings'], $subform_state);
          $props[$propName]['providers_nested'][$childShape->getNestedId()][$childProviderId] = [
            'plugin' => $childProviderId,
            'settings' => $subform_state->getValues(),
          ];
        }
      }
      $childKey = 'modifiers_' . $childShape->getNestedId();
      foreach ($form_state->getValue([$childKey], []) as $childModifierId => $childProviderValue) {
        $form_state->set([$childKey, $childModifierId, 'status'], !empty($childProviderValue['status']));
        if (!empty($childProviderValue['status'])) {
          $childInstance = $childShape->getValueModifier($childModifierId, FALSE);
          $subform_state = SubformState::createForSubform($form[$childKey]['values'][$childModifierId]['settings'], $form, $form_state);
          $childInstance->validateConfigurationForm($form[$childKey]['values'][$childModifierId]['settings'], $subform_state);
          $props[$propName]['modifiers_nested'][$childShape->getNestedId()][$childModifierId] = [
            'plugin' => $childModifierId,
            'settings' => $subform_state->getValues(),
          ];
        }
      }
    }

    if (!$form_state->getErrors()) {
      $this->entity->setSetting('props', $props);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    return $result;
  }

}
