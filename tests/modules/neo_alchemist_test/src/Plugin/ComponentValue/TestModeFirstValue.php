<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;

/**
 * The FIRST provider in a two-provider processing-mode chain.
 *
 * Mode-aware and value-producing: `produce` says what it hands back (an empty
 * string means "produced nothing"). Paired with TestModeSecondValue, which
 * runs after it and always produces a competing value, so a test can observe
 * whether this provider's configured mode actually stopped the pipeline.
 *
 * This is the real thing — ComponentValuePluginBase plus the production trait
 * — rather than the Unit suite's hand-rolled double, so the end-to-end
 * behavior is exercised through the actual claim bookkeeping.
 */
#[ComponentValue(
  id: 'na_mode_first',
  label: new TranslatableMarkup('Test mode provider (first)'),
  description: new TranslatableMarkup('Produces a configurable value and honours its processing mode.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 10,
)]
final class TestModeFirstValue extends ComponentValuePluginBase implements ComponentValueProcessingModeInterface {

  use ComponentValueProcessingModeTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['produce' => '', 'produce_empty' => FALSE] + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, ?array &$complete_form = NULL) {
    $form = parent::buildConfigurationForm($form, $form_state, $complete_form);
    return $this->buildProcessingModeForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Deliberately the DEFAULT pass only. The processing mode is applied in
   * getDefaultValue() and nowhere else, so a fixture that also implements
   * provideOverrideValue() would let the override pass overwrite the result
   * and mask the very behavior under test.
   *
   * Two different kinds of "empty" are configurable, and the difference is the
   * whole point of `produce_empty`:
   * - `produce: ''` means DECLINED — hand back whatever arrived. Since the
   *   pipeline seeds the value from the schema's examples, a component that
   *   has examples still carries them out of this method.
   * - `produce_empty: TRUE` means PRODUCED NOTHING — actively return an empty
   *   value, overwriting any seeded example. That is the only way to observe
   *   what the pipeline does with a genuinely empty resolved value on a
   *   component whose schema declares examples.
   */
  public function provideDefaultValue(mixed $value): mixed {
    if (!empty($this->configuration['produce_empty'])) {
      return '';
    }
    $produce = $this->configuration['produce'] ?? '';
    return $produce === '' ? $value : $produce;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return TRUE;
  }

}
