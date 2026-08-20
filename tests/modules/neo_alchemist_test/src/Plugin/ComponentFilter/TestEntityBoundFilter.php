<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentFilter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentFilter;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Filter\ComponentFilterPluginBase;

/**
 * A filter plugin that only applies to entity-bound components.
 *
 * The filter family had no way to say "not on this component" and its manager
 * had no method to ask, so the filter form listed every definition and a site
 * builder could configure a filter that does nothing. This plugin is the
 * narrowing under test: it mirrors the shipped entity_field_value access rule,
 * which is meaningless on a component that is not registered against an entity
 * type.
 *
 * No shipped filter plugin narrows today, so this fixture is what proves the
 * add picker honours a plugin that does.
 */
#[ComponentFilter(
  id: 'na_entity_bound_filter',
  label: new TranslatableMarkup('Test entity-bound filter'),
  description: new TranslatableMarkup('Only offered on components registered against an entity type.'),
)]
final class TestEntityBoundFilter extends ComponentFilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    return !empty($component->getTargetEntityTypeId());
  }

}
