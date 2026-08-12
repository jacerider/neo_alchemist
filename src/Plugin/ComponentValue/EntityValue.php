<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\MatcherField;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'entity',
  label: new TranslatableMarkup('Entity Field'),
  description: new TranslatableMarkup('Provide values from entity fields.'),
  group: 'providers',
  ref_types: [
    '!' . ComponentShapePluginInterface::OBJECT,
  ],
  entity_types: ['*'],
  weight: 5,
)]
final class EntityValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

  use DependencySerializationTrait;
  use ComponentValueMatchTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * Flag to indicate if the value has been set.
   *
   * @var bool
   */
  protected $hasEntityValue = FALSE;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    MatcherField $matcher_field,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->matcherField = $matcher_field;
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
      $container->get('neo_alchemist.matcher_field')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return $this->defaultMatchConfiguration() + [
      'field_assign' => FALSE,
    ] + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $field = $this->configuration['field'];

    $form = $this->buildMatchConfigurationForm($form, $form_state);

    if (
      $field &&
      $this->shape instanceof ComponentShapeChildrenPluginInterface
    ) {
      $fieldDefinition = $this->matcherField->getFieldDefinition($this->shape, $field);
      $fieldProperties = $fieldDefinition->getFieldStorageDefinition()->getPropertyDefinitions();
      if (count($fieldProperties)) {
        $assign = $this->configuration['field_assign'];
        $form['field_assign'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Manually assign properties'),
          '#description' => $this->t('Will allow you to assign properties to the field values.'),
          '#default_value' => $assign,
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
        if ($assign) {
          // Allow assigning properties to the field.
          $form = $this->buildMatchPropertiesConfigurationForm($form, $form_state);
        }
      }
    }

    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   *
   * Only allow processing if the entity is not new.
   */
  public function isAllowed(string $op): bool {
    if ($op === 'manage') {
      return !empty($this->matcherField->getMatchesAsOptions($this->shape));
    }
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
    $hasOverrideValue = !empty($this->shape->getOverrideValue());
    // When properties are assigned manually use that map; otherwise derive a
    // default mapping so children props (e.g. media/entity_reference) populate
    // automatically instead of silently resolving to nothing.
    $properties = $this->configuration['field_assign']
      ? $this->configuration['field_properties']
      : $this->getDefaultMatchProperties();
    $entityValue = $this->getMatchValueWithFallback($this->shape, $properties, FALSE);
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

    return $entityValue;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

}
