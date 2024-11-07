<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;

/**
 * Data type for stroring component prop values.
 */
#[DataType(
  id: "neo_component_props_values",
  label: new TranslatableMarkup("Component prop values"),
  description: new TranslatableMarkup("The prop values for the components in a component tree: without structure"),
)]
class ComponentPropsValues extends TypedData implements \Stringable {

  /**
   * The data value.
   *
   * @var string
   *
   * @todo Delete this property after https://www.drupal.org/project/drupal/issues/2232427
   */
  protected string $value;

  /**
   * The parsed data value.
   *
   * @var array
   */
  protected array $propsValues = [];

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    // @todo Uncomment next line and delete last line after https://www.drupal.org/project/drupal/issues/2232427
    // return $this->propsValues;
    // Fall back to NULL if not yet initialized, to allow validation.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    return $this->value ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    // Default to the empty JSON object.
    $this->setValue('{}', $notify);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    assert(str_starts_with($value, '{'));
    // @todo Delete next line; update this code to ONLY do the JSON-to-PHP-object parsing after https://www.drupal.org/project/drupal/issues/2232427 lands — that will allow specifying the "json" serialization strategy rather than only PHP's serialize().
    $this->value = $value;
    $this->propsValues = Json::decode($value);

    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return Json::encode($this->propsValues);
  }

  /**
   * Set prop values for a component instance.
   *
   * @param string $uuid
   *   The UUID of the component instance.
   * @param array $propValues
   *   The prop values for the component instance.
   */
  public function setComponent(string $uuid, array $propValues = []) {
    $this->propsValues[$uuid] = $propValues;
    $this->setValue(Json::encode($this->propsValues));
  }

  /**
   * Remove a component instance from the prop values.
   *
   * @param string $uuid
   *   The UUID of the component instance.
   */
  public function removeComponent(string $uuid) {
    unset($this->propsValues[$uuid]);
    $this->setValue(empty($this->propsValues) ? '{}' : Json::encode($this->propsValues));
  }

  /**
   * Get the prop values for a component instance.
   *
   * @return string[]
   *   Component instance UUIDs.
   */
  public function getComponentInstanceUuids(): array {
    return array_keys($this->propsValues);
  }

  /**
   * Get component prop sources.
   *
   * @param string $component_instance_uuid
   *   The UUID of a placed component instance.
   *
   * @return array
   *   The component prop sources.
   */
  public function getComponentPropsSources(string $component_instance_uuid): array {
    if (!array_key_exists($component_instance_uuid, $this->propsValues)) {
      throw new \OutOfRangeException(sprintf('No props sources stored for %s. Caused by either incorrect logic or `props` being out of sync with `tree`.', $component_instance_uuid));
    }
    return $this->propsValues[$component_instance_uuid];
  }

}
