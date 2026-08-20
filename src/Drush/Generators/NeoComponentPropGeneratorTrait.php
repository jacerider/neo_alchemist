<?php

namespace Drupal\neo_alchemist\Drush\Generators;

use Drupal\neo_alchemist\ComponentPropDefPluginManager;
use Drupal\neo_alchemist\Shape\ComponentShapePluginManager;
use DrupalCodeGenerator\InputOutput\Interviewer;
use DrupalCodeGenerator\Utils;
use DrupalCodeGenerator\Validator\Required;
use DrupalCodeGenerator\Validator\RequiredMachineName;

/**
 * Provides a prop generator trait for Neo components.
 *
 * @internal
 */
trait NeoComponentPropGeneratorTrait {

  /**
   * {@inheritdoc}
   */
  public function askProp(array $vars, Interviewer $ir, array $parents = [], ?array $parentsOverride = NULL): array {
    $parentLabels = implode(' → ', ['Root'] + $parents);
    $prop = [];
    $prop['title'] = $ir->ask(\sprintf('%s: Prop title', $parentLabels), '', new Required());
    $default = Utils::human2machine($prop['title']);
    $prop['name'] = $ir->ask(\sprintf('%s: Prop machine name', $parentLabels), $default, new RequiredMachineName());
    $prop['description'] = $ir->ask(\sprintf('%s: Prop description (optional)', $parentLabels), '');
    $choices = [
      'string' => 'String',
      'number' => 'Number',
      'boolean' => 'Boolean',
      'array' => 'Array',
      'object' => 'Object',
      'null' => 'Always null',
    ];
    $styles = [];
    $propDefinitions = $this->propDefManager()->getDefinitions();
    foreach ($propDefinitions as $name => $definition) {
      if ($definition['type'] === 'style') {
        $styles[$name] = 'Style: ' . $definition['title'];
      }
      elseif ($name === 'style') {
        $styles[$name] = 'Style: Custom';
      }
      else {
        $choices[$name] = (string) $definition['title'];
      }
    }
    $choices += $styles;
    $prop = ['type' => $ir->choice(\sprintf('%s: Object prop type', $parentLabels), $choices, 'String')] + $prop;

    $shapeType = $prop['type'];
    if (isset($propDefinitions[$prop['type']])) {
      if (!$this->shapeManager()->hasDefinition($shapeType)) {
        $shapeType = $propDefinitions[$prop['type']]['type'];
      }
    }

    $shapeDefinition = $this->shapeManager()->getDefinition($shapeType);
    // Call shape's onGeneration method to allow it to modify the prop.
    $shapeDefinition['class']::onGeneration($prop, $vars, $ir, $this, $parents);

    if (!isset($prop['examples'])) {
      $prop['examples'] = $propDefinitions[$prop['type']]['examples'] ?? $shapeDefinition['class']::getGenerationExamples($prop);
      if (empty($prop['examples'])) {
        $prop['examples'] = '[]';
      }
    }
    if ($prop['examples'] === FALSE) {
      unset($prop['examples']);
    }

    // Prepare the prop's Twig template.
    if (!isset($prop['twig'])) {
      $defaults = $propDefinitions[$prop['type']]['twig'] ?? NULL;
      $twig = new NeoComponentTwig($prop, $parentsOverride ?? array_keys($parents), $defaults);
      if (!$defaults) {
        $shapeDefinition['class']::onGenerateTwig($twig);
      }
      $prop['twig'] = $twig->getTwig();
    }

    return $prop;
  }

  /**
   * Gets the shape manager.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginManager
   *   The component shape plugin manager.
   */
  private function shapeManager(): ComponentShapePluginManager {
    if (isset($this->shapeManager)) {
      return $this->shapeManager;
    }
    return \Drupal::service('plugin.manager.neo_component_shape');
  }

  /**
   * Gets the prop definition manager.
   *
   * @return \Drupal\neo_alchemist\ComponentPropDefPluginManager
   *   The component prop definition plugin manager.
   */
  private function propDefManager(): ComponentPropDefPluginManager {
    if (isset($this->propDefManager)) {
      return $this->propDefManager;
    }
    return \Drupal::service('plugin.manager.neo_component_prop_def');
  }

}
