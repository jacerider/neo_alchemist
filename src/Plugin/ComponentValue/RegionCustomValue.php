<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\Plugin\ComponentShape\RegionShape;

/**
 * Flags a region as entity-customizable.
 *
 * Enabling this plugin on a region prop is the flag itself — it has no
 * settings. When a component carrying such a region is placed in a field
 * default layout whose field does not allow per-entity customization, the
 * field switches to "hybrid" mode: content creators may manage the components
 * inside this region on each entity while the rest of the layout remains
 * controlled by the field default.
 *
 * @see \Drupal\neo_alchemist\ComponentFieldConfigInterface::getCustomRegions()
 */
#[ComponentValue(
  id: 'region_custom',
  label: new TranslatableMarkup('Entity Customizable'),
  description: new TranslatableMarkup('Allow content creators to manage the components in this region on each entity, even when the layout itself is not customizable.'),
  group: 'providers',
  ref_types: [
    'region',
  ],
  weight: 11,
)]
final class RegionCustomValue extends ComponentValuePluginBase {

  /**
   * Configuration form for the value plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['info'] = [
      '#markup' => '<p>' . $this->t('Enabling this plugin allows content creators to add, sort and remove components inside this region on each entity — even when the field layout itself is locked. The surrounding layout stays controlled by the field default layout.') . '</p>',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    if ($this->shape instanceof RegionShape) {
      $this->shape->setEntityCustomizable();
    }
  }

}
