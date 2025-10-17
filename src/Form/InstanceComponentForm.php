<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class InstanceComponentForm extends ContentEntityForm {

  use ComponentAjaxFormHelperTrait;
  use IconTrait;

  /**
   * Private temporary storage.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $store;

  /**
   * Component.
   *
   * @var \Drupal\neo_alchemist\ComponentInstanceInterface
   */
  protected $instance;

  /**
   * Before.
   *
   * @var string|null
   */
  protected $before;

  /**
   * After.
   *
   * @var string|null
   */
  protected $after;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * PatternEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The temp storage factory.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, PrivateTempStoreFactory $temp_store_factory) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->store = $temp_store_factory->get('neo_alchemist');
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFormId() {
    $base_form_id = 'neo_component_' . $this->entity->getEntityTypeId() . '_form';
    if ($base_form_id == $this->getFormId()) {
      $base_form_id = NULL;
    }
    return $base_form_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    $form_id = 'neo_component_' . $this->entity->getEntityTypeId();
    if ($this->entity->getEntityType()->hasKey('bundle')) {
      $form_id .= '_' . $this->entity->bundle();
    }
    return $form_id . '_form';
  }

  /**
   * Initialize the form state and the entity before the first form build.
   */
  protected function init(FormStateInterface $form_state) {
    parent::init($form_state);
    $this->instance = $form_state->get('neo_component_instance');
    $this->before = $form_state->get('before');
    $this->after = $form_state->get('after');
    $form_state->set('neo_component_form', TRUE);

    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->instance->getFieldItem()));
    $form_state->set('original_values', $this->instance->getValues());
    $this->store->delete($this->instance->getFieldItem()->getDraftKey($this->instance->uuid()));
    $form_state->set('neo_component_uuid', $this->instance->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $form['footer'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['sticky bottom-0 bg-base-0'],
      ],
    ];

    if ($form['values']['#access'] ?? FALSE) {
      $form['footer']['#attributes']['class'][] = '!mt-0 py-3 translate-y-4 border-t';
    }
    elseif (!empty($form['description'])) {
      $form['footer']['#attributes']['class'][] = 'mt-3';
    }
    else {
      $form['footer']['#attributes']['class'][] = '!mt-0';
    }

    $form['footer']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#neo_size' => 'xs',
      '#default_value' => $this->instance->isPublished(),
    ];

    $form['footer']['refresh'] = [
      '#type' => 'submit',
      '#id' => 'neo-alchemist--refresh',
      '#op' => 'refresh',
      '#value' => $this->t('Refresh'),
      '#submit' => ['::submitRefresh'],
      '#ajax' => [
        'callback' => '::ajaxRefresh',
      ],
      '#weight' => -1000,
      '#prefix' => '<div class="hidden">',
      '#suffix' => '</div>',
    ];

    $form['actions']['#weight'] = 1000;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form['#parents'] = [];
    $form['#id'] = 'neo-alchemist--instance-component-form';
    $form['#attributes']['class'][] = 'neo-alchemist--instance-component-form';
    $form['#neo_style'] = 'default';
    $form['#neo_size'] = 'sm';

    $form['#process'][] = '::processForm';
    $form['#attached']['library'][] = 'neo_alchemist/component.ajax';
    $form['#attached']['library'][] = 'neo_alchemist/component.ajax.form';

    if ($description = $this->instance->getDescription()) {
      $form['description'] = [
        '#type' => 'item',
        '#markup' => $description,
        '#prefix' => '<div class="text-xs bg-base-100 p-4 rounded text-base-100-content/70">',
        '#suffix' => '</div>',
      ];
    }

    $form['uuid'] = [
      '#type' => 'hidden',
      '#default_value' => $this->instance->uuid(),
    ];

    $form['styles'] = [
      '#type' => 'accordion',
      '#title' => $this->icon('Styles', 'palette'),
      '#access' => FALSE,
      '#neo_size' => 'xs',
    ];

    $form['filters'] = [
      '#type' => 'accordion',
      '#title' => $this->icon('Filters', 'filter'),
      '#access' => FALSE,
      '#neo_size' => 'xs',
    ];

    $form['values'] = [
      '#title' => $this->t('Values'),
      '#type' => 'container',
      '#access' => FALSE,
    ];

    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      if (!$shape->access('update')) {
        continue;
      }
      $form['values']['#access'] = TRUE;
      $subform = [
        '#type' => 'container',
        '#parents' => ['values'],
      ];
      $subform_state = SubformState::createForSubform($subform, $form, $form_state);
      $form['values'][$propName] = $shape->getForm($subform, $subform_state);
      if ($shape instanceof ComponentShapeStylePluginInterface) {
        $form['styles']['#access'] = TRUE;
        $form['values'][$propName]['#type'] = 'details';
        $form['values'][$propName]['#title'] = $shape->getTitle();
        $form['values'][$propName]['#group'] = 'styles';
        $form['values'][$propName]['widget']['widget']['#title'] = '';
      }
    }

    foreach ($this->instance->getFilters() as $uuid => $filter) {
      if (!$filter->isEditable()) {
        continue;
      }
      $id = Html::getId('filter-' . $uuid);
      $form['filters']['#access'] = TRUE;

      $allowDefault = $filter->allowDefault();
      $hasOverrideValue = $filter->hasOverrideValue();

      $subform = [
        '#type' => 'details',
        '#title' => $filter->label(),
        '#group' => 'filters',
        '#tree' => TRUE,
        '#open' => !$allowDefault && !$hasOverrideValue,
        '#required' => $filter->isRequired(),
        '#attributes' => [
          'id' => $id,
        ],
      ];
      if ($summary = $filter->valueSummary()) {
        $subform['#title'] .= '<div class="inline-block badge bg-primary text-primary-content leading-tight">' . $summary . '</div>';
      }
      $subform['value'] = [
        '#type' => 'container',
        '#parents' => ['filters', $uuid, 'value'],
      ];
      $subform_state = SubformState::createForSubform($subform['value'], $form, $form_state);
      $subform['value'] = $filter->buildForm($subform['value'], $subform_state);

      if ($allowDefault) {
        if (!$hasOverrideValue && $form_state->getValue([
          'filters',
          $uuid,
          '_default',
        ], $hasOverrideValue === FALSE)) {
          $subform['value']['#prefix'] = '<div class="hidden">';
          $subform['value']['#suffix'] = '</div>';
        }
        $subform['_default'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Default'),
          '#description' => $this->t('Use the default value of @label', ['@label' => $filter->label()]),
          '#parents' => ['filters', $uuid, '_default'],
          '#default_value' => !$hasOverrideValue,
          '#access' => $allowDefault,
          '#neo_size' => 'xs',
          '#ajax' => [
            'callback' => [get_class($this), 'ajaxFilter'],
            'wrapper' => $id,
          ],
        ];
      }
      $form['filters'][$filter->uuid()] = $subform;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    // No entity building is needed.
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = [
      'status' => (int) !empty($form_state->getValue('status')),
    ];
    $original_values = $form_state->get('original_values') ?? [];
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#op'] ?? NULL) === 'refresh') {
      // We do not validate the form when we are just refreshing it.
      $form_state->clearErrors();
    }
    // Update shapes.
    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      if (isset($form['values'][$propName])) {
        $subform_state = SubformState::createForSubform($form['values'][$propName], $form, $form_state);
        $originalValue = $original_values['props'][$propName]['value'] ?? [];
        $value = $subform_state->getValues();
        $shape->validateForm($form['values'][$propName], $subform_state, $value);
        $values['props'][$propName]['ref'] = $shape->getRef();
        $values['props'][$propName]['value'] = $shape->massageFormValues($value, $originalValue, $form['values'][$propName], $subform_state);
        if (!$shape->isIterable() && !empty($values['props'][$propName]['value'])) {
          $values['props'][$propName]['value'] += $originalValue;
        }
        $values['props'][$propName]['options'] = $shape->getNestedOptions();
      }
    }
    // Update filters.
    foreach ($this->instance->getFilters() as $uuid => $filter) {
      if (isset($form['filters'][$uuid])) {
        $value = NULL;
        if (!$form_state->getValue(['filters', $uuid, '_default']) && isset($form['filters'][$uuid]['value'])) {
          $subform_state = SubformState::createForSubform($form['filters'][$uuid]['value'], $form, $form_state);
          $filter->validateForm($form['filters'][$uuid]['value'], $subform_state);
          $value = $filter->massageFormValue($subform_state->getValues(), $form['filters'][$uuid]['value'], $subform_state);
        }
        $values['filters'][$uuid]['value'] = $value;
      }
    }
    $this->instance->setValues($values);
    return $this->entity;
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
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->instance->toUrl(),
      '#attributes' => [
        'data-neo-modal-close' => '1',
      ],
    ];
    $actions['submit']['#attributes']['class'][] = 'btn btn-primary btn-xs';
    $actions['cancel']['#attributes']['class'][] = 'btn btn-xs';
    if ($this->isAjax()) {
      $actions['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $this->instance->setRebuilding(FALSE);
    $form_state->setRedirectUrl($this->instance->toUrl());

    $fieldItem = $this->instance->getFieldItem();
    $fieldDefinition = $fieldItem->getFieldDefinition();
    $this->messenger()->addStatus($this->t('@op component %name successfully on %label: %field_label.', [
      '@op' => $this->instance->isNew() ? 'Created' : 'Updated',
      '%name' => $this->instance->label(),
      '%label' => $fieldItem->belongsToFieldConfig() ? $this->entityTypeManager->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $this->entity->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));

    // If we have requested a position change, we make it here.
    $position = $this->after ? 'after' : ($this->before ? 'before' : NULL);
    if ($position) {
      $this->instance->getFieldItem()->moveComponent($this->instance->uuid(), $this->after ?: $this->before, $position);
    }

    return $this->instance->save();
  }

  /**
   * Submit refresh.
   */
  public function submitRefresh(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    $this->store->set($this->instance->getFieldItem()->getDraftKey($form_state->getValue('uuid')), $this->instance->getValues());
  }

  /**
   * Ajax refresh.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state) {
    $form['#old_build_id'] = $form['#build_id'];
    $response = new AjaxResponse();
    $response->addCommand(new InstanceComponentManageIframeCommand('#' . ComponentManageHelper::getId($this->instance) . ' iframe'));
    return $response;
  }

  /**
   * Ajax callback.
   */
  public static function ajaxFilter(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
  }

}
