<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\PropShape;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\neo_alchemist\JsonSchemaInterpreter\SdcPropJsonSchemaType;
use Drupal\neo_alchemist\PropExpressions\Component\ComponentPropExpression;

/**
 * A prop shape: a normalized SDC prop schema.
 *
 * Pass a `Component` plugin instance to `PropShape::getComponentProps()` and
 * receive an array of PropShape objects.
 *
 * @phpstan-type JsonSchema array<string, mixed>
 */
final class PropShape {

  /**
   * The resolved schema of the prop shape.
   */
  public readonly array $resolvedSchema;

  /**
   *
   */
  public function __construct(
    // The schema of the prop shape.
    public readonly array $schema,
  ) {
    if ($schema !== self::normalizePropSchema($this->schema)) {
      throw new \InvalidArgumentException();
    }
    $this->resolvedSchema = self::resolveSchemaReferences($schema);
  }

  /**
   *
   */
  public static function normalize(array $raw_sdc_prop_schema): PropShape {
    return new PropShape(self::normalizePropSchema($raw_sdc_prop_schema));
  }

  /**
   * @param JsonSchema $schema
   * @return JsonSchema
   *
   * @see \Drupal\neo_alchemist\Plugin\Adapter\AdapterBase::resolveSchemaReferences
   */
  private static function resolveSchemaReferences(array $schema): array {
    if (isset($schema['$ref'])) {
      // Perform the same schema resolving as `justinrainbow/json-schema`.
      // @todo Delete this method, actually use `justinrainbow/json-schema`.
      $schema = json_decode(file_get_contents($schema['$ref']) ?: '{}', TRUE);
    }

    // Recurse.
    if ($schema['type'] === 'object') {
      $schema['properties'] = array_map([__CLASS__, 'resolveSchemaReferences'], $schema['properties'] ?? []);
    }
    elseif ($schema['type'] === 'array' && is_array($schema['items'])) {
      $schema['items'] = self::resolveSchemaReferences($schema['items']);
      // $schema['items'] = array_map([__CLASS__, 'resolveSchemaReferences'], $schema['items'] ?? []);
      // $schema['items'] = array_map(function ($item) {
      //   ksm($item);
      // }, $schema['items'] ?? []);
    }

    return $schema;
  }

  /**
   * @param \Drupal\Core\Plugin\Component $component
   *
   * @return \Drupal\neo_alchemist\PropShape\PropShape[]
   */
  public static function getComponentProps(Component $component): array {
    return self::getComponentPropsForMetadata($component->getPluginId(), $component->metadata);
  }

  /**
   * @param string $plugin_id
   * @param \Drupal\Core\Theme\Component\ComponentMetadata $metadata
   *
   * @return \Drupal\neo_alchemist\PropShape\PropShape[]
   */
  public static function getComponentPropsForMetadata(string $plugin_id, ComponentMetadata $metadata): array {
    $prop_shapes = [];

    // Retrieve the full JSON schema definition from the SDC's metadata.
    // @see \Drupal\sdc\Component\ComponentValidator::validateProps()
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
    /** @var array<string, mixed> $component_schema */
    $component_schema = $metadata->schema;
    foreach ($component_schema['properties'] ?? [] as $prop_name => $prop_schema) {
      // TRICKY: `attributes` is a special case — it is kind of a reserved
      // prop.
      // @see \Drupal\sdc\Twig\TwigExtension::mergeAdditionalRenderContext()
      // @see https://www.drupal.org/project/drupal/issues/3352063#comment-15277820
      if ($prop_name === 'attributes') {
        assert($prop_schema['type'][0] === Attribute::class);
        continue;
      }

      $component_prop_expression = new ComponentPropExpression($plugin_id, $prop_name);
      $prop_shapes[(string) $component_prop_expression] = static::normalize($prop_schema);
    }

    return $prop_shapes;
  }

  /**
   *
   */
  public function uniquePropSchemaKey(): string {
    // A reliable key thanks to ::normalizePropSchema().
    return urldecode(http_build_query($this->schema));
  }

  /**
   * @param JsonSchema $prop_schema
   *
   * @return JsonSchema
   */
  public static function normalizePropSchema(array $prop_schema): array {
    ksort($prop_schema);
    // Ensure that `type` is always listed first.
    $normalized_prop_schema = ['type' => $prop_schema['type']] + $prop_schema;

    // Title, description and examples do not affect which field type + widget
    // should be used.
    unset($normalized_prop_schema['title']);
    unset($normalized_prop_schema['description']);
    unset($normalized_prop_schema['examples']);
    // @todo Add support to `SDC` for `default` in https://www.drupal.org/project/neo_alchemist/issues/3462705?
    // @see https://json-schema.org/draft/2020-12/draft-bhutton-json-schema-validation-00#rfc.section.9.2
    unset($normalized_prop_schema['default']);

    $normalized_prop_schema['type'] = SdcPropJsonSchemaType::from(
    // TRICKY: SDC always allowed `object` for Twig integration reasons.
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
      is_array($prop_schema['type']) ? $prop_schema['type'][0] : $prop_schema['type']
    )->value;

    return $normalized_prop_schema;
  }

  /**
   *
   */
  public function getStorage(): ?StorablePropShape {
    // The default storable prop shape, if any. Prefer the original prop shape,
    // which may contain `$ref`, and allows hook_storage_prop_shape_alter()
    // implementations to suggest a field type based on the
    // definition name.
    // If that finds no field type storage, resolve `$ref`, which removes `$ref`
    // altogether. Try to find a field type storage again, but then the decision
    // relies solely on the final (fully resolved) JSON schema.
    $json_schema_type = SdcPropJsonSchemaType::from($this->schema['type']);
    $storable_prop_shape = SdcPropJsonSchemaType::from($this->schema['type'])->computeStorablePropShape($this);
    if ($storable_prop_shape === NULL) {
      $resolved_prop_shape = PropShape::normalize($this->resolvedSchema);
      $storable_prop_shape = $json_schema_type->computeStorablePropShape($resolved_prop_shape);
    }

    $alterable = $storable_prop_shape
      ? CandidateStorablePropShape::fromStorablePropShape($storable_prop_shape)
      // If no default storable prop shape exists, generate an empty candidate.
      : new CandidateStorablePropShape($this);

    // Allow modules to alter the default.
    self::moduleHandler()->alter(
      'storage_prop_shape',
      // The value that other modules can alter.
      $alterable,
    );

    // @todo DX: validate that the field type exists.
    // @todo DX: validate that the field prop exists.
    // @todo DX: validate that the field widget exists.
    return $alterable->toStorablePropShape();
  }

  /**
   *
   */
  private static function moduleHandler(): ModuleHandlerInterface {
    return \Drupal::moduleHandler();
  }

}
