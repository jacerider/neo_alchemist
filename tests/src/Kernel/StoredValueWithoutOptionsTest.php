<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins that a prop value written in code renders, however it was written.
 *
 * A placement stores each prop as `{ref, value, options}`, and the options
 * decide whether the value shows. The editor always writes `options`. Code
 * writing a tree by hand usually did not, and the value was then stored but
 * never rendered: the prop took the component's Default Value options (here,
 * starting hidden) or its class's starting options (an image starts on its
 * default), and nothing said why. Code passing a raw value instead of the
 * `{ref, value}` wrapper fared worse: the shapes read nothing and every prop
 * rendered the examples.
 *
 * Two fixes, pinned here together because they are one promise:
 *
 * - StoredValueOptions: a prop stored WITHOUT an `options` key takes its value
 *   as the author's decision, down through every child that carries one, and
 *   the parts it leaves out as hidden rather than as examples.
 * - ComponentPropValueNormalizer: a raw prop value is converted into the
 *   stored wrapper, or refused loudly; NULL hides.
 *
 * And what they must leave alone: an explicit options entry (the editor's Hide
 * and Use default), an empty value (keeps its fallback) and a locked prop.
 *
 * Red/green proof performed during development: with the `array_key_exists(
 * 'options', $stored)` branch in ComponentShapePluginManager::
 * getInstancesFromSchema() replaced by the old `$stored['options'] ?? []`,
 * five tests go red: the four "wins" tests (hidden text, the builder
 * default, and two example images where the authored media should be) and the
 * raw round trip, whose normalized props carry no options either. The guard
 * tests stay green, as they must. With StoredValueOptions::hideLeftOut()
 * reduced to a no-op, testLeftOutPartsAreHidden and testNullHides go red: the
 * omitted parts show the examples.
 *
 * @see \Drupal\neo_alchemist\Shape\StoredValueOptions
 * @see \Drupal\neo_alchemist\ComponentPropValueNormalizer
 */
#[Group('neo_alchemist')]
class StoredValueWithoutOptionsTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

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
    // The heading's `size` is a style prop, backed by list_string.
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The fixture component id.
   */
  private const ID = 'na_stored_value';

  /**
   * The media entity authored values point at.
   */
  private Media $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
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

    \Drupal::service('file_system')->copy(\Drupal::root() . '/core/tests/fixtures/files/image-test.png', 'public://na-authored.png');
    $file = File::create(['uri' => 'public://na-authored.png', 'status' => 1]);
    $file->save();
    $this->media = Media::create([
      'bundle' => 'image',
      'name' => 'Authored image',
      $sourceField->getName() => ['target_id' => $file->id(), 'alt' => 'Authored alt'],
    ]);
    $this->media->save();

    Component::create([
      'id' => self::ID,
      'label' => 'Stored value fixture',
      'description' => 'Stored value fixture',
      'component' => 'neo_alchemist_test:' . self::ID,
      'status' => TRUE,
    ])->save();

    // The site-builder configuration the authored values have to beat: `text`
    // starts hidden, `fallback_text` starts on a builder default, and
    // `locked_text` cannot be edited at all. Set after the first save, which
    // derives the expression and would rebuild the props settings.
    $component = $this->load();
    $hidden = ['empty' => TRUE, 'default' => FALSE];
    $onDefault = ['empty' => FALSE, 'default' => TRUE];
    $locked = $this->defaultValueSettings('locked_text', 'BUILDER LOCKED', $onDefault);
    $component->setSetting('props', [
      'text' => $this->defaultValueSettings('text', 'BUILDER TEXT', $hidden),
      'fallback_text' => $this->defaultValueSettings('fallback_text', 'BUILDER DEFAULT', $onDefault),
      'locked_text' => ['editable' => FALSE] + $locked,
    ])->save();
  }

  /**
   * Builds a prop's settings carrying a configured Default Value.
   */
  private function defaultValueSettings(string $prop, string $default, array $options): array {
    return [
      'prop' => $prop,
      'ref' => 'string',
      'field_type' => 'string',
      'active' => TRUE,
      'editable' => TRUE,
      'required' => FALSE,
      'expanded' => [],
      'plugins' => [
        $prop => [
          'default' => [
            'id' => 'default',
            'settings' => [
              'field_type' => 'string',
              'default' => ['value' => $default],
              'options' => [$prop => $options],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Loads the fixture freshly, so no shape state is memoised.
   */
  private function load(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache([self::ID]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load(self::ID);
    return $component;
  }

  /**
   * Resolves the fixture's prop values with the given placement props stored.
   */
  private function resolve(array $props): array {
    $component = $this->load();
    $component->setPreview(TRUE);
    $this->setPreviewValues($component, ['props' => $props]);
    return $component->getPropValues();
  }

  /**
   * Premise: the builder configuration really does hide or replace values.
   *
   * Without it every "wins" test below could pass by accident.
   */
  public function testPremiseBuilderConfigurationApplies(): void {
    $values = $this->resolve([]);
    $this->assertEmpty($values['text'] ?? NULL, 'Text starts hidden.');
    $this->assertSame('BUILDER DEFAULT', $values['fallback_text']);
    $this->assertStringContainsString('placehold.co', $values['image']['src'] ?? '', 'The image starts on its example.');
  }

  /**
   * A stored value without options beats a Default Value that starts hidden.
   */
  public function testValueWithoutOptionsBeatsHiddenFallback(): void {
    $values = $this->resolve([
      'text' => ['ref' => 'string', 'value' => ['value' => 'AUTHORED']],
    ]);
    $this->assertSame('AUTHORED', $values['text']);
  }

  /**
   * A stored value without options beats a Default Value that starts on it.
   */
  public function testValueWithoutOptionsBeatsDefaultFallback(): void {
    $values = $this->resolve([
      'fallback_text' => ['ref' => 'string', 'value' => ['value' => 'AUTHORED']],
    ]);
    $this->assertSame('AUTHORED', $values['fallback_text']);
  }

  /**
   * An image stored without options shows its media, not its example.
   *
   * ImageShape starts on its default by class, which is the second way a
   * value used to be lost — no site-builder configuration involved.
   */
  public function testImageWithoutOptionsShowsAuthoredMedia(): void {
    $values = $this->resolve([
      'image' => ['ref' => 'image', 'value' => ['target_id' => $this->media->id()]],
    ]);
    $this->assertStringContainsString('na-authored', $values['image']['src'] ?? '');
  }

  /**
   * Row children of an array take their stored values too.
   *
   * The rows' option keys carry the delta after the child's name
   * (`items~image~0`), so the rule has to reach them by the same keys.
   */
  public function testArrayRowChildrenWithoutOptionsShowAuthoredValues(): void {
    $values = $this->resolve([
      'items' => [
        'ref' => 'array',
        'value' => [
          ['image' => ['target_id' => $this->media->id()], 'label' => ['value' => 'ROW ZERO']],
          ['label' => ['value' => 'ROW ONE']],
        ],
      ],
    ]);
    $this->assertStringContainsString('na-authored', $values['items'][0]['image']['src'] ?? '');
    $this->assertSame('ROW ZERO', $values['items'][0]['label']);
    $this->assertSame('ROW ONE', $values['items'][1]['label']);
  }

  /**
   * Parts a written value leaves out are hidden, not filled from examples.
   *
   * The heading's examples fill all three parts and each row's example has a
   * label, so a part that fell back would show. The heading's size is
   * presentation and keeps its default.
   */
  public function testLeftOutPartsAreHidden(): void {
    $values = $this->resolve([
      'heading' => ['ref' => 'heading', 'value' => ['title' => ['value' => 'AUTHORED']]],
      'items' => [
        'ref' => 'array',
        'value' => [
          ['image' => ['target_id' => $this->media->id()]],
        ],
      ],
    ]);
    $this->assertSame('AUTHORED', $values['heading']['title'] ?? NULL);
    $this->assertEmpty($values['heading']['supertitle'] ?? NULL, 'The left-out supertitle is hidden.');
    $this->assertEmpty($values['heading']['subtitle'] ?? NULL, 'The left-out subtitle is hidden.');
    $this->assertStringContainsString('na-authored', $values['items'][0]['image']['src'] ?? '');
    $this->assertEmpty($values['items'][0]['label'] ?? NULL, 'The left-out row label is hidden.');
  }

  /**
   * An options key that is present is honoured, even an empty one.
   *
   * The editor always writes the key, so this is what editor-saved data looks
   * like: its Hide must keep hiding, and its fallbacks must keep applying.
   */
  public function testPresentOptionsAreHonoured(): void {
    $hidden = $this->resolve([
      'fallback_text' => [
        'ref' => 'string',
        'value' => ['value' => 'AUTHORED'],
        'options' => ['fallback_text' => ['empty' => 1, 'default' => 0]],
      ],
    ]);
    $this->assertEmpty($hidden['fallback_text'] ?? NULL, 'An explicit Hide still hides.');

    $emptyKey = $this->resolve([
      'fallback_text' => ['ref' => 'string', 'value' => ['value' => 'AUTHORED'], 'options' => []],
    ]);
    $this->assertSame('BUILDER DEFAULT', $emptyKey['fallback_text'], 'An empty options key still takes the fallback.');
  }

  /**
   * An empty stored value keeps the fallback it had.
   */
  public function testEmptyValueKeepsFallback(): void {
    $values = $this->resolve([
      'fallback_text' => ['ref' => 'string', 'value' => ['value' => '']],
    ]);
    $this->assertSame('BUILDER DEFAULT', $values['fallback_text']);
  }

  /**
   * A locked prop still ignores the instance value.
   */
  public function testLockedPropIgnoresValue(): void {
    $values = $this->resolve([
      'locked_text' => ['ref' => 'string', 'value' => ['value' => 'AUTHORED']],
    ]);
    $this->assertNotSame('AUTHORED', $values['locked_text'] ?? NULL);
  }

  /**
   * Raw values are converted into the stored wrapper, and then render.
   */
  public function testRawValuesNormalizeAndRender(): void {
    $normalizer = $this->container->get('neo_alchemist.prop_value_normalizer');
    $normalized = $normalizer->normalize($this->load(), [
      'status' => 1,
      'props' => [
        'text' => 'RAW TEXT',
        'image' => (int) $this->media->id(),
        'heading' => ['title' => 'RAW TITLE'],
        'items' => [
          ['image' => (int) $this->media->id(), 'label' => 'RAW ROW'],
        ],
        // Already in stored form: passes through untouched.
        'fallback_text' => ['ref' => 'string', 'value' => ['value' => 'STORED']],
      ],
    ]);

    $this->assertSame(1, $normalized['status']);
    $props = $normalized['props'];
    $this->assertSame(['ref' => 'string', 'value' => ['value' => 'RAW TEXT']], $props['text']);
    $this->assertSame('image', $props['image']['ref']);
    $this->assertSame(['target_id' => (int) $this->media->id()], $props['image']['value'], 'Only the media reference is stored, none of the examples.');
    $this->assertSame(['title' => ['value' => 'RAW TITLE']], $props['heading']['value']);
    $this->assertSame(['value' => 'RAW ROW'], $props['items']['value'][0]['label']);
    $this->assertSame(['ref' => 'string', 'value' => ['value' => 'STORED']], $props['fallback_text']);

    $values = $this->resolve($props);
    $this->assertSame('RAW TEXT', $values['text']);
    $this->assertStringContainsString('na-authored', $values['image']['src'] ?? '');
    $this->assertSame('RAW TITLE', $values['heading']['title'] ?? NULL);
    $this->assertSame('RAW ROW', $values['items'][0]['label']);
    $this->assertStringContainsString('na-authored', $values['items'][0]['image']['src'] ?? '');
  }

  /**
   * NULL hides: a whole prop at the top level, a part inside a value.
   */
  public function testNullHides(): void {
    $normalizer = $this->container->get('neo_alchemist.prop_value_normalizer');
    $normalized = $normalizer->normalize($this->load(), [
      'props' => [
        'fallback_text' => NULL,
        'heading' => ['title' => 'RAW TITLE', 'subtitle' => NULL],
      ],
    ]);
    $this->assertSame(
      ['ref' => 'string', 'value' => [], 'options' => ['fallback_text' => ['empty' => 1, 'default' => 0]]],
      $normalized['props']['fallback_text'],
    );
    $this->assertSame(['title' => ['value' => 'RAW TITLE']], $normalized['props']['heading']['value'], 'A NULL part is left out.');

    $values = $this->resolve($normalized['props']);
    $this->assertEmpty($values['fallback_text'] ?? NULL, 'Hidden, rather than the builder default.');
    $this->assertSame('RAW TITLE', $values['heading']['title'] ?? NULL);
    $this->assertEmpty($values['heading']['subtitle'] ?? NULL);
  }

  /**
   * A raw value that cannot be stored is refused, naming what went wrong.
   */
  public function testUnstorableRawValuesThrow(): void {
    $normalizer = $this->container->get('neo_alchemist.prop_value_normalizer');
    $cases = [
      'unknown prop' => [['nope' => 'x'], 'has no prop "nope"'],
      'unknown child' => [['heading' => ['bogus' => 'x']], 'has no child "bogus"'],
      'missing media' => [['image' => 999999], 'references no existing entity'],
      'unknown property' => [['text' => ['bogus' => 'x']], 'has no "bogus" property'],
      'rows not a list' => [['items' => ['label' => 'x']], 'takes a list of rows'],
      'unhideable' => [['toggle' => NULL], 'cannot be hidden'],
    ];
    foreach ($cases as $label => [$props, $message]) {
      try {
        $normalizer->normalize($this->load(), ['props' => $props]);
        $this->fail(sprintf('No exception for %s.', $label));
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $label);
      }
    }
  }

}
