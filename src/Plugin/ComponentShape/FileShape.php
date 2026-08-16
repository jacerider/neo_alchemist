<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_icon\IconMimeTrait;
use Drupal\neo_icon\IconTrait;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'file',
  label: new TranslatableMarkup('File'),
  default_plugins: ['media'],
)]
class FileShape extends MediaShapeBase {

  use IconTrait;
  use IconMimeTrait;

  /**
   * {@inheritDoc}
   */
  protected bool $optionDefaultInitValue = TRUE;

  /**
   * {@inheritDoc}
   */
  public function getSupportedMediaTypes(): array {
    return ['document'];
  }

  /**
   * {@inheritDoc}
   */
  public function getValueFromMedia(MediaInterface $media): array {
    $file = $this->getFileFromMedia($media);
    if ($file instanceof FileInterface) {
      return [
        'src' => $file->createFileUrl(),
        'uri' => $file->getFileUri(),
        'title' => $media->label(),
        'name' => $file->getFilename(),
        'target_id' => $media->id(),
      ];
    }
    return [];
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultPreview(): ?array {
    $value = $this->getValue();
    if (!empty($value['src'])) {
      return [
        '#type' => 'inline_template',
        '#template' => '<div class="media-library-item--preview text-4xl"><div>{{ icon }}</div><div class="text-xs">{{ filename }}</div></div>',
        '#context' => [
          'icon' => $this->icon('', $this->getIconFromSrc($value['src'])),
          'filename' => $value['name'] ?? '',
        ],
      ];
    }
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function buildMediaOverrideForm(array $form, FormStateInterface $form_state, ?MediaInterface $media = NULL): array {
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('@label Title', [
        '@label' => $this->getTitle(),
      ]),
      '#required' => TRUE,
      '#default_value' => $this->getValue()['title'] ?? $media->label(),
      '#description' => $this->t('Override the title of the media item. Leave empty to use the media title.'),
      '#neo_size' => 'xs',
    ];
    return $form;
  }

}
