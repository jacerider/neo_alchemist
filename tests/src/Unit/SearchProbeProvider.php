<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ComponentValueProvision;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;

/**
 * A real value provider whose produced value a test presets.
 *
 * Extends the production base and mixes in the production processing-mode
 * trait, so the outcome the search reads and the mode logic it consults are the
 * real ones — the only things this adds are a settable value for the producer
 * to return and the option to raise a veto-style claim while producing it.
 * Nothing here re-implements what it stands in for, so there is no copy to keep
 * honest; this is a preset, not a double of ComponentValuePluginBase.
 */
final class SearchProbeProvider extends ComponentValuePluginBase implements ComponentValueProcessingModeInterface {

  use ComponentValueProcessingModeTrait;

  /**
   * The value this provider produces on the provide pass.
   *
   * @var mixed
   */
  public mixed $provided = NULL;

  /**
   * Whether to claim the value while producing it, the way a veto does.
   *
   * @var bool
   */
  public bool $vetoes = FALSE;

  /**
   * {@inheritdoc}
   */
  public function provide(mixed $value): ComponentValueProvision {
    return $this->vetoes
      ? ComponentValueProvision::claim($this->provided)
      : ComponentValueProvision::offer($this->provided);
  }

}
