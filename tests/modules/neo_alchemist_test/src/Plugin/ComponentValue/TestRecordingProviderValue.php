<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentValue;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist_test\TestValueCallLog;

/**
 * A providers-group plugin that records its calls and passes values through.
 *
 * Used to observe the value pipeline's group ordering (providers must run
 * before fallback regardless of the saved drag-and-drop order) and the
 * getDefaultValue() call discipline, without changing any value.
 */
#[ComponentValue(
  id: 'na_record_provider',
  label: new TranslatableMarkup('Test recording provider'),
  description: new TranslatableMarkup('Logs its calls; never changes the value.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 10,
)]
final class TestRecordingProviderValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed {
    TestValueCallLog::record('na_record_provider', 'provideOverrideValue');
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    TestValueCallLog::record('na_record_provider', 'provideDefaultValue');
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function alterValue(mixed $value, string $type): mixed {
    TestValueCallLog::record('na_record_provider', 'alterValue');
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return TRUE;
  }

}
