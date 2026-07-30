<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentValue;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;

/**
 * The SECOND provider in a two-provider processing-mode chain.
 *
 * Always produces 'SECOND', unconditionally, and is deliberately NOT
 * mode-aware. Its only job is to be visible: if 'SECOND' shows up in the
 * resolved value, the first provider did not stop the pipeline.
 *
 * @see \Drupal\neo_alchemist_test\Plugin\ComponentValue\TestModeFirstValue
 */
#[ComponentValue(
  id: 'na_mode_second',
  label: new TranslatableMarkup('Test mode provider (second)'),
  description: new TranslatableMarkup('Always produces SECOND, so a halted pipeline is observable.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 20,
)]
final class TestModeSecondValue extends ComponentValuePluginBase {

  /**
   * The value this provider always produces.
   */
  public const PRODUCES = 'SECOND';

  /**
   * {@inheritdoc}
   *
   * The DEFAULT pass only — see TestModeFirstValue for why.
   */
  public function provideDefaultValue(mixed $value): mixed {
    return static::PRODUCES;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return TRUE;
  }

}
