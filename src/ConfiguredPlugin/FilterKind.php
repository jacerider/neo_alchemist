<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ConfiguredPlugin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\ComponentFilterFactory;
use Drupal\neo_alchemist\ComponentFilterInterface;
use Drupal\neo_alchemist\ComponentFilterPluginManager;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ConfiguredPluginManagerBase;
use Drupal\neo_alchemist\ConfiguredPluginWrapperInterface;

/**
 * Filters as a configured-plugin kind.
 *
 * A filter is an access rule plus five fields: a title and description (it is
 * a per-placement parameter a content author sees), a default value built by
 * the plugin itself, and the editable/required pair that decides whether an
 * instance may override that value. Those five are supplied here rather than
 * by forking the shared form.
 */
final class FilterKind implements ConfiguredPluginKindInterface {

  use StringTranslationTrait;

  /**
   * Constructs the kind.
   *
   * @param \Drupal\neo_alchemist\ComponentFilterPluginManager $manager
   *   The filter plugin manager.
   * @param \Drupal\neo_alchemist\ComponentFilterFactory $factory
   *   The filter wrapper factory.
   */
  public function __construct(
    protected ComponentFilterPluginManager $manager,
    protected ComponentFilterFactory $factory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'filter';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup|string {
    return $this->t('filter');
  }

  /**
   * {@inheritdoc}
   */
  public function getManager(): ConfiguredPluginManagerBase {
    return $this->manager;
  }

  /**
   * {@inheritdoc}
   */
  public function create(ComponentInterface $component): ConfiguredPluginWrapperInterface {
    return $this->factory->get($component);
  }

  /**
   * {@inheritdoc}
   */
  public function load(ComponentInterface $component, string $uuid): ?ConfiguredPluginWrapperInterface {
    return $component->getFilter($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function stage(ComponentInterface $component, ConfiguredPluginWrapperInterface $wrapper): ConfiguredPluginWrapperInterface {
    assert($wrapper instanceof ComponentFilterInterface);
    return $component->setFilter($wrapper);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(ComponentInterface $component, string $uuid): void {
    $component->deleteFilter($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function pluginSettingsElementType(): string {
    return 'fieldset';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ConfiguredPluginWrapperInterface $wrapper, array $ajax): array {
    assert($wrapper instanceof ComponentFilterInterface);

    // Above the plugin select: what a content author sees this filter as.
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $wrapper->label(),
      '#required' => TRUE,
      '#weight' => -20,
    ];
    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $wrapper->getDescription(),
      '#weight' => -10,
    ];

    // Below the plugin's settings: the default value, which only the plugin
    // knows how to build a widget for.
    if ($wrapper->getPlugin()) {
      $form['value'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Default Value'),
        '#tree' => TRUE,
        '#weight' => 5,
      ];

      $subform_state = SubformState::createForSubform($form['value'], $form, $form_state);
      $form['value']['value'] = [
        '#type' => 'container',
        '#access' => !$form_state->getValue(['value', '_empty'], TRUE) || !$wrapper->isEmpty() || $wrapper->isRequired(),
      ];
      $form['value']['value'] = $wrapper->buildForm($form['value']['value'], $subform_state, TRUE);
      $form['value']['#access'] = !empty(Element::children($form['value']['value']));
      $form['value']['_empty'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Empty'),
        '#description' => $this->t('Do not provide a default value of @label', ['@label' => $wrapper->label()]),
        '#neo_region' => 'legend_end',
        '#wrapper_attributes' => [
          'class' => ['!m-0'],
        ],
        '#default_value' => $wrapper->isEmpty(),
        '#access' => !$wrapper->isRequired(),
        '#neo_size' => 'xs',
        '#ajax' => $ajax,
      ];
    }

    $form['editable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow Edit'),
      '#description' => $this->t('Allow the value of this filter to be changed per component instance.'),
      '#default_value' => $wrapper->isEditable(),
      '#weight' => 10,
      '#ajax' => $ajax,
    ];

    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required'),
      '#description' => $this->t('Require this filter to be set for the component to be valid.'),
      '#default_value' => $wrapper->isRequired(),
      '#weight' => 20,
      '#ajax' => $ajax,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, ConfiguredPluginWrapperInterface $wrapper): void {
    assert($wrapper instanceof ComponentFilterInterface);
    $wrapper->setTitle($form_state->getValue('title'));
    $wrapper->setDescription($form_state->getValue('description'));
    $wrapper->setEditable((bool) $form_state->getValue('editable'));
    $wrapper->setRequired((bool) $form_state->getValue('required'));

    // The default value is read last: whether it is collected at all depends
    // on the required flag set immediately above.
    $value = NULL;
    if (($wrapper->isRequired() || !$form_state->getValue(['value', '_empty'], FALSE)) && !empty($form['value']['value'])) {
      $subform_state = SubformState::createForSubform($form['value']['value'], $form, $form_state);
      $wrapper->validateForm($form['value']['value'], $subform_state);
      $value = $wrapper->massageFormValue($subform_state->getValues(), $form['value']['value'], $subform_state);
    }
    $wrapper->setDefaultValue($value);
  }

}
