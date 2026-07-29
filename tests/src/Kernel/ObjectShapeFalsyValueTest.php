<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Falsy-but-real values survive object-shaped props.
 *
 * Two shapes had the same defect through different code:
 * - ObjectShape::buildValue() unset any child whose value was empty() —
 *   deleting an authored FALSE, 0 or '0' from the object.
 * - StructuredObjectShapeBase::buildValue() ran array_filter($value) and then
 *   merged the schema examples back in — replacing an authored falsy value
 *   with the component author's placeholder text.
 *
 * Red/green proof performed during development: with the pre-fix empty() /
 * bare array_filter() restored, testObjectChildFalsyValuesSurvive and
 * testStructuredObjectFalsyChildNotRefilled go red with lost or substituted
 * content; the invariant methods stay green.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ObjectShape::buildValue()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\StructuredObjectShapeBase::buildValue()
 */
#[Group('neo_alchemist')]
class ObjectShapeFalsyValueTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The `link` prop's shape stores its value in a link field item, and its
    // enum'd `target` child resolves to a list_string item — so both field
    // types must exist. The first rung of TESTING.md's escalation ladder
    // beyond the minimal set.
    'link',
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * Builds the fixture component with authored prop values.
   *
   * @param array $props
   *   Preview prop values keyed by prop name, each already in the
   *   ['ref' => ..., 'value' => ..., 'options' => ...] structure.
   */
  private function buildComponent(array $props): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_falsy_object')) {
      Component::create([
        'id' => 'na_falsy_object',
        'label' => 'Falsy object fixture',
        'description' => 'Falsy object fixture',
        'component' => 'neo_alchemist_test:na_falsy_object',
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache(['na_falsy_object']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_falsy_object');

    $component->setPreview(TRUE);
    $component->setPreviewValues(['props' => $props]);

    return $component;
  }

  /**
   * Authored falsy children survive ObjectShape::buildValue().
   */
  public function testObjectChildFalsyValuesSurvive(): void {
    $component = $this->buildComponent([
      'box' => [
        'ref' => 'object',
        'value' => [
          'text' => ['value' => '0'],
          'flag' => ['value' => FALSE],
          'count' => ['value' => 0],
        ],
        'options' => [
          'box~text' => ['default' => 0],
          'box~flag' => ['default' => 0],
          'box~count' => ['default' => 0],
        ],
      ],
    ]);

    $box = $component->getPropValues()['box'] ?? [];

    $this->assertSame('0', $box['text'] ?? NULL, 'The authored "0" string child survived.');
    $this->assertArrayHasKey('flag', $box, 'The authored FALSE boolean child survived.');
    $this->assertFalse($box['flag']);
    $this->assertSame(0.0, $box['count'] ?? NULL, 'The authored 0 number child survived.');
  }

  /**
   * A genuinely empty child is still omitted from the object.
   */
  public function testObjectEmptyChildStillOmitted(): void {
    $component = $this->buildComponent([
      'box' => [
        'ref' => 'object',
        'value' => [
          'text' => ['value' => 'AUTHORED'],
          'note' => ['value' => ''],
        ],
        'options' => [
          'box~text' => ['default' => 0],
          'box~note' => ['default' => 0],
        ],
      ],
    ]);

    $box = $component->getPropValues()['box'] ?? [];

    $this->assertSame('AUTHORED', $box['text'] ?? NULL);
    $this->assertArrayNotHasKey('note', $box, 'An empty string child is omitted per the value-emptiness contract.');
  }

  /**
   * Structured objects keep authored falsy children instead of examples.
   *
   * The link prop's schema example titles the link 'EXAMPLE LINK TITLE'. An
   * authored title of '0' must never be replaced by that example.
   */
  public function testStructuredObjectFalsyChildNotRefilled(): void {
    $component = $this->buildComponent([
      'link' => [
        'ref' => 'link',
        // A structured object holds a single field item (here a LinkItem), so
        // the override is the item's property-keyed value — children are NOT
        // individually field-item-wrapped like ObjectShape children are.
        'value' => [
          'uri' => 'https://example.com/authored',
          'title' => '0',
        ],
        'options' => [
          'link' => ['default' => 0],
        ],
      ],
    ]);

    $link = $component->getPropValues()['link'] ?? [];

    $this->assertSame('0', $link['title'] ?? NULL, 'The authored "0" title survived.');
    $this->assertNotSame('EXAMPLE LINK TITLE', $link['title'] ?? NULL, 'The schema example did not replace the authored value.');
    $this->assertSame('https://example.com/authored', $link['uri'] ?? NULL);
  }

  /**
   * A top-level 0 prop reaches #props instead of being dropped.
   *
   * The render-boundary check (Component::isPropValueEmpty()) exempted only
   * booleans, so a number prop resolving to 0 vanished from the values
   * handed to SDC. This is render-visible: templates now receive the 0
   * (twig's |default() still substitutes for falsy, so most templates are
   * unaffected).
   *
   * Red/green proof performed during development: with the pre-fix
   * `!is_bool($value) && empty($value)` restored, this goes red with the
   * count key missing.
   */
  public function testTopLevelZeroPropReachesProps(): void {
    $component = $this->buildComponent([
      'count' => [
        'ref' => 'number',
        'value' => ['value' => 0],
        'options' => [
          'count' => ['default' => 0],
        ],
      ],
    ]);

    $values = $component->getPropValues();

    $this->assertArrayHasKey('count', $values, 'The 0-valued number prop reached the prop values.');
    $this->assertSame(0.0, $values['count']);
  }

}
