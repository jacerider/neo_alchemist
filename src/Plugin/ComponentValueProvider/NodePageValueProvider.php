<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValueProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValueProvider;
use Drupal\neo_alchemist\ComponentValueProviderPluginBase;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValueProvider(
  id: 'node_page',
  label: new TranslatableMarkup('Node: Page'),
  description: new TranslatableMarkup('Provide values from entity fields.'),
  entity_types: ['node.page'],
)]
final class NodePageValueProvider extends ComponentValueProviderPluginBase {

}
