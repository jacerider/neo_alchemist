<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginManager;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;
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
   * The component shape plugin manager.
   *
   * @var \Drupal\neo_alchemist\Shape\ComponentShapePluginManager
   */
  protected ComponentShapePluginManager $shapeManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /**
     * @var \Drupal\neo_alchemist\Form\ComponentStyleSettingsForm
     */
    $instance = parent::create($container);
    $instance->propManager = $container->get('plugin.manager.neo_component_prop_def');
    $instance->shapeManager = $container->get('plugin.manager.neo_component_shape');
    $instance->entityTypeManager = $container->get('entity_type.manager');
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

    // Instead of reading the raw `styles` list off each prop def, instantiate
    // the component shape each prop def resolves to and ask it for the options
    // it exposes (::getStyleOptions()). This lets style options be defined in
    // code (e.g. SchemeShape, whose options are neo_scheme entities) without a
    // static `styles` list in a prop def, while still letting administrators
    // include/exclude them here. The transient component below only satisfies
    // the shape constructor; getStyleOptions() does not use it.
    $component = $this->entityTypeManager->getStorage('neo_component')->create([]);
    $styles = [];
    foreach ($this->propManager->getDefinitions() as $style_id => $definition) {
      $schema = $definition;
      $schema['name'] = $style_id;
      // Resolve the prop def to its shape by machine name so code-defined
      // shapes (e.g. `scheme`) are matched. When no shape is registered under
      // that name, getInstance() falls back to the prop type — that is how the
      // type:style prop defs (spacing, text_align, …) resolve to StyleShape.
      $schema['ref'] = $style_id;
      try {
        $shape = $this->shapeManager->getInstance([
          'schema' => $schema,
          'settings' => [],
          'component' => $component,
        ]);
      }
      catch (\Throwable $e) {
        // Be resilient to prop defs from other modules whose shapes cannot be
        // instantiated here; skip them rather than breaking the whole form.
        $this->logger('neo_alchemist')->warning('Could not build style options for prop def %id: @message', [
          '%id' => $style_id,
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
      if (!$shape instanceof ComponentShapeStylePluginInterface) {
        continue;
      }
      $options = $shape->getStyleOptions();
      if (!$options) {
        continue;
      }
      $styles[$style_id] = [
        'title' => $definition['title'] ?? $style_id,
        'description' => $definition['description'] ?? '',
        'options' => $options,
      ];
    }

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
          '#default_value' => $config->get('styles.' . $style_id . '.mode') ?? 'exclude',
        ];
        $options = [];
        foreach ($style['options'] as $key => $data) {
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
