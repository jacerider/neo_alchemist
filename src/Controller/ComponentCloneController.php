<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentCloneController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component): array {
    $values = $neo_component->toArray();
    // A clone is a brand new config entity: it must not carry the source's
    // identifiers, and dependencies are recalculated on save.
    unset($values['id'], $values['uuid'], $values['dependencies']);
    // The thumbnail is a neo_config_file parented to the source component and
    // named after it. Carrying the reference over would let a save on the
    // clone re-parent the source's file, so the clone starts out on the SDC's
    // default thumbnail until one is captured for it.
    $values['thumbnail'] = '';

    // Component::createDuplicate() is deliberately not used: EntityBase clones
    // the object, which would hand the new entity the source's memoised prop
    // shapes, slots and filters — every one of them still bound to the source
    // component. Rebuilding from the exported values gives a clean entity that
    // resolves its own shapes from the copied settings.
    $clone = $this->entityTypeManager()->getStorage('neo_component')->create($values);
    return $this->entityFormBuilder()->getForm($clone, 'clone');
  }

}
