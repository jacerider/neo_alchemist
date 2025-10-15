<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Neo | Alchemist settings for this site.
 */
final class ComponentStyleSettingsForm extends ConfigFormBase {

  /**
   * The component property definition manager.
   *
   * @var \Drupal\neo_alchemist\ComponentPropDefPluginManager
   */
  protected $propManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /**
     * @var \Drupal\neo_alchemist\Form\ComponentStyleSettingsForm
     */
    $instance = parent::create($container);
    $instance->propManager = $container->get('plugin.manager.neo_component_prop_def');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'neo_alchemist_component_style_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['neo_alchemist.style_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory->getEditable('neo_alchemist.style_settings');
    $definitions = $this->propManager->getDefinitions();
    $styles = array_filter($definitions, function ($def) {
      return $def['type'] === 'style';
    });

    if ($styles) {
      uasort($styles, function ($a, $b) {
        return strcmp((string) $a['title'], (string) $b['title']);
      });
      $form['styles'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Component Styles'),
        '#description' => $this->t('Select styles to include or exclude from the style dropdown on components. If no styles are selected, all styles will be available.'),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];
      foreach ($styles as $style_id => $style) {
        $styleForm = [
          '#type' => 'details',
          '#title' => $style['title'],
          '#description' => $style['description'] ?? '',
          '#neo_size' => 'xs',
        ];
        $styleForm['mode'] = [
          '#type' => 'select',
          '#title' => $this->t('Mode'),
          '#options' => [
            'exclude' => $this->t('Exclude'),
            'include' => $this->t('Include'),
          ],
          '#default_value' => 'exclude',
        ];
        $options = [];
        foreach ($style['styles'] as $key => $data) {
          $options[$key] = $data['label'] . ' <small>(Key: <strong>' . $key . '</strong> | Value: ' . $data['value'] . ')</small>';
        }
        $styleForm['values'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Styles'),
          '#options' => $options,
          '#description' => $this->t('Select the styles to include or exclude.'),
          '#default_value' => $config->get('styles.' . $style_id . '.values') ?? [],
        ];
        $form['styles'][$style_id] = $styleForm;
      }
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $values = $form_state->getValues();
    foreach ($values['styles'] as $styleId => $style) {
      $styleValues = array_filter($style['values']);
      if ($styleValues) {
        $values['styles'][$styleId]['values'] = array_keys($styleValues);
      }
      else {
        unset($values['styles'][$styleId]);
      }
    }
    $form_state->setValues($values);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('neo_alchemist.style_settings')
      ->set('styles', $form_state->getValue('styles'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
