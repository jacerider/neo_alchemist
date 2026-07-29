<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the transient component builder.
 *
 * ComponentPreviewBuilder is the entry point shared by the SDC preview
 * workspace and `drush neo:alchemist:render`, and it is the gate that keeps
 * Alchemist from taking over every SDC in a site: anything without `neo: true`
 * is declined.
 *
 * @see \Drupal\neo_alchemist\ComponentPreviewBuilder
 */
#[Group('neo_alchemist')]
class ComponentPreviewBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The preview builder under test.
   */
  private function builder() {
    return $this->container->get('neo_alchemist.preview_builder');
  }

  /**
   * An unknown component id yields NULL rather than an exception.
   */
  public function testUnknownComponentReturnsNull(): void {
    $this->assertNull($this->builder()->build('neo_alchemist_test:does_not_exist'));
  }

  /**
   * A valid SDC without `neo: true` is declined.
   */
  public function testNonAlchemistComponentReturnsNull(): void {
    // Guard the premise: the fixture must really be a discoverable SDC, or
    // this would pass for the wrong reason.
    $this->assertTrue(
      $this->container->get('plugin.manager.sdc')->hasDefinition('neo_alchemist_test:na_not_neo'),
      'The plain SDC fixture is discoverable.',
    );

    $this->assertNull($this->builder()->build('neo_alchemist_test:na_not_neo'));
  }

  /**
   * An Alchemist SDC yields a transient, deterministically-identified entity.
   *
   * The id is derived from the component id so that preview overrides written
   * by the workspace forms are read back on the next request.
   */
  public function testAlchemistComponentBuildsTransientEntity(): void {
    $componentId = 'neo_alchemist_test:na_array_provider';
    $entity = $this->builder()->build($componentId);

    $this->assertInstanceOf(ComponentInterface::class, $entity);
    $this->assertSame('sdc_preview_' . hash('crc32b', $componentId), $entity->id());
    $this->assertSame($componentId, $entity->getComponentId());
    $this->assertTrue($entity->status());
    $this->assertTrue($entity->isNew(), 'The entity is transient and never saved.');
  }

  /**
   * The preview flag is honoured in both directions.
   *
   * TRUE is the editor preview; FALSE is what `--live` on
   * `drush neo:alchemist:render` produces, and it is exposed to Twig as the
   * `neoIsPreview` prop.
   */
  public function testPreviewFlagIsHonoured(): void {
    $componentId = 'neo_alchemist_test:na_array_provider';

    $this->assertTrue($this->builder()->build($componentId)->isPreview());
    $this->assertTrue($this->builder()->build($componentId, TRUE)->isPreview());
    $this->assertFalse($this->builder()->build($componentId, FALSE)->isPreview());
  }

  /**
   * Building the same component twice is stable and does not persist anything.
   */
  public function testBuildIsRepeatableAndDoesNotPersist(): void {
    $componentId = 'neo_alchemist_test:na_array_provider';

    $first = $this->builder()->build($componentId);
    $second = $this->builder()->build($componentId);

    $this->assertSame($first->id(), $second->id());
    $this->assertNull(
      $this->container->get('entity_type.manager')->getStorage('neo_component')->load($first->id()),
      'Nothing was written to config storage.',
    );
  }

}
