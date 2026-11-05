<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Plugin\ComponentShape\StyleShapeBase;
use Drupal\neo_alchemist\Plugin\ComponentShape\StyleToggleShape;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentStyleForm extends EntityForm {

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The SDC preview workspace store.
   *
   * Not `private readonly`: a form object is serialized into the form cache,
   * and DependencySerializationTrait::__sleep() swaps services for their IDs
   * using get_object_vars() from FormBase's scope, which cannot see a private
   * property declared in a subclass.
   *
   * @var \Drupal\neo_alchemist\EditorState\SdcPreviewStore
   */
  protected $sdcPreviewStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->sdcPreviewStore = $container->get('neo_alchemist.sdc_preview_store');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->entity));
    $form['#attached']['library'][] = 'neo_alchemist/component.ajax';
    $form['#neo_style'] = 'default';
    $form['#neo_size'] = 'xs';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $form['#id'] = 'neo-component-style-form';
    $form['#neo_entity_form'] = FALSE;

    $shapes = array_filter($this->entity->getPropShapes(), fn ($shape) => $shape->access('manage_value'));
    $controls = [];
    $overridden = 0;
    foreach ($shapes as $propName => $shape) {
      if (!$shape instanceof ComponentShapeStylePluginInterface || !$shape->access('update')) {
        continue;
      }
      if ($shape instanceof StyleToggleShape) {
        // An on/off style has no options to list, so a select would offer
        // nothing but "- Default -".
        $control = [
          '#type' => 'checkbox',
          '#default_value' => (bool) $shape->getValue(),
        ];
      }
      else {
        $options = $shape->getFieldOptions();
        if (!$options) {
          continue;
        }
        if (!$shape->isRequired()) {
          $options = array_merge(['' => $this->t('- Default -')], $options);
        }
        $control = [
          '#type' => 'select',
          '#options' => $options,
          '#default_value' => $shape->getValue(),
        ];
      }
      $isOverridden = $shape instanceof StyleShapeBase && $shape->isPreviewOverridden();
      $overridden += (int) $isOverridden;
      $controls[$propName] = $control + [
        '#title' => $shape->getTitle(),
        '#description' => $shape->getDescription(),
        '#shape_id' => $shape->id(),
        '#neo_align' => 'inline',
        '#neo_size' => 'xs',
        '#wrapper_attributes' => [
          'class' => array_filter([
            'neo-component-style-form--row',
            $isOverridden ? 'is-overridden' : NULL,
          ]),
          'data-neo-style-row' => $propName,
        ],
        '#ajax' => [
          'callback' => '::ajaxStyle',
        ],
      ];
    }
    if (!$controls) {
      return $form;
    }

    $form['styles'] = ComponentManageHelper::buildBandPopover(
      'neo-component-style-trigger',
      $this->t('Styles'),
      'palette',
      $overridden,
      $this->t('Preview styles'),
    ) + ['#tree' => TRUE];
    $form['styles'] += $controls;

    // Cloned into the panel's footer by the popover's smart actions.
    $form['styles']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset overrides'),
      '#limit_validation_errors' => [],
      '#submit' => ['::submitReset'],
      '#attributes' => [
        'class' => ['neo-component-style-form--reset', 'btn', 'btn-xs'],
      ],
    ];
    if (!$this->sdcPreviewStore->hasStyles($this->entity)) {
      $form['styles']['reset']['#attributes']['style'] = 'display: none;';
    }

    return $form;
  }

  /**
   * Ajax callback for the style form.
   */
  public function ajaxStyle(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    // Delete all status messages.
    $this->messenger()->deleteByType('warning');

    if ($manageId = $form_state->get('neo_component_manage_id')) {
      $trigger = $form_state->getTriggeringElement();
      $shapeId = $trigger['#shape_id'] ?? NULL;
      $shapeValue = $form_state->getValue($trigger['#parents'], NULL);
      if ($shapeId && $shapeValue !== NULL) {
        // A checkbox (an on/off style) submits an int; the store keeps the
        // form's values as strings.
        $this->sdcPreviewStore->setStyle($this->entity, $shapeId, (string) $shapeValue);
        $response->addCommand(new InstanceComponentManageIframeCommand('#' . $manageId . ' iframe'));
      }
    }

    $response->addCommand(new CssCommand('.neo-component-style-form--reset', [
      'display' => '',
    ]));

    // The style just changed may now match the saved one, or differ from it,
    // so the count and every row's marker are recomputed rather than bumped.
    $overridden = 0;
    foreach ($this->entity->getPropShapes() as $propName => $shape) {
      if (!$shape instanceof StyleShapeBase) {
        continue;
      }
      $isOverridden = $shape->isPreviewOverridden();
      $overridden += (int) $isOverridden;
      $response->addCommand(new InvokeCommand(
        '[data-neo-style-row="' . $propName . '"]',
        'toggleClass',
        ['is-overridden', $isOverridden],
      ));
    }
    $response->addCommand(new ReplaceCommand(
      '#neo-component-style-trigger .neo-alchemist--band-popover-count',
      ComponentManageHelper::buildBandPopoverCount($overridden),
    ));

    return $response;
  }

  /**
   * Ajax callback for the style form.
   */
  public function submitReset(array $form, FormStateInterface $form_state) {
    $this->sdcPreviewStore->resetStyles($this->entity);
    $form_state->setRedirectUrl($this->entity->toUrl());
  }

  /**
   * Ajax callback for the style form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // There is no submit action for this form.
  }

  /**
   * Returns the action form element for the current entity form.
   */
  protected function actionsElement(array $form, FormStateInterface $form_state) {
    return NULL;
  }

}
