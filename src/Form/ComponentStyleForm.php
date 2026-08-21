<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentStyleForm extends EntityForm {

  use IconTrait;

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
    $form['#attributes']['class'][] = 'border-l-2 pl-2';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $form['#id'] = 'neo-component-style-form';
    $form['#neo_entity_form'] = FALSE;

    $form['styles'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#neo_align' => 'inline',
      '#neo_size' => 'min',
    ];
    $shapes = array_filter($this->entity->getPropShapes(), fn ($shape) => $shape->access('manage_value'));
    foreach ($shapes as $propName => $shape) {
      if ($shape instanceof ComponentShapeStylePluginInterface) {
        if (!$shape->access('update')) {
          continue;
        }
        $options = $shape->getFieldOptions();
        if (!$shape->isRequired()) {
          $options = array_merge(['' => $this->t('- Default -')], $options);
        }
        if ($options) {
          $form['styles'][$propName] = [
            '#type' => 'select',
            '#title' => $shape->getTitle(),
            '#options' => $options,
            '#default_value' => $shape->getValue(),
            '#description' => $shape->getDescription(),
            '#shape_id' => $shape->id(),
            '#neo_align' => 'inline',
            '#neo_size' => 'xs',
            '#ajax' => [
              'callback' => '::ajaxStyle',
            ],
          ];
        }
      }
    }

    $form['styles']['reset'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['neo-component-style-form--reset flex items-center gap-2'],
      ],
    ];

    $form['styles']['reset']['alert'] = [
      '#type' => 'neo_icon',
      '#title' => 'Alert',
      '#icon' => 'exclamation-triangle',
      '#icon_only' => TRUE,
      '#icon_attributes' => [
        'class' => 'text-alert text-sm',
      ],
      '#tooltip' => $this->t('The component is being previewed with style overrides. You can click the @icon button to reset the preview styles.', [
        '@icon' => $this->icon('Reset Preview Styles', 'undo')->iconOnly(),
      ]),
    ];

    $form['styles']['reset']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->icon('Reset Preview Styles', 'undo')->iconOnly(),
      '#description' => $this->t('Reset Preview Styles'),
      '#neo_size' => 'xs',
      '#submit' => ['::submitReset'],
      '#attributes' => [
        'class' => ['neo-component-style-form--reset'],
      ],
    ];

    if (!$this->sdcPreviewStore->hasStyles($this->entity)) {
      $form['styles']['reset']['#attributes']['style'] = 'display: none;';
    }
    elseif (!$form_state->get('neo_component_style_changed')) {
      $form_state->set('neo_component_style_changed', TRUE);
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
        $this->sdcPreviewStore->setStyle($this->entity, $shapeId, $shapeValue);
        $response->addCommand(new InstanceComponentManageIframeCommand('#' . $manageId . ' iframe'));
      }
    }

    $response->addCommand(new CssCommand('.neo-component-style-form--reset', [
      'display' => '',
    ]));

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
