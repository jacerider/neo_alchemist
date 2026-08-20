<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Match;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Provides methods for matching fields between content entities and shapes.
 */
abstract class MatcherBase {

  use StringTranslationTrait;

  /**
   * The maximum number of levels to match.
   *
   * @var int
   */
  protected $maxLevels = 1;

  /**
   * Constructs a MatcherField object.
   */
  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * Determines if the given data definition or typed data is a reference.
   *
   * This method checks if the provided data definition or typed data is a
   * reference type. It throws an exception if the provided typed data is not a
   * leaf node.
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface|\Drupal\Core\TypedData\DataDefinitionInterface $td_or_dd
   *   The data definition or typed data to check.
   *
   * @return bool|null
   *   TRUE if the data definition is a reference, FALSE if it is a primitive
   *   type, or NULL if the type cannot be handled and merits logging.
   *
   * @throws \LogicException
   *   Thrown when the provided typed data is not a leaf node.
   */
  protected function isReference(TypedDataInterface|DataDefinitionInterface $td_or_dd): ?bool {
    if ($td_or_dd instanceof TypedDataInterface && !$td_or_dd->getParent() instanceof FieldItemInterface) {
      throw new \LogicException(__METHOD__ . ' was given a non-leaf.');
    }
    $dd = $td_or_dd instanceof TypedDataInterface ? $td_or_dd->getDataDefinition() : $td_or_dd;
    return match(TRUE) {
      $dd instanceof DataReferenceDefinitionInterface => TRUE,
      is_a($dd->getClass(), PrimitiveInterface::class, TRUE) => FALSE,
      // Anything else cannot be handled and merits logging.
      TRUE => NULL,
    };
  }

  /**
   * Provides data definitions based on the type of DataDefinitionInterface.
   *
   * This method uses a match expression to handle different types of data
   * definitions:
   * - EntityDataDefinitionInterface: Handles entity level data definitions.
   * - FieldDefinitionInterface: Recursively handles field level data
   *   definitions.
   * - FieldItemDataDefinitionInterface: Handles field item data definitions.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $dd
   *   The data definition to process.
   * @param bool $allBundles
   *   Whether to process all bundles or only the base fields.
   *
   * @return array
   *   An array of data definitions based on the type of the provided data
   *   definition.
   *
   * @throws \LogicException
   *   Thrown when an unhandled data definition type is encountered.
   */
  protected function dataDefinitions(DataDefinitionInterface $dd, bool $allBundles = TRUE): array {
    return match (TRUE) {
      // Entity level.
      $dd instanceof EntityDataDefinitionInterface => (function ($dd) use ($allBundles) {
        if ($dd->getClass() === ConfigEntityAdapter::class) {
          // @todo load config entity type, look at export properties?
          return [];
        }
        assert($dd->getClass() === EntityAdapter::class);
        $entityTypeId = $dd->getEntityTypeId();
        assert(is_string($entityTypeId));
        // If no bundles or multiple bundles are specified, inspect the base
        // fields. Otherwise (if a single bundle is specified), inspect all
        // fields.
        if ($dd->getBundles() !== NULL && count($dd->getBundles()) === 1) {
          return $this->entityFieldManager->getFieldDefinitions($entityTypeId, $dd->getBundles()[0]);
        }
        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        if (empty($entityType->getBundleEntityType())) {
          // For entity types without bundles, return the base fields.
          return $this->entityFieldManager->getBaseFieldDefinitions($entityTypeId, $entityTypeId);
        }
        if ($allBundles) {
          $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
          $definitions = [];
          foreach ($bundles as $bundle => $bundle_info) {
            if ($bundle !== $entityTypeId) {
              $bundleEntityDataDefinition = EntityDataDefinition::createFromDataType(implode(':', [
                'entity',
                $entityTypeId,
                $bundle,
              ]));
              $definitions += $this->dataDefinitions($bundleEntityDataDefinition, FALSE);
            }
          }
          return $definitions;
        }
        return $this->entityFieldManager->getBaseFieldDefinitions($entityTypeId);
      })($dd),
      // Field level.
      $dd instanceof FieldDefinitionInterface => $this->recurseDataDefinitionInterface($dd->getItemDefinition()),
      $dd instanceof FieldItemDataDefinitionInterface => $dd->getPropertyDefinitions(),
      default => throw new \LogicException('Unhandled.'),
    };
  }

  /**
   * Recursively matches data definitions and returns field definitions.
   *
   * This method inspects the provided DataDefinitionInterface instance and
   * determines the appropriate field definitions based on the type of data
   * definition. It handles entity-level and field-level data definitions.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $dd
   *   The data definition to inspect.
   *
   * @return array
   *   An array of field definitions.
   *
   * @throws \LogicException
   *   Thrown when an unhandled data definition type is encountered.
   */
  protected function recurseDataDefinitionInterface(DataDefinitionInterface $dd): array {
    return match (TRUE) {
      // Entity level.
      $dd instanceof EntityDataDefinitionInterface => (function ($dd) {
        if ($dd->getClass() === ConfigEntityAdapter::class) {
          // @todo load config entity type, look at export properties?
          return [];
        }
        assert($dd->getClass() === EntityAdapter::class);
        $entityTypeId = $dd->getEntityTypeId();
        assert(is_string($entityTypeId));
        // If no bundles or multiple bundles are specified, inspect the base
        // fields. Otherwise (if a single bundle is specified), inspect all
        // fields.
        if ($dd->getBundles() !== NULL && count($dd->getBundles()) === 1) {
          return $this->entityFieldManager->getFieldDefinitions($entityTypeId, $dd->getBundles()[0]);
        }
        return $this->entityFieldManager->getBaseFieldDefinitions($entityTypeId);
      })($dd),
      // Field level.
      $dd instanceof FieldDefinitionInterface => $this->recurseDataDefinitionInterface($dd->getItemDefinition()),
      $dd instanceof FieldItemDataDefinitionInterface => $dd->getPropertyDefinitions(),
      default => throw new \LogicException('Unhandled.'),
    };
  }

  /**
   * Get the label for a set of definitions.
   *
   * @param Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $definitions
   *   The definitions.
   * @param string|\Stringable|null $propertyLabel
   *   The property label.
   *
   * @return string
   *   The key.
   */
  protected function label(array $definitions, $propertyLabel = NULL): string {
    $definition = end($definitions);
    return $definition->getLabel() . ($propertyLabel ? ": $propertyLabel" : '') . ' (' . $definition->getName() . ')';
  }

  /**
   * Get the group for a set of definitions.
   *
   * @param Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $definitions
   *   The definitions.
   *
   * @return string
   *   The key.
   */
  protected function group(array $definitions): string {
    $first = array_shift($definitions);
    $group = [
      ucwords(str_replace('_', ' ', $first->getTargetEntityTypeId())),
    ];
    if (!empty($definitions)) {
      $group = [
        ucwords(str_replace('_', ' ', $first->getTargetEntityTypeId())) . ' (' . $first->getName() . ')',
      ];
      foreach ($definitions as $delta => $definition) {
        if ($delta + 1 === count($definitions)) {
          $group[] = ucwords(str_replace('_', ' ', $definition->getTargetEntityTypeId()));
        }
        else {
          $group[] = ucwords(str_replace('_', ' ', $definition->getTargetEntityTypeId())) . ' (' . $definition->getName() . ')';
        }
      }
    }
    return implode(' → ', $group);
  }

  /**
   * Get the key for a set of definitions.
   *
   * @param Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $definitions
   *   The definitions.
   * @param string|null $propertyName
   *   The property name.
   *
   * @return string
   *   The key.
   */
  protected function key(array $definitions, $propertyName = NULL): string {
    $key = implode('.', array_map(fn ($d) => $d->getName(), $definitions));
    if ($propertyName) {
      $key .= ':' . $propertyName;
    }
    return $key;
  }

}
