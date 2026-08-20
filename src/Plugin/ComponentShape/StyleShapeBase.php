<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use DrupalCodeGenerator\InputOutput\Interviewer;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Shape\ComponentShapePluginBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentTwig;

/**
 * A base class for style shapes.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
abstract class StyleShapeBase extends ComponentShapePluginBase implements ComponentShapeStylePluginInterface {

  /**
   * {@inheritDoc}
   */
  public function init(): ComponentShapePluginInterface {
    $this->getOptionEmpty()->setAccess(FALSE, 'Styles cannot be empty.');
    return parent::init();
  }

  /**
   * {@inheritdoc}
   */
  public function getStyleOptions(): array {
    $options = [];
    foreach ($this->getSchema()['styles'] ?? [] as $key => $style) {
      $value = $style['value'] ?? '';
      $options[$key] = [
        'label' => (string) ($style['label'] ?? 'Unnamed'),
        // Style values are normally CSS class strings, but some shapes (e.g.
        // image sizes) use structured array values that have no displayable
        // class. Only expose scalar values.
        'value' => is_scalar($value) ? (string) $value : '',
      ];
    }
    return $options;
  }

  /**
   * Filters a set of option labels by the site-wide style settings.
   *
   * Both StyleShape and SchemeShape expose options that administrators can
   * restrict globally via the component style settings form
   * (neo_alchemist.style_settings). This applies that include/exclude
   * configuration in a single place so the behavior stays consistent across
   * every style shape.
   *
   * @param array $labels
   *   Option labels keyed by option key.
   *
   * @return array
   *   The filtered option labels.
   *
   * @see \Drupal\neo_alchemist\Form\ComponentStyleSettingsForm
   */
  protected function filterStyleSettings(array $labels): array {
    $settings = \Drupal::config('neo_alchemist.style_settings')->get('styles.' . $this->getName());
    if ($settings) {
      $mode = $settings['mode'] ?? 'exclude';
      $selected = array_filter($settings['values'] ?? []);
      if ($selected) {
        if ($mode === 'include') {
          $labels = array_intersect_key($labels, array_flip($selected));
        }
        elseif ($mode === 'exclude') {
          $labels = array_diff_key($labels, array_flip($selected));
        }
      }
    }
    return $labels;
  }

  /**
   * {@inheritDoc}
   */
  protected function buildValue(?Attribute $renderAttributes = NULL): mixed {
    $value = parent::buildValue($renderAttributes);
    if ($this->getComponent()->getScope() === 'config') {
      $previewValue = $this->getComponent()->getPreviewStyle($this->id());
      if ($previewValue !== NULL) {
        $value = $previewValue;
      }
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public static function onGeneration(array &$prop, array $vars, Interviewer $ir, NeoComponentPropGeneratorInterface $generator, array $parents) {
    $prop['apply'] = $ir->confirm('Apply style to component wrapper?', TRUE) ? 'true' : 'false';
  }

  /**
   * {@inheritDoc}
   */
  public static function onGenerateTwig(NeoComponentTwig $twig) {
    // Do nothing.
  }

}
