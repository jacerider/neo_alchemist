<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * Shared fixture for the entity field picker.
 *
 * The picker resolves a shape from scalars and then asks MatcherField what
 * that shape may take its value from, so a test needs a real component with a
 * real target entity type. `user` is the target because it is already in every
 * kernel test's baseline and still has what the tree needs: plain string
 * fields, an excluded field type (`pass`), system plumbing (`uuid`,
 * `langcode`), link templates, and exactly one entity reference (`roles`) to
 * descend through.
 */
abstract class FieldMatchKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The heading's `size` sub-prop is a StyleShape backed by `list_string`.
    'options',
    // MatcherField::dynamicEntityDefinitions() synthesises a `link`-typed
    // definition per entity link template, so the matcher cannot run at all
    // without the field type behind it.
    'link',
    // A second content entity type, so the entity-type override has somewhere
    // to point that actually has fields (a config entity has none).
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['user']);
    // FieldMatchLocator::resolveShape() runs the component's update access
    // check, so an anonymous kernel test resolves nothing at all.
    $this->setUpCurrentUser(['uid' => 1], ['administer neo_alchemist']);

    Component::create([
      'id' => 'na_heading',
      'label' => 'Heading fixture',
      'description' => 'Heading fixture',
      'component' => 'neo_alchemist_test:na_heading',
      'target_entity_type' => 'user',
      'status' => TRUE,
    ])->save();
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_heading')
      ->set('settings.props.heading.prop', 'heading')
      ->set('settings.props.heading.ref', 'heading')
      ->set('settings.props.heading.active', TRUE)
      ->set('settings.props.heading.editable', TRUE)
      ->save();
    $this->container->get('entity_type.manager')->getStorage('neo_component')->resetCache();
  }

  /**
   * The locator under test.
   *
   * @return \Drupal\neo_alchemist\Match\FieldMatchLocator
   *   The locator.
   */
  protected function locator() {
    return $this->container->get('neo_alchemist.field_match_locator');
  }

  /**
   * The heading's title sub-prop shape.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   *   The shape.
   */
  protected function titleShape(): ComponentShapePluginInterface {
    $shape = $this->locator()->resolveShape('na_heading', 'heading', 'heading~title');
    $this->assertNotNull($shape, 'The fixture shape resolves.');
    return $shape;
  }

}
