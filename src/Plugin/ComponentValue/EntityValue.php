<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\FieldMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'entity',
  label: new TranslatableMarkup('Entity'),
  description: new TranslatableMarkup('Provide values from entity fields.'),
  group: 'providers',
  ref_types: [
    '!' . ComponentShapePluginInterface::OBJECT,
  ],
  entity_types: ['*'],
  weight: 5,
)]
final class EntityValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

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
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
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
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('override', !empty($form_state->getValue('override')));
    $form_state->setValue('override_empty', !empty($form_state->getValue('override_empty')));
  }

  /**
   * {@inheritdoc}
   *
   * Only allow processing if the entity is not new.
   */
  public function isAllowed(string $op): bool {
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
  public function provideOverrideValue(mixed $value): mixed {
    $overrideEmpty = !empty($this->configuration['override_empty']);
    $override = !empty($this->configuration['override']);
    $hasOverrideValue = !empty($this->shape->getOverrideValue());

    $entityValue = $this->fieldMatcher->getEntityValue($this->shape->getEntity(), $this->configuration['field']);
    $hasValue = !empty($entityValue);
    $this->hasEntityValue = $hasValue;

    if ($this->shape->isNew() && !$this->shape->isRebuilding()) {
      // On a brand new component, we set the option default to true so that
      // it uses the entity value by default.
      $optionDefault = $this->shape->getOptionDefault();
      if ($optionDefault->isAllowed()) {
        $optionDefault->setValue(empty($hasOverrideValue));
      }
      if ($parentShape = $this->shape->getParentShape()) {
        if ($parentShape instanceof ComponentShapeChildrenPluginInterface && $parentShape->isSingleProp()) {
          $parentOptionDefault = $parentShape->getOptionDefault();
          if ($parentOptionDefault->isAllowed()) {
            // If we are a single property, we set the parent shape to use the
            // default.
            $optionDefault->setValue(empty($hasOverrideValue));
            $parentOptionDefault->setValue(empty($hasOverrideValue));
          }
        }
      }
    }

    // No matter what, if we don't allow overrideing, we return the value.
    if (!$overrideEmpty && !$override) {
      $this->stopFurtherProcessing();
      return $entityValue;
    }

    if ($this->shape->getOptionDefault()->isEnabled()) {
      if (!$overrideEmpty && !$override) {
        $this->stopFurtherProcessing();
        return $entityValue;
      }
      if (!$overrideEmpty && !$hasValue) {
        $this->stopFurtherProcessing();
        return $entityValue;
      }
      if (!$override && $hasValue) {
        $this->stopFurtherProcessing();
        return $entityValue;
      }
      if ($hasValue) {
        return $entityValue;
      }
    }

    if ($hasValue && !$hasOverrideValue) {
      $this->stopFurtherProcessing();
      return $entityValue;
    }

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
    $fieldDefinition = $this->fieldMatcher->getFieldDefinition($this->shape, $this->configuration['field']);
    if (!$fieldDefinition || !isset($element['_options']['value_default'])) {
      return;
    }
    $entityTypeLabel = (string) $this->shape->getEntity()->getEntityType()->getLabel();
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
      $description[] = $this->t('The %field field does not currently have a value. A value can be set for this property that will be used as long as the %field field remains empty.', [
        '%label' => strtolower($entityTypeLabel),
        '%field' => $fieldDefinition->getLabel(),
      ]);
    }
    $element['_options']['value_default']['#description'] = implode(' ', $description);
  }

}
