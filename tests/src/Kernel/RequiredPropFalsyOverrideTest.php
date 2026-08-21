<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * A required prop authored as a falsy-but-real value keeps that value.
 *
 * The init lifecycle decides "is there an override, or should the default
 * apply?" twice, and for a required prop an override mistaken for absent is
 * silently replaced by the schema example. Those checks now use
 * isProvidedValueEmpty() rather than PHP truthiness.
 *
 * Scope note, stated honestly: this passed before that change too, because
 * overrides arrive in wrapped field-item form (['value' => '0']) — a
 * non-empty array. It is an INVARIANT pin, not the proof of a past failure:
 * it fails the moment anything starts handing bare scalars to
 * setOverrideValue()/setParentValue(), which is exactly the refactor that
 * would otherwise reintroduce the falsy-drop family quietly.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::init()
 * @see \Drupal\Tests\neo_alchemist\Unit\IsProvidedValueEmptyTest
 */
#[Group('neo_alchemist')]
class RequiredPropFalsyOverrideTest extends KernelTestBase {

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
   * Builds the probe component with an authored label value.
   *
   * @param mixed $authored
   *   The authored value for the required `label` prop.
   */
  private function buildComponent(mixed $authored): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_required_probe')) {
      Component::create([
        'id' => 'na_required_probe',
        'label' => 'Required probe fixture',
        'description' => 'Required probe fixture',
        'component' => 'neo_alchemist_test:na_required_probe',
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache(['na_required_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_required_probe');
    $component->setPreview(TRUE);
    $this->setPreviewValues($component, [
      'props' => [
        'label' => [
          'ref' => 'string',
          'value' => ['value' => $authored],
          'options' => ['label' => ['default' => 0]],
        ],
      ],
    ]);
    return $component;
  }

  /**
   * An authored '0' survives on a required prop.
   */
  public function testRequiredPropKeepsFalsyString(): void {
    $component = $this->buildComponent('0');

    $values = $component->getPropValues();

    $this->assertSame('0', $values['label'] ?? NULL, 'The authored "0" survived on a required prop.');
    $this->assertNotSame('EXAMPLE LABEL', $values['label'] ?? NULL, 'The schema example did not replace it.');
  }

  /**
   * A genuinely empty required prop is omitted, not example-filled.
   *
   * The counterpart that keeps the contract honest — and a characterization:
   * an authored '' does NOT fall back to the schema example. The wrapped
   * override (['value' => '']) is a non-empty array, so the "required ⇒ use
   * the default" branch in init() never fires; the field item then reports
   * itself empty and the prop is dropped from the values handed to SDC.
   * Preview catches this separately (the unsatisfied-required-props notice);
   * a live render would hand SDC a missing required prop.
   */
  public function testRequiredPropOmittedWhenGenuinelyEmpty(): void {
    $component = $this->buildComponent('');

    $values = $component->getPropValues();

    $this->assertArrayNotHasKey('label', $values, 'An empty required prop is omitted rather than example-filled.');
  }

}
