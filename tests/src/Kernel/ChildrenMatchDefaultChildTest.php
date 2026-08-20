<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the "Use Default" child binding on a children-match provider.
 *
 * A child shape bound to `_default` is saying "this provider supplies nothing;
 * fall back to the prop's own schema example". The provider seeds every child
 * with `[]` before dispatching, and an empty array is a *value* to the `??`
 * chain in Object/ArrayShape::loadChildSchema() that distributes the resolved
 * value onto the child schemas — so leaving the seed in place overwrote the
 * child's `examples`, and the child then dutifully used a default of nothing.
 *
 * Symptom: a style prop set to "Use Default" emitted no class at all — a
 * component that should carry `component-spacing` / `neo-section` rendered
 * bare, with nothing in the config to suggest why.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
 */
#[Group('neo_alchemist')]
class ChildrenMatchDefaultChildTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'options',
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
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
  }

  /**
   * Builds an aggregated component with the given per-child field bindings.
   *
   * @param array $shapeFields
   *   The children-match `shape_fields` configuration.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component.
   */
  private function buildComponent(array $shapeFields): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Default child fixture',
      'description' => 'Default child fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
      'aggregate' => TRUE,
      'target_entity_type' => 'entity_test',
    ]);
    $component->save();
    $id = $component->id();

    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props._aggregate.plugins._aggregate', [
        'entity_query' => [
          'id' => 'entity_query',
          'settings' => [
            'entity_type' => 'entity_test',
            'length' => 10,
            'shape_fields' => $shapeFields,
          ],
        ],
      ])
      ->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

  /**
   * A `_default` child resolves to its own schema example.
   *
   * The one that matters: this is the value that reaches the rendered page.
   */
  public function testDefaultChildResolvesToSchemaExample(): void {
    EntityTest::create(['name' => 'ENTITY NAME'])->save();

    $child = $this->defaultBoundLinkChild();

    $this->assertSame(
      'https://example.com',
      $child->getValue()['uri'] ?? NULL,
      '"Use Default" resolves to the prop\'s schema example, not to nothing.',
    );
  }

  /**
   * The flag is set AND the example it falls back to survives distribution.
   *
   * Both halves are needed and only the second one broke: the provider flagged
   * the child correctly all along, but had already overwritten the example the
   * flag points at, so "use default" meant "use nothing".
   */
  public function testDefaultFlagAndExampleBothSurvive(): void {
    EntityTest::create(['name' => 'ENTITY NAME'])->save();

    $child = $this->defaultBoundLinkChild();

    $this->assertTrue(
      $child->getOptionDefault()->isEnabled(),
      'The provider flags the child as "use default".',
    );
    $this->assertNotEmpty(
      $child->getDefaultSchemaValue(),
      'The flag is useless if the example it points at was overwritten on the way down.',
    );
  }

  /**
   * The `link` child of a component whose `link` prop is bound to `_default`.
   */
  private function defaultBoundLinkChild() {
    $component = $this->buildComponent([
      // Keeps the item alive so the delta is not pruned as wholly empty.
      'box' => [
        'field' => '_expand',
        'shape_fields' => ['text' => ['field' => 'name']],
      ],
      'link' => ['field' => '_default'],
    ]);
    $aggregate = $component->getPropShape('_aggregate');
    return $aggregate->getChildShapes()['link'];
  }

  /**
   * A child bound to a real field still takes the field value, not the example.
   *
   * Guards the scope of the fix: only `_default` drops out of the resolved
   * value. A normal binding must keep behaving exactly as before, or every
   * children-match provider would start leaking schema examples.
   */
  public function testBoundChildStillTakesTheFieldValue(): void {
    EntityTest::create(['name' => 'ENTITY NAME'])->save();

    $component = $this->buildComponent([
      'box' => [
        'field' => '_expand',
        'shape_fields' => ['text' => ['field' => 'name']],
      ],
    ]);
    $value = $component->getPropShape('_aggregate')->getDefaultValue();

    $this->assertSame('ENTITY NAME', $value['box']['text'][0]['value'] ?? $value['box']['text'] ?? NULL);
  }

}
