<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\ComponentUsage;

/**
 * A field config that tracks the components in its Alchemist default layout.
 *
 * A neo_component_tree field can bake a component tree into
 * "settings.defaults.tree", so the field config genuinely depends on those
 * neo_component config entities. Without the dependency, deleting a component
 * silently leaves a default layout pointing at nothing.
 *
 * This class is installed as the class for *every* field_config, because the
 * config dependency system loads dependents straight from storage and calls
 * onDependencyRemoval() on whatever class comes back — a subclass used only
 * for field *definitions* would never be consulted. Both methods are inert for
 * fields of any other type, so non-Alchemist fields behave exactly as before.
 *
 * @see neo_alchemist_entity_type_alter()
 * @see \Drupal\neo_alchemist\Entity\ComponentFieldConfig
 */
class NeoFieldConfig extends FieldConfig {

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    foreach ($this->getDefaultLayoutComponentIds() as $componentId) {
      $this->addDependency('config', 'neo_alchemist.neo_component.' . $componentId);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Strips the removed components out of the default layout and reports the
   * field as fixed, so a component deletion updates this field config instead
   * of destroying it — losing the field would take every entity's stored
   * values with it.
   */
  public function onDependencyRemoval(array $dependencies) {
    $changed = parent::onDependencyRemoval($dependencies);
    $componentIds = ComponentUsage::componentIdsFromDependencies($dependencies);
    if (!$componentIds || !$this->isAlchemistField()) {
      return $changed;
    }
    $defaults = $this->getSetting('defaults') ?: [];
    $updated = ComponentUsage::detachComponents($defaults, $componentIds);
    if ($updated !== $defaults) {
      $this->setSetting('defaults', $updated);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Whether this field stores an Alchemist component tree.
   *
   * @return bool
   *   TRUE if the field type is neo_component_tree.
   */
  protected function isAlchemistField(): bool {
    return $this->getType() === 'neo_component_tree';
  }

  /**
   * The component ids baked into this field's default layout.
   *
   * @return string[]
   *   The component config entity ids.
   */
  protected function getDefaultLayoutComponentIds(): array {
    if (!$this->isAlchemistField()) {
      return [];
    }
    $tree = $this->getSetting('defaults')['tree'] ?? [];
    return is_array($tree) ? ComponentUsage::extractComponentIds($tree) : [];
  }

}
