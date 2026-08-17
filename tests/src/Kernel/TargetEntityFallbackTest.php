<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\MissingHostEntityException;
use PHPUnit\Framework\Attributes\Group;

/**
 * The host lookup answers about the type it was asked about, or refuses.
 *
 * The method never returns NULL, so every caller treats whatever comes back as
 * the component's host. It has three sources for that, and one of them used to
 * lie: when a component declared a `target_entity_type` whose placeholder could
 * not be built, the method fell through to a fabricated `node:page` and handed
 * back an entity of an entirely different type. Props then resolved against
 * node fields — a token, an entity-field binding or a reference lookup reading
 * a field that belongs to some other entity type — and produced values that
 * look right and are not. Nothing distinguished that from a normal answer.
 *
 * The unbound case is deliberately NOT an error. Most components declare no
 * target entity type at all; they are placed in a layout rather than rendered
 * against a host, and their shapes still call getEntity() unconditionally. A
 * throwaway node keeps those callers from null-checking a host they never had.
 * The distinction this test pins is between "asked about nothing" (placeholder)
 * and "asked about something we cannot produce" (refuse).
 *
 * ValidComponentTreeConstraintValidator already catches
 * MissingHostEntityException and silences it for config-scoped trees, so the
 * refusal has a handler waiting for it — before this change nothing ever threw
 * it and that catch was unreachable.
 *
 * @see \Drupal\neo_alchemist\ComponentInterface::getTargetEntity()
 * @see \Drupal\neo_alchemist\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
 */
#[Group('neo_alchemist')]
class TargetEntityFallbackTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'options',
    'node',
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
    $this->installEntitySchema('node');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_with_bundle');
    $this->installConfig(['neo_alchemist']);
  }

  /**
   * Builds an unsaved component bound to the given target entity type.
   */
  private function buildComponent(?string $targetEntityType): Component {
    $values = [
      'label' => 'Target entity fixture',
      'description' => 'Target entity fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
    ];
    if ($targetEntityType !== NULL) {
      $values['target_entity_type'] = $targetEntityType;
    }
    $component = Component::create($values);
    $component->save();
    return $component;
  }

  /**
   * A component bound to no entity type still gets a placeholder to work with.
   */
  public function testUnboundComponentGetsPlaceholder(): void {
    $entity = $this->buildComponent(NULL)->getTargetEntity();

    $this->assertSame('node', $entity->getEntityTypeId());
    $this->assertTrue($entity->isNew(), 'The placeholder is unsaved, which is how callers tell it apart from a real host.');
  }

  /**
   * A bound component is answered with its own entity type, not a node.
   */
  public function testBoundComponentGetsItsOwnEntityType(): void {
    $entity = $this->buildComponent('entity_test')->getTargetEntity();

    $this->assertSame('entity_test', $entity->getEntityTypeId());
    $this->assertTrue($entity->isNew());
  }

  /**
   * A target type with no instantiable bundle is refused, not substituted.
   *
   * `entity_test_with_bundle` takes its bundles from `entity_test_bundle`
   * config entities. With none created the type has a bundle key and no bundle
   * to satisfy it, which is exactly the state that used to silently become a
   * node.
   */
  public function testUnbuildableTargetTypeIsRefused(): void {
    $component = $this->buildComponent('entity_test_with_bundle');

    $this->expectException(MissingHostEntityException::class);
    $this->expectExceptionMessage('targets entity type entity_test_with_bundle');
    $component->getTargetEntity();
  }

  /**
   * With no node type to stand in, the unbound case refuses too.
   *
   * The placeholder for an unbound component is a throwaway node, so a site
   * without node has nothing generic left. Returning NULL would break the
   * return type every caller relies on and fataling inside the storage handler
   * would name neither the component nor the reason, so this refuses in the
   * same terms as a declared type that cannot be built.
   */
  public function testUnboundComponentRefusesWithoutNode(): void {
    $component = $this->buildComponent(NULL);
    // Stand in a definition-less entity type manager: the site behaves as
    // though node were never installed, which is the only way to reach this.
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->willReturn(FALSE);
    $container = new ContainerBuilder();
    foreach (['config.factory', 'uuid', 'language_manager', 'cache_contexts_manager', 'state'] as $service) {
      $container->set($service, $this->container->get($service));
    }
    $container->set('entity_type.manager', $entityTypeManager);
    \Drupal::setContainer($container);

    try {
      $this->expectException(MissingHostEntityException::class);
      $this->expectExceptionMessage('the node entity type is unavailable');
      $component->getTargetEntity();
    }
    finally {
      \Drupal::setContainer($this->container);
    }
  }

}
