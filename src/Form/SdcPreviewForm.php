<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;
use Drupal\neo_icon\IconTrait;

/**
 * Editable value form for the standalone SDC preview workspace.
 *
 * This mirrors the live prop/style editor used when a component is placed on a
 * page (@see InstanceComponentForm) but operates on the transient
 * (unsaved) neo_component entity built for an SDC preview. Instead of saving,
 * changes are written as cache-backed preview-value overrides
 * (Component::setPreviewValues()) and the preview iframe is reloaded so the
 * developer sees them immediately. Nothing is persisted to configuration.
 */
final class SdcPreviewForm extends EntityForm {

  use IconTrait;

  /**
   * The component entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  protected function init(FormStateInterface $form_state) {
    parent::init($form_state);
    // Capture the value overrides present when the workspace first loaded so
    // that non-iterable shape values can be merged correctly on refresh.
    $form_state->set('original_values', $this->entity->getPreviewValues());
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['#parents'] = [];
    // The component.ajax.form behavior keys off this exact id.
    $form['#id'] = 'neo-alchemist--instance-component-form';
    // Match the saved-component manage form styling so the form is inset from
    // its container rather than flush to the edges.
    $form['#neo_style'] = 'clean';

    $form['#attached']['library'][] = 'neo_alchemist/component.ajax';
    $form['#attached']['library'][] = 'neo_alchemist/component.ajax.form';

    $form['styles'] = [
      '#type' => 'accordion',
      '#title' => $this->icon('Styles', 'palette'),
      '#access' => FALSE,
      '#neo_size' => 'xs',
    ];

    $form['values'] = [
      '#title' => $this->t('Values'),
      '#type' => 'container',
      '#access' => FALSE,
    ];

    foreach ($this->entity->getPropShapes() as $propName => $shape) {
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
      // The shape form exposes per-prop config controls (Allow Edit / Default /
      // Hide) because it builds in 'config' scope. They are meaningless when a
      // developer is just previewing values, so hide them. Their default values
      // are preserved during processing, so value massaging is unaffected.
      $this->hideOptionControls($form['values'][$propName]);
      if ($shape instanceof ComponentShapeStylePluginInterface) {
        $form['styles']['#access'] = TRUE;
        $form['values'][$propName]['#type'] = 'details';
        $form['values'][$propName]['#title'] = $shape->getTitle();
        $form['values'][$propName]['#group'] = 'styles';
        $form['values'][$propName]['widget']['widget']['#title'] = '';
      }
    }

    // Hidden refresh submit, triggered by the component.ajax.form behavior on
    // every (debounced) input change.
    $form['refresh'] = [
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = [];
    $actions['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#limit_validation_errors' => [],
      '#submit' => ['::submitReset'],
      '#access' => $this->entity->hasPreviewValues(),
      '#attributes' => [
        'class' => ['btn', 'btn-xs'],
      ],
    ];
    return $actions;
  }

  /**
   * Recursively hides the per-prop option controls in a shape form.
   *
   * Shape forms built in 'config' scope add an `_options` group (Allow Edit /
   * Default / Hide) for each prop and nested prop. Setting `#access` to FALSE
   * removes them from the UI while keeping their default values available to
   * form processing, so massaging the submitted values is unaffected.
   *
   * @param array $element
   *   The render element to process, modified by reference.
   */
  private function hideOptionControls(array &$element): void {
    foreach (Element::children($element) as $key) {
      if ($key === '_options') {
        $element[$key]['#access'] = FALSE;
        continue;
      }
      if (is_array($element[$key])) {
        $this->hideOptionControls($element[$key]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    // No entity building is needed; this form never persists to config.
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    // Intentionally empty — values are stored as preview overrides, not config.
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    // We never block a refresh on validation errors — this is a live preview.
    if (($trigger['#op'] ?? NULL) === 'refresh') {
      $form_state->clearErrors();
    }

    $values = [];
    $original_values = $form_state->get('original_values') ?? [];
    foreach ($this->entity->getPropShapes() as $propName => $shape) {
      if (!isset($form['values'][$propName])) {
        continue;
      }
      $subform_state = SubformState::createForSubform($form['values'][$propName], $form, $form_state);
      $originalValue = $original_values['props'][$propName]['value'] ?? [];
      $shape->validateForm($form['values'][$propName], $subform_state);
      $value = $subform_state->getValues();
      $values['props'][$propName]['ref'] = $shape->getRef();
      $values['props'][$propName]['value'] = $shape->massageFormValues($value, $originalValue, $form['values'][$propName], $subform_state);
      if (!$shape->isIterable() && !empty($values['props'][$propName]['value'])) {
        $values['props'][$propName]['value'] += $originalValue;
      }
      $values['props'][$propName]['options'] = $shape->getNestedOptions();
    }

    // Stash for the submit handler.
    $form_state->set('preview_values', $values);
    return $this->entity;
  }

  /**
   * Submit handler for the (debounced) live refresh.
   */
  public function submitRefresh(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    $this->entity->setPreviewValues($form_state->get('preview_values') ?? []);
  }

  /**
   * Ajax callback for the live refresh: reload the preview iframe(s).
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state) {
    $form['#old_build_id'] = $form['#build_id'];
    $response = new AjaxResponse();
    $response->addCommand(new InstanceComponentManageIframeCommand('#' . ComponentManageHelper::getId($this->entity) . ' iframe'));
    return $response;
  }

  /**
   * Submit handler for the reset button: clear all preview overrides.
   */
  public function submitReset(array $form, FormStateInterface $form_state) {
    $this->entity->resetPreviewValues();
    $form_state->setRedirectUrl(Url::fromRoute('<current>'));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // This form never saves the entity.
    return 0;
  }

}
