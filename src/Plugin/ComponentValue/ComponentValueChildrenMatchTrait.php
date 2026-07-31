<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\ComponentPropRenderable;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Event\ComponentValueEvent;
use Drupal\neo_alchemist\FieldFormatterTrait;
use Drupal\neo_alchemist\MatcherField;
use Drupal\neo_alchemist\MatcherReference;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A trait for adding value matching capabilities to component value plugins.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ComponentValueChildrenMatchTrait {

  use ComponentValuePluginTrait;
  use FieldFormatterTrait;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * The reference matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherReference
   */
  protected MatcherReference $matcherReference;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * Default configuration for the value provider plugin.
   */
  protected function childrenMatchDefaultConfiguration(): array {
    return [
      'shape_fields' => [],
      'shape_published' => TRUE,
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function buildChildrenMatchConfigurationForm(ComponentShapeChildrenMatchPluginInterface $shape, $form, FormStateInterface $form_state, $entityTypeId, $bundle = NULL, array $configuration = []): array {
    $wrapperId = $form['#id'];
    $childShapes = $shape->getChildShapes();
    if ($childShapes) {
      $form['shape_fields'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Shape Fields'),
        '#element_validate' => [[static::class, 'validateChildrenMatchConfigurationForm']],
      ];
      foreach ($childShapes as $shapeName => $childShape) {
        $form['shape_fields'][$shapeName] = [
          '#id' => $wrapperId . '-' . $shapeName,
          '#parents' => array_merge($form['#parents'], [
            'shape_fields',
            $shapeName,
          ]),
        ];
        $form['shape_fields'][$shapeName] = $this->buildChildMatchConfigurationForm($childShape, $form['shape_fields'][$shapeName], $form_state, $entityTypeId, $bundle, $configuration['shape_fields'][$shapeName] ?? []);
      }

      $form['shape_published'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Only use published entities'),
        '#description' => $this->t('If checked, only published entities will be used. This is only applicaable for entities that have a "status" entity key.'),
        '#default_value' => $configuration['shape_published'] ?? TRUE,
      ];
    }
    else {
      $form['message'] = [
        '#type' => 'item',
        '#title' => $this->t('Shapes'),
        '#markup' => $this->t('No child shapes available.'),
      ];
    }
    return $form;
  }

  /**
   * Validate the match configuration form.
   */
  public static function validateChildrenMatchConfigurationForm(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($element['#parents']);
    $values = array_filter($values);
    $form_state->setValue($element['#parents'], $values);
  }

  /**
   * Builds the configuration form for a child match.
   */
  private function buildChildMatchConfigurationForm(ComponentShapePluginInterface $shape, $form, FormStateInterface $form_state, $entityTypeId, $bundle = NULL, array $configuration = []): array {
    $wrapperId = $form['#id'];
    $form += [
      '#type' => 'fieldset',
      '#neo_size' => 'sm',
      '#title' => $shape->getTitle(),
      '#neo_region' => [
        'legend_end' => [
          '#markup' => '<div class="text-xs text-base-400">' . $this->t('Type: %type', [
            '%type' => $shape->getType(),
          ]) . '</div>',
        ],
      ],
      '#description_display' => 'before',
    ];
    $form['#element_validate'][] = [static::class, 'validateChildMatchConfigurationForm'];
    $options = [
      '- Shape -' => [
        '_default' => $this->t('Use Default'),
        '_event' => $this->t('Use Event'),
      ],
    ];

    if ($shape->isIterable()) {
      // Arrays are not currently supported for field binding.
      $refOptions = $this->getMatcherReference()->getReferencesAsOptions($entityTypeId, $bundle);
      foreach ($refOptions as $group => $refs) {
        foreach ($refs as $refKey => $refLabel) {
          $options['- Entity Reference -'][$group]['_reference~' . $refKey] = $refLabel;
        }
      }
    }
    else {
      $options += $this->matcherField->getMatchesAsOptions($shape, $entityTypeId, $bundle);
    }
    if (in_array($shape->getRef(), ['markup', 'string'])) {
      $options['- Shape -']['_render'] = $this->t('Render with field formatter');
    }
    if ($shape instanceof ComponentShapeMediaPluginInterface && $entityTypeId === 'media') {
      // The iterated entity is itself a media entity a media shape can consume
      // (e.g. a media reference field used as the iteration source). Bundle
      // support is deliberately not checked here — the placeholder bundle is
      // unreliable for multi-bundle references — the strict check happens at
      // fetch time in fetchChildrenMatchValuesSelf().
      $options['- Shape -']['_self'] = $this->t('This entity');
    }
    if ($shape->getType() === 'boolean') {
      $options['- Raw -']['_raw:boolean_true'] = $this->t('Boolean: True');
      $options['- Raw -']['_raw:boolean_false'] = $this->t('Boolean: False');
    }
    if ($shape->getType() === 'string') {
      $options['- Raw -']['_raw:string'] = $shape->getFieldOptions() ? $this->t('Option') : $this->t('String');
    }
    if ($shape->isExpandable()) {
      $options['- Shape -']['_expand'] = $this->t('Expand to configure child shapes');
    }
    $this->alterChildMatchOptions($options, $shape, $form_state);

    $field = $configuration['field'] ?? NULL;
    $groups = array_keys($options);
    $groups = array_combine($groups, $groups);
    asort($groups);
    $group = $form_state->get('group--' . $form['#id']);
    if (!$group) {
      foreach ($options as $optionGroup => $ops) {
        foreach ($ops as $key => $data) {
          if (is_array($data)) {
            foreach ($data as $subKey => $subLabel) {
              if ($subKey === $field) {
                $group = $optionGroup;
                break 3;
              }
            }
          }
          else {
            if ($key === $field) {
              $group = $optionGroup;
              break 2;
            }
          }
        }
      }
    }
    $form['group'] = [
      '#type' => 'select',
      '#title' => $this->t('Group'),
      '#description' => $this->t('Select the group to use as the value.'),
      '#options' => $groups,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $group,
      '#ajax' => [
        'callback' => [static::class, 'refreshChildrenMatchAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($group && isset($options[$group])) {
      $field = isset($this->flattenArray($options[$group])[$field]) ? $field : NULL;
      $suboptions = $options[$group];
      $form['field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field'),
        '#description' => $shape->getDescription(),
        '#required' => $shape->isRequired(),
        '#options' => $suboptions,
        '#empty_option' => $shape->isRequired() ? $this->t('- Select -') : $this->t('- None -'),
        '#default_value' => $field,
        '#ajax' => [
          'callback' => [static::class, 'refreshChildrenMatchAjax'],
          'wrapper' => $wrapperId,
        ],
      ];

      if ($field) {
        $find = explode('~', $field)[0];
        switch ($find) {
          case '_render':
            $renderFieldId = $configuration['render_field'] ?? NULL;
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
                'callback' => [static::class, 'refreshChildrenMatchAjax'],
                'wrapper' => $wrapperId,
              ],
            ];
            if ($renderFieldId) {
              $renderField = $this->matcherField->getFieldDefinition($shape, $renderFieldId, $entityTypeId, $bundle, NULL, TRUE);
              if ($renderField) {
                $form['render_field_format'] = [
                  '#type' => 'fieldset',
                  '#title' => $this->t('Formatter'),
                ];
                $renderFieldFormatConfiguration = $configuration['render_field_format'] ?? [];
                $form['render_field_format'] = $this->formatterConfigurationForm($form['render_field_format'], $form_state, $renderField, $renderFieldFormatConfiguration, [
                  'callback' => [static::class, 'refreshChildrenMatchAjax'],
                  'wrapper' => $wrapperId,
                ]);
              }
            }
            break;

          case '_event':
            $form['info'] = [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#attributes' => ['class' => ['messages', 'messages--warning']],
              '#value' => $this->t('Will call the <em>\Drupal\neo_alchemist\Event\ComponentValueEvent</em> to get the value.'),
            ];
            break;

          case '_expand':
            if ($shape instanceof ComponentShapeChildrenMatchPluginInterface) {
              foreach ($shape->getChildShapes() as $childShapeName => $childShape) {
                $form['shape_fields'][$childShapeName] = [
                  '#id' => $wrapperId . '-' . $childShapeName,
                  '#parents' => array_merge($form['#parents'], [
                    'shape_fields',
                    $childShapeName,
                  ]),
                ];
                $form['shape_fields'][$childShapeName] = $this->buildChildMatchConfigurationForm($childShape, $form['shape_fields'][$childShapeName], $form_state, $entityTypeId, $bundle, $configuration['shape_fields'][$childShapeName] ?? []);
              }
            }
            break;

          case '_reference':
            // Not currently supported.
            $parts = explode('~', $field);
            $entityKey = $parts[1];
            $referenceEntity = $this->matcherReference->getReferenceEntityByEntityType($entityTypeId, $entityKey, TRUE);
            if ($referenceEntity) {
              $form = $this->buildChildrenMatchConfigurationForm($shape, $form, $form_state, $referenceEntity->getEntityTypeId(), $referenceEntity->bundle(), $configuration);
            }
            $form['#type'] = 'details';
            break;

          case '_raw:string':
            if ($options = $shape->getFieldOptions()) {
              $form['string'] = [
                '#type' => 'select',
                '#title' => $this->t('Value'),
                '#options' => $options,
                '#default_value' => $configuration['string'] ?? '',
              ];
              break;
            }
            else {
              $form['string'] = [
                '#type' => 'textfield',
                '#title' => $this->t('Value'),
                '#default_value' => $configuration['string'] ?? '',
              ];
            }
            break;

          default:
            $pluginDefaults = $configuration['plugins'] ?? [];
            $form = $this->buildPluginConfigurationForm($shape, $pluginDefaults, $form, $form_state);
            break;
        }
      }
    }
    $this->alterChildMatchConfigurationForm($shape, $form, $form_state, $entityTypeId, $bundle, $configuration);
    return $form;
  }

  /**
   * Validate the match configuration form.
   */
  public static function validateChildMatchConfigurationForm(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($element['#parents']);
    // Unset group so it is not saved. It is only used in the UI.
    $form_state->set('group--' . $element['#id'], $values['group']);
    if (empty($values['group'])) {
      $values['field'] = NULL;
    }
    unset($values['group']);
    // Store plugin IDs for use in schema.
    $values['plugins'] = $values['plugins'] ?? [];
    foreach ($values['plugins'] as $pluginId => &$plugin) {
      if (empty($plugin['status'])) {
        unset($values['plugins'][$pluginId]);
        continue;
      }
      $plugin['plugin_id'] = $pluginId;
    }
    if (empty($values['plugins'])) {
      unset($values['plugins']);
    }
    if (empty($values['field'])) {
      $values = [];
    }
    $form_state->setValue($element['#parents'], $values);
  }

  /**
   * Alter the available child match options.
   */
  protected function alterChildMatchOptions(array &$options, ComponentShapePluginInterface $shape, FormStateInterface $form_state) {
  }

  /**
   * Alter the configuration form for a child match.
   */
  protected function alterChildMatchConfigurationForm(ComponentShapePluginInterface $shape, &$form, FormStateInterface $form_state, $entityTypeId, $bundle = NULL, array $configuration = []) {
  }

  /**
   * Ajax callback.
   */
  public static function refreshChildrenMatchAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * Get the values for the shape matcher.
   */
  protected function getChildrenMatchValues(ComponentShapeChildrenMatchPluginInterface $shape, array $entities, array $configuration = [], ?string $parentId = NULL): mixed {
    return $this->fetchChildrenMatchValues($shape->getChildShapeNames(), $shape, $entities, $configuration, $parentId);
  }

  /**
   * Recursively fetch the values for the shape matcher.
   */
  protected function fetchChildrenMatchValues(array $shapeNames, ComponentShapeChildrenMatchPluginInterface $shape, array $entities, array $configuration = [], ?string $parentId = NULL): mixed {
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
    $values = [];
    $delta = 0;
    if ($entities) {
      $parentId = $parentId ?? $shape->id();
      foreach (array_filter($entities) as $entity) {
        if (!empty($configuration['shape_published']) && $entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
          continue;
        }
        $shape->addCacheableDependency($entity);
        foreach ($shapeNames as $shapeName) {
          $shapeId = implode('~', [$parentId, $shapeName]);
          $values[$delta][$shapeName] = [];
          $settings = $configuration['shape_fields'][$shapeName] ?? [];
          $field = $settings['field'] ?? NULL;
          // Queue child shape plugins.
          if (!empty($settings['plugins'])) {
            foreach ($settings['plugins'] as $pluginId => $pluginSettings) {
              if ($pluginSettings['status'] ?? FALSE) {
                $shape->enableChildShapePlugin($shapeId, $pluginId, $pluginSettings['settings'] ?? []);
              }
              else {
                $shape->disableChildShapePlugin($shapeId, $pluginId);
              }
            }
          }
          // Never use defaults. (images default to true).
          // @todo Consider handling this another way so that it is optional.
          // @todo We removed this as we have switched all values provides to
          // providing default values.
          // $shape->defaultChildShape($shapeId, FALSE);
          if ($field) {
            if (substr($field, 0, 1) === '_') {
              // Pseudo fields have custom handlers.
              $parts = explode(':', $field);
              $parts = explode('~', $parts[0]);
              $method = 'fetchChildrenMatchValues' . ucfirst(substr($parts[0], 1));
              if (method_exists($this, $method)) {
                $value = $this->{$method}($shapeId, $shapeName, $delta, $shape, $entity, $settings);
                if (!is_null($value)) {
                  $values[$delta][$shapeName] = $value;
                }
                continue;
              }
            }
            $values[$delta][$shapeName] = $this->matcherField->getEntityValue(
              entity: $entity,
              key: $field,
              default: [],
              published: !empty($configuration['shape_published']),
              cacheableMetadata: $shape->getCacheableMetadata()
            );
          }
          else {
            // Hide the shape if no field is selected.
            $shape->hideChildShape($shapeId);
          }
        }
        // Remove values that are completely empty.
        if (!array_filter($values[$delta])) {
          unset($values[$delta]);
        }
        $delta++;
      }
    }
    elseif (!$shape->isIterable()) {
      // When we have no entities, we return empty values for each shape so that
      // the shape will not be shown.
      foreach ($shapeNames as $shapeName) {
        $values[$delta][$shapeName] = [];
        $shape->hideChildShape($shapeName);
      }
    }
    if (!$shape->isIterable()) {
      $values = reset($values) ?: [];
    }
    return $values;
  }

  /**
   * Fetch children match values for expand fields.
   */
  protected function fetchChildrenMatchValuesDefault(string $shapeId, string $shapeName, int $delta, ComponentShapeChildrenMatchPluginInterface $shape, ContentEntityInterface $entity, array $configuration): mixed {
    $shape->defaultChildShape($shapeId, TRUE);
    return NULL;
  }

  /**
   * Fetch children match values for expand fields.
   */
  protected function fetchChildrenMatchValuesEvent(string $shapeId, string $shapeName, int $delta, ComponentShapeChildrenMatchPluginInterface $shape, ContentEntityInterface $entity, array $configuration): mixed {
    $event = new ComponentValueEvent($shape, [], $entity, $delta, $shapeId);
    $this->getEventDispatcher()->dispatch($event, ComponentValueEvent::EVENT_NAME);
    $value = $event->getValue();
    $shape->addCacheableDependency($event);
    return $value;
  }

  /**
   * Fetch children match values for default fields.
   */
  protected function fetchChildrenMatchValuesExpand(string $shapeId, string $shapeName, int $delta, ComponentShapeChildrenMatchPluginInterface $shape, ContentEntityInterface $entity, array $configuration): mixed {
    if ($configuration['shape_fields']) {
      $childShapeNames = array_keys($configuration['shape_fields']);
      return $this->fetchChildrenMatchValues($childShapeNames, $shape, [$entity], $configuration, $shapeId);
    }
    return NULL;
  }

  /**
   * Fetch children match values for default fields.
   */
  protected function fetchChildrenMatchValuesReference(string $shapeId, string $shapeName, int $delta, ComponentShapeChildrenMatchPluginInterface $shape, ContentEntityInterface $entity, array $configuration): mixed {
    if ($configuration['shape_fields']) {
      $entityKey = explode('~', $configuration['field'])[1];
      $field = $this->matcherReference->getReferenceField($entity, $entityKey, $shape->getCacheableMetadata());
      if ($field) {
        $childShapeNames = array_keys($configuration['shape_fields']);
        return $this->fetchChildrenMatchValues($childShapeNames, $shape, $field->referencedEntities(), $configuration, $shapeId);
      }
    }
    return NULL;
  }

  /**
   * Fetch children match values for the iterated entity itself.
   *
   * Used when the iteration source yields entities a child shape can consume
   * directly — a media reference field iterated by entity_reference, a media
   * entity query, etc. The media shape's own converter builds the value, so
   * the child receives the same structure a stored media reference would
   * produce.
   */
  protected function fetchChildrenMatchValuesSelf(string $shapeId, string $shapeName, int $delta, ComponentShapeChildrenMatchPluginInterface $shape, ContentEntityInterface $entity, array $configuration): mixed {
    if (!$entity instanceof MediaInterface) {
      return NULL;
    }
    $childShape = $this->getChildMatchShapeById($shape, $shapeId);
    if (!$childShape instanceof ComponentShapeMediaPluginInterface || !in_array($entity->bundle(), $childShape->getSupportedMediaTypes(), TRUE)) {
      return NULL;
    }
    return $childShape->getValueFromMedia($entity) ?: NULL;
  }

  /**
   * Resolve a child shape from a children-match shape id.
   *
   * Handlers always receive the ROOT children-match shape while $shapeId
   * chains as `rootId~child~grandchild` through nested _expand/_reference
   * levels. Walks each segment via getValueResolverShape() — the accessor
   * that is safe while getDefaultValue() is being memoized (getChildShapes()
   * would recurse).
   *
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $root
   *   The root children-match shape.
   * @param string $shapeId
   *   The chained shape id.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   *   The uninitialized child shape, or NULL if the path does not resolve.
   */
  private function getChildMatchShapeById(ComponentShapeChildrenMatchPluginInterface $root, string $shapeId): ?ComponentShapePluginInterface {
    $path = $shapeId;
    if (str_starts_with($path, $root->id() . '~')) {
      $path = substr($path, strlen($root->id()) + 1);
    }
    $current = $root;
    foreach (explode('~', $path) as $segment) {
      if (!$current instanceof ComponentShapeChildrenMatchPluginInterface) {
        return NULL;
      }
      $current = $current->getValueResolverShape($segment);
      if (!$current) {
        return NULL;
      }
    }
    return $current;
  }

  /**
   * Fetch children match values for default fields.
   */
  protected function fetchChildrenMatchValuesRender(string $shapeId, string $shapeName, int $delta, ComponentShapeChildrenMatchPluginInterface $shape, ContentEntityInterface $entity, array $configuration): mixed {
    if (!empty($configuration['render_field'])) {
      $item = $this->matcherField->getEntityField($entity, $configuration['render_field'], !empty($configuration['shape_published']), $shape->getCacheableMetadata());
      if ($item && !$item->isEmpty() && !empty($configuration['render_field_format']['field_plugin'])) {
        $build = $item->view([
          'type' => $configuration['render_field_format']['field_plugin'],
          'label' => $configuration['render_field_format']['field_label'] ?? 'hidden',
          'settings' => $configuration['render_field_format']['field_settings'] ?? [],
        ]);
        $cacheableMetadata = CacheableMetadata::createFromRenderArray($build);
        $shape->addCacheableDependency($cacheableMetadata);
        return ComponentPropRenderable::create($build);
      }
    }
    return NULL;
  }

  /**
   * Fetch children match values for raw fields.
   */
  protected function fetchChildrenMatchValuesRaw(string $shapeId, string $shapeName, int $delta, ComponentShapeChildrenMatchPluginInterface $shape, ContentEntityInterface $entity, array $configuration): mixed {
    $fieldName = substr($configuration['field'], 5);
    return match ($fieldName) {
      'boolean_true' => TRUE,
      'boolean_false' => FALSE,
      'string' => $configuration['string'] ?? '',
      default => NULL,
    };
  }

  /**
   * Flatten a multi-dimensional array to a single-dimensional array.
   */
  protected function flattenArray(array $array): array {
    $result = [];
    array_walk_recursive($array, function ($value, $key) use (&$result) {
      $result[$key] = $value;
    });
    return $result;
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

  /**
   * The matcher.
   *
   * @return \Drupal\neo_alchemist\MatcherReference
   *   The matcher.
   */
  protected function getMatcherReference(): MatcherReference {
    if (!isset($this->matcherReference)) {
      $this->matcherReference = \Drupal::service('neo_alchemist.matcher_reference');
    }
    return $this->matcherReference;
  }

  /**
   * The event dispatcher.
   *
   * @return \Symfony\Component\EventDispatcher\EventDispatcherInterface
   *   The event dispatcher.
   */
  protected function getEventDispatcher(): EventDispatcherInterface {
    if (!isset($this->eventDispatcher)) {
      $this->eventDispatcher = \Drupal::service('event_dispatcher');
    }
    return $this->eventDispatcher;
  }

}
