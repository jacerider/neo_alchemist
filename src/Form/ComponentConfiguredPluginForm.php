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
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindInterface;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindRepository;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits one configured plugin on a component.
 *
 * Replaces the access and filter forms, which were 200 and 270 lines with the
 * filter-only fields accounting for the difference. What is left here is the
 * part that was the same in both: pick a plugin from the definitions this
 * component supports, build that plugin's settings form, and stage the result
 * on the component. The kind supplies the rest.
 *
 * The form's operation names the kind, so `neo_component`'s `access` and
 * `filter` form handlers both point here.
 *
 * @see \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindInterface
 */
final class ComponentConfiguredPluginForm extends EntityForm {

  use ComponentAjaxFormHelperTrait;
  use LimitedSubmissionTrait;
  use StringTranslationTrait;

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The kind repository.
   *
   * @var \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindRepository
   */
  protected $kinds;

  /**
   * The kind being configured.
   *
   * @var \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindInterface
   */
  protected $kind;

  /**
   * The configured plugin being added or edited.
   *
   * @var \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface
   */
  protected $wrapper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('neo_alchemist.configured_plugin_kinds'),
    );
  }

  /**
   * Constructs the form.
   *
   * @param \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindRepository $kinds
   *   The kind repository.
   */
  public function __construct(ConfiguredPluginKindRepository $kinds) {
    $this->kinds = $kinds;
  }

  /**
   * The kind this form is editing.
   *
   * @return \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindInterface
   *   The kind, resolved from the entity form operation.
   */
  protected function getKind(): ConfiguredPluginKindInterface {
    $this->kind = $this->kind ?? $this->kinds->get($this->getOperation());
    return $this->kind;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->wrapper = $this->wrapper ?? $form_state->get($this->getKind()->id());
    assert($this->wrapper instanceof ConfiguredPluginWrapperInterface);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $kind = $this->getKind();
    $id = Html::getId('neo-component-' . $kind->id() . '-' . $this->wrapper->uuid());
    $form['#id'] = $id;
    if (!$form_state->get('new')) {
      $form_state->set('new', $this->wrapper->isNew());
    }
    $ajax = [
      'callback' => [static::class, 'refreshAjax'],
      'wrapper' => $id,
    ];

    // Only the plugins this component supports. Before the manager base owned
    // this narrowing, the filter form listed every definition, and a site
    // builder could configure a filter that does nothing.
    $manager = $kind->getManager();
    $definitions = $manager->getFilteredDefinitionsFromComponent($this->entity);
    // …except the one already configured here. Narrowing changes what can be
    // ADDED; a rule saved before its plugin stopped applying keeps its entry
    // and keeps running, so dropping it from this select would leave the site
    // builder on a required field with nothing selected and no way to save.
    $configured = $this->wrapper->getPluginId();
    if ($configured !== '' && !isset($definitions[$configured]) && $manager->hasDefinition($configured)) {
      $definitions[$configured] = $manager->getDefinition($configured);
    }
    $options = array_map(static fn (array $definition) => $definition['label'], $definitions);
    asort($options);
    $form['plugin_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Plugin'),
      '#required' => TRUE,
      '#options' => $options,
      '#default_value' => $this->wrapper->getPluginId(),
      '#empty_option' => $this->t('- Select -'),
      '#weight' => 0,
      '#ajax' => $ajax,
    ];

    if ($plugin = $this->wrapper->getPlugin()) {
      if ($description = ($plugin->getPluginDefinition()['description'] ?? NULL)) {
        $form['plugin_description'] = [
          '#type' => 'markup',
          '#markup' => $this->t('<small>%description</small>', ['%description' => $description]),
          '#weight' => 1,
        ];
      }
      $form['plugin_settings'] = [
        '#type' => $kind->pluginSettingsElementType(),
        '#title' => $this->t('Plugin Settings'),
        '#tree' => TRUE,
        '#weight' => 2,
        '#form_ajax' => $ajax,
      ];
      $subform_state = SubformState::createForSubform($form['plugin_settings'], $form, $form_state);
      $form['plugin_settings'] = $plugin->buildConfigurationForm($form['plugin_settings'], $subform_state, $form);
      $form['plugin_settings']['#access'] = !empty(Element::children($form['plugin_settings']));
    }

    return $kind->buildForm($form, $form_state, $this->wrapper, $ajax);
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

    // Delete submits with validation limited to nothing, so none of the values
    // read below are present. Committing from that empty value set would clear
    // the plugin id and its settings on the way to removing the wrapper — the
    // outcome happened to be the same, but only because ::delete() then threw
    // the result away.
    if ($this->isLimitedSubmission($form_state)) {
      return;
    }

    $kind = $this->getKind();
    $this->wrapper->setPluginId($form_state->getValue('plugin_id') ?: '');

    $plugin = $this->wrapper->getPlugin();
    if ($plugin && !empty($form['plugin_settings'])) {
      $subform_state = SubformState::createForSubform($form['plugin_settings'], $form, $form_state);
      $plugin->validateConfigurationForm($form['plugin_settings'], $subform_state);
      $this->wrapper->setPluginSettings($subform_state->getValues());
    }

    $kind->submitForm($form, $form_state, $this->wrapper);
    $this->wrapper = $kind->stage($this->entity, $this->wrapper);
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
    return $this->saveAndReport($form, $form_state, $form_state->get('new') ? $this->t('Added') : $this->t('Updated'));
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $form, FormStateInterface $form_state): int {
    $this->getKind()->delete($this->entity, (string) $this->wrapper->uuid());
    return $this->saveAndReport($form, $form_state, $this->t('Deleted'));
  }

  /**
   * Persists the component and reports what happened to the configured plugin.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $op
   *   Added, Updated or Deleted.
   *
   * @return int
   *   The save result.
   */
  private function saveAndReport(array $form, FormStateInterface $form_state, $op): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('@op @kind %label.', [
      '@op' => $op,
      '@kind' => $this->getKind()->label(),
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
