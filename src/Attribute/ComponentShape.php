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
   * @param string|null $default_field_type
   *   (optional) The default field type. If null, will be the prop type. For
   *   example: string.
   * @param string|null $default_field_type_with_options
   *   (optional) The default field type if prop has options (enum). If null,
   *   the $default_field_type will be used. For example: list_string.
   * @param string|null $default_field_widget
   *   (optional) The default field widget. If null, no widget will be used. For
   *   example: string_textfield.
   * @param string|null $default_field_widget_with_options
   *   (optional) The default field widget if prop has options (enum). If null,
   *   the $default_field_widget will be used. For example: options_select.
   * @param array|null $default_plugins
   *   (optional) The default plugins. For example: ['prefix', 'suffix'].
   * @param array|null $supports_field_types
   *   (optional) The supported field types. For example:
   *   - ['string', 'string_long'].
   * @param array|null $supports_field_props
   *   (optional) The supported field properties. For example:
   *   - ['integer', 'float', 'decimal'].
   * @param array|null $formats
   *   (optional) The supported formats. For example:
   *   - ['textarea' => ['default_field_type' => 'string_long']].
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $prop,
    public readonly ?TranslatableMarkup $label,
    public readonly ?string $default_field_type = NULL,
    public readonly ?string $default_field_type_with_options = NULL,
    public readonly ?string $default_field_widget = NULL,
    public readonly ?string $default_field_widget_with_options = NULL,
    public readonly ?array $default_plugins = NULL,
    public readonly ?array $supports_field_types = NULL,
    public readonly ?array $supports_field_props = NULL,
    public readonly ?array $formats = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return $this->prop;
  }

}
