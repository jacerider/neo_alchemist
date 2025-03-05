<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapeTextFormatPluginInterface;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'markup',
  label: new TranslatableMarkup('Markup'),
  default_field_type: 'string_long',
  default_field_widget: 'string_textarea',
  supports_field_props: ['string_long'],
  default_plugins: ['formatted_text'],
)]
class MarkupShape extends ComponentShapePluginBase implements ComponentShapeTextFormatPluginInterface {

  /**
   * The markup format.
   *
   * @var string
   */
  protected $textFormat = 'neo_simple';

  /**
   * Retrieves the markup format.
   *
   * @return string
   *   The markup format.
   */
  protected function getTextFormat(): string {
    return $this->textFormat;
  }

  /**
   * {@inheritDoc}
   */
  public function setTextFormat(string $textFormat): self {
    $this->textFormat = $textFormat;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $value): mixed {
    if ($value) {
      return [
        '#type' => 'processed_text',
        '#text' => $value,
        '#format' => $this->getTextFormat(),
      ];
    }
    return $value;
  }

}
