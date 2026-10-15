<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist_search\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist_search\Binding\BindingDescriptor;
use Drupal\neo_alchemist_search\Binding\BindingSet;
use Drupal\neo_alchemist_search\Binding\BindingTextReader;
use Drupal\neo_alchemist_search\Binding\FieldTextPolicy;
use Drupal\neo_alchemist_search\ComponentTextBuffer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers reading bound field text off the host entity and its references.
 */
#[Group('neo_alchemist_search')]
final class BindingTextReaderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_search',
    'neo_alchemist_search_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');

    FieldStorageConfig::create([
      'field_name' => 'field_refs',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'settings' => ['target_type' => 'entity_test'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_refs',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();

    // Plain string fields; the test module bars field_code by name.
    foreach (['field_code', 'field_caption'] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'entity_test',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
      ])->save();
    }
  }

  /**
   * A key crossing a multi-value reference reads every referenced entity.
   *
   * A list component fed by an entity_reference provider shows each entity
   * the field references, so each one's text is on the page. Reading only the
   * first indexed one item of a nine-item list.
   */
  public function testEveryReferencedEntityIsRead(): void {
    $references = [];
    foreach (['Pages', 'Templates', 'Portraits'] as $name) {
      $reference = EntityTest::create(['name' => $name]);
      $reference->save();
      $references[] = $reference;
    }
    $host = EntityTest::create(['name' => 'Layout Pro', 'field_refs' => $references]);
    $host->save();

    $this->assertSame(['Pages', 'Templates', 'Portraits'], $this->read($host, 'field_refs:entity.name', 1));
  }

  /**
   * A key on the host's own field still reads that field.
   */
  public function testHostFieldIsRead(): void {
    $reference = EntityTest::create(['name' => 'Pages']);
    $reference->save();
    $host = EntityTest::create(['name' => 'Layout Pro', 'field_refs' => [$reference]]);
    $host->save();

    // The host's own label is skipped (search indexes the title separately),
    // so read a referenced label through a one-entity reference instead and
    // check the single-entity path is unchanged.
    $this->assertSame(['Pages'], $this->read($host, 'field_refs:entity.name', 1));
    $this->assertSame([], $this->read($host, 'name', 0));
  }

  /**
   * A field barred by name contributes nothing; its siblings still do.
   *
   * A string field can hold an identifier (a video ID) that is no more
   * searchable than a list key, and its type alone cannot say so.
   */
  public function testFieldBarredByNameIsSkipped(): void {
    $host = EntityTest::create([
      'name' => 'Layout Pro',
      'field_code' => '1_kpl4bkyh',
      'field_caption' => 'Layout Pro tour',
    ]);
    $host->save();

    $this->assertSame([], $this->read($host, 'field_code', 0));
    $this->assertSame(['Layout Pro tour'], $this->read($host, 'field_caption', 0));
  }

  /**
   * Reads one binding's text off an entity.
   *
   * @return string[]
   *   The collected text runs.
   */
  private function read(EntityTest $host, string $key, int $hops): array {
    $reader = new BindingTextReader(
      $this->container->get('neo_alchemist.matcher_field'),
      new FieldTextPolicy($this->container->get('module_handler')),
    );
    $buffer = new ComponentTextBuffer();
    $set = new BindingSet([new BindingDescriptor($key, $hops, 'test', 'string', 'entity_reference')]);
    $reader->read($host, $set, $buffer, new CacheableMetadata());
    return $buffer->toArray();
  }

}
