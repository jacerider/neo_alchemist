<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;

/**
 * A minimal value provider carrying only what the processing-mode trait needs.
 *
 * ComponentValuePluginBase would drag in a plugin id, a plugin definition, a
 * shape and a configuration array just to reach four one-line methods, so this
 * reimplements the claim bookkeeping exactly as the base class does it and
 * leaves everything else out.
 *
 * The flag starts TRUE, matching ComponentValuePluginBase: a fresh instance has
 * NOT claimed anything. The pipeline still resets it on every instance in
 * getAllowedInstances() because value plugin instances are memoised on the
 * collection and reused across the default, value and modify passes — the
 * reset clears a claim raised in an earlier pass, not an initial-state quirk.
 *
 * ComponentValueProcessingModeTest asserts this class stays in step with the
 * base class, so the double cannot drift into testing a fiction.
 */
final class TestProcessingModeProvider {

  use ComponentValueProcessingModeTrait;

  /**
   * The plugin configuration, holding `processing_mode` when set.
   *
   * @var array
   */
  public array $configuration = [];

  /**
   * The shape this provider decides a value for.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   */
  public ?ComponentShapePluginInterface $shape = NULL;

  /**
   * Whether the pipeline may keep running after this provider.
   *
   * @var bool
   */
  private bool $continueProcessing = TRUE;

  /**
   * Resets the claim, as the pipeline does before each run.
   *
   * @return $this
   */
  public function allowFurtherProcessing(): self {
    $this->continueProcessing = TRUE;
    return $this;
  }

  /**
   * Halts the pipeline after this provider.
   *
   * @return $this
   */
  public function stopFurtherProcessing(): self {
    $this->continueProcessing = FALSE;
    return $this;
  }

  /**
   * Whether later providers may still run.
   *
   * @return bool
   *   TRUE when the pipeline should continue.
   */
  public function shouldContinueProcessing(): bool {
    return $this->continueProcessing === TRUE;
  }

  /**
   * Claims the value, halting the pipeline.
   *
   * @return $this
   */
  public function claimValue(): self {
    return $this->stopFurtherProcessing();
  }

  /**
   * Whether this provider has claimed the value.
   *
   * @return bool
   *   TRUE when the value has been claimed.
   */
  public function hasClaimedValue(): bool {
    return !$this->shouldContinueProcessing();
  }

}
