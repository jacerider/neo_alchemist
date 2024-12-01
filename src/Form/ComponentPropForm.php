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

    $tableId = 'neo-alchemist-component-prop-form-providers';
    $form['providers'] = [
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

    foreach ($this->shape->getValueProviderDefinitions() as $providerId => $definition) {
      $isActive = $form_state->get(['providers', $providerId, 'status']) ?? $this->shape->isValueProviderEnabled($providerId);
      $row = [];
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
        $row['provider']['#markup'] = '<div class="font-bold text-base">' . $definition['label'] . '</div>';
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
      $form['providers'][$providerId] = $row;

      if ($isActive) {
        // $row = [];
        // $row['#wrapper_attributes']['colspan'] = count($form['providers']) - 1;
        // $row['form']['#markup'] = 'form here';
        // $form['providers'][$providerId . '_form'] = $row;
      }
    }

    $form['editable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow Edit'),
      '#description' => $this->t('Allow the default value of this property to be changed per component instance.'),
      '#default_value' => $this->shape->isEditable(),
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

    $providerValues = $form_state->getValue(['providers']);
    foreach ($providerValues as $providerId => $providerValue) {
      $form_state->set(['providers', $providerId, 'status'], !empty($providerValue['status']));
      if (!empty($providerValue['status'])) {
        $instance = $this->shape->getValueProvider($providerId);
        $subform_state = SubformState::createForSubform($form['providers'][$providerId]['provider'], $form, $form_state);
        $instance->validateConfigurationForm($form['providers'][$providerId]['provider'], $subform_state);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $providerIds = [];
    $props = $this->entity->getSetting('props', []);
    $propName = $this->shape->getName();
    $props[$propName] = [
      'prop' => $propName,
      'field_type' => $this->shape->getFieldType(),
      'editable' => !empty($form_state->getValue(['editable'])),
    ];
    foreach ($form_state->getValue(['providers']) as $providerId => $providerValue) {
      if (!empty($providerValue['status'])) {
        $providerIds[] = $providerId;
        $instance = $this->shape->getValueProvider($providerId);
        $subform_state = SubformState::createForSubform($form['providers'][$providerId]['provider'], $form, $form_state);
        $instance->submitConfigurationForm($form['providers'][$providerId]['provider'], $subform_state);
        $props[$propName]['providers'][$providerId] = [
          'plugin' => $providerId,
          'settings' => $subform_state->getValues(),
        ];
        // $providers[$propName][$providerId] = [
        //   'plugin' => $providerId,
        //   'field_type' => $this->shape->getType(),
        //   'settings' => $subform_state->getValues(),
        // ];
      }
    }

    // $providerValues = [
    //   [
    //     'plugin' => 'blek',
    //     'settings' => [],
    //   ]
    // ];

    // ksm($props);
    // return 1;
    // return 1;
    $this->entity->setSetting('props', $props);
    // $this->entity->set('props', $props);
    $result = parent::save($form, $form_state);
    return $result;
  }

}
