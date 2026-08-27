<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Binding;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\neo_alchemist\Match\MatcherField;
use Drupal\neo_alchemist_search\ComponentTextBuffer;

/**
 * Reads the text out of the fields a layout declares it surfaces.
 *
 * The field keys use the matcher grammar, including reference hops, so the
 * resolution itself is delegated to the matcher service that already
 * implements it — including honouring the publication status of a referenced
 * entity. That service loads entities for hops, which is bounded and
 * necessary, but it renders nothing and executes no views.
 */
final class BindingTextReader {

  /**
   * How many deltas of one field to read.
   *
   * Long multi-value fields are almost always listings rather than prose about
   * the entity, and the tail of one adds noise faster than recall.
   */
  private const MAX_DELTAS = 20;

  /**
   * Constructs a BindingTextReader.
   *
   * @param \Drupal\neo_alchemist\Match\MatcherField $matcherField
   *   Resolves a field key against an entity, hops included.
   * @param \Drupal\neo_alchemist_search\Binding\FieldTextPolicy $policy
   *   Decides which field properties carry text.
   */
  public function __construct(
    private readonly MatcherField $matcherField,
    private readonly FieldTextPolicy $policy,
  ) {}

  /**
   * Collects the text the bindings name.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity, in the language being indexed.
   * @param \Drupal\neo_alchemist_search\Binding\BindingSet $set
   *   The bindings to read.
   * @param \Drupal\neo_alchemist_search\ComponentTextBuffer $buffer
   *   Collects the text runs.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of everything read, so a caller that is not
   *   the indexer can stay cache-correct.
   */
  public function read(
    ContentEntityInterface $entity,
    BindingSet $set,
    ComponentTextBuffer $buffer,
    CacheableMetadata $cacheability,
  ): void {
    $labelKey = $this->labelFieldName($entity);

    foreach ($set->descriptors as $descriptor) {
      if ($buffer->isFull()) {
        return;
      }
      // A layout almost always shows the entity's own title somewhere. Search
      // indexes that separately and usually at a higher weight, so repeating
      // it in the body text would score a title match twice and push these
      // entities above ones that genuinely discuss the phrase. A title reached
      // through a reference belongs to a different entity, so it stays.
      if ($descriptor->hops === 0 && $labelKey !== NULL && $this->fieldName($descriptor) === $labelKey) {
        continue;
      }
      $list = $this->resolve($entity, $descriptor, $cacheability);
      if ($list === NULL) {
        continue;
      }
      $this->readList($list, $descriptor, $buffer);
    }
  }

  /**
   * The field that holds an entity's label, if it has one.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity.
   *
   * @return string|null
   *   The label field name, or NULL when the entity type declares none.
   */
  private function labelFieldName(ContentEntityInterface $entity): ?string {
    $key = $entity->getEntityType()->getKey('label');
    return is_string($key) && $key !== '' ? $key : NULL;
  }

  /**
   * The field name a binding names, without its property or hops.
   *
   * @param \Drupal\neo_alchemist_search\Binding\BindingDescriptor $descriptor
   *   The binding.
   *
   * @return string
   *   The field name.
   */
  private function fieldName(BindingDescriptor $descriptor): string {
    return explode(':', $descriptor->fieldKey)[0];
  }

  /**
   * Resolves one binding against the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity.
   * @param \Drupal\neo_alchemist_search\Binding\BindingDescriptor $descriptor
   *   The binding to resolve.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects cacheability.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|null
   *   The resolved field, or NULL when the key does not apply to this entity.
   */
  private function resolve(
    ContentEntityInterface $entity,
    BindingDescriptor $descriptor,
    CacheableMetadata $cacheability,
  ): ?FieldItemListInterface {
    try {
      $list = $this->matcherField->getEntityField($entity, $descriptor->fieldKey, TRUE, $cacheability);
    }
    catch (\Throwable) {
      // A key that names a field this bundle does not have is normal: one
      // component can be placed on several bundles.
      return NULL;
    }
    return $list instanceof FieldItemListInterface && !$list->isEmpty() ? $list : NULL;
  }

  /**
   * Reads the text properties of every delta of a resolved field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $list
   *   The resolved field.
   * @param \Drupal\neo_alchemist_search\Binding\BindingDescriptor $descriptor
   *   The binding that produced it.
   * @param \Drupal\neo_alchemist_search\ComponentTextBuffer $buffer
   *   Collects the text runs.
   */
  private function readList(
    FieldItemListInterface $list,
    BindingDescriptor $descriptor,
    ComponentTextBuffer $buffer,
  ): void {
    $fieldType = $list->getFieldDefinition()->getType();
    if (!$this->policy->allowsType($fieldType)) {
      return;
    }
    // A property named on the binding key itself wins over the policy: the
    // component said exactly which part of the field it puts on the page.
    $explicit = $this->explicitProperties($descriptor);

    $delta = 0;
    foreach ($list as $item) {
      if (!$item instanceof FieldItemInterface || $delta >= self::MAX_DELTAS || $buffer->isFull()) {
        return;
      }
      $delta++;
      foreach ($item->getProperties(FALSE) as $name => $property) {
        $name = (string) $name;
        if ($explicit !== NULL) {
          if (!in_array($name, $explicit, TRUE)) {
            continue;
          }
        }
        else {
          $definition = $item->getFieldDefinition()
            ->getFieldStorageDefinition()
            ->getPropertyDefinition($name);
          if ($definition === NULL || !$this->policy->isTextProperty($definition, $name)) {
            continue;
          }
        }
        $value = $property->getValue();
        // Rich text arrives with markup that would otherwise become tokens.
        $buffer->add(is_scalar($value) ? (string) $value : NULL, TRUE);
      }
    }
  }

  /**
   * The properties this binding explicitly asked for, if any.
   *
   * @param \Drupal\neo_alchemist_search\Binding\BindingDescriptor $descriptor
   *   The binding.
   *
   * @return string[]|null
   *   Property names, or NULL to let the policy decide.
   */
  private function explicitProperties(BindingDescriptor $descriptor): ?array {
    $hops = explode('.', $descriptor->fieldKey);
    $parts = explode(':', (string) end($hops));
    // `field_name:property`, but never `field_name:entity`, which names the
    // referenced entity rather than a property to read.
    if (count($parts) > 1 && $parts[1] !== '' && $parts[1] !== 'entity') {
      return [$parts[1]];
    }
    return NULL;
  }

}
