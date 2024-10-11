<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * @see \Drupal\Core\Validation\Plugin\Validation\Constraint\ValidKeysConstraint
 * @internal
 * @todo Try to subclass ValidKeysConstraint
 */
#[Constraint(
  id: 'KeyForEverySdcProp',
  label: new TranslatableMarkup('Validates the component tree structure', [], ['context' => 'Validation']),
  type: ['mapping']
)]
class SdcPropKeysConstraint extends SymfonyConstraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public $message = 'Configuration for the SDC prop "%prop_title" (%prop_machine_name) is missing.';

  /**
   * The ID of the SDC whose props must be present as keys on a `type: mapping`.
   */
  public string $sdcPluginId;

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['sdcPluginId'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): ?string {
    return 'sdcPluginId';
  }

}
