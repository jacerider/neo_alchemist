<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\entity_test\Entity\EntityTestWithBundle;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * The media provider must not starve providers placed after it.
 *
 * The media plugin is auto-attached infrastructure on image props: it builds
 * the media-library widget (onShapeInit), converts the picked media into the
 * rendered image value (alterValue), and offers a fallback image
 * (provideDefaultValue). None of those jobs claim a value — but under its old
 * stop_when_found default the provider-search pass claimed the THREADED
 * seeded value (the schema example), so any provider a site builder placed
 * after media silently never ran. The observable symptom: "I added an Entity
 * Field provider and nothing happened until I dragged it above Media."
 *
 * Media now defaults to `continue`. For a media-only provider list the two
 * modes are outcome-identical (nothing follows that could overwrite), so the
 * default change only un-breaks the chained case. An explicit stop_when_found
 * keeps the old behavior, which the last test pins as configured-not-default.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaValue
 */
#[Group('neo_alchemist')]
class MediaProviderChainTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * A media entity wrapping a real image file.
   */
  protected Media $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('entity_test_with_bundle');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['image']);

    $mediaType = MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
    ]);
    $mediaType->save();
    $sourceField = $mediaType->getSource()->createSourceField($mediaType);
    $sourceField->getFieldStorageDefinition()->save();
    $sourceField->save();
    $mediaType->set('source_configuration', ['source_field' => $sourceField->getName()])->save();

    \Drupal::service('file_system')->copy(\Drupal::root() . '/core/tests/fixtures/files/image-test.png', 'public://na-image-probe.png');
    $file = File::create([
      'uri' => 'public://na-image-probe.png',
      'status' => 1,
    ]);
    $file->save();
    $this->media = Media::create([
      'bundle' => 'image',
      'name' => 'Probe image',
      $sourceField->getName() => [
        'target_id' => $file->id(),
        'alt' => 'Probe alt',
      ],
    ]);
    $this->media->save();

    EntityTestBundle::create(['id' => 'main', 'label' => 'Main'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'entity_test_with_bundle',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'entity_test_with_bundle',
      'bundle' => 'main',
      'label' => 'Media',
    ])->save();
  }

  /**
   * A component whose image prop chains media above an entity provider.
   *
   * The trap order on purpose: media FIRST, the site builder's provider after
   * it — exactly how the Add flow appends and exactly what confused people.
   *
   * @param string|null $mediaMode
   *   An explicit processing mode for the media instance, or NULL to use the
   *   shipped default.
   * @param string $entityMode
   *   The entity instance's processing mode.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component.
   */
  private function buildComponent(?string $mediaMode = NULL, string $entityMode = 'block'): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Media chain fixture',
      'description' => 'Media chain fixture',
      'component' => 'neo_alchemist_test:na_image_probe',
      'status' => TRUE,
      'target_entity_type' => 'entity_test_with_bundle',
      'target_entity_bundle' => 'main',
    ]);
    $component->save();
    $id = $component->id();

    $mediaSettings = ['default' => []];
    if ($mediaMode !== NULL) {
      $mediaSettings['processing_mode'] = $mediaMode;
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props.image.plugins.image', [
        'media' => [
          'id' => 'media',
          'settings' => $mediaSettings,
        ],
        'entity' => [
          'id' => 'entity',
          'settings' => [
            'field' => 'field_media',
            'processing_mode' => $entityMode,
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
   * Creates a saved host and binds it as the component target.
   */
  private function bindHost(Component $component, array $values = []): void {
    $host = EntityTestWithBundle::create(['type' => 'main', 'name' => 'HOST'] + $values);
    $host->save();
    $this->assertTrue($component->setTargetPreviewEntity((string) $host->id()));
  }

  /**
   * The resolved image src.
   */
  private function imageSrc(Component $component): ?string {
    $values = $component->getPropValues();
    return $values['image']['src'] ?? NULL;
  }

  /**
   * An entity provider BELOW media wins without reordering.
   *
   * The reported confusion: this exact arrangement produced the schema
   * example under media's old stop_when_found default, because media claimed
   * the threaded example before the entity provider ever ran.
   */
  public function testEntityProviderBelowMediaWins(): void {
    $component = $this->buildComponent();
    $this->bindHost($component, ['field_media' => [$this->media->id()]]);

    $src = $this->imageSrc($component);
    $this->assertNotNull($src);
    $this->assertStringContainsString('na-image-probe.png', $src, 'The bound field supplies the image; media does not starve it.');
  }

  /**
   * An empty source below media falls back to the seeded example.
   *
   * Media on `continue` never claims, and an entity provider on
   * stop_when_found that finds nothing falls through — the prop keeps its
   * example, so attaching a provider cannot make the prop worse.
   */
  public function testEmptySourceKeepsTheExample(): void {
    $component = $this->buildComponent(NULL, 'stop_when_found');
    $this->bindHost($component);

    $src = $this->imageSrc($component);
    $this->assertStringContainsString('placehold.co', (string) $src, 'The schema example survives an empty chain.');
  }

  /**
   * An EXPLICIT stop_when_found keeps the old claiming behavior.
   *
   * The default changed; a configured mode did not. This doubles as the
   * red-proof for testEntityProviderBelowMediaWins(): revert the shipped
   * default and that test produces exactly this outcome.
   */
  public function testExplicitStopWhenFoundStillClaims(): void {
    $component = $this->buildComponent('stop_when_found');
    $this->bindHost($component, ['field_media' => [$this->media->id()]]);

    $src = $this->imageSrc($component);
    $this->assertStringContainsString('placehold.co', (string) $src, 'An explicit stop_when_found claims the seeded example and starves the entity provider.');
  }

  /**
   * The media plugin cannot be removed from the provider list.
   *
   * Removing it destroys the prop's authoring UI — onShapeInit() is what
   * makes the prop a media reference field with the media-library widget.
   */
  public function testMediaIsStatusLocked(): void {
    $definition = $this->container->get('plugin.manager.neo_component_value')->getDefinition('media');
    $this->assertTrue($definition['status_lock'], 'The media plugin is locked into the provider list.');
  }

}
