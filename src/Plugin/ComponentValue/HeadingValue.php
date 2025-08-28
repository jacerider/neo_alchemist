<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
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
      'supertitle_edit' => TRUE,
      'supertitle_default' => FALSE,
      'supertitle_empty' => FALSE,
      'supertitle_page' => FALSE,
      'supertitle_value' => NULL,
      'title_edit' => TRUE,
      'title_default' => FALSE,
      'title_page' => FALSE,
      'title_value' => NULL,
      'subtitle_edit' => TRUE,
      'subtitle_default' => FALSE,
      'subtitle_empty' => FALSE,
      'subtitle_page' => FALSE,
      'subtitle_value' => NULL,
      'h1_edit' => TRUE,
      'h1_default' => FALSE,
      'h1_value' => FALSE,
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $shape = $this->shape;
    if (!$shape instanceof ObjectShape) {
      return $form;
    }
    $childShapes = $shape->getChildShapes();
    $hasEditableChild = FALSE;
    foreach ([
      'supertitle' => $this->t('Supertitle'),
      'title' => $this->t('Title'),
      'subtitle' => $this->t('Subtitle'),
      'h1' => $this->t('H1'),
    ] as $key => $label) {
      $form["{$key}"] = [
        '#type' => 'fieldset',
        '#title' => $label,
      ];
      if (isset($this->configuration["{$key}_edit"])) {
        $form["{$key}"]["edit"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Allow @op', ['@op' => empty($this->configuration["{$key}_page"]) ? 'edit' : 'override']),
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
          '#title' => $this->t('Use page title as value'),
          '#default_value' => $this->configuration["{$key}_page"],
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_value"]) || is_null($this->configuration["{$key}_value"])) {
        $form["{$key}"]["value"] = [
          '#type' => $key === 'h1' ? 'checkbox' : 'textfield',
          '#title' => $key === 'h1' ? $this->t('Show as H1') : $this->t('Value'),
          '#default_value' => $this->configuration["{$key}_value"] ?? $childShapes[$key]->getDefaultValue(),
          '#description' => $this->t('The default value for the @label.', ['@label' => $label]),
          '#access' => empty($this->configuration["{$key}_page"]),
        ];
      }
    }

    return $form;
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
    /** @var \Drupal\neo_alchemist\Plugin\ComponentShape\HeadingShape $shape */
    $shape = $this->shape;

    foreach (['supertitle', 'title', 'subtitle'] as $field) {
      if ($this->configuration["{$field}_page"] ?? FALSE && $this->shape->getOptionDefault()->isEnabled()) {
        $shape->setDefaultNestedOptionDefault($field);
      }
      if ($this->configuration["{$field}_default"]) {
        $shape->setDefaultNestedOptionDefault($field);
      }
      elseif ($this->configuration["{$field}_empty"] ?? $this->configuration["{$field}_page"]) {
        $shape->setDefaultNestedOptionEmpty($field);
      }
      if (!($this->configuration["{$field}_edit"] ?? TRUE)) {
        if ($this->configuration["{$field}_empty"] ?? FALSE) {
          $shape->setNestedOptionEmpty($field);
        }
        $shape->setNestedOptionAccess($field);
      }
    }
    if ($this->configuration['h1_default']) {
      $shape->setNestedOptionDefault('h1');
    }
    if (!$this->configuration['h1_edit']) {
      $shape->setNestedOptionAccess('h1');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    foreach (['supertitle', 'title', 'subtitle'] as $field) {
      if ($this->configuration["{$field}_page"]) {
        $value[$field] = $this->getPageTitle();
      }
      else {
        $value[$field] = $this->configuration["{$field}_value"] ?? NULL;
      }
    }
    $value['h1'] = !empty($this->configuration['h1_value']);

    $this->stopFurtherProcessing();
    return $value;
  }

}
