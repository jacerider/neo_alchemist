<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentFilter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentFilter;
use Drupal\neo_alchemist\Filter\ComponentFilterPluginBase;

/**
 * Plugin implementation of the neo_component_filter.
 */
#[ComponentFilter(
  id: 'string',
  label: new TranslatableMarkup('String'),
  description: new TranslatableMarkup('A raw string value.'),
)]
final class StringFilter extends ComponentFilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function valueSummary(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
      return NULL;
    }
    return mb_strlen($value) > 40 ? mb_substr($value, 0, 40) . '…' : $value;
  }

}
