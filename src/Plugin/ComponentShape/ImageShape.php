<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

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
#[ComponentShape(
  prop: 'image',
  label: new TranslatableMarkup('Image'),
)]
class ImageShape extends ComponentShapePluginBase {

  use StringTranslationTrait;

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    return 'entity_reference';
  }

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
  protected function getDefaultWidgetType(): ?string {
    return 'media_library_widget';
  }

  // /**
  //  * {@inheritDoc}
  //  */
  // public function getWidget(): ?WidgetInterface {
  //   $value = $this->getFieldItemValue();
  //   if ($this->getFieldItem()->isEmpty()) {
  //     $default = $this->getDefaultValue();
  //     $file = File::create([
  //       'uri' => $default['src'],
  //     ]);
  //     $media = Media::create([
  //       'mid' => 0,
  //       'bundle' => 'image',
  //       'thumbnail' => $file,
  //     ]);
  //     // $media->set('id', 0);
  //     $sourceField = $media->getSource()->getConfiguration()['source_field'];
  //     $media->set($sourceField, $file);
  //     $this->setFieldItemValue($media);
  //   }
  //   ksm($value, $this->getDefaultValue());
  //   $widget = parent::getWidget();

  //   return $widget;
  // }

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
  public function massageFormValues(array $form, FormStateInterface $form_state, array $values): array {
    $hide = !empty($values['hide']);
    $values = parent::massageFormValues($form, $form_state, $values);
    if (!$hide) {
      $values += $this->getDefaultValue();
    }
    return $values;
  }

}
