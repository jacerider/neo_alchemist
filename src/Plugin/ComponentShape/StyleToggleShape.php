<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * An on/off setting that belongs on the Style tab.
 *
 * The editor puts a prop on the Style tab when its shape is a style shape, and
 * every other style shape is a select of named options. A presentation switch
 * — show the numerals, flank the overline with rules, let the header overlay
 * this banner — is neither content nor a choice between options, so before
 * this it could only be a plain `boolean`, which lands on the Content tab
 * beside the copy. This is that boolean, as a style shape: a checkbox on the
 * Style tab.
 *
 * Twig receives TRUE or FALSE, exactly as it does from a `boolean` prop, so a
 * template's `{% if prop %}` needs no change when a prop moves over.
 *
 * It also reads values and settings stored for a `boolean` prop (see
 * ::isStoredRefCompatible()): both store `{value: bool}`, so converting a prop
 * keeps every saved placement and every component's saved configuration.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\BooleanShape
 */
#[ComponentShape(
  prop: 'style_toggle',
  label: new TranslatableMarkup('Style toggle'),
  default_field_type: 'boolean',
  default_field_widget: 'boolean_checkbox',
)]
class StyleToggleShape extends StyleShapeBase {

  /**
   * The prop type this shape reads stored values and settings from.
   */
  private const LEGACY_REF = 'boolean';

  /**
   * {@inheritDoc}
   */
  public function isStoredRefCompatible(string $ref): bool {
    return $ref === self::LEGACY_REF || parent::isStoredRefCompatible($ref);
  }

  /**
   * {@inheritDoc}
   *
   * No options: a checkbox is not a choice between named styles, and options
   * here would also switch the field to the shape's `_with_options` type.
   */
  public function getStyleOptions(): array {
    return [];
  }

  /**
   * {@inheritDoc}
   */
  protected function buildValue(?Attribute $renderAttributes = NULL): mixed {
    return $this->castScalar(parent::buildValue($renderAttributes));
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    return $this->castScalar(parent::preRenderValue($value, $attributes));
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array {
    $values = parent::massageFormValues($values, $original_values, $form, $form_state);
    if (isset($values['value'])) {
      $values['value'] = $this->castScalar($values['value']);
    }
    return $values;
  }

  /**
   * Casts a value to the boolean this shape renders.
   *
   * Accepts the forms a value reaches the shape in: a bare scalar, or the
   * field-item wrapper (`{value: 1}`) the preview style store and the child
   * path can hand over.
   *
   * @param mixed $value
   *   The value to cast.
   *
   * @return bool
   *   The value as a bool.
   */
  protected function castScalar(mixed $value): bool {
    if (is_array($value)) {
      $value = $value['value'] ?? $value[0]['value'] ?? NULL;
    }
    return (bool) $value;
  }

  /**
   * Get default examples for this shape.
   *
   * @return mixed
   *   The default examples for this shape.
   */
  public static function getGenerationExamples(array $prop) {
    return 'false';
  }

}
