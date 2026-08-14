<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo\Helpers\Str;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'heading',
  label: new TranslatableMarkup('Heading'),
)]
class HeadingShape extends ObjectShape {

  /**
   * Flag to allow anchor override.
   *
   * @var bool
   */
  protected static bool $allowAnchorOverride;

  /**
   * {@inheritDoc}
   */
  protected function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    // ObjectShape::form() emits only editable children, so it can hand back a
    // form with no children at all. Writing into the keys below would then
    // auto-vivify them as phantom elements that massageFormValues() goes on to
    // persist as empty values.
    if (isset($form['anchor'])) {
      $form['anchor']['widget']['widget'][0]['value']['#neo_size'] = 'xs';
      if (!empty($form['title']['widget'])) {
        $form['anchor']['widget']['widget'][0]['value']['#slug']['source'] = array_merge($form['title']['widget']['widget'][0]['value']['#field_parents'], [
          'widget',
          'widget',
          0,
          'value',
        ]);
      }
      if (!$this->allowAnchorOverride()) {
        $form['anchor']['#access'] = FALSE;
      }
    }
    if (isset($form['size'])) {
      $form['size']['#neo_size'] = 'xs';
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   *
   * A heading carries content in `supertitle`, `title` and `subtitle` only.
   * `size` always resolves — it falls back to `md` even when the editor has
   * touched nothing — and `anchor` is a link target rather than text, so
   * neither says anything about whether there is a heading to render.
   */
  protected function getPresentationalValueKeys(): array {
    return array_merge(parent::getPresentationalValueKeys(), ['anchor']);
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    // Whether the heading has any text, decided before the anchor work below
    // reads from it.
    $isEmpty = $this->isProvidedValueEmpty($value);
    // Use the anchor.
    if (!$this->allowAnchorOverride()) {
      $anchor = $value['title'] ?? '';
    }
    else {
      $anchor = $value['anchor'] ?? $value['title'] ?? '';
    }
    $anchor = Str::machine($anchor, '-');
    // An explicitly authored anchor still makes the component linkable even
    // when the heading itself is textless, so this is deliberately keyed on
    // the resolved anchor rather than on $isEmpty. A heading with neither text
    // nor anchor used to emit a bare `id=""` plus a scroll offset for a target
    // that does not exist.
    if ($anchor !== '') {
      $attributes->setAttribute('id', $anchor);
      $attributes->addClass('scroll-mt-neo');
    }
    if ($value['title'] ?? NULL) {
      $attributes->setAttribute('data-component-title', $value['title']);
    }
    // Hand Twig an empty value when there is no text, so a template can guard
    // the whole heading with a plain `{% if heading %}`. Without this the
    // `size` child alone keeps the array non-empty, every heading tests as
    // truthy, and a textless heading renders an empty wrapper whose spacing
    // classes still push the content below it down.
    return $isEmpty ? [] : $value;
  }

  /**
   * Determine if anchor override is allowed.
   */
  protected function allowAnchorOverride(): bool {
    if (!isset(static::$allowAnchorOverride)) {
      /** @var \Drupal\neo_alchemist\Settings\AlchemistSettings $settings */
      $settings = \Drupal::service('neo_alchemist.settings')->getActive();
      static::$allowAnchorOverride = $settings ? $settings->getValue('anchor_override_status') : TRUE;
    }
    return static::$allowAnchorOverride;
  }

}
