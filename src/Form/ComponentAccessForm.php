<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\ComponentAccessInterface;
use Drupal\neo_alchemist\ComponentAccessPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentAccessForm extends EntityForm {

  use ComponentAjaxFormHelperTrait;
  use StringTranslationTrait;

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The access manager.
   *
   * @var \Drupal\neo_alchemist\ComponentAccessPluginManager
   */
  protected $accessManager;

  /**
   * The access.
   *
   * @var \Drupal\neo_alchemist\ComponentAccessInterface
   */
  protected $access;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.neo_component_access'),
    );
  }

  /**
   * ComponentAccessForm constructor.
   *
   * @param \Drupal\neo_alchemist\ComponentAccessPluginManager $access_manager
   *   The access manager.
   */
  public function __construct(ComponentAccessPluginManager $access_manager) {
    $this->accessManager = $access_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->access = $this->access ?? $form_state->get('access');
    assert($this->access instanceof ComponentAccessInterface);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $id = Html::getId('neo-component-access-' . $this->access->uuid());
    $form['#id'] = $id;
    if (!$form_state->get('new')) {
      $form_state->set('new', $this->access->isNew());
    }

    $pluginId = $this->access->getPluginId();
    $options = array_map(fn($definition) => $definition['label'], $this->accessManager->getDefinitions());
    asort($options);
    $form['plugin_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Plugin'),
      '#required' => TRUE,
      '#options' => $options,
      '#default_value' => $pluginId,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $id,
      ],
    ];

    if ($plugin = $this->access->getPlugin()) {
      if ($description = ($plugin->getPluginDefinition()['description'] ?? NULL)) {
        $form['plugin_description'] = [
          '#type' => 'markup',
          '#markup' => $this->t('<small>%description</small>', ['%description' => $description]),
        ];
      }
      $form['plugin_settings'] = [
        '#type' => 'container',
        '#title' => $this->t('Plugin Settings'),
        '#tree' => TRUE,
        '#form_ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
      $subform_state = SubformState::createForSubform($form['plugin_settings'], $form, $form_state);
      $form['plugin_settings'] = $plugin->buildConfigurationForm($form['plugin_settings'], $subform_state, $form);
      $form['plugin_settings']['#access'] = !empty(Element::children($form['plugin_settings']));
    }

    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $this->access->setPluginId($form_state->getValue('plugin_id') ?: '');

    $plugin = $this->access->getPlugin();
    if ($plugin && !empty($form['plugin_settings'])) {
      $subform_state = SubformState::createForSubform($form['plugin_settings'], $form, $form_state);
      $plugin->validateConfigurationForm($form['plugin_settings'], $subform_state);
      $this->access->setPluginSettings($subform_state->getValues());
    }

    $this->access = $this->entity->setAccess($this->access);
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
    if (!$form_state->get('new')) {
      $actions['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete'),
        '#limit_validation_errors' => [],
        '#submit' => ['::submitForm', '::delete'],
      ];
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('@op access %label.', [
      '@op' => $form_state->get('new') ? $this->t('Added') : $this->t('Updated'),
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $form, FormStateInterface $form_state): int {
    $this->entity->deleteAccess($this->access->uuid());
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('@op access %label.', [
      '@op' => $this->t('Deleted'),
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
