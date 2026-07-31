<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentPropRenderable;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\FieldFormatterTrait;
use Drupal\neo_alchemist\MatcherField;

/**
 * A trait for adding value matching capabilities to component value plugins.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ComponentValueMatchTrait {

  use FieldFormatterTrait;

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
      'render_field' => '',
      'render_field_format' => [],
    ];
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
  protected function getMatchValue(ComponentShapePluginInterface $shape, ?string $field = NULL, ?array $properties = NULL, ?bool $published = TRUE): mixed {
    $field = $field ?? $this->configuration['field'] ?? '';
    if (!$field) {
      return NULL;
    }

    if ($field === '_render') {
      return $this->getMatchRenderValue($shape, $published);
    }

    return $this->matcherField->getEntityValue(
      entity: $shape->getEntity(),
      key: $field,
      properties: $properties ?? $this->configuration['field_properties'] ?? [],
      published: $published,
      cacheableMetadata: $shape->getCacheableMetadata()
    );
  }

  /**
   * Derive the default child-shape to field-property mapping.
   *
   * When "Manually assign properties" is off, AUTOMATIC mode still needs a
   * mapping so each source field property reaches the child prop that expects
   * it. Without one, a field whose property names never match the child names
   * (e.g. an entity_reference/media field, whose only value is target_id)
   * resolves to nothing. This reconstructs, with no user interaction, the same
   * mapping the manual UI would produce.
   *
   * @return array
   *   A child-shape-name => field-property-name map, or an empty array when the
   *   shape is not a children shape or no bindable field is configured.
   */
  protected function getDefaultMatchProperties(): array {
    $field = $this->configuration['field'] ?? '';
    if (!$field || $field === '_render' || !$this->shape instanceof ComponentShapeChildrenPluginInterface) {
      return [];
    }
    // Fetch only the field definition. Passing published: FALSE avoids the
    // status gate nulling the item, and getEntityField() walks the entity
    // without calling getMatches()/getChildShapes(), so it is safe to call
    // during value resolution.
    $item = $this->matcherField->getEntityField(
      entity: $this->shape->getEntity(),
      key: $field,
      published: FALSE,
      cacheableMetadata: $this->shape->getCacheableMetadata()
    );
    if (!$item) {
      return [];
    }
    return $this->shape->getAutoMatchProperties($item->getFieldDefinition());
  }

  /**
   * Gets the rendered field value for a _render match.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape plugin.
   * @param bool|null $published
   *   Whether to only return values from published entities.
   *
   * @return \Drupal\neo_alchemist\ComponentPropRenderable|null
   *   The renderable prop, or NULL if the field is empty or unconfigured.
   */
  private function getMatchRenderValue(ComponentShapePluginInterface $shape, ?bool $published): ?ComponentPropRenderable {
    $formatConfig = $this->configuration['render_field_format'] ?? [];
    if (empty($formatConfig['field_plugin'])) {
      return NULL;
    }
    $item = $this->matcherField->getEntityField($this->shape->getEntity(), $this->configuration['render_field'], $published, $shape->getCacheableMetadata());
    if (!$item || $item->isEmpty()) {
      return NULL;
    }
    $build = $item->view([
      'type' => $formatConfig['field_plugin'],
      'label' => $formatConfig['field_label'] ?? 'hidden',
      'settings' => $formatConfig['field_settings'] ?? [],
    ]);
    $shape->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));
    return ComponentPropRenderable::create($build);
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function buildMatchConfigurationForm($form, FormStateInterface $form_state, $entityTypeId = NULL, $bundle = NULL): array {
    $wrapperId = $form['#id'];
    $field = $this->configuration['field'];
    $shape = $this->shape;

    // One searchable, browsable control in place of the group-then-field pair
    // of selects. The group step existed only to keep the option count down —
    // a shape's match list runs past a thousand entries once referenced
    // entities are walked — and cost the ability to find a field by name
    // without already knowing which reference path reaches it.
    $extra = [];
    if ($shape->getRef() === 'markup') {
      $extra['_render'] = $this->t('Render with field formatter');
    }
    $form['field'] = [
      '#type' => 'neo_field_select',
      '#title' => $this->t('Field'),
      '#description' => $this->t('Select the field to use as the value.'),
      '#component' => $shape->getComponent()->id(),
      '#prop' => $shape->getRootShape()->getName(),
      '#shape' => $shape->id(),
      '#entity_type' => $entityTypeId,
      '#bundle' => $bundle,
      '#extra' => $extra,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $field,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshMatchAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($field === '_render') {
      $this->buildRenderFieldForm($form, $form_state, $shape, $entityTypeId, $bundle, $wrapperId);
    }

    return $form;
  }

  /**
   * Builds the render field sub-form for _render matches.
   */
  private function buildRenderFieldForm(array &$form, FormStateInterface $form_state, ComponentShapePluginInterface $shape, ?string $entityTypeId, ?string $bundle, string $wrapperId): void {
    $renderFieldId = $this->configuration['render_field'] ?? NULL;
    $form['render_field'] = [
      '#type' => 'neo_field_select',
      '#title' => $this->t('Field to render'),
      '#required' => TRUE,
      '#component' => $shape->getComponent()->id(),
      '#prop' => $shape->getRootShape()->getName(),
      '#shape' => $shape->id(),
      '#all' => TRUE,
      '#entity_type' => $entityTypeId,
      '#bundle' => $bundle,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $renderFieldId,
      '#ajax' => [
        'callback' => [static::class, 'refreshMatchAjax'],
        'wrapper' => $wrapperId,
      ],
    ];
    if (!$renderFieldId) {
      return;
    }
    $renderField = $this->matcherField->getFieldDefinition($shape, $renderFieldId, $entityTypeId, $bundle, NULL, TRUE);
    if (!$renderField) {
      return;
    }
    $form['render_field_format'] = $this->formatterConfigurationForm(
      [
        '#type' => 'fieldset',
        '#title' => $this->t('Formatter'),
      ],
      $form_state,
      $renderField,
      $this->configuration['render_field_format'] ?? [],
      [
        'callback' => [static::class, 'refreshMatchFieldFormatAjax'],
        'wrapper' => $wrapperId,
      ]
    );
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function buildMatchPropertiesConfigurationForm($form, FormStateInterface $form_state, $entityTypeId = NULL, $bundle = NULL): array {
    $field = $this->configuration['field'];
    $fieldDefinition = $this->matcherField->getFieldDefinition($this->shape, $field, $entityTypeId, $bundle);
    $fieldProperties = $fieldDefinition->getFieldStorageDefinition()->getPropertyDefinitions();
    // Pre-fill each select with the same mapping AUTOMATIC mode derives, so
    // toggling the checkbox on surfaces sensible defaults instead of blanks.
    $autoProperties = $this->shape->getAutoMatchProperties($fieldDefinition);

    $form['field_properties'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field Properties'),
    ];
    foreach ($this->shape->getChildShapes() as $name => $childShape) {
      $definitions = $childShape->getFieldItem()->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
      $shapeProperty = reset($definitions);
      $targetDataType = $shapeProperty->getDataType();
      $options = [];
      foreach ($fieldProperties as $propName => $property) {
        if ($property->getDataType() === $targetDataType) {
          $options[$propName] = $property->getLabel();
        }
      }
      $form['field_properties'][$name] = [
        '#type' => 'select',
        '#title' => $childShape->getTitle(),
        '#default_value' => $this->configuration['field_properties'][$name] ?? $autoProperties[$name] ?? '',
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
   * Ajax callback.
   */
  public static function refreshMatchFieldFormatAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -2));
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
