<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\Plugin\ComponentShape\ObjectShape;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'heading',
  label: new TranslatableMarkup('Heading'),
  description: new TranslatableMarkup('Provides modifications for headings.'),
  group: 'providers',
  allow_on_default: TRUE,
  ref_types: [
    'heading',
  ],
  weight: 900
)]
final class HeadingValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use ComponentValueTitleResolverTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'hide' => FALSE,
      'supertitle_edit' => TRUE,
      'supertitle_default' => FALSE,
      'supertitle_empty' => FALSE,
      'supertitle_page' => FALSE,
      'supertitle_entity' => FALSE,
      'supertitle_value' => NULL,
      'title_edit' => TRUE,
      'title_default' => FALSE,
      'title_page' => FALSE,
      'title_entity' => FALSE,
      'title_value' => NULL,
      'subtitle_edit' => TRUE,
      'subtitle_default' => FALSE,
      'subtitle_empty' => FALSE,
      'subtitle_page' => FALSE,
      'subtitle_entity' => FALSE,
      'subtitle_value' => NULL,
      'size_edit' => TRUE,
      'size_default' => FALSE,
      'size_value' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function onUpdate(): void {
    if (isset($this->configuration['h1_edit'])) {
      $this->configuration['size_edit'] = $this->configuration['h1_edit'];
      unset($this->configuration['h1_edit']);
    }
    if (isset($this->configuration['h1_default'])) {
      $this->configuration['h1_default'] = $this->configuration['h1_default'];
      unset($this->configuration['h1_default']);
    }
    if (isset($this->configuration['h1_value'])) {
      $this->configuration['size_value'] = !empty($this->configuration['h1_value']) ? 'xl' : '';
      unset($this->configuration['h1_value']);
    }
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $form['hide'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide'),
      '#default_value' => $this->configuration['hide'],
      '#description' => $this->t('If checked, the component will be hidden by default.'),
    ];

    $shape = $this->shape;
    if (!$shape instanceof ObjectShape) {
      return $form;
    }
    $childShapes = $shape->getChildShapes();
    foreach ([
      'supertitle' => $this->t('Supertitle'),
      'title' => $this->t('Title'),
      'subtitle' => $this->t('Subtitle'),
      'size' => $this->t('Size'),
    ] as $key => $label) {
      $form["{$key}"] = [
        '#type' => 'fieldset',
        '#title' => $label,
      ];
      if (isset($this->configuration["{$key}_edit"])) {
        $allowOverride = !empty($this->configuration["{$key}_page"]) || !empty($this->configuration["{$key}_entity"]);
        $form["{$key}"]["edit"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Allow @op', ['@op' => $allowOverride ? 'override' : 'edit']),
          '#neo_size' => 'xs',
          '#default_value' => $this->configuration["{$key}_edit"],
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_default"])) {
        $form["{$key}"]["default"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Default'),
          '#neo_size' => 'xs',
          '#description' => $this->t('If checked, the default option will be enabled on component creation.'),
          '#default_value' => $this->configuration["{$key}_default"],
          '#disabled' => empty($this->configuration["{$key}_edit"]) || !empty($this->configuration["{$key}_empty"]),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_empty"])) {
        $form["{$key}"]["empty"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Hide'),
          '#neo_size' => 'xs',
          '#description' => $this->t('If checked, the hide option will be enabled on component creation.'),
          '#default_value' => $this->configuration["{$key}_empty"],
          '#disabled' => !empty($this->configuration["{$key}_default"]),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_page"])) {
        $form["{$key}"]["page"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Use %title as value', ['%title' => $this->t('page title')]),
          '#neo_size' => 'xs',
          '#default_value' => $this->configuration["{$key}_page"],
          '#access' => empty($this->configuration["{$key}_entity"]),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_entity"]) && $this->getShape()->getComponent()->getTargetEntityTypeId()) {
        $form["{$key}"]["entity"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Use %title as value', ['%title' => $this->t('entity label')]),
          '#neo_size' => 'xs',
          '#default_value' => $this->configuration["{$key}_entity"],
          '#access' => empty($this->configuration["{$key}_page"]),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_value"]) || is_null($this->configuration["{$key}_value"])) {
        if ($key === 'size') {
          $form["{$key}"]["value"] = [
            '#type' => 'select',
            '#title' => $this->t('Value'),
            '#default_value' => $this->configuration["{$key}_value"] ?? $childShapes[$key]->getDefaultValue(),
            '#description' => $this->t('The default value for the @label.', ['@label' => $label]),
            '#options' => ['' => $this->t('- Default -')] + $this->getSizeOptions(),
          ];
        }
        else {
          $form["{$key}"]["value"] = [
            '#type' => 'textfield',
            '#title' => $this->t('Value'),
            '#default_value' => $this->configuration["{$key}_value"] ?? $childShapes[$key]->getDefaultValue(),
            '#description' => $this->t('The default value for the @label.', ['@label' => $label]),
          ];
        }
      }
      $form["{$key}"]['value']['#access'] = empty($this->configuration["{$key}_page"]) && empty($this->configuration["{$key}_entity"]);
    }

    return $form;
  }

  /**
   * Get size options.
   */
  protected function getSizeOptions(): array {
    $shape = $this->shape;
    if (!$shape instanceof ObjectShape) {
      return [];
    }
    /** @var \Drupal\neo_alchemist\Plugin\ComponentShape\StyleShape $sizeShape */
    $sizeShape = $shape->getChildShapes()['size'];
    return $sizeShape->getFieldOptions();
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -2));
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $finalValues = [];
    foreach ($values as $key => $v) {
      if (!is_array($v)) {
        $finalValues[$key] = $v;
        continue;
      }
      foreach ($v as $ii => $vv) {
        $finalValues["{$key}_{$ii}"] = $vv;
      }
    }
    $form_state->setValues($finalValues);
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $shape = $this->shape;

    if (!$shape instanceof ComponentShapeChildrenPluginInterface) {
      return;
    }

    if ($this->configuration['hide']) {
      $shape->getOptionEmpty()->setLockedValue(TRUE, 'Heading hidden by Heading value provider.');
    }

    foreach (['supertitle', 'title', 'subtitle'] as $field) {
      if (($this->configuration["{$field}_page"] ?? $this->configuration["{$field}_entity"] ?? FALSE) && $this->shape->getOptionDefault()->isEnabled()) {
        $shape->setDefaultNestedOptionDefault($field);
      }
      if ($this->configuration["{$field}_default"]) {
        $shape->setDefaultNestedOptionDefault($field);
      }
      elseif ($this->configuration["{$field}_empty"] ?? $this->configuration["{$field}_page"] ?? $this->configuration["{$field}_entity"] ?? FALSE) {
        $shape->setDefaultNestedOptionEmpty($field);
      }
      if (!($this->configuration["{$field}_edit"] ?? TRUE)) {
        if ($this->configuration["{$field}_empty"] ?? FALSE) {
          $shape->setNestedOptionEmpty($field);
        }
        $shape->setNestedOptionDefault($field);
        $shape->setNestedOptionAccess($field);
      }
    }
    if ($this->configuration['size_default']) {
      $shape->setNestedOptionDefault('size');
    }
    if (!$this->configuration['size_edit']) {
      $shape->setNestedOptionDefault('size');
      $shape->setNestedOptionAccess('size');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    foreach (['supertitle', 'title', 'subtitle'] as $field) {
      if (!empty($this->configuration["{$field}_page"])) {
        $value[$field] = $this->getPageTitle();
      }
      elseif (!empty($this->configuration["{$field}_entity"])) {
        $value[$field] = $this->getShape()->getEntity()->label();
      }
      else {
        $value[$field] = $this->configuration["{$field}_value"] ?? NULL;
      }
    }
    if ($this->configuration['size_value']) {
      $value['size'] = $this->configuration['size_value'];
    }
    $this->stopFurtherProcessing();
    return $value;
  }

}
