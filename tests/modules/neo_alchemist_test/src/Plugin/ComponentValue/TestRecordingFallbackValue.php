<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentValue;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist_test\TestValueCallLog;

/**
 * A fallback-group plugin that records its calls and yields NULL when empty.
 *
 * Returning NULL for an empty incoming value mirrors real providers such as
 * MediaValue/DefaultValue, and is what exposes the getDefaultValue()
 * memoisation miss: `isset(NULL)` is FALSE, so a computed-NULL default was
 * recomputed — side effects included — on every call.
 */
#[ComponentValue(
  id: 'na_record_fallback',
  label: new TranslatableMarkup('Test recording fallback'),
  description: new TranslatableMarkup('Logs its calls; returns NULL for empty values.'),
  group: 'fallback',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 10,
)]
final class TestRecordingFallbackValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed {
    TestValueCallLog::record('na_record_fallback', 'provideOverrideValue');
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    TestValueCallLog::record('na_record_fallback', 'provideDefaultValue');
    if ($this->getShape()->isProvidedValueEmpty($value)) {
      return NULL;
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function alterValue(mixed $value, string $type): mixed {
    TestValueCallLog::record('na_record_fallback', 'alterValue');
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return TRUE;
  }

}
