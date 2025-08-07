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
trait ComponentValueChildrenMatchTrait {

  use ComponentValueModifierTrait;
  use FieldFormatterTrait;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

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
  protected function buildChildrenMatchConfigurationForm(ComponentShapeChildrenPluginInterface $shape, $form, FormStateInterface $form_state, $entityTypeId, $bundle = NULL, array $configuration = []): array {
    $wrapperId = $form['#id'];
    $childShapes = $shape->getChildShapes();
    if ($childShapes) {
      $form['shape_fields'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Shape Fields'),
      ];
      foreach ($childShapes as $shapeName => $childShape) {
        if ($childShape->getRef() === ComponentShapePluginInterface::ARRAY) {
          // Arrays are not currently support for field binding.
          continue;
        }
        $childShapeId = $wrapperId . '-' . $shapeName;
        $childShapeDefaults = $configuration['shape_fields'][$shapeName] ?? [];
        $form['shape_fields'][$shapeName] = [
          '#type' => 'fieldset',
          '#title' => $childShape->getTitle(),
          '#attributes' => [
            'id' => $childShapeId,
          ],
          '#parents' => array_merge($form['#parents'], [
            'shape_fields',
            $shapeName,
          ]),
          '#neo_fieldset_region' => [
            'legend_end' => [
              '#markup' => '<div class="text-xs text-base-400">' . $this->t('Type: %type', [
                '%type' => $childShape->getType(),
              ]) . '</div>',
            ],
          ],
          '#description_display' => 'before',
        ];
        $options = [
          '- Shape -' => [
            '_default' => $this->t('Use Default'),
          ],
        ] + $this->matcherField->getMatchesAsOptions($childShape, $entityTypeId, $bundle);
        if ($childShape->getRef() === 'markup') {
          $options['- Shape -']['_render'] = $this->t('Render with field formatter');
        }
        $field = $childShapeDefaults['field'] ?? NULL;
        $form['shape_fields'][$shapeName]['field'] = [
          '#type' => 'select',
          '#title' => $this->t('Field'),
          '#description' => $childShape->getDescription(),
          '#required' => $childShape->isRequired(),
          '#options' => $options,
          '#empty_option' => $childShape->isRequired() ? $this->t('- Select -') : $this->t('- None -'),
          '#default_value' => $field,
          '#ajax' => [
            'callback' => [static::class, 'refreshChildrenMatchAjax'],
            'wrapper' => $childShapeId,
          ],
        ];
        if ($field) {
          switch ($field) {
            case '_render':
              $renderFieldId = $childShapeDefaults['render_field'] ?? NULL;
              $form['shape_fields'][$shapeName]['render_field'] = [
                '#type' => 'select',
                '#title' => $this->t('Field to render'),
                '#required' => TRUE,
                '#options' => $this->matcherField->getMatchesAsOptions($childShape, $entityTypeId, $bundle, NULL, TRUE),
                '#default_value' => $renderFieldId,
                '#ajax' => [
                  'callback' => [static::class, 'refreshChildrenMatchAjax'],
                  'wrapper' => $childShapeId,
                ],
              ];
              if ($renderFieldId) {
                $renderField = $this->matcherField->getFieldDefinition($childShape, $renderFieldId, $entityTypeId, $bundle, NULL, TRUE);
                if ($renderField) {
                  $form['shape_fields'][$shapeName]['render_field_format'] = [
                    '#type' => 'fieldset',
                    '#title' => $this->t('Formatter'),
                  ];
                  $renderFieldFormatConfiguration = $childShapeDefaults['render_field_format'] ?? [];
                  $form['shape_fields'][$shapeName]['render_field_format'] = $this->formatterConfigurationForm($form['shape_fields'][$shapeName]['render_field_format'], $form_state, $renderField, $renderFieldFormatConfiguration, [
                    'callback' => [static::class, 'refreshChildrenMatchAjax'],
                    'wrapper' => $childShapeId,
                  ]);
                }
              }
              break;

            default:
              $modifierDefaults = $childShapeDefaults['modifiers'] ?? [];
              $form['shape_fields'][$shapeName] = $this->buildModifierConfigurationForm($childShape, $modifierDefaults, $form['shape_fields'][$shapeName], $form_state);
              break;
          }
        }
      }
      $form['shape_published'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Only use published entities'),
        '#description' => $this->t('If checked, only published entities will be used. This is only applicaable for entities that have a "status" entity key.'),
        '#default_value' => $configuration['shape_published'] ?? TRUE,
      ];
    }
    return $form;
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
  protected function getChildrenMatchValues(ComponentShapeChildrenPluginInterface $shape, array $entities, array $configuration = []): mixed {
    $values = [];
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
    $shapeNames = $shape->getChildShapeNames();
    $delta = 0;
    if ($entities) {
      foreach ($entities as $entity) {
        $shape->addCacheableDependency($entity);
        foreach ($shapeNames as $shapeName) {
          $values[$delta][$shapeName] = [];
          $settings = $configuration['shape_fields'][$shapeName] ?? [];
          $field = $settings['field'] ?? NULL;
          if ($field) {
            switch ($field) {
              case '_default':
                // Will fall back to the default value.
                $values[$delta][$shapeName] = NULL;
                break;

              case '_render':
                $item = $this->matcherField->getEntityField($entity, $settings['render_field'], !empty($configuration['shape_published']), $shape->getCacheableMetadata());
                if ($item && !$item->isEmpty() && !empty($settings['render_field_format']['field_plugin'])) {
                  $build = $item->view([
                    'type' => $settings['render_field_format']['field_plugin'],
                    'label' => $settings['render_field_format']['field_label'],
                    'settings' => $settings['render_field_format']['field_settings'],
                  ]);
                  $cacheableMetadata = CacheableMetadata::createFromRenderArray($build);
                  $shape->addCacheableDependency($cacheableMetadata);
                  $values[$delta][$shapeName] = ComponentPropRenderable::create($build);
                }
                else {
                  $values[$delta][$shapeName] = [];
                }
                break;

              default:
                $values[$delta][$shapeName] = $this->matcherField->getEntityValue(
                  entity: $entity,
                  key: $field,
                  default: NULL,
                  published: !empty($configuration['shape_published']),
                  cacheableMetadata: $shape->getCacheableMetadata()
                );
                if (!empty($settings['modifiers'])) {
                  $shape->setChildShapePlugins($shapeName, $settings['modifiers'] ?? []);
                }
                break;
            }
          }
          else {
            // Hide the shape if no field is selected.
            $shape->hideChildShape($shapeName);
          }
        }
        // Remove values that are completely empty.
        if (!array_filter($values[$delta])) {
          unset($values[$delta]);
        }
        $delta++;
      }
    }
    else {
      // When we have no entities, we return empty values for each shape so that
      // the shape will not be shown.
      foreach ($shapeNames as $shapeName) {
        $values[$delta][$shapeName] = [];
        $shape->hideChildShape($shapeName);
      }
    }
    if ($shape->getType() === ComponentShapePluginInterface::OBJECT) {
      $values = reset($values) ?: [];
    }
    return $values;
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
