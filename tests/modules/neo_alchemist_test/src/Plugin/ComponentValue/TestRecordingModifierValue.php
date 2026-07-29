<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentValue;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist_test\TestValueCallLog;

/**
 * A modifiers-group plugin that records its calls and passes values through.
 */
#[ComponentValue(
  id: 'na_record_modifier',
  label: new TranslatableMarkup('Test recording modifier'),
  description: new TranslatableMarkup('Logs its calls; never changes the value.'),
  group: 'modifiers',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 10,
)]
final class TestRecordingModifierValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    TestValueCallLog::record('na_record_modifier', 'provideDefaultValue');
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function alterValue(mixed $value, string $type): mixed {
    TestValueCallLog::record('na_record_modifier', 'alterValue');
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    TestValueCallLog::record('na_record_modifier', 'modifyValue');
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return TRUE;
  }

}
