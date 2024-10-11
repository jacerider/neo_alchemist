<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\PropShape;

use Drupal\neo_alchemist\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\neo_alchemist\PropExpressions\StructuredData\FieldTypePropExpression;

/**
 * A candidate storable prop shape: for hook_storage_prop_shape_alter().
 *
 * The difference with StorablePropShape: all alterable properties are:
 * - writable instead of read-only
 * - optional instead of required
 *
 * @see \Drupal\neo_alchemist\PropShape\StorablePropShape
 */
final class CandidateStorablePropShape {

  public function __construct(
    public readonly PropShape $shape,
    public FieldTypePropExpression|FieldTypeObjectPropsExpression|null $fieldTypeProp = NULL,
    public string|null $fieldWidget = NULL,
    public array|null $fieldStorageSettings = NULL,
    public array|null $fieldInstanceSettings = NULL,
  ) {}

  public static function fromStorablePropShape(StorablePropShape $immutable): CandidateStorablePropShape {
    return new CandidateStorablePropShape(
      $immutable->shape,
      $immutable->fieldTypeProp,
      $immutable->fieldWidget,
      $immutable->fieldStorageSettings,
      $immutable->fieldInstanceSettings,
    );
  }

  public function toStorablePropShape() : ?StorablePropShape {
    if ($this->fieldTypeProp === NULL) {
      return NULL;
    }

    // Note: this will result in a fatal PHP error if a
    // hook_storage_prop_shape_alter() implementation alters incorrectly.
    // @phpstan-ignore-next-line
    return new StorablePropShape($this->shape, $this->fieldTypeProp, $this->fieldWidget, $this->fieldStorageSettings, $this->fieldInstanceSettings);
  }

}
