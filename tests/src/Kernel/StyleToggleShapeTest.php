<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Plugin\ComponentShape\StyleToggleShape;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the style_toggle shape: a boolean that lives on the Style tab.
 *
 * Three promises, each one a reason the shape exists:
 *
 * - It is a style shape, which is the whole of what puts a prop on the Style
 *   tab (ComponentValuePanelBuilder groups by that interface), yet it keeps a
 *   checkbox: no style options, so the field stays boolean.
 * - Twig receives a real boolean, so a template's `{% if prop %}` survives a
 *   prop moving over from `boolean`.
 * - It reads values and settings stored for the `boolean` prop it replaces.
 *   Both record the ref they were written for, and a mismatched ref used to
 *   discard them: converting a prop would have reset every page.
 *
 * Red/green proof performed during development: with
 * StyleToggleShape::isStoredRefCompatible() reduced to the parent's exact
 * match, testReadsValuesStoredAsBoolean and testKeepsSettingsSavedAsBoolean go
 * red (the stored "off" is dropped and the example "on" renders); the others
 * stay green.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\StyleToggleShape
 */
#[Group('neo_alchemist')]
class StyleToggleShapeTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

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
   * The fixture component id.
   */
  private const ID = 'na_style_toggle';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    Component::create([
      'id' => self::ID,
      'label' => 'Style toggle fixture',
      'description' => 'Style toggle fixture',
      'component' => 'neo_alchemist_test:' . self::ID,
      'status' => TRUE,
    ])->save();
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
   * Resolves the toggle with the given placement value stored.
   */
  private function resolve(array $entry): mixed {
    $component = $this->load();
    $component->setPreview(TRUE);
    $this->setPreviewValues($component, ['props' => ['toggle' => $entry]]);
    return $component->getPropValues()['toggle'] ?? NULL;
  }

  /**
   * The prop is a style shape that keeps a boolean checkbox.
   */
  public function testIsStyleShapeWithBooleanField(): void {
    $shape = $this->load()->getPropShapes()['toggle'];
    $this->assertInstanceOf(StyleToggleShape::class, $shape);
    $this->assertInstanceOf(ComponentShapeStylePluginInterface::class, $shape, 'A style shape, so the editor puts it on the Style tab.');
    $this->assertSame('boolean', $shape->getFieldType());
    $this->assertSame([], $shape->getStyleOptions(), 'No options, which would switch the field to a select.');
  }

  /**
   * Twig receives TRUE or FALSE, not a field-item array or an attribute.
   */
  public function testTwigReceivesBoolean(): void {
    $this->assertTrue($this->load()->getPropValues()['toggle'], 'The example (on) is a real TRUE.');
    $this->assertFalse($this->resolve(['ref' => 'style_toggle', 'value' => ['value' => 0]]), 'A stored off is a real FALSE.');
  }

  /**
   * A value stored for the `boolean` prop this replaced is read as-is.
   */
  public function testReadsValuesStoredAsBoolean(): void {
    $this->assertFalse($this->resolve(['ref' => 'boolean', 'value' => ['value' => 0]]));
    $this->assertTrue(
      $this->resolve(['ref' => 'string', 'value' => ['value' => 0]]),
      'Premise: a ref the shape cannot read is still discarded, leaving the example.',
    );
  }

  /**
   * A component's saved settings for the `boolean` prop survive the change.
   *
   * The saved Default Value here turns the toggle off; reading it proves the
   * settings were applied rather than discarded with the old ref.
   */
  public function testKeepsSettingsSavedAsBoolean(): void {
    $component = $this->load();
    $component->setSetting('props', [
      'toggle' => [
        'prop' => 'toggle',
        'ref' => 'boolean',
        'field_type' => 'boolean',
        'active' => TRUE,
        'editable' => TRUE,
        'required' => FALSE,
        'expanded' => [],
        'plugins' => [
          'toggle' => [
            'default' => [
              'id' => 'default',
              'settings' => [
                'field_type' => 'boolean',
                'default' => ['value' => 0],
                'options' => ['toggle' => ['default' => TRUE]],
              ],
            ],
          ],
        ],
      ],
    ])->save();

    $this->assertFalse($this->load()->getPropValues()['toggle']);
  }

}
