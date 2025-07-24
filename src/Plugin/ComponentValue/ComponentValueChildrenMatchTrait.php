<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\MatcherField;

/**
 * A trait for adding entity type manager.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ComponentValueChildrenMatchTrait {

  use ComponentValueModifierTrait;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * Configuration form for the value provider plugin.
   */
  protected function buildChildrenMatchConfigurationForm(ComponentShapeChildrenPluginInterface $shape, $form, FormStateInterface $form_state, $entityTypeId, $bundle = NULL): array {
    $wrapperId = $form['#id'];
    $childShapes = $shape->getChildShapes();
    if ($childShapes) {
      $form['shape_fields'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Shape Fields'),
      ];
      foreach ($childShapes as $shapeName => $childShape) {
        if ($childShape->isIterable()) {
          // Arrays are not currently support for field binding.
          continue;
        }
        $childShapeId = $wrapperId . '-' . $shapeName;
        $childShapeDefaults = $this->configuration['shape_fields'][$shapeName] ?? [];
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
        $form['shape_fields'][$shapeName]['field'] = [
          '#type' => 'select',
          '#title' => $this->t('Field'),
          '#description' => $childShape->getDescription(),
          '#required' => $childShape->isRequired(),
          '#options' => [
            '- Shape -' => [
              '_default' => $this->t('Use Default'),
            ],
          ] + $this->matcherField->getMatchesAsOptions($childShape, $entityTypeId, $bundle),
          '#empty_option' => $childShape->isRequired() ? $this->t('- Select -') : $this->t('- None -'),
          '#default_value' => $childShapeDefaults['field'] ?? NULL,
          '#ajax' => [
            'callback' => [static::class, 'refreshChildrenMatchAjax'],
            'wrapper' => $childShapeId,
          ],
        ];
        if (!empty($childShapeDefaults['field'])) {
          $modifierDefaults = $childShapeDefaults['modifiers'] ?? [];
          $form['shape_fields'][$shapeName] = $this->buildModifierConfigurationForm($childShape, $modifierDefaults, $form['shape_fields'][$shapeName], $form_state);
        }
      }
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
  protected function getChildrenMatchValues(ComponentShapeChildrenPluginInterface $shape, array $entities) {
    $values = [];
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
    $shapeNames = $shape->getChildShapeNames();
    $delta = 0;
    if ($entities) {
      foreach ($entities as $entity) {
        $shape->addCacheableDependency($entity);
        foreach ($shapeNames as $shapeName) {
          $values[$delta][$shapeName] = [];
          $settings = $this->configuration['shape_fields'][$shapeName] ?? [];
          $field = $settings['field'] ?? NULL;
          if ($field) {
            switch ($field) {
              case '_default':
                // Will fall back to the default value.
                $values[$delta][$shapeName] = NULL;
                break;

              default:
                $values[$delta][$shapeName] = $this->matcherField->getEntityValue($entity, $field, [], [], $shape->getCacheableMetadata());
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
