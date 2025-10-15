<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentTwig;
use DrupalCodeGenerator\InputOutput\Interviewer;

/**
 * A base class for style shapes.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
abstract class StyleShapeBase extends ComponentShapePluginBase implements ComponentShapeStylePluginInterface {

  /**
   * {@inheritDoc}
   */
  public function init(): self {
    $this->getOptionEmpty()->setAccess(FALSE, 'Styles cannot be empty.');
    return parent::init();
  }

  /**
   * {@inheritDoc}
   */
  protected function buildValue(): mixed {
    $value = parent::buildValue();
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
