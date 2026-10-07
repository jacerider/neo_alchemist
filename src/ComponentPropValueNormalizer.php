<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\MapItem;
use Drupal\neo_alchemist\Plugin\ComponentShape\ArrayShape;
use Drupal\neo_alchemist\Plugin\ComponentShape\MediaShapeBase;
use Drupal\neo_alchemist\Plugin\ComponentShape\ObjectShape;
use Drupal\neo_alchemist\Plugin\ComponentShape\RegionShape;
use Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * Converts raw prop values written in code into the stored `{ref, value}` form.
 *
 * A placement stores each prop as `['ref' => …, 'value' => <field item>]`, and
 * shapes only read that wrapper. Code writing a tree by hand tends to pass the
 * value alone — `'title' => 'Text'`, `'image' => 33` — and before this existed
 * such a map was stored without complaint and every prop then rendered the
 * component's examples instead, which looks right with demo data.
 *
 * ComponentTreeItem::addComponent() and ::updateComponent() run every write
 * through here. An entry carrying `ref` or `value` is already in stored form
 * and passes through untouched; anything else is raw and is converted through
 * the prop's own shape, so the stored value is whatever that shape's field item
 * makes of it:
 *
 * - scalars land on the field item's main property (`'Text'` becomes
 *   `['value' => 'Text']`, a media id becomes `['target_id' => 33]`);
 * - structured field items take their own array (a link's `uri`/`title`);
 * - an array prop takes a list of rows and an object or heading prop a map of
 *   children, each converted through its child shape in turn.
 *
 * The SDC `examples` format is NOT accepted: an image's `{src, alt}` describes
 * a rendered value, not a stored media reference.
 *
 * NULL hides: a top-level NULL stores the prop hidden (the one place this
 * writes `options`), and a NULL child is simply left out, which hides it when
 * the prop loads.
 *
 * A prop the component does not declare, or a value its field item refuses,
 * throws rather than storing something that would silently render the
 * examples. Otherwise no `options` are written: a stored prop without them
 * takes its value as the author's decision when it loads, and the parts it
 * leaves out as hidden.
 *
 * @see \Drupal\neo_alchemist\Shape\StoredValueOptions
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::addComponent()
 */
final class ComponentPropValueNormalizer {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Normalizes one placement's prop values.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being placed.
   * @param array $propValues
   *   The placement's values: `status`, `props`, and anything else, which is
   *   kept as it is.
   *
   * @return array
   *   The values with every raw prop converted to `{ref, value}`.
   *
   * @throws \InvalidArgumentException
   *   When a raw prop is not declared by the component, or its value cannot be
   *   stored by the prop's shape.
   */
  public function normalize(ComponentInterface $component, array $propValues): array {
    if (!$this->hasRawProps($propValues)) {
      return $propValues;
    }
    if ($component->isAggregate()) {
      throw new \InvalidArgumentException(sprintf('Component "%s" is in aggregate mode; write its props in stored form under "_aggregate" rather than as raw values.', $component->id()));
    }
    // Converting writes field items on the component's prop shapes, which are
    // memoised on the entity. A fresh copy keeps that away from any instance
    // the caller, or the render, is holding.
    $fresh = $this->entityTypeManager->getStorage('neo_component')->loadUnchanged($component->id());
    if (!$fresh instanceof ComponentInterface) {
      throw new \InvalidArgumentException(sprintf('Cannot convert raw prop values: component "%s" does not exist.', $component->id()));
    }
    $shapes = $fresh->getPropShapes();
    foreach ($propValues['props'] as $propName => $entry) {
      if (!$this->isRaw($entry)) {
        continue;
      }
      $shape = $shapes[$propName] ?? NULL;
      if (!$shape) {
        throw new \InvalidArgumentException(sprintf('Component "%s" has no prop "%s".', $component->id(), $propName));
      }
      // NULL hides the prop. A value can only say what to show, and a prop
      // left out of the write falls back to its default — the component's
      // example, often. Hiding is an option, so it is stored as one.
      if ($entry === NULL) {
        if (!$shape->getOptionEmpty()->isAllowed()) {
          throw new \InvalidArgumentException(sprintf('Prop "%s" of component "%s" cannot be hidden.', $propName, $component->id()));
        }
        $propValues['props'][$propName] = [
          'ref' => $shape->getRef(),
          'value' => [],
          'options' => [$shape->id() => ['empty' => 1, 'default' => 0]],
        ];
        continue;
      }
      $propValues['props'][$propName] = [
        'ref' => $shape->getRef(),
        'value' => $this->convert($component, $shape, $entry, (string) $propName),
      ];
    }
    return $propValues;
  }

  /**
   * Whether any prop in a placement's values is raw.
   *
   * Cheap, and needs no component: callers use it to skip loading one for the
   * stored-form writes that make up nearly every call.
   *
   * @param array $propValues
   *   The placement's values.
   *
   * @return bool
   *   TRUE when at least one prop needs ::normalize().
   */
  public function hasRawProps(array $propValues): bool {
    if (empty($propValues['props']) || !is_array($propValues['props'])) {
      return FALSE;
    }
    foreach ($propValues['props'] as $entry) {
      if ($this->isRaw($entry)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a prop entry is a raw value rather than the stored wrapper.
   *
   * @param mixed $entry
   *   The prop entry.
   *
   * @return bool
   *   TRUE when the entry carries neither `ref` nor `value`.
   */
  private function isRaw(mixed $entry): bool {
    return !is_array($entry) || (!array_key_exists('ref', $entry) && !array_key_exists('value', $entry));
  }

  /**
   * Converts a raw value into the stored value of the given shape.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being placed, for error messages.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The initialised shape the value belongs to.
   * @param mixed $raw
   *   The raw value.
   * @param string $path
   *   The prop path, for error messages.
   *
   * @return mixed
   *   The stored value.
   */
  private function convert(ComponentInterface $component, ComponentShapePluginInterface $shape, mixed $raw, string $path): mixed {
    if ($shape instanceof RegionShape) {
      throw new \InvalidArgumentException(sprintf('Prop "%s" of component "%s" is a region; place components into it rather than giving it a raw value.', $path, $component->id()));
    }
    if ($shape instanceof ArrayShape) {
      if (!is_array($raw) || !array_is_list($raw)) {
        throw new \InvalidArgumentException(sprintf('Prop "%s" of component "%s" takes a list of rows.', $path, $component->id()));
      }
      $rows = [];
      foreach ($raw as $delta => $row) {
        $rows[] = $this->convertChildren($component, $shape, $delta, $row, "$path.$delta");
      }
      return $rows;
    }
    // Media shapes are objects, but they store their own field item (a media
    // reference), not a map of their children.
    if ($shape instanceof ObjectShape && !$shape instanceof MediaShapeBase) {
      return $this->convertChildren($component, $shape, NULL, $raw, $path);
    }
    return $this->convertLeaf($component, $shape, $raw, $path);
  }

  /**
   * Converts a raw map of child values through each child's shape.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being placed, for error messages.
   * @param \Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase $parent
   *   The initialised shape owning the children.
   * @param int|null $delta
   *   The row the children belong to, when the parent is iterable.
   * @param mixed $raw
   *   The raw map.
   * @param string $path
   *   The path of the shape owning the children, for error messages.
   *
   * @return array
   *   Child name => stored value.
   */
  private function convertChildren(ComponentInterface $component, ChildrenShapeBase $parent, ?int $delta, mixed $raw, string $path): array {
    if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
      throw new \InvalidArgumentException(sprintf('Prop "%s" of component "%s" takes a map of its children.', $path, $component->id()));
    }
    $children = [];
    foreach ($parent->getChildShapes($delta) as $child) {
      $children[$child->getName()] = $child;
    }
    $values = [];
    foreach ($raw as $name => $childRaw) {
      // A NULL child is left out, which hides it when the prop loads.
      if ($childRaw === NULL) {
        continue;
      }
      if (!isset($children[$name])) {
        throw new \InvalidArgumentException(sprintf('Prop "%s" of component "%s" has no child "%s".', $path, $component->id(), $name));
      }
      $values[$name] = $this->convert($component, $children[$name], $childRaw, "$path.$name");
    }
    return $values;
  }

  /**
   * Converts a raw value through a shape's own field item.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being placed, for error messages.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The initialised shape.
   * @param mixed $raw
   *   The raw value.
   * @param string $path
   *   The prop path, for error messages.
   *
   * @return array
   *   The field item's stored value.
   */
  private function convertLeaf(ComponentInterface $component, ComponentShapePluginInterface $shape, mixed $raw, string $path): array {
    $fail = fn (string $reason, ?\Throwable $previous = NULL) => new \InvalidArgumentException(sprintf('Prop "%s" of component "%s" cannot store %s: %s', $path, $component->id(), $this->describe($raw), $reason), 0, $previous);
    $item = $shape->getFieldItem();
    try {
      // init() seeded the item with the schema examples; start from nothing so
      // none of them are stored beside the author's value.
      $item->setValue(NULL);
      $shape->setFieldItemValue($raw, FALSE);
    }
    catch (\Throwable $e) {
      throw $fail($e->getMessage(), $e);
    }
    $value = $shape->getFieldItemValue();
    // A map item takes any keys. Every other item names its properties: a raw
    // key outside them is a typo, and only stored properties are kept — a
    // computed one, or a key the shape adds (a link's access), is re-derived
    // on load.
    if (!$item instanceof MapItem) {
      $properties = $item->getDataDefinition()->getPropertyDefinitions();
      if (is_array($raw) && ($unknown = array_diff(array_keys($raw), array_keys($properties)))) {
        throw $fail(sprintf('its field item has no "%s" property.', implode('", "', $unknown)));
      }
      $value = array_intersect_key($value, array_filter($properties, fn ($definition) => !$definition->isComputed()));
    }
    if ($value === [] && $raw !== NULL && $raw !== '' && $raw !== []) {
      throw $fail('its field item reads it as empty.');
    }
    // The field item's own validate() is not usable here: these items have no
    // host entity, and several core constraints ask for one. These are the
    // two mistakes a hand-written value actually makes.
    if (isset($value['target_id']) && $item->getDataDefinition()->getPropertyDefinition('entity') && !$item->get('entity')->getValue()) {
      throw $fail('it references no existing entity.');
    }
    $allowed = $item->getFieldDefinition()->getSetting('allowed_values');
    if (is_array($allowed) && $allowed && isset($value['value']) && !array_key_exists($value['value'], $allowed)) {
      throw $fail(sprintf('it is not one of the allowed values (%s).', implode(', ', array_keys($allowed))));
    }
    return $value;
  }

  /**
   * Describes a raw value for an error message.
   *
   * @param mixed $raw
   *   The raw value.
   *
   * @return string
   *   A short, printable description.
   */
  private function describe(mixed $raw): string {
    $json = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: get_debug_type($raw);
    return mb_strlen($json) > 120 ? mb_substr($json, 0, 117) . '...' : $json;
  }

}
