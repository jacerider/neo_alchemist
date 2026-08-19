<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets a developer render neighbor components in the SDC preview workspace.
 *
 * Two selects (Above / Below) choose Alchemist SDCs to render as siblings
 * around the previewed component so spacing — including the same-background
 * `component-bg` collapse — can be tested against real neighbors. The selection
 * is stored as a cache-backed preview context on the (transient) component and
 * the preview iframes are reloaded to reflect it.
 *
 * @see \Drupal\neo_alchemist\Form\ComponentStyleForm
 */
final class SdcPreviewContextForm extends EntityForm {

  use IconTrait;

  /**
   * The component entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The SDC plugin manager.
   *
   * Not `private readonly`: a form object is serialized into the form cache,
   * and DependencySerializationTrait::__sleep() swaps services for their IDs
   * using get_object_vars() from FormBase's scope, which cannot see a private
   * property declared in a subclass — the manager would be serialized whole,
   * dragging its cache backend and discovery into every cached form.
   *
   * @var \Drupal\Core\Theme\ComponentPluginManager
   */
  protected $sdcPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->sdcPluginManager = $container->get('plugin.manager.sdc');
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
    $form['#id'] = 'neo-component-context-form';
    $form['#neo_entity_form'] = FALSE;

    $context = $this->entity->getPreviewContext();
    $options = $this->getComponentOptions();

    $form['context'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#neo_align' => 'inline',
      '#neo_size' => 'min',
    ];
    $form['context']['above'] = [
      '#type' => 'select',
      '#title' => $this->icon('Above', 'arrow-to-top'),
      '#options' => $options,
      '#default_value' => $context['above'] ?? '',
      '#neo_align' => 'inline',
      '#neo_size' => 'xs',
      '#ajax' => [
        'callback' => '::ajaxContext',
      ],
    ];
    $form['context']['below'] = [
      '#type' => 'select',
      '#title' => $this->icon('Below', 'arrow-to-bottom'),
      '#options' => $options,
      '#default_value' => $context['below'] ?? '',
      '#neo_align' => 'inline',
      '#neo_size' => 'xs',
      '#ajax' => [
        'callback' => '::ajaxContext',
      ],
    ];

    return $form;
  }

  /**
   * Builds the select options of Alchemist-enabled SDCs.
   *
   * @return array
   *   Options keyed by SDC id, prefixed with an empty "- None -" option.
   */
  protected function getComponentOptions(): array {
    $options = ['' => $this->t('- None -')];
    $definitions = array_filter($this->sdcPluginManager->getDefinitions(), fn ($definition) => !empty($definition['neo']));
    uasort($definitions, fn ($a, $b) => strnatcasecmp($a['name'] ?? '', $b['name'] ?? ''));
    foreach ($definitions as $id => $definition) {
      $options[$id] = $definition['name'] ?? $id;
    }
    return $options;
  }

  /**
   * Ajax callback: store the chosen neighbors and reload the preview iframes.
   */
  public function ajaxContext(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $above = $form_state->getValue(['context', 'above']) ?: NULL;
    $below = $form_state->getValue(['context', 'below']) ?: NULL;
    $this->entity->setPreviewContext($above, $below);
    if ($manageId = $form_state->get('neo_component_manage_id')) {
      $response->addCommand(new InstanceComponentManageIframeCommand('#' . $manageId . ' iframe'));
    }
    return $response;
  }

  /**
   * {@inheritdoc}
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
