<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The neo_component_value_modifier attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ComponentValueModifier extends AttributeBase {

  /**
   * Constructs a new ComponentValueModifier instance.
   *
   * @param string $id
   *   The plugin ID. There are some implementation bugs that make the plugin
   *   available only if the ID follows a specific pattern. It must be either
   *   identical to group or prefixed with the group. E.g. if the group is "foo"
   *   the ID must be either "foo" or "foo:bar".
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A brief description of the plugin.
   * @param bool $status_default
   *   (optional) The default status of the plugin.
   * @param bool $status_lock
   *   (optional) Whether the plugin is locked to its default status.
   * @param array|null $prop_types
   *   (optional) The prop type the plugin supports.
   * @param array|null $ref_types
   *   (optional) The ref type the plugin supports.
   * @param array|null $entity_types
   *   (optional) The entity types the plugin supports.
   *   Format: [entity].[bundle] for specific entity-bundle combinations or
   *   [entity].* for all bundles of the entity. If not set, the plugin will be
   *   available universally.
   * @param int $weight
   *   The weight of the plugin.
   * @param string|null $provider
   *   (optional) The provider of the plugin.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly bool $status_default = FALSE,
    public readonly bool $status_lock = FALSE,
    public readonly ?array $prop_types = NULL,
    public readonly ?array $ref_types = NULL,
    public readonly ?array $entity_types = NULL,
    public readonly int $weight = 0,
    public ?string $provider = NULL,
    public readonly ?string $deriver = NULL
  ) {}

}
