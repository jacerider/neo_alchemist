<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The page's first component's first image is given neo_image's priority.
 *
 * Priority (eager, `fetchpriority="high"`, preloaded from the head) goes to
 * exactly one image: the first neo_image render inside the first component
 * of the page's main content. It is decided from the tree, so a render served
 * from the render cache carries the same image's priority and preloads.
 *
 * Each fixture component renders two images, alt `<label> first` and
 * `<label> second`, so which image of which component took it is read off
 * the markup.
 *
 * @see \Drupal\neo_alchemist\PagePriority
 */
#[Group('neo_alchemist')]
class PagePriorityTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'breakpoint',
    'entity_test',
    'neo_icon',
    'neo_tooltip',
    'neo_settings',
    'neo_twig',
    'neo_image',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // neo_image is optional for neo_alchemist, so skip rather than fail where
    // it is not installed alongside.
    if (!class_exists('Drupal\neo_image\NeoImagePriority')) {
      $this->markTestSkipped('The neo_image module is not available.');
    }
    parent::setUp();
    $this->installConfig(['system', 'image']);
    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      'public://priority-test.png',
      FileExists::Replace
    );
    foreach (['na_priority_image', 'na_priority_optout'] as $id) {
      $this->createFixtureComponent($id);
    }
    // Custom mode, so each entity owns its own tree.
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    $this->container->get('entity_display.repository')
      ->getViewDisplay('entity_test', 'entity_test')
      ->setComponent(static::FIELD_NAME, ['type' => 'neo_component_tree'])
      ->save();
    $this->resetFieldCaches('na_priority_image');
    // Components take their view access from the host entity.
    $this->setUpCurrentUser([], ['view test entity']);
  }

  /**
   * Only the first component's first image takes priority.
   */
  public function testFirstComponentsFirstImage(): void {
    $entity = $this->entityWithTree([
      'a' => ['na_priority_image', $this->imageProps('A')],
      'b' => ['na_priority_image', $this->imageProps('B')],
    ]);
    [$html, $preloads] = $this->renderEntity($entity);
    $this->assertSame(['A first'], $this->priorityImages($html));
    $this->assertSame(4, substr_count($html, '<img'), 'Every image still renders.');
    $this->assertCount(1, $preloads);
    $this->assertSame($this->imageSrc($html, 'A first'), $preloads[0]['href'], 'The preload fetches the image that took priority.');
  }

  /**
   * A first component without an image of its own lends it to its children.
   */
  public function testFirstComponentsChildrenAreInside(): void {
    $entity = $this->entityWithTree(
      [
        'host' => ['na_region_host', ['status' => TRUE, 'props' => []]],
        'b' => ['na_priority_image', $this->imageProps('B')],
      ],
      ['host' => ['child' => ['na_priority_image', $this->imageProps('Child')]]],
    );
    [$html] = $this->renderEntity($entity);
    $this->assertSame(['Child first'], $this->priorityImages($html));
  }

  /**
   * Reordering moves priority, and a warm render cache agrees.
   *
   * The second render of each order is served from the render cache (see
   * ::renderEntity()) and must carry the same priority and preloads.
   */
  public function testReorderMovesPriorityOnWarmCache(): void {
    $entity = $this->entityWithTree([
      'a' => ['na_priority_image', $this->imageProps('A')],
      'b' => ['na_priority_image', $this->imageProps('B')],
    ]);
    [$cold, $coldPreloads] = $this->renderEntity($entity);
    $this->assertSame(['A first'], $this->priorityImages($cold));
    [$warm, $warmPreloads] = $this->renderEntity($this->reloadEntity($entity), TRUE);
    $this->assertSame($cold, $warm);
    $this->assertSame($coldPreloads, $warmPreloads);

    $item = $entity->get(static::FIELD_NAME)->first();
    $item->reorderComponents(['b', 'a']);
    $entity->save();
    $entity = $this->reloadEntity($entity);

    [$cold] = $this->renderEntity($entity);
    $this->assertSame(['B first'], $this->priorityImages($cold), 'Saving the reorder invalidates the cached choice.');
    [$warm, $warmPreloads] = $this->renderEntity($this->reloadEntity($entity), TRUE);
    $this->assertSame(['B first'], $this->priorityImages($warm));
    $this->assertCount(1, $warmPreloads);
    $this->assertSame($this->imageSrc($warm, 'B first'), $warmPreloads[0]['href']);
  }

  /**
   * A first component that declines priority leaves the page without any.
   */
  public function testOptOutDeclinesForThePage(): void {
    $entity = $this->entityWithTree([
      'out' => ['na_priority_optout', $this->imageProps('Out')],
      'b' => ['na_priority_image', $this->imageProps('B')],
    ]);
    [$html, $preloads] = $this->renderEntity($entity);
    $this->assertSame([], $this->priorityImages($html));
    $this->assertSame([], $preloads);

    // Declining matters only first: later, it is never offered anyway.
    $entity = $this->entityWithTree([
      'b' => ['na_priority_image', $this->imageProps('B')],
      'out' => ['na_priority_optout', $this->imageProps('Out')],
    ]);
    [$html] = $this->renderEntity($entity);
    $this->assertSame(['B first'], $this->priorityImages($html));
  }

  /**
   * A tree that is not page content never offers priority.
   *
   * Another view mode, and a tree rendered directly — as a header block or a
   * mega menu panel renders its tree — are rendered as part of some page whose
   * main content is elsewhere.
   */
  public function testTreesOutsideThePageContentNeverOfferIt(): void {
    $entity = $this->entityWithTree([
      'a' => ['na_priority_image', $this->imageProps('A')],
    ]);
    [$html, $preloads] = $this->renderEntity($entity, FALSE, 'teaser');
    $this->assertSame([], $this->priorityImages($html));
    $this->assertSame([], $preloads);

    $build = $this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->toRenderable();
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertSame([], $this->priorityImages($html));
    $this->assertSame([], $this->preloads($build));
  }

  /**
   * Renders an entity; returns its markup and its image preload links.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The entity.
   * @param bool $expectCacheHit
   *   Assert that the render is served from the render cache.
   * @param string $viewMode
   *   The view mode.
   *
   * @return array{0: string, 1: array<int, array<string, string>>}
   *   The markup, and the preload links' attributes.
   */
  private function renderEntity(EntityTest $entity, bool $expectCacheHit = FALSE, string $viewMode = 'full'): array {
    // The probe: a grant made before a render that hits the cache is still
    // outstanding after it, as no image rendered to claim it. Made only for
    // a render expected to hit, since on a miss it would hand the first image
    // priority itself.
    $grant = $this->container->get('neo_image.priority');
    if ($expectCacheHit) {
      $grant->grant();
    }
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity, $viewMode);
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    if ($expectCacheHit) {
      $this->assertTrue($grant->withdraw(), 'Served from the render cache: no image rendered to claim the probe grant.');
    }
    $this->assertFalse($grant->withdraw(), 'No grant is left outstanding after a render.');
    return [$html, $this->preloads($build)];
  }

  /**
   * The alt text of every image that took priority, in order.
   *
   * @return string[]
   *   Alt texts.
   */
  private function priorityImages(string $html): array {
    preg_match_all('#<img\b[^>]*>#', $html, $matches);
    $alts = [];
    foreach ($matches[0] as $tag) {
      if (str_contains($tag, 'fetchpriority="high"') && preg_match('#alt="([^"]*)"#', $tag, $alt)) {
        $alts[] = $alt[1];
      }
    }
    return $alts;
  }

  /**
   * The src of the image with the given alt text.
   */
  private function imageSrc(string $html, string $alt): string {
    preg_match_all('#<img\b[^>]*>#', $html, $matches);
    foreach ($matches[0] as $tag) {
      if (str_contains($tag, 'alt="' . $alt . '"') && preg_match('#src="([^"]*)"#', $tag, $src)) {
        return html_entity_decode($src[1]);
      }
    }
    $this->fail("No image with alt \"$alt\".");
  }

  /**
   * The image preload links a rendered build attached to the head.
   *
   * @return array<int, array<string, string>>
   *   Each link's attributes.
   */
  private function preloads(array $build): array {
    $links = [];
    foreach ($build['#attached']['html_head'] ?? [] as [$element, $key]) {
      if (str_starts_with((string) $key, 'neo_image_preload:')) {
        $links[] = $element['#attributes'];
      }
    }
    return $links;
  }

  /**
   * Creates an entity whose tree holds the given components, in order.
   *
   * @param array<string, array{0: string, 1: array}> $components
   *   Root components: component id and props entry, keyed by instance uuid.
   * @param array<string, array<string, array{0: string, 1: array}>> $children
   *   Components in a parent's `body` region, keyed by the parent's uuid.
   */
  private function entityWithTree(array $components, array $children = []): EntityTest {
    $tree = [];
    $props = [];
    foreach ($components as $uuid => [$component, $entry]) {
      $tree[ComponentTreeStructure::ROOT_UUID][] = ['uuid' => $uuid, 'component' => $component];
      $props[$uuid] = $entry;
    }
    foreach ($children as $parent => $parentChildren) {
      foreach ($parentChildren as $uuid => [$component, $entry]) {
        $tree[$parent]['body'][] = ['uuid' => $uuid, 'component' => $component];
        $props[$uuid] = $entry;
      }
    }
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [['tree' => $tree, 'props' => $props]]);
    $entity->save();
    return $this->reloadEntity($entity);
  }

  /**
   * A props entry for an image fixture with the given label.
   */
  private function imageProps(string $label): array {
    return [
      'status' => TRUE,
      'props' => [
        'label' => ['ref' => 'string', 'value' => ['value' => $label]],
        'src' => ['ref' => 'string', 'value' => ['value' => 'public://priority-test.png']],
      ],
    ];
  }

}
