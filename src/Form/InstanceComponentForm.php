<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Render\Element;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentPropValueHarvester;
use Drupal\neo_alchemist\EditorState\EditorScratchStore;
use Drupal\neo_alchemist\Value\ComponentValuePanelBuilder;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class InstanceComponentForm extends ContentEntityForm {

  use ComponentAjaxFormHelperTrait;
  use IconTrait;

  /**
   * The per-user scratch store holding the live form buffer.
   *
   * Must be protected and non-promoted, like every service held by a form
   * object here: DependencySerializationTrait swaps services for their ids
   * from FormBase's scope, where a private property declared on this class
   * would be invisible and would be serialized whole into the form cache.
   *
   * @var \Drupal\neo_alchemist\EditorState\EditorScratchStore
   */
  protected $scratchStore;

  /**
   * Component.
   *
   * @var \Drupal\neo_alchemist\ComponentInstanceInterface
   */
  protected $instance;

  /**
   * Parent UUID.
   *
   * @var string|null
   */
  protected $parent;

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
   * The value panel builder.
   *
   * Must be protected and non-promoted, like every service held by a form
   * object here: DependencySerializationTrait swaps services for their ids
   * from FormBase's scope, where a private property declared on this class
   * would be invisible and would be serialized whole into the form cache.
   *
   * @var \Drupal\neo_alchemist\Value\ComponentValuePanelBuilder
   */
  protected $valuePanelBuilder;

  /**
   * The prop value harvester.
   *
   * @var \Drupal\neo_alchemist\ComponentPropValueHarvester
   */
  protected $propValueHarvester;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('neo_alchemist.editor_scratch_store'),
      $container->get('neo_alchemist.value_panel_builder'),
      $container->get('neo_alchemist.prop_value_harvester'),
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
   * @param \Drupal\neo_alchemist\EditorState\EditorScratchStore $scratch_store
   *   The per-user scratch store holding the live form buffer.
   * @param \Drupal\neo_alchemist\Value\ComponentValuePanelBuilder $value_panel_builder
   *   The value panel builder.
   * @param \Drupal\neo_alchemist\ComponentPropValueHarvester $prop_value_harvester
   *   The prop value harvester.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, EditorScratchStore $scratch_store, ComponentValuePanelBuilder $value_panel_builder, ComponentPropValueHarvester $prop_value_harvester) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->scratchStore = $scratch_store;
    $this->valuePanelBuilder = $value_panel_builder;
    $this->propValueHarvester = $prop_value_harvester;
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
    $this->parent = $form_state->get('parent');
    $this->before = $form_state->get('before');
    $this->after = $form_state->get('after');
    $form_state->set('neo_component_form', TRUE);

    // This form is only shown when previewing or managing a component.
    $this->instance->setPreview(TRUE);

    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->instance->getFieldItem()));
    $form_state->set('original_values', $this->instance->getValues());
    // Opening the form clears any stale live buffer for this instance; the
    // scratch store invalidates the preview's cache tag as part of the delete.
    $this->scratchStore->delete($this->instance->getFieldItem(), $this->instance->uuid());
    $form_state->set('neo_component_uuid', $this->instance->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $form['#attributes']['class'][] = 'neo-alchemist--component-form';
    $form['#attached']['library'][] = 'neo_alchemist/component.form';

    $form['footer'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['sticky bottom-0 bg-base-0 z-10'],
      ],
    ];

    if ($form['values']['#access'] ?: $form['filters']['#access'] ?? FALSE) {
      $form['footer']['#attributes']['class'][] = 'mb-0 py-3 border-t';
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

    $form['footer']['refresh'] = $this->valuePanelBuilder->buildRefresh();

    $form['actions']['#weight'] = 1000;
    $form['actions']['#attributes']['class'][] = 'mt-0';
    $form['footer']['actions'] = $form['actions'];
    unset($form['actions']);

    // This is a content entity form, so field_group attaches the entity's form
    // display groups to it, even though the form display is never rendered
    // here. Without opting out, field_group pulls any element whose key
    // matches a grouped field name into that group - 'description' being the
    // common collision - and renders a stray tab around it.
    foreach (Element::children($form) as $key) {
      $form[$key]['#field_group_ignore'] = TRUE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form['#parents'] = [];
    $form['#id'] = ComponentValuePanelBuilder::FORM_ID;
    $form['#attributes']['class'][] = ComponentValuePanelBuilder::FORM_ID;
    $form['#neo_style'] = 'default';
    $form['#neo_size'] = 'sm';

    $form['#process'][] = '::processForm';
    $this->valuePanelBuilder->attachClient($form);

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

    // Assigned in this order because top-level render order is array order and
    // the Context accordion belongs between the two panel elements.
    $panel = $this->valuePanelBuilder->build($this->instance, $form, $form_state);
    $form['styles'] = $panel['styles'];

    $form['filters'] = [
      '#type' => 'accordion',
      '#title' => $this->icon('Context', 'flux-capacitor'),
      '#access' => FALSE,
      '#neo_size' => 'xs',
    ];

    $form['values'] = $panel['values'];

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
    // Update shapes. Left unset rather than set empty when nothing was
    // harvested, which is what the loop this replaced did.
    if ($props = $this->propValueHarvester->harvest($this->instance, $form, $form_state, $original_values)) {
      $values['props'] = $props;
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
    if ($this->instance->isNew()) {
      // A new component was picked from the library, so cancel returns there
      // rather than to the manage page — with the placement intact, so picking
      // a different component lands in the same spot. The query is set on the
      // Url object because neither scope's toUrl() forwards options.
      $url = $this->instance->getFieldItem()->toUrl('library');
      if ($query = array_filter(['parent' => $this->parent, 'before' => $this->before, 'after' => $this->after])) {
        $url->setOption('query', $query);
      }
      $actions['cancel']['#url'] = $url;
      if ($this->isAjax()) {
        // In the modal flow the library and add screens replace each other in
        // one dialog: a plain href would navigate the whole page and
        // data-neo-modal-close would just close the modal. Mirror the
        // library's own component links so cancel swaps the library back in.
        // $actions is the local array built at the top of this method; the
        // sniff does not follow it into this branch.
        // phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UndefinedUnsetVariable
        unset($actions['cancel']['#attributes']['data-neo-modal-close']);
        $actions['cancel']['#attributes']['class'][] = 'use-ajax';
        $actions['cancel']['#attributes']['data-dialog-type'] = 'modal';
        $actions['cancel']['#attributes']['data-dialog-options'] = Json::encode([
          'width' => '100%',
          'height' => '100%',
          'neo' => [
            'displaceTop' => '0px',
            'displaceBottom' => '0px',
            'contentPadding' => '0px',
          ],
        ]);
        $actions['cancel']['#attached']['library'][] = 'core/drupal.dialog.ajax';
      }
    }
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

    // Update shapes.
    $values = $this->instance->getValues();
    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      $values['props'][$propName] = $shape->massageFinalValues($values['props'][$propName] ?? []);
    }
    $this->instance->setValues($values);

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
      $this->instance->getFieldItem()->moveComponent($this->instance->uuid(), $this->after ?: $this->before, $position, $this->instance->getParentUuid(), $this->instance->getParentSlot());
    }

    return $this->instance->save();
  }

  /**
   * Submit refresh.
   */
  public function submitRefresh(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    // Buffer the in-progress values so this editor's preview iframe reflects
    // them; the scratch store invalidates the preview's cache tag on write.
    $this->scratchStore->set($this->instance->getFieldItem(), $form_state->getValue('uuid'), $this->instance->getValues());
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
