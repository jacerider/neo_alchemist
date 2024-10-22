<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The neo_component_shape attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ComponentShape extends AttributeBase {

  /**
   * Constructs a new ComponentShape instance.
   *
   * @param string $prop
   *   The prop id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the plugin.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $prop,
    public readonly ?TranslatableMarkup $label,
    public readonly ?string $deriver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return $this->prop;
  }

}
