<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;
use Drupal\neo_alchemist_search\Authored\AuthoredTextExtractor;
use Drupal\neo_alchemist_search\Binding\BindingResolver;
use Drupal\neo_alchemist_search\Binding\BindingSet;
use Drupal\neo_alchemist_search\Binding\BindingTextReader;

/**
 * Produces the text an entity's Alchemist layouts put on the page.
 *
 * Two sources, because a component tree carries text in two different ways:
 *
 * - Text an editor typed into this entity's own components. Read straight out
 *   of the stored props, and scoped to the components the entity actually owns
 *   so a shared default layout is not re-indexed for every entity of a bundle.
 * - Text the layout pulls from the entity's ordinary fields through value
 *   plugins. The components declare those bindings in configuration, so the
 *   fields can be read directly instead of being rendered.
 *
 * The second source is the one that matters for most content on a typical
 * site: a locked layout stores nothing per entity, so without it a bundle with
 * hundreds of entities would index nothing but its titles.
 *
 * The two are scoped differently and deliberately so. Authored text is taken
 * only from entity-owned components, because an inherited component's stored
 * props are identical on every entity. Bindings are read from *every*
 * component in the tree, inherited ones included, because a binding resolves
 * against this entity's fields and so produces different text for each one.
 */
final class ComponentTextExtractor {

  /**
   * Constructs a ComponentTextExtractor.
   *
   * @param \Drupal\neo_alchemist_search\Authored\AuthoredTextExtractor $authored
   *   Extracts text stored on the entity's own components.
   * @param \Drupal\neo_alchemist_search\Binding\BindingResolver $resolver
   *   Learns which entity fields a component surfaces.
   * @param \Drupal\neo_alchemist_search\Binding\BindingTextReader $reader
   *   Reads those fields off the entity.
   */
  public function __construct(
    private readonly AuthoredTextExtractor $authored,
    private readonly BindingResolver $resolver,
    private readonly BindingTextReader $reader,
  ) {}

  /**
   * Extracts the searchable text of every component tree field on an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity, in the language being indexed.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects the cacheability of what was read, for callers that need it.
   *
   * @return string[]
   *   Normalised, de-duplicated text runs. Empty when the entity's layouts
   *   contribute nothing — which is the correct answer for a locked layout
   *   whose props are all fed by excluded providers, not a failure.
   */
  public function extract(ContentEntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $buffer = new ComponentTextBuffer();
    $cacheability ??= new CacheableMetadata();

    foreach ($this->fieldNames($entity) as $fieldName) {
      $this->authored->extract($entity, $fieldName, $buffer);
      $this->reader->read($entity, $this->bindingsFor($entity, $fieldName), $buffer, $cacheability);
    }

    return $buffer->toArray();
  }

  /**
   * Resolves the bindings declared by every component in a field's tree.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity.
   * @param string $fieldName
   *   The component tree field to inspect.
   *
   * @return \Drupal\neo_alchemist_search\Binding\BindingSet
   *   The union of every placed component's bindings.
   */
  public function bindingsFor(ContentEntityInterface $entity, string $fieldName): BindingSet {
    $set = new BindingSet();
    foreach ($this->componentIds($entity, $fieldName) as $componentId) {
      $set = $set->merge($this->resolver->resolve($componentId));
    }
    return $set;
  }

  /**
   * The component tree fields on an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity.
   *
   * @return string[]
   *   Field names, in definition order.
   */
  public function fieldNames(ContentEntityInterface $entity): array {
    $names = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === 'neo_component_tree') {
        $names[] = (string) $name;
      }
    }
    return $names;
  }

  /**
   * The components placed in a field's tree, inherited ones included.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity.
   * @param string $fieldName
   *   The component tree field to inspect.
   *
   * @return string[]
   *   Component ids, de-duplicated.
   */
  private function componentIds(ContentEntityInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName)) {
      return [];
    }
    $list = $entity->get($fieldName);
    if (!$list instanceof NeoComponentTreeList) {
      return [];
    }
    $item = $list->first();
    if (!$item instanceof ComponentTreeItem) {
      return [];
    }
    $tree = $item->get('tree');
    if ($tree === NULL) {
      return [];
    }
    $ids = [];
    foreach ($tree->getComponentInstanceUuids() as $uuid) {
      $componentId = $tree->getComponentId($uuid);
      if (is_string($componentId) && $componentId !== '') {
        $ids[$componentId] = $componentId;
      }
    }
    return array_values($ids);
  }

}
