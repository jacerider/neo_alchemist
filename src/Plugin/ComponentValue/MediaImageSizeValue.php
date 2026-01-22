<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo\Helpers\NestedArray;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'media_image_size',
  label: new TranslatableMarkup('Media Image Size'),
  description: new TranslatableMarkup('Provide the ability to allow the image size to be set on the component.'),
  group: 'providers',
  ref_types: [
    'image',
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
      // 'override' => TRUE,
    ];
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
      '#markup' => '<div class="messages messages--warning">' . $this->t('This modifier will work as long as your template is rendering images with {{ neo_image_style(image.src, {scaleCrop: {width: 100, height: 100}}, image.alt) }}.') . '</div>',
    ];

    $form['sizes'] = [
      '#type' => $count > 0 ? 'fieldset' : 'container',
      '#title' => $this->t('Image Sizes'),
      '#attributes' => [
        'id' => $id,
      ],
      // '#access' => $count > 0,
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
    $value['size'] = NULL;
    if ($overrideValue = $this->shape->getOverrideValue()) {
      $value['size'] = $overrideValue['size'] ?? NULL;
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function alterValue(mixed $value, string $type): mixed {
    if ($size = $this->getSize($value['size'] ?? NULL)) {
      $value['size'] = $size['size']['dimensions'] ?? [];
    }
    else {
      $value['size'] = [];
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function massageValuesAlter(array &$values, array $submitted_values, array $original_values, array $form, FormStateInterface $form_state): void {
    $values['size'] = $submitted_values['size'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$element, FormStateInterface $form_state) {
    $options = array_map(function ($size) {
      return $size['label'];
    }, $this->configuration['sizes']);
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
