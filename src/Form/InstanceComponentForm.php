<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\neo_alchemist\Ajax\InstanceComponentPreviewIframeCommand;
use Drupal\neo_alchemist\Ajax\InstanceIframeHelper;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class InstanceComponentForm extends ContentEntityForm {

  use InstanceIframeHelper;

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
    // kint(ComponentManageHelper::getId($this->instance->getFieldItem()));
    // die;
    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->instance->getFieldItem()));
    $form_state->set('original_values', $this->instance->getValues());
    $this->store->delete($this->instance->getFieldItem()->getDraftKey($this->instance->uuid()));
    $form_state->set('neo_draft_uuid', $this->instance->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

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
    $form['#neo_style'] = 'clean';

    $form['#process'][] = '::processForm';
    $form['#attached']['library'][] = 'neo_alchemist/instance.ajax';
    $form['#attached']['library'][] = 'neo_alchemist/instance.component.form';

    $form['uuid'] = [
      '#type' => 'hidden',
      '#default_value' => $this->instance->uuid(),
    ];

    $form['advanced'] = [
      '#type' => 'accordion',
      '#title' => $this->t('Styles'),
      '#access' => FALSE,
    ];

    $form['values'] = [
      '#title' => $this->t('Values'),
      '#type' => 'container',
    ];

    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      if (!$shape->access('update')) {
        continue;
      }
      $subform = [
        '#type' => 'container',
        '#parents' => ['values'],
      ];
      $subform_state = SubformState::createForSubform($subform, $form, $form_state);
      $form['values'][$propName] = $shape->getForm($subform, $subform_state);
      if ($shape instanceof ComponentShapeStylePluginInterface) {
        $form['advanced']['#access'] = TRUE;
        $form['values'][$propName]['#type'] = 'details';
        $form['values'][$propName]['#title'] = $shape->getTitle();
        $form['values'][$propName]['#group'] = 'advanced';
        $form['values'][$propName]['widget']['widget']['#title'] = '';
      }
    }

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->instance->isPublished(),
    ];

    $form['refresh'] = [
      '#type' => 'submit',
      '#id' => 'neo-alchemist--refresh',
      '#value' => $this->t('Refresh'),
      '#submit' => ['::submitRefresh'],
      '#ajax' => [
        'callback' => '::ajaxRefresh',
      ],
      '#weight' => -1000,
      '#prefix' => '<div class="hidden">',
      '#suffix' => '</div>',
    ];

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
    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      if (isset($form['values'][$propName])) {
        $subform_state = SubformState::createForSubform($form['values'][$propName], $form, $form_state);
        $originalValue = $original_values['props'][$propName]['value'] ?? [];
        $value = $subform_state->getValues();
        $shape->validateForm($form['values'][$propName], $subform_state, $value);
        $values['props'][$propName]['shape'] = $shape->getPluginId();
        if (is_array($value) && !empty($value)) {
          $values['props'][$propName]['value'] = $shape->massageFormValues($value, $originalValue, $form['values'][$propName], $subform_state);
        }
        else {
          $values['props'][$propName]['value'] = $originalValue;
        }
        $values['props'][$propName]['options'] = $shape->getNestedOptions();
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
    if ($this->isAjax()) {
      $actions['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->instance->toUrl(),
      '#attributes' => [
        'data-neo-modal-close' => '1',
      ],
    ];
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
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('.region.region--status', ['#type' => 'status_messages']));
    $response->addCommand(new InstanceComponentPreviewIframeCommand('#' . ComponentManageHelper::getId($this->instance) . ' iframe'));
    return $response;
  }

}
