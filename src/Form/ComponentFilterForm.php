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
use Drupal\neo_alchemist\ComponentFilterInterface;
use Drupal\neo_alchemist\ComponentFilterPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentFilterForm extends EntityForm {

  use ComponentAjaxFormHelperTrait;
  use StringTranslationTrait;

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The filter manager.
   *
   * @var \Drupal\neo_alchemist\ComponentFilterPluginManager
   */
  protected $filterManager;

  /**
   * The filter.
   *
   * @var \Drupal\neo_alchemist\ComponentFilterInterface
   */
  protected $filter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.neo_component_filter'),
    );
  }

  /**
   * ComponentFilterForm constructor.
   *
   * @param \Drupal\neo_alchemist\ComponentFilterPluginManager $filter_manager
   *   The filter manager.
   */
  public function __construct(ComponentFilterPluginManager $filter_manager) {
    $this->filterManager = $filter_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->filter = $this->filter ?? $form_state->get('filter');
    assert($this->filter instanceof ComponentFilterInterface);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $id = Html::getId('neo-component-filter-' . $this->filter->uuid());
    $form['#id'] = $id;
    if (!$form_state->get('new')) {
      $form_state->set('new', $this->filter->isNew());
    }

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $this->filter->label(),
      '#required' => TRUE,
    ];

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $this->filter->getDescription(),
    ];

    $pluginId = $this->filter->getPluginId();
    $options = array_map(fn($definition) => $definition['label'], $this->filterManager->getDefinitions());
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

    if ($plugin = $this->filter->getPlugin()) {
      $form['plugin_settings'] = [
        '#type' => 'fieldset',
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

      $form['value'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Default Value'),
        '#tree' => TRUE,
      ];

      $subform_state = SubformState::createForSubform($form['value'], $form, $form_state);
      $form['value']['value'] = [
        '#type' => 'container',
        '#access' => !$form_state->getValue(['value', '_empty'], TRUE) || !$this->filter->isEmpty() || $this->filter->isRequired(),
      ];
      $form['value']['value'] = $this->filter->buildForm($form['value']['value'], $subform_state, TRUE);
      $form['value']['#access'] = !empty(Element::children($form['value']['value']));
      $form['value']['_empty'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Empty'),
        '#description' => $this->t('Do not provide a default value of @label', ['@label' => $this->filter->label()]),
        '#neo_fieldset_region' => 'legend_end',
        '#wrapper_attributes' => [
          'class' => ['!m-0'],
        ],
        '#default_value' => $this->filter->isEmpty(),
        '#access' => !$this->filter->isRequired(),
        '#neo_size' => 'xs',
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
    }

    $form['editable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow Edit'),
      '#description' => $this->t('Allow the value of this filter to be changed per component instance.'),
      '#default_value' => $this->filter->isEditable(),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $id,
      ],
    ];

    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required'),
      '#description' => $this->t('Require this filter to be set for the component to be valid.'),
      '#default_value' => $this->filter->isRequired(),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $id,
      ],
    ];

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
    $this->filter->setTitle($form_state->getValue('title'));
    $this->filter->setDescription($form_state->getValue('description'));
    $this->filter->setPluginId($form_state->getValue('plugin_id') ?: '');
    $this->filter->setEditable((bool) $form_state->getValue('editable'));
    $this->filter->setRequired((bool) $form_state->getValue('required'));

    $plugin = $this->filter->getPlugin();
    if ($plugin && !empty($form['plugin_settings'])) {
      $subform_state = SubformState::createForSubform($form['plugin_settings'], $form, $form_state);
      $plugin->validateConfigurationForm($form['plugin_settings'], $subform_state);
      $this->filter->setPluginSettings($subform_state->getValues());
    }

    $value = NULL;
    if (($this->filter->isRequired() || !$form_state->getValue(['value', '_empty'], FALSE)) && !empty($form['value']['value'])) {
      $subform_state = SubformState::createForSubform($form['value']['value'], $form, $form_state);
      $this->filter->validateForm($form['value']['value'], $subform_state);
      $value = $this->filter->massageFormValue($subform_state->getValues(), $form['value']['value'], $subform_state);
    }
    $this->filter->setDefaultValue($value);
    $this->filter = $this->entity->setFilter($this->filter);
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
    $this->messenger()->addStatus($this->t('@op filter %label.', [
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
    $this->entity->deleteFilter($this->filter->uuid());
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('@op filter %label.', [
      '@op' => $this->t('Deleted'),
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
