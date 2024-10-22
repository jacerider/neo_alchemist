<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeHydrated;

/**
 * Plugin implementation of the 'component_tree' field type.
 *
 * @todo Implement PreconfiguredFieldUiOptionsInterface?
 * @todo How to achieve https://www.previousnext.com.au/blog/pitchburgh-diaries-decoupled-layout-builder-sprint-1-2?
 * @see https://git.drupalcode.org/project/metatag/-/blob/2.0.x/src/Plugin/Field/FieldType/MetatagFieldItem.php
 *
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\neo_alchemist\Entity\Component
 */
#[FieldType(
  id: "neo_component_tree",
  label: new TranslatableMarkup("Alchemist"),
  description: new TranslatableMarkup("Field to use Alchemist for presenting these entities"),
  default_formatter: "neo_component_tree",
  // list_class: ComponentItemList::class,
  // constraints: [
  //   'ValidComponentTree' => [],
  // ],.
  // @see docs/data-model.md
  // @see content_translation_field_info_alter()
  // @see neo_alchemist_entity_prepare_view()
  column_groups: [
    'props' => [
      'label' => new TranslatableMarkup('Component property values'),
      'translatable' => TRUE,
    ],
    'tree' => [
      'label' => new TranslatableMarkup('Component tree'),
      'translatable' => TRUE,
    ],
  ],
  cardinality: 1,
)]
class ComponentTreeItem extends FieldItemBase implements RenderableInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'tree' => [
          'description' => 'The component tree structure.',
          'type' => 'json',
          'pgsql_type' => 'jsonb',
          'mysql_type' => 'json',
          'sqlite_type' => 'json',
          'not null' => FALSE,
        ],
        'props' => [
          'description' => 'The prop values for each component in the component tree.',
          'type' => 'json',
          'pgsql_type' => 'jsonb',
          'mysql_type' => 'json',
          'sqlite_type' => 'json',
          'not null' => FALSE,
        ],
      ],
      'indexes' => [],
      'foreign keys' => [
        // @todo Add the "hash" part the proposal at https://www.drupal.org/project/drupal/issues/3440578
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['tree'] = DataDefinition::create('neo_component_tree_structure')
      ->setLabel(new TranslatableMarkup('A component tree without props values.'))
      ->setRequired(TRUE);

    $properties['props'] = DataDefinition::create('neo_component_props_values')
      ->setLabel(new TranslatableMarkup('Prop values for each component in the component tree'))
      ->setRequired(TRUE);

    $properties['hydrated'] = DataDefinition::create('neo_component_tree_hydrated')
      ->setLabel(new TranslatableMarkup('The hydrated component tree: structure + props values combined — provides render tree for client side or render array for server side.'))
      ->setComputed(TRUE)
      ->setInternal(FALSE)
      ->setReadOnly(TRUE)
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable(): array {
    $hydrated = $this->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);
    return $hydrated->toRenderable();
  }

}
