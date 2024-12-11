<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
// #[ComponentShape(
//   prop: 'image',
//   label: new TranslatableMarkup('Image'),
//   default_field_type: 'entity_reference',
//   default_field_widget: 'media_library_widget',
// )]
class ImageShapeBackup extends ComponentShapePluginBase {

  use StringTranslationTrait;

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldStorageSettings(): array {
    return [
      'target_type' => 'media',
    ];
  }

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldInstaceSettings(): array {
    return [
      'handler' => 'default:media',
      'handler_settings' => [
        'target_bundles' => [
          'image' => 'image',
        ],
      ],
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getForm(array $form, FormStateInterface $form_state): ?array {
    $form = parent::getForm($form, $form_state);
    if ($this->getWidgetType() === 'media_library_widget' && !$this->isRequired()) {
      // When an image provides a default value, we need to provide a way to
      // toggle it on/off.
      $form['widget']['hide'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Hide Default Image'),
        '#default_value' => empty($this->getValue()),
        '#access' => empty(Element::children($form['widget']['selection'])) && !empty($this->getDefaultValue()),
      ];
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  protected function isFieldItemEmpty(): bool {
    $value = $this->fieldItem->getValue();
    // Since this shape provides non-standard default values, we do not consider
    // the field as empty if it has an src value.
    if (!empty($value['src'])) {
      return FALSE;
    }
    return parent::isFieldItemEmpty();
  }

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $value): array {
    $entity = $this->fieldItem->entity;
    if ($entity instanceof MediaInterface) {
      $source = $entity->getSource();
      $fid = $source->getSourceFieldValue($entity);
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($file instanceof FileInterface) {
        $value = [
          'src' => $file->createFileUrl(),
          'alt' => $source->getMetadata($entity, 'thumbnail_alt_value'),
          'width' => $source->getMetadata($entity, 'width'),
          'height' => $source->getMetadata($entity, 'height'),
        ];
      }
    }
    elseif ($entity instanceof FileInterface) {
      $value = [
        'src' => $entity->createFileUrl(),
        'alt' => $entity->get('alt')->value,
        'width' => $entity->get('width')->value,
        'height' => $entity->get('height')->value,
      ];
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $hide = !empty($values['hide']);
    $values = parent::massageFormValues($values, $form, $form_state);
    if (!$hide) {
      $values += $this->getDefaultValue();
    }
    if (!empty($values['target_id'])) {
      // If the target ID is set, we remove all other values.
      $values = [
        'target_id' => $values['target_id'],
      ];
    }
    return $values;
  }

  /**
   * Matches the field definition type with the entity field definition type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition types match, FALSE otherwise.
   */
  public function supportsFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool {
    $fieldDefinition = $this->getFieldItemList()->getFieldDefinition();
    if ($fieldDefinition->getType() === $entityFieldDefinition->getType()) {
      if ($entityFieldDefinition->getSetting('target_type') === 'media') {
        $target_bundles = $entityFieldDefinition->getSetting('handler_settings')['target_bundles'] ?? [];
        if (count($target_bundles) === 1 && isset($target_bundles['image'])) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
