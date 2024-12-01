<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
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
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    $valueProviderDefinitions = $this->shape->getValueProviderDefinitions();
    if (!empty($valueProviderDefinitions)) {
      $tableId = 'neo-alchemist-component-prop-form-providers';
      $form['providers'] = [
        '#type' => 'details',
        '#title' => $this->t('Value Providers'),
        '#group' => 'tabs',
      ];
      $form['providers']['values'] = [
        '#type' => 'table',
        '#header' => [
          'status' => $this->t('Status'),
          'provider' => $this->t('Provider'),
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
        $isActive = $form_state->get(['providers', $providerId, 'status']) ?? $this->shape->isValueProviderEnabled($providerId);
        $form['providers']['values'][$providerId] = [
          '#parents' => [
            'providers',
            $providerId,
          ],
        ];
        $row = &$form['providers']['values'][$providerId];
        $row['#attributes']['class'][] = 'draggable';
        $row['status'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Active'),
          '#title_display' => 'invisible',
          '#default_value' => $isActive,
          '#disabled' => !empty($definition['status_lock']),
          '#ajax' => [
            'callback' => '::refreshAjax',
            'wrapper' => $tableId,
          ],
        ];

        if ($isActive) {
          $instance = $this->shape->getValueProvider($providerId);
          $row['provider'] = [
            '#type' => 'fieldset',
            '#title' => $definition['label'],
            '#description' => $definition['description'],
            '#description_display' => 'before',
            '#parents' => [
              'providers',
              $providerId,
              'provider',
            ],
          ];
          $subform_state = SubformState::createForSubform($row['provider'], $form, $form_state);
          $row['provider'] = $instance->buildConfigurationForm($row['provider'], $subform_state, $form);
        }
        else {
          $row['provider']['#markup'] = '<div><span class="font-bold text-base">' . $definition['label'] . '</span>' . ($definition['description'] ? '<br><small class="description">' . $definition['description'] . '</small>' : '') . '</div>';
        }

        $row['weight'] = [
          '#type' => 'weight',
          '#title' => $this->t('Weight for @title', ['@title' => $definition['label']]),
          '#title_display' => 'invisible',
          '#attributes' => [
            'class' => [
              'table-sort-weight',
            ],
          ],
        ];
        // $form['providers']['values'][$providerId] = $row;
      }
    }

    $valueModifierDefinitions = $this->shape->getValueModifierDefinitions();
    if (!empty($valueModifierDefinitions)) {
      $tableId = 'neo-alchemist-component-prop-form-modifiers';
      $form['modifiers'] = [
        '#type' => 'details',
        '#title' => $this->t('Value Modifiers'),
        '#group' => 'tabs',
      ];
      $form['modifiers']['values'] = [
        '#type' => 'table',
        '#header' => [
          'status' => $this->t('Status'),
          'modifier' => $this->t('Modifier'),
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
        $isActive = $form_state->get(['modifiers', $modifierId, 'status']) ?? $this->shape->isValueModifierEnabled($modifierId);
        $form['modifiers']['values'][$modifierId] = [
          '#parents' => [
            'modifiers',
            $modifierId,
          ],
        ];
        $row = &$form['modifiers']['values'][$modifierId];
        $row['#attributes']['class'][] = 'draggable';
        $row['status'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Active'),
          '#title_display' => 'invisible',
          '#default_value' => $isActive,
          '#disabled' => !empty($definition['status_lock']),
          '#ajax' => [
            'callback' => '::refreshAjax',
            'wrapper' => $tableId,
          ],
        ];

        if ($isActive) {
          $instance = $this->shape->getValueModifier($modifierId);
          $row['modifier'] = [
            '#type' => 'fieldset',
            '#title' => $definition['label'],
            '#description' => $definition['description'],
            '#description_display' => 'before',
            '#parents' => [
              'modifiers',
              $modifierId,
              'modifier',
            ],
          ];
          $subform_state = SubformState::createForSubform($row['modifier'], $form, $form_state);
          $row['modifier'] = $instance->buildConfigurationForm($row['modifier'], $subform_state, $form);
        }
        else {
          $row['modifier']['#markup'] = '<div><span class="font-bold text-base">' . $definition['label'] . '</span>' . ($definition['description'] ? '<br><small class="description">' . $definition['description'] . '</small>' : '') . '</div>';
        }

        $row['weight'] = [
          '#type' => 'weight',
          '#title' => $this->t('Weight for @title', ['@title' => $definition['label']]),
          '#title_display' => 'invisible',
          '#attributes' => [
            'class' => [
              'table-sort-weight',
            ],
          ],
        ];
      }
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
    // Providers.
    foreach ($form_state->getValue(['providers'], []) as $providerId => $providerValue) {
      $form_state->set(['providers', $providerId, 'status'], !empty($providerValue['status']));
      if (!empty($providerValue['status'])) {
        $instance = $this->shape->getValueProvider($providerId);
        $subform_state = SubformState::createForSubform($form['providers']['values'][$providerId]['provider'], $form, $form_state);
        $instance->validateConfigurationForm($form['providers']['values'][$providerId]['provider'], $subform_state);
      }
    }
    // Modifiers.
    foreach ($form_state->getValue(['modifiers'], []) as $modifierId => $modifierValue) {
      $form_state->set(['modifiers', $modifierId, 'status'], !empty($modifierValue['status']));
      if (!empty($modifierValue['status'])) {
        $instance = $this->shape->getValueModifier($modifierId);
        $subform_state = SubformState::createForSubform($form['modifiers']['values'][$modifierId]['modifier'], $form, $form_state);
        $instance->validateConfigurationForm($form['modifiers']['values'][$modifierId]['modifier'], $subform_state);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $props = $this->entity->getSetting('props', []);
    $propName = $this->shape->getName();
    $props[$propName] = [
      'prop' => $propName,
      'field_type' => $this->shape->getFieldType(),
      'editable' => !empty($form_state->getValue(['editable'])),
      'required' => !empty($form_state->getValue(['required'])),
    ];

    // Providers.
    foreach ($form_state->getValue(['providers']) as $providerId => $providerValue) {
      if (!empty($providerValue['status'])) {
        $instance = $this->shape->getValueProvider($providerId);
        $subform_state = SubformState::createForSubform($form['providers']['values'][$providerId]['provider'], $form, $form_state);
        $instance->submitConfigurationForm($form['providers']['values'][$providerId]['provider'], $subform_state);
        $props[$propName]['providers'][$providerId] = [
          'plugin' => $providerId,
          'settings' => $subform_state->getValues(),
        ];
      }
    }

    // Modifiers.
    foreach ($form_state->getValue(['modifiers']) as $modifierId => $modifierValue) {
      if (!empty($modifierValue['status'])) {
        $instance = $this->shape->getValueModifier($modifierId);
        $subform_state = SubformState::createForSubform($form['modifiers']['values'][$modifierId]['modifier'], $form, $form_state);
        $instance->submitConfigurationForm($form['modifiers']['values'][$modifierId]['modifier'], $subform_state);
        $props[$propName]['modifiers'][$modifierId] = [
          'plugin' => $modifierId,
          'settings' => $subform_state->getValues(),
        ];
      }
    }

    $this->entity->setSetting('props', $props);
    $result = parent::save($form, $form_state);
    return $result;
  }

}
