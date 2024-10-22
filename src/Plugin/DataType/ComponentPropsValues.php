<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\DataType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;

/**
 * @todo Implement ListInterface because it conceptually fits, but … what does it get us?
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
   * @var array<string, array<string, array{'sourceType': string, 'value': array<mixed>, 'expression': string}>>
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
  public function __toString(): string {
    return '';
  }

}
