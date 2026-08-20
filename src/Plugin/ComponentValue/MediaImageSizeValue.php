<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo\Helpers\NestedArray;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_provider.
 *
 * This plugin can be used on both image fields and on the image_size style
 * shape. It provides values differently based on the context.
 */
#[ComponentValue(
  id: 'media_image_size',
  label: new TranslatableMarkup('Media Image Size'),
  description: new TranslatableMarkup('Provide the ability to allow the image size to be set on the component.'),
  group: 'modifiers',
  ref_types: [
    'image',
    'image_size',
  ],
  weight: 10,
)]
final class MediaImageSizeValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'sizes' => [],
      'dimensions' => [
        'sm' => [
          'width' => 640,
          'height' => '',
        ],
      ],
    ];
  }

  /**
   * Check if the shape is an image size shape.
   *
   * @return bool
   *   TRUE if the shape is an image size shape, FALSE otherwise.
   */
  protected function isStyle(): bool {
    return $this->shape instanceof ComponentShapeStylePluginInterface;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $id = $form['#wrapper_id'] . '-sizes';
    $key = implode('_', $form['#parents']);
    $count = $form_state->get($key . '_count');
    if ($count === NULL) {
      $count = count($this->configuration['sizes']);
      $form_state->set($key . '_count', $count);
    }

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $this->t('This modifier will work as long as your template is rendering images with something like: {{ neo_image_style(image.src, image.size|default({scaleCrop: {width: 100, height: 100}}), image.alt) }}.') . '</div>',
    ];

    $form['sizes'] = [
      '#type' => $count > 0 ? 'fieldset' : 'container',
      '#title' => $this->t('Image Sizes'),
      '#attributes' => [
        'id' => $id,
      ],
    ];

    for ($i = 0; $i < $count; $i++) {
      $form['sizes'][$i]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#default_value' => $this->configuration['sizes'][$i]['label'] ?? '',
      ];
      $form['sizes'][$i]['size'] = [
        '#type' => 'neo_settings',
        '#title' => $this->t('Image Settings'),
        '#settings_id' => 'neo_image',
        '#default_value' => $this->configuration['sizes'][$i]['size'] ?? [
          'sm' => [
            'width' => 640,
            'height' => '',
          ],
        ],
        '#allow_variation' => FALSE,
        '#theme_wrappers' => ['container'],
      ];
    }

    $form['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Size'),
      '#id' => $id . '-add-size',
      '#submit' => [[get_class($this), 'addSizeSubmit']],
      '#ajax' => [
        'callback' => [get_class($this), 'addSizeAjax'],
        'wrapper' => $id,
      ],
    ];

    return $form;
  }

  /**
   * Submit handler for adding a size.
   */
  public static function addSizeSubmit(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = $triggering_element['#array_parents'];
    array_pop($parents);
    $container = NestedArray::getValue($form, $parents);
    $key = implode('_', $container['#parents']);
    $count = $form_state->get($key . '_count');
    $count++;
    $form_state->set($key . '_count', $count);
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for adding a size.
   */
  public static function addSizeAjax(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = $triggering_element['#array_parents'];
    array_pop($parents);
    return NestedArray::getValue($form, $parents)['sizes'];
  }

  /**
   * Get the size configuration.
   */
  public function getSize(?string $sizeKey = NULL): array {
    $size = [];
    if (isset($sizeKey)) {
      $size = $this->configuration['sizes'][$sizeKey] ?? [];
    }
    else {
      $defaultKey = array_key_first($this->configuration['sizes']);
      $size = $this->configuration['sizes'][$defaultKey] ?? [];
    }
    return $size;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    if (!$this->isStyle()) {
      $value['size'] = NULL;
      if ($overrideValue = $this->shape->getOverrideValue()) {
        $value['size'] = $overrideValue['size'] ?? NULL;
      }
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    if ($this->isStyle()) {
      // If this is a style shape, we just return the size dimensions.
      $size = NULL;
      if ($overrideValue = $this->shape->getOverrideValue()) {
        $size = $overrideValue['value'] ?: NULL;
      }
      if ($size = $this->getSize($size)) {
        return $size['size']['dimensions'] ?? [];
      }
    }
    else {
      if ($size = $this->getSize($value['size'] ?? NULL)) {
        $value['size'] = $size['size']['dimensions'] ?? [];
      }
      else {
        $value['size'] = NULL;
      }
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function massageValuesAlter(array &$values, array $submitted_values, array $original_values, array $form, FormStateInterface $form_state): void {
    if ($this->isStyle()) {
      // We do not need to massage the values.
      return;
    }
    $values['size'] = $submitted_values['size'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$element, FormStateInterface $form_state) {
    $options = array_map(function ($size) {
      return $size['label'];
    }, $this->configuration['sizes']);

    if ($this->isStyle()) {
      // If this is a style shape, provide the select options directly.
      $element['value']['#options'] = $options;
      return;
    }

    $element['size'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Size'),
      '#options' => $options,
      '#neo_size' => 'xs',
      '#default_value' => '',
      '#weight' => 1000,
    ];
  }

}
