<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Ajax\InstanceIframeHelper;
use Drupal\neo_alchemist\ComponentManageHelper;

/**
 * Component form.
 */
final class InstanceComponentSortForm extends ContentEntityForm {

  use InstanceIframeHelper;

  /**
   * Field item.
   *
   * @var \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem
   */
  protected $fieldItem;

  /**
   * The component UUID.
   *
   * Will only be set if editing an existing component.
   *
   * @var string|null
   */
  protected $uuid;

  /**
   * Entity component.
   *
   * @var \Drupal\neo_alchemist\Entity\EntityComponent
   */
  protected $entityComponent;

  /**
   * {@inheritdoc}
   */
  public function getBaseFormId() {
    $base_form_id = 'neo_compponent_' . $this->entity->getEntityTypeId() . '_order_form';
    if ($base_form_id == $this->getFormId()) {
      $base_form_id = NULL;
    }
    return $base_form_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    $form_id = 'neo_compponent_' . $this->entity->getEntityTypeId();
    if ($this->entity->getEntityType()->hasKey('bundle')) {
      $form_id .= '_' . $this->entity->bundle();
    }
    return $form_id . '_order_form';
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $this->fieldItem = $form_state->get('fieldItem');
    $form_state->set('neo_component_form', TRUE);
    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->fieldItem));
    $focusUuid = $form_state->get('uuid');

    // Add #process and #after_build callbacks.
    $form['#process'][] = '::processForm';
    $form['#after_build'][] = '::afterBuild';

    $form['values'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Title'),
        'weight' => $this->t('Weight'),
      ],
      '#attributes' => [
        'id' => 'neo-components-sort',
      ],
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'draggable-weight',
        ],
      ],
    ];
    $weight = 0;
    foreach ($this->fieldItem->toOptions() as $uuid => $label) {
      $row = [];
      $row['#attributes']['class'] = ['draggable'];
      if ($uuid === $focusUuid) {
        $row['#attributes']['class'][] = 'tr--focus';
      }
      $row['label'] = [
        '#markup' => $label . ' <small>(' . $uuid . ')</small>',
      ];
      $row['weight'] = [
        '#type' => 'weight',
        '#title' => t('Weight'),
        '#title_display' => 'invisible',
        '#default_value' => $weight,
        '#attributes' => [
          'class' => [
            'draggable-weight',
          ],
        ],
      ];
      $weight++;
      $form['values'][$uuid] = $row;
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
    // The entity was validated.
    $this->entity->setValidationRequired(FALSE);
    $form_state->setTemporaryValue('entity_validated', TRUE);
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
      '#url' => $this->fieldItem->toUrl(),
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
    $fieldDefinition = $this->fieldItem->getFieldDefinition();
    $fieldItem = $this->fieldItem;
    $fieldItem->sortComponents(array_keys($form_state->getValue('values')));
    $result = $fieldItem->saveComponents();
    $this->messenger()->addStatus($this->t('Components have been sorted successfully on %label: %field_label.', [
      '%label' => $fieldItem->belongsToFieldConfig() ? $this->entityTypeManager->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $this->entity->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));
    $form_state->setRedirectUrl($fieldItem->toUrl());
    return $result;
  }

}
