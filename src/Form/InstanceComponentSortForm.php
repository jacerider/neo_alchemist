<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\ComponentManageHelper;

/**
 * Component form.
 */
final class InstanceComponentSortForm extends ContentEntityForm {

  use ComponentAjaxFormHelperTrait;

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
    $form_state->set('neo_component_instance', $this->fieldItem);
    $form_state->set('neo_component_form', TRUE);
    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->fieldItem));
    $focusUuid = $form_state->get('uuid');
    $parentUuid = $form_state->get('parentUuid');
    $shapeId = $form_state->get('shapeId');
    $form['#attached']['library'][] = 'neo_alchemist/component.sort';
    $form['#attributes']['class'][] = 'neo-component-sort-form';

    // Add #process and #after_build callbacks.
    $form['#process'][] = '::processForm';
    $form['#after_build'][] = '::afterBuild';
    $form['values'] = [
      '#type' => 'table',
      '#id' => 'neo-components-sort',
      '#header' => [
        'label' => $this->t('Title'),
        'weight' => $this->t('Weight'),
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
    $options = $this->fieldItem->toOptions($parentUuid, $shapeId);
    foreach ($options as $uuid => $label) {
      $row = [];
      $row['#attributes']['class'] = ['draggable'];
      $row['#attributes']['data-component-sort-uuid'] = $uuid;
      if ($uuid === $focusUuid) {
        $row['#attributes']['class'][] = 'tr--focus';
      }
      // Seed the row with the component type's own thumbnail. component-sort.js
      // swaps in a live capture of this particular instance once the preview
      // frame answers, but the frame only exists when the dialog was opened
      // over the layout editor — these sort routes are also reachable directly,
      // where nothing would ever fill an empty placeholder. It also means the
      // row reserves its box from first paint instead of reflowing the whole
      // table when the captures land.
      //
      // Fixed box with object-cover/object-top, matching the component picker
      // in neo-alchemist-library.html.twig: components are arbitrarily tall,
      // and letting the image size itself produced rows from 55px to 209px.
      $instance = $this->fieldItem->getComponent($uuid);
      $row['label'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="flex items-center gap-4">
          <span class="thumbnail block shrink-0 w-50 h-30 overflow-hidden rounded border border-base-300 shadow-sm bg-base-100">
            {% if thumbnail %}<img src="{{ thumbnail }}" alt="" class="object-cover object-left-top w-full h-30 hover:object-left-bottom transition-all duration-700" />{% endif %}
          </span>
          <span>{{ label }} <small>({{ uuid }})</small></span>
        </div>',
        '#context' => [
          // Escaped, unlike the raw concatenation this replaces: a label is
          // entity-authored, so it is not ours to trust as markup.
          'thumbnail' => $instance ? $instance->getThumbnail() : NULL,
          'label' => $label,
          'uuid' => $uuid,
        ],
      ];
      $row['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight'),
        '#title_display' => 'invisible',
        '#default_value' => $weight,
        // Integer, and rounded up. Weight::processWeight() walks -delta to
        // +delta with $n++ and uses each step as an array key, so an odd row
        // count and a plain division put a half-step float in that loop and
        // every key is an implicit lossy cast. Rounding up rather than down
        // also keeps the range wide enough to seat every row.
        '#delta' => (int) ceil(count($options) / 2),
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
    $parentUuid = $form_state->get('parentUuid');
    $shapeId = $form_state->get('shapeId');
    // The table only carries rows for instances that have a label, so this
    // list can be incomplete — which is exactly why the reorder permutes
    // rather than rebuilds. An unlabelable instance keeps its position.
    $fieldItem->reorderComponents(array_keys($form_state->getValue('values')), $parentUuid, $shapeId);
    $result = $fieldItem->saveComponents();
    $this->messenger()->addStatus($this->t('Components have been sorted successfully on %label: %field_label.', [
      '%label' => $fieldItem->belongsToFieldConfig() ? $this->entityTypeManager->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $this->entity->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));
    $form_state->setRedirectUrl($fieldItem->toUrl());
    return $result;
  }

}
