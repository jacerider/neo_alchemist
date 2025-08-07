<?php

namespace Drupal\neo_alchemist\Drush\Generators;

use DrupalCodeGenerator\InputOutput\Interviewer;

/**
 * Defines a common interface for generators that create Neo component props.
 */
interface NeoComponentPropGeneratorInterface {

  /**
   * Asks for multiple questions to define a prop and its schema.
   *
   * @psalm-param array{component_machine_name: mixed, ...<array-key, mixed>} $vars
   *   The answers to the CLI questions.
   *
   * @return array
   *   The prop data, if any.
   */
  public function askProp(array $vars, Interviewer $ir, array $parents = [], ?array $parentsOverride = NULL): array;

}
