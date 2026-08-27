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
   * @param bool|array|null $text_keys
   *   (optional) Which parts of this shape's value are human-readable text,
   *   for callers that collect what a page says without rendering it — search
   *   indexing, excerpts, summaries.
   *
   *   Four settings:
   *   - NULL (the default): this shape holds no text of its own. A shape with
   *     children still has them read, since the children answer for
   *     themselves — that is how a plain object prop works. Style tokens,
   *     media references, slugs, URIs, numbers and machine names all leave
   *     this alone.
   *   - TRUE: the whole value is text. For example: string, markup.
   *   - An array: only these value keys are text, everything else being
   *     structure or presentation. For example: ['title'] on a link, whose
   *     uri and options are neither.
   *   - FALSE: nothing here, and nothing below here either. For a shape whose
   *     children are real enough to render but say nothing about the entity —
   *     a breadcrumb, a views filter. Without it such a shape would be
   *     descended into and its children, being strings and links, would
   *     answer that they are text.
   *
   *   Declared here rather than inferred, because a shape's value keys are the
   *   shape's own business and no caller can tell prose from a machine name by
   *   looking: a heading's title and a button style are both a string under a
   *   `value` key. Reachable from the cached plugin definition, so a caller
   *   pays nothing to ask, and alterable through
   *   hook_neo_component_shape_info_alter().
   * @param bool $text_markup
   *   (optional) Whether that text carries HTML a caller must strip before
   *   treating it as words. Defaults to FALSE.
   * @param string|null $provider
   *   (optional) The provider.
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
    public readonly bool|array|null $text_keys = NULL,
    public readonly bool $text_markup = FALSE,
    public ?string $provider = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return $this->prop;
  }

}
