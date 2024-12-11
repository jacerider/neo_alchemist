<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValueProvider;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValueProvider;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProviderPluginBase;
use Drupal\neo_alchemist\FieldMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValueProvider(
  id: 'entity',
  label: new TranslatableMarkup('Entity'),
  description: new TranslatableMarkup('Provide values from entity fields.'),
  ref_types: [
    '!' . ComponentShapePluginInterface::OBJECT,
  ],
  entity_types: ['*'],
  weight: 5,
)]
final class EntityValueProvider extends ComponentValueProviderPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * Flag to indicate if the value has been set.
   *
   * @var bool
   */
  protected $hasEntityValue = FALSE;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\FieldMatcher
   */
  protected FieldMatcher $fieldMatcher;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    FieldMatcher $field_matcher
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->fieldMatcher = $field_matcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('neo_alchemist.field_matcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'field' => '',
      'override' => FALSE,
      'override_empty' => FALSE,
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function providerForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Field'),
      '#description' => $this->t('Select the field to use as the value.'),
      '#options' => $this->fieldMatcher->getMatchesAsOptions($this->shape),
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->configuration['field'],
      '#required' => TRUE,
    ];
    $form['override'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow override when value is found'),
      '#description' => $this->t('Will allow this value to be changed from the value provided by the entity. If not checked, the value provided by the entity will be used and will not be able to be changed. This will stop any following value providers from being processed.'),
      '#default_value' => $this->configuration['override'],
    ];
    $form['override_empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow override when value is empty'),
      '#description' => $this->t('Will allow this value to be changed from the value provided by the entity. If not checked, the value will remain empty and will not be able to be changed. This will stop any following value providers from being processed.'),
      '#default_value' => $this->configuration['override_empty'],
    ];
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function providerValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('override', !empty($form_state->getValue('override')));
    $form_state->setValue('override_empty', !empty($form_state->getValue('override_empty')));
  }

  /**
   * {@inheritdoc}
   *
   * Only allow processing if the entity is not new.
   */
  public function allowProcessing(string $op): bool {
    return match($this->shape->getScope()) {
      'field' => match($op) {
        'default' => !$this->shape->getEntity()->isNew(),
        default => TRUE,
      },
      default => !$this->shape->getEntity()->isNew(),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    $value = $this->fieldMatcher->getEntityValue($this->shape->getEntity(), $this->configuration['field']);
    $this->hasEntityValue = !empty($value);
    $this->stopFurtherProcessing();
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return match(TRUE) {
      $this->shape->getScope() === 'field' => !empty($this->configuration['override_empty']) || !empty($this->configuration['override']),
      !$this->hasEntityValue && empty($this->configuration['override_empty']) => FALSE,
      $this->hasEntityValue && empty($this->configuration['override']) => FALSE,
      default => parent::isEditable(),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$element, FormStateInterface $form_state) {
    return;
    $fieldDefinition = $this->fieldMatcher->getFieldDefinition($this->shape, $this->configuration['field']);
    if (!$fieldDefinition) {
      return;
    }
    $parents = $element['widget']['#parents'];
    $overrideParents = array_merge(['override'], $parents);
    $id = Html::getId('widget-container-' . implode('-', $parents));
    $element = [
      '#type' => 'fieldset',
      '#id' => $id,
      '#title' => $this->shape->getTitle(),
      '#parents' => $element['#parents'],
      'widget' => $element,
    ];
    $entityTypeLabel = (string) $this->shape->getEntity()->getEntityType()->getLabel();
    $title = $this->t('Override %label Value', [
      '%label' => $entityTypeLabel,
    ]);
    $description = [
      $this->t('The default value of this property is provided by the %field field of the %label.', [
        '%label' => strtolower($entityTypeLabel),
        '%field' => $fieldDefinition->getLabel(),
      ]),
    ];
    if ($this->shape->getScope() === 'field') {
      $description[] = $this->t('A value can be set for this property that will override the default value provided by the %field field. This property is configured so that the override will happen when @when.', [
        '%label' => strtolower($entityTypeLabel),
        '%field' => $fieldDefinition->getLabel(),
        '@when' => match(TRUE) {
          !empty($this->configuration['override_empty']) && empty($this->configuration['override']) => $this->t('the value is empty'),
          !empty($this->configuration['override']) && empty($this->configuration['override_empty']) => $this->t('a value is found'),
          default => $this->t('the value is empty or a value is found'),
        },
      ]);
    }
    elseif (!empty($this->configuration['override_empty']) && !empty($this->configuration['override'])) {
      $description[] = $this->t('A value can be set for this property that will override the value provided by the %field field.', [
        '%label' => strtolower($entityTypeLabel),
        '%field' => $fieldDefinition->getLabel(),
      ]);
    }
    elseif ($this->hasEntityValue) {
      $description[] = $this->t('The %field field currently has a value. A value can be set for this property that will override the value provided by the %field field.', [
        '%label' => strtolower($entityTypeLabel),
        '%field' => $fieldDefinition->getLabel(),
      ]);
    }
    else {
      $title = $this->t('Set Value');
      $description[] = $this->t('The %field field does not currently have a value. A value can be set for this property that will be used as long as the %field field remains empty.', [
        '%label' => strtolower($entityTypeLabel),
        '%field' => $fieldDefinition->getLabel(),
      ]);
    }
    $element['#description'] = implode(' ', $description);
    $element['#element_validate'][] = [static::class, 'widgetFormValidate'];

    $hasOverrideValue = !is_null($this->shape->getOverrideValue());
    $element['override'] = [
      '#type' => 'checkbox',
      '#title' => $title,
      '#default_value' => $hasOverrideValue,
      '#weight' => -100,
      '#parents' => $overrideParents,
      '#ajax' => [
        'callback' => [static::class, 'widgetFormAjax'],
        'wrapper' => $id,
      ],
    ];
    $element['widget']['#access'] = $hasOverrideValue;
  }

  /**
   * Ajax callback for the widget form element.
   *
   * This method returns the widget form element when the override checkbox is
   * checked.
   *
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The widget form element.
   */
  public static function widgetFormAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#parents'], 1);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Validates the widget form element.
   *
   * This method checks the override value of the form element. If the override
   * value is not set, it unsets the corresponding value in the form state.
   *
   * @param array $element
   *   The form element to validate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function widgetFormValidate(array &$element, FormStateInterface $form_state) {
    $overrideValue = $form_state->getValue($element['override']['#parents']);
    if (!$overrideValue) {
      $parents = array_slice($element['override']['#parents'], 1);
      $form_state->unsetValue($parents);
    }
  }

}
