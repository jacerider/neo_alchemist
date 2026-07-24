<?php

namespace Drupal\neo_alchemist\Routing;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Parameter converter for a custom ID.
 */
class FieldComponentParamConverter implements ParamConverterInterface {

  /**
   * Entity type manager which performs the upcasting in the end.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new EntityConverter.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entityFieldManager, RequestStack $requestStack) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entityFieldManager;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return isset($definition['type']) && $definition['type'] == 'neo_alchemist_field_component';
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    $neoField = $defaults['neo_field'] ?? NULL;
    if ($neoField instanceof ComponentTreeItem) {
      // The $value can be either a component id or a component UUID.
      if ($neoComponent = $this->entityTypeManager->getStorage('neo_component')->load($value)) {
        /** @var \Drupal\neo_alchemist\ComponentInstanceInterface $instance */
        $instance = $neoField->createComponent($neoComponent);
        // The add routes carry the target section in the 'parent' query
        // parameter. Attach it so route access evaluates the real target
        // (hybrid mode restricts creation to entity-customizable regions).
        $parent = (string) ($this->requestStack->getCurrentRequest()?->query->get('parent') ?? '');
        if ($parent && str_contains($parent, '--')) {
          [$parentUuid, $shapeId] = explode('--', $parent, 2);
          $parentComponent = $parentUuid && $shapeId ? $neoField->getComponent($parentUuid) : NULL;
          if ($parentComponent) {
            $shape = $parentComponent->getPropShapesAll(NULL, TRUE)[$shapeId] ?? NULL;
            if ($shape && $shape->getRef() === 'region') {
              $instance->setParent($parentUuid, $shapeId);
            }
          }
        }
        return $instance;
      }
      return $neoField->getComponent($value);
    }
    return NULL;
  }

}
