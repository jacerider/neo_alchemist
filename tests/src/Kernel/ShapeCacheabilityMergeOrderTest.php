<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist_test\Plugin\ComponentValue\TestCacheTagValue;
use PHPUnit\Framework\Attributes\Group;

/**
 * Provider-added cacheability lands in the component and its render array.
 *
 * Value plugins add cache dependencies WHILE the value is being computed
 * (menu trees, media entities, entity queries). Component::getPropValues()
 * merges each shape's metadata only AFTER getPropValue() for exactly that
 * reason — hoisting the merge above the value computation loses every
 * provider-added tag with no error and no symptom until a stale page ships.
 * toRenderable() then applies the metadata after the values are resolved.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::getPropValues()
 * @see \Drupal\neo_alchemist\Entity\Component::toRenderable()
 */
#[Group('neo_alchemist')]
class ShapeCacheabilityMergeOrderTest extends KernelTestBase {

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
   * Builds the recorder component with the cache-tag modifier active.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_recorder')) {
      Component::create([
        'id' => 'na_recorder',
        'label' => 'Recorder fixture',
        'description' => 'Recorder fixture',
        'component' => 'neo_alchemist_test:na_recorder',
        'status' => TRUE,
      ])->save();
      $this->container->get('config.factory')
        ->getEditable('neo_alchemist.neo_component.na_recorder')
        ->set('settings.props.note.plugins.note', [
          'na_cache_tag_value' => ['id' => 'na_cache_tag_value', 'settings' => []],
        ])
        ->save();
    }
    $storage->resetCache(['na_recorder']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_recorder');
    $component->setPreview(TRUE);
    $component->setPreviewValues([
      'props' => [
        'note' => [
          'ref' => 'test_recorded',
          'value' => ['value' => 'CACHED VALUE'],
          'options' => ['note' => ['default' => 0]],
        ],
      ],
    ]);
    return $component;
  }

  /**
   * The tag added during value computation reaches the component metadata.
   */
  public function testProviderTagMergedAfterValueComputation(): void {
    $component = $this->buildComponent();

    $this->assertNotContains(TestCacheTagValue::CACHE_TAG, $component->getCacheableMetadata()->getCacheTags(), 'Premise: the tag does not exist before values are resolved.');

    $values = $component->getPropValues();

    $this->assertSame('CACHED VALUE', $values['note'] ?? NULL, 'Premise: the modifier ran (the value resolved).');
    $this->assertContains(TestCacheTagValue::CACHE_TAG, $component->getCacheableMetadata()->getCacheTags(), 'The tag added during modifyValue() was merged into the component.');
  }

  /**
   * The tag survives into the final render array's #cache.
   *
   * This is the assertion a refactor cannot dodge: whatever the internal
   * ordering, the provider-added tag must reach the page cacheability.
   *
   * Rendered live (not preview — preview stands up a placeholder target
   * entity, which the minimal module set has no entity type for). The
   * modifier runs during value resolution either way, so the tag is added
   * even though the note prop resolves empty without preview values.
   */
  public function testProviderTagReachesRenderable(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $this->buildComponent();
    $storage->resetCache(['na_recorder']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_recorder');

    $build = $component->toRenderable();

    $this->assertContains(TestCacheTagValue::CACHE_TAG, $build['#cache']['tags'] ?? [], 'The provider-added tag reached #cache.');
  }

}
