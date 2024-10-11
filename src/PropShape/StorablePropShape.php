<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\PropShape;

use Drupal\neo_alchemist\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\neo_alchemist\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\neo_alchemist\PropSource\StaticPropSource;

/**
 * A storable prop shape: a prop shape with corresponding field type + widget.
 */
final class StorablePropShape {

  public function __construct(
    public readonly PropShape $shape,
    // The corresponding UX for the prop shape:
    // - field type to use + which field properties to extract from an instance of the field type
    public readonly FieldTypePropExpression|FieldTypeObjectPropsExpression $fieldTypeProp,
    // - which widget to use to populate an instance of the field type
    public readonly string $fieldWidget,
    // - (optionally) which storage settings to specify for an instance of this
    //   field type — crucial for e.g. the `enum` use case
    public readonly ?array $fieldStorageSettings = NULL,
    // - (optionally) which storage settings to specify for an instance of this
    //   field type — necessary for the `entity_reference` field type
    public readonly ?array $fieldInstanceSettings = NULL,
  ) {
    // In theory, this could be validated: `$this->fieldTypeProp->fieldType` is
    // a field type plugin ID, which determines which field widgets
    // (`$this->fieldWidget`) would be acceptable, and what
    // `$this->fieldStorageSettings`, if any, would be acceptable.
    // In practice, we leave this to the Component config entity, because that
    // is where these values of the StorablePropShape object are persisted.
    // @see \Drupal\neo_alchemist\Entity\Component
    // @see `type: neo_alchemist.component.*`.
  }

  public function toStaticPropSource(): StaticPropSource {
    return StaticPropSource::generate($this->fieldTypeProp, $this->fieldStorageSettings, $this->fieldInstanceSettings);
  }

}
