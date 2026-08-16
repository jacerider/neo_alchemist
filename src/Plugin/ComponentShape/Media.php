<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use DrupalCodeGenerator\InputOutput\Interviewer;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'media',
  label: new TranslatableMarkup('Media'),
  default_plugins: ['media'],
)]
class Media extends MediaShapeBase {

  /**
   * {@inheritDoc}
   */
  protected bool $optionDefaultInitValue = FALSE;

  /**
   * {@inheritDoc}
   */
  public function getSupportedMediaTypes(): array {
    return $this->getSchema()['media_types'] ?? ['image'];
  }

  /**
   * {@inheritDoc}
   *
   * The authored datum is the referenced media entity, not a resolved src: a
   * value can carry a src derived from the entity, so treating src as content
   * here would report a value with no entity behind it as authored.
   */
  protected function getMediaValueContentKeys(): array {
    return ['entity_id'];
  }

  /**
   * {@inheritDoc}
   */
  public function getValueFromMedia(MediaInterface $media): array {
    $source = $media->getSource();
    return [
      'entity_id' => $media->id(),
      'src' => $source->getSourceFieldValue($media),
      'title' => $media->label(),
      'render' => $this->entityTypeManager->getViewBuilder('media')->view($media),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public static function onGeneration(array &$prop, array $vars, Interviewer $ir, NeoComponentPropGeneratorInterface $generator, array $parents) {
    $parents[$prop['name']] = $prop['title'];
    $parentLabels = implode(' → ', ['Root'] + $parents);

    $mediaTypes = \Drupal::entityTypeManager()->getStorage('media_type')->loadMultiple();
    $choices = [];
    foreach ($mediaTypes as $id => $mediaType) {
      $choices[$id] = $mediaType->label();
    }
    if (empty($choices)) {
      return;
    }

    $default = isset($choices['image']) ? 'image' : array_key_first($choices);
    $selected = $ir->choice(\sprintf('%s: Allowed media types (comma-separated)', $parentLabels), $choices, $default, TRUE);
    $prop['media_types'] = array_values((array) $selected);
  }

}
