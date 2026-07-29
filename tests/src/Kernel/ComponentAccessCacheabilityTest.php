<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Component::checkAccess() folds every consulted plugin's cacheability.
 *
 * Access results gate rendering, and the render call sites
 * (ComponentTreeHydrated::renderify(), RegionShape::preRenderValue()) merge
 * the access result's cacheability into the build — that is what lets a
 * currently-visible component drop out of cache when the condition that
 * showed it changes. checkAccess() previously returned a bare neutral()
 * (discarding e.g. PropValueAccess's resolved-value dependencies) and
 * returned the first forbidden result without earlier plugins' metadata —
 * a hidden-forever / visible-forever class of cache bug with no visible
 * symptom until a stale page ships.
 *
 * Red/green proof performed during development: with the pre-fix bare
 * returns restored, testNeutralPathCarriesAllPluginCacheability and
 * testForbiddenPathCarriesEarlierPluginCacheability go red with missing
 * tags.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::checkAccess()
 */
#[Group('neo_alchemist')]
class ComponentAccessCacheabilityTest extends KernelTestBase {

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
   * Builds an exposed component carrying the given access entries.
   *
   * @param array $accessEntries
   *   The settings.access entries keyed by uuid, each with plugin_id and
   *   plugin_settings.
   */
  private function buildComponent(array $accessEntries): CheckAccessExposedComponent {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_leaf')) {
      Component::create([
        'id' => 'na_leaf',
        'label' => 'Leaf fixture',
        'description' => 'Leaf fixture',
        'component' => 'neo_alchemist_test:na_leaf',
        'status' => TRUE,
      ])->save();
    }
    // Access entries are written by raw config after the save —
    // Component::preSave() only regenerates settings.props, but staying on
    // the raw-config path keeps the fixture recipe uniform.
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_leaf')
      ->set('settings.access', $accessEntries)
      ->save();
    $storage->resetCache(['na_leaf']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_leaf');
    return new CheckAccessExposedComponent($component->toArray(), 'neo_component');
  }

  /**
   * Reads an access result's cache tags, asserting it can carry them.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $result
   *   The access result.
   *
   * @return string[]
   *   The cache tags.
   */
  private function cacheTags(AccessResultInterface $result): array {
    $this->assertInstanceOf(CacheableDependencyInterface::class, $result);
    assert($result instanceof CacheableDependencyInterface);
    return $result->getCacheTags();
  }

  /**
   * The neutral path carries every consulted plugin's cacheability.
   */
  public function testNeutralPathCarriesAllPluginCacheability(): void {
    $component = $this->buildComponent([
      'access-one' => [
        'plugin_id' => 'na_cache_tag_access',
        'plugin_settings' => ['forbid' => FALSE, 'tag' => 'na_tag_one'],
      ],
      'access-two' => [
        'plugin_id' => 'na_cache_tag_access',
        'plugin_settings' => ['forbid' => FALSE, 'tag' => 'na_tag_two'],
      ],
    ]);

    $result = $component->checkAccessPublic('view');

    $this->assertTrue($result->isNeutral(), 'Two neutral plugins yield a neutral result.');
    $this->assertContains('na_tag_one', $this->cacheTags($result), 'The first plugin tag survived.');
    $this->assertContains('na_tag_two', $this->cacheTags($result), 'The second plugin tag survived.');
  }

  /**
   * The first forbidden result carries earlier plugins' cacheability.
   */
  public function testForbiddenPathCarriesEarlierPluginCacheability(): void {
    $component = $this->buildComponent([
      'access-one' => [
        'plugin_id' => 'na_cache_tag_access',
        'plugin_settings' => ['forbid' => FALSE, 'tag' => 'na_tag_one'],
      ],
      'access-two' => [
        'plugin_id' => 'na_cache_tag_access',
        'plugin_settings' => ['forbid' => TRUE, 'tag' => 'na_tag_two'],
      ],
    ]);

    $result = $component->checkAccessPublic('view');

    $this->assertTrue($result->isForbidden(), 'The forbidding plugin still wins.');
    $this->assertContains('na_tag_two', $this->cacheTags($result), 'The forbidding plugin tag is present.');
    $this->assertContains('na_tag_one', $this->cacheTags($result), 'The earlier neutral plugin tag was folded in.');
  }

  /**
   * PropValueAccess's neutral result keeps the resolved-value cacheability.
   *
   * The plugin resolves the component's prop values specifically so the
   * visibility decision is re-evaluated when they change; discarding that
   * metadata on the neutral path defeats it.
   */
  public function testPropValueAccessNeutralKeepsResolvedValueCacheability(): void {
    $component = $this->buildComponent([
      'access-prop' => [
        'plugin_id' => 'prop_value',
        'plugin_settings' => ['props' => ['text']],
      ],
    ]);

    $result = $component->checkAccessPublic('view');

    $this->assertTrue($result->isNeutral(), 'The prop resolves the schema example, so the gate stays neutral.');
    $this->assertContains('config:neo_alchemist.neo_component.na_leaf', $this->cacheTags($result), 'The resolved-value cacheability reached the result.');
  }

}
