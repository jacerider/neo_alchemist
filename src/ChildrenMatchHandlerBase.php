<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Common defaults for a children-match handler.
 *
 * A handler that offers a plain choice with no configuration of its own — Use
 * Default, This entity, a raw boolean — needs to implement only getName() and
 * fetch(); the base supplies the rest. The mapper injects translation before
 * calling addOptions()/buildForm(), so a subclass may use $this->t().
 *
 * @see \Drupal\neo_alchemist\ChildrenMatchHandlerInterface
 */
abstract class ChildrenMatchHandlerBase implements ChildrenMatchHandlerInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array $configuration, ChildrenMatchFormContext $context): ?array {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function removeChildWhenAbsent(): bool {
    return FALSE;
  }

}
