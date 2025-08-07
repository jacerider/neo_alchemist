<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\MatcherField;

/**
 * A trait for adding value matching capabilities to component value plugins.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ComponentValueMatchTrait {

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * {@inheritdoc}
   */
  public function defaultMatchConfiguration() {
    return [
      'field' => '',
      'field_properties' => [],
    ];
  }

  /**
   * Get the values for the shape matcher.
   */
  protected function getMatchOptions(): array {
    return $this->matcherField->getMatchesAsOptions($this->shape);
  }

  /**
   * Get the values for the shape matcher.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape plugin.
   * @param string|null $field
   *   The field to match.
   * @param array|null $properties
   *   Additional properties to pass to the matcher.
   * @param bool $published
   *   (optional) Whether to only return values from published entities.
   *
   * @return mixed
   *   The matched values.
   */
  protected function getMatchValue(ComponentShapePluginInterface $shape, ?string $field = NULL, ?array $properties = [], ?bool $published = TRUE): mixed {
    $field = $field ?? $this->configuration['field'] ?? '';
    if (!$field) {
      return NULL;
    }
    $properties = $properties ?? $this->configuration['field_properties'] ?? [];
    return $this->matcherField->getEntityValue(
      entity: $shape->getEntity(),
      key: $field,
      properties: $properties,
      published: $published,
      cacheableMetadata: $shape->getCacheableMetadata()
    );
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function buildMatchConfigurationForm($form, FormStateInterface $form_state): array {
    $wrapperId = $form['#id'];
    $field = $this->configuration['field'];

    $options = $this->getMatchOptions();
    $groups = array_keys($options);
    $groups = array_combine($groups, $groups);
    asort($groups);
    $group = $form_state->getValue('group', NULL);
    if (!$group) {
      foreach ($options as $optionGroup => $ops) {
        foreach ($ops as $key => $label) {
          if ($key === $field) {
            $group = $optionGroup;
            break 2;
          }
        }
      }
    }
    $form['group'] = [
      '#type' => 'select',
      '#title' => $this->t('Group'),
      '#description' => $this->t('Select the group to use as the value.'),
      '#options' => $groups,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $group,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshMatchAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($group) {
      $suboptions = $options[$group];
      $form['field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field'),
        '#description' => $this->t('Select the field to use as the value.'),
        '#options' => $suboptions,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $field,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [static::class, 'refreshMatchAjax'],
          'wrapper' => $wrapperId,
        ],
      ];
    }

    return $form;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function buildMatchPropertiesConfigurationForm($form, FormStateInterface $form_state): array {
    $field = $this->configuration['field'];
    $fieldDefinition = $this->matcherField->getFieldDefinition($this->shape, $field);
    $fieldProperties = $fieldDefinition->getFieldStorageDefinition()->getPropertyDefinitions();

    $form['field_properties'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field Properties'),
    ];
    foreach ($this->shape->getChildShapes() as $name => $childShape) {
      $shapeProperties = $childShape->getFieldItem()->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
      $shapeProperty = reset($shapeProperties);
      $options = array_map(function ($property) {
        return $property->getLabel();
      }, array_filter($fieldProperties, function ($property) use ($shapeProperty) {
        return $property->getDataType() === $shapeProperty->getDataType();
      }));
      $form['field_properties'][$name] = [
        '#type' => 'select',
        '#title' => $childShape->getTitle(),
        '#default_value' => $this->configuration['field_properties'][$name] ?? '',
        '#options' => $options,
        '#empty_option' => $this->t('- Select -'),
        '#required' => $childShape->isRequired(),
      ];
    }
    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function refreshMatchAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * The matcher.
   *
   * @return \Drupal\neo_alchemist\MatcherField
   *   The matcher.
   */
  protected function getMatcher(): MatcherField {
    if (!isset($this->matcherField)) {
      $this->matcherField = \Drupal::service('neo_alchemist.matcher_field');
    }
    return $this->matcherField;
  }

}
