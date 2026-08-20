<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentValue;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;

/**
 * A `providers`-group plugin that deliberately does nothing to the value.
 *
 * Its only purpose is to make ChildrenShapeBase::childHasOwnValueProvider()
 * return TRUE for whatever shape it is attached to. That predicate is a plain
 * `(bool) $shape->getValueCollection()->getActiveInstances('providers')`, so
 * membership of the `providers` group is the entire trigger — the plugin need
 * not actually source anything.
 *
 * This exists so the delta-distribution regression can be reproduced without
 * pulling `media`, `file` and `image` into the Kernel test. The real-world
 * trigger is ImageShape's `default_plugins: ['media']`, and MediaValue also
 * rewrites the shape's field type to `entity_reference` during onShapeInit() —
 * behaviour that is irrelevant to the bug and would only make the test slower
 * and more brittle.
 *
 * Every hook is a pass-through and nothing is ever claimed, so a value that
 * reaches the shape must have come from the parent's distribution.
 */
#[ComponentValue(
  id: 'na_test_provider',
  label: new TranslatableMarkup('Test provider'),
  description: new TranslatableMarkup('Inert providers-group plugin used by the test suite.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 10,
)]
final class TestProviderValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function alterValue(mixed $value, string $type): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return TRUE;
  }

}
