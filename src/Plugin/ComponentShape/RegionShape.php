<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentShapeRegionPluginInterface;
use Drupal\neo_icon\IconTrait;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'region',
  label: new TranslatableMarkup('Region'),
)]
class RegionShape extends ArrayShape implements ComponentShapeRegionPluginInterface {

  use IconTrait;

  /**
   * {@inheritDoc}
   */
  public function buildSchema($schema): array {
    $schema = parent::buildSchema($schema);
    $schema['examples'] = $schema['examples'] ?? [];
    return $schema;
  }

  /**
   * {@inheritDoc}
   */
  public function allowEmpty(): bool {
    return TRUE;
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $component = $this->getComponent();
    $regionAttributes = new Attribute([
      'class' => ['neo-region'],
    ]);
    $newValues = [];
    $message = $this->t('No components have been added to this region.');
    if ($component->isInstancePreview()) {
      $message = $this->t('Component Region');
    }
    if ($component instanceof ComponentInstanceInterface) {
      foreach ($value as $delta => $uuid) {
        $childComponent = $component->getFieldItem()->getComponent($uuid);
        if ($childComponent && $childComponent->access('view')) {
          $newValues[$delta] = $childComponent->toRenderable();
        }
      }
    }
    else {
      $message = $this->t('This is a region and supports adding components when used in a content field.');
      // This will happen in config scope.
      foreach ($value as $delta => $componentId) {
        /** @var \Drupal\neo_alchemist\ComponentInterface $childComponent */
        $childComponent = $this->entityTypeManager->getStorage('neo_component')->load($componentId);
        if ($childComponent) {
          $newValues[$delta] = $childComponent->toRenderable();
        }
      }
    }
    $value = $newValues;
    if ($component->isPreview()) {
      $data = [
        'id' => $this->id(),
        'label' => $this->getNestedTitle(),
      ];
      $regionAttributes->setAttribute('data-region-uuid', $component->uuid() . '--' . $this->id());
      $regionAttributes->setAttribute('data-region', Json::encode($data));
      if (empty($value)) {
        $value['empty'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="text-xs p-3 bg-base-100 text-base-700 border border-dashed text-center">{{ icon("info-circle") }}{{ empty_message }}</div>',
          '#context' => [
            'empty_message' => $message,
          ],
        ];
      }
    }
    if ($value) {
      $value['#prefix'] = '<div' . ((string) $regionAttributes) . '>';
      $value['#suffix'] = '</div>';
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  protected function form(array $form, FormStateInterface $form_state): array {
    $form['#access'] = FALSE;
    return $form;
  }

}
