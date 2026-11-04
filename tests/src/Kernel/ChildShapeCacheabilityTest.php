<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cacheability a child shape records reaches the component.
 *
 * Component::getPropValues() merges the metadata of each ROOT prop shape.
 * The shapes inside an array row or an object are separate instances, so
 * what they recorded while resolving (a link's access decision, an entity a
 * token read) used to stay on the child and never reach the render array: a
 * button row linking to an admin page was cached with no user.permissions
 * context, and an editor's "allowed" was served to anonymous.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::getCacheableMetadata()
 */
#[Group('neo_alchemist')]
class ChildShapeCacheabilityTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'link',
    'options',
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
    // LinkAccess checks the route, so system.admin has to exist.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * A row link's access decision varies the component by permissions.
   */
  public function testRowLinkAccessReachesComponent(): void {
    $component = $this->buildComponent([
      'links' => [
        'ref' => 'array',
        'value' => [
          ['link' => ['uri' => 'route:system.admin', 'title' => 'Admin', 'options' => []]],
        ],
        'options' => ['links' => ['default' => 0]],
      ],
    ]);

    $values = $component->getPropValues();

    $this->assertSame('Admin', $values['links'][0]['link']['title'] ?? NULL, 'Premise: the row link resolved.');
    $this->assertContains('user.permissions', $component->getCacheableMetadata()->getCacheContexts(), 'The row link access context reached the component.');
  }

  /**
   * The same decision on a root link, which has always been merged.
   */
  public function testRootLinkAccessReachesComponent(): void {
    $component = $this->buildComponent([
      'link' => [
        'ref' => 'link',
        'value' => ['uri' => 'route:system.admin', 'title' => 'Admin', 'options' => []],
        'options' => ['link' => ['default' => 0]],
      ],
    ]);

    $component->getPropValues();

    $this->assertContains('user.permissions', $component->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * The context survives into the render array's #cache.
   */
  public function testRowLinkAccessReachesRenderable(): void {
    $component = $this->buildComponent([
      'links' => [
        'ref' => 'array',
        'value' => [
          ['link' => ['uri' => 'route:system.admin', 'title' => 'Admin', 'options' => []]],
        ],
        'options' => ['links' => ['default' => 0]],
      ],
    ]);

    $build = $component->toRenderable();

    $this->assertContains('user.permissions', $build['#cache']['contexts'] ?? []);
  }

  /**
   * Builds the links fixture in preview with the given prop values.
   *
   * @param array $props
   *   Stored prop values keyed by prop name.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The component.
   */
  private function buildComponent(array $props): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_links_probe')) {
      Component::create([
        'id' => 'na_links_probe',
        'label' => 'Links fixture',
        'description' => 'Links fixture',
        'component' => 'neo_alchemist_test:na_links_probe',
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache(['na_links_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_links_probe');
    $component->setPreview(TRUE);
    $this->setPreviewValues($component, ['props' => $props]);
    return $component;
  }

}
