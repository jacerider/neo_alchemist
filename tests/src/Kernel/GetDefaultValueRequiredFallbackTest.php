<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * The last line of getDefaultValue(): a required prop's example fallback.
 *
 * After the provider search ends, getDefaultValue() gives a REQUIRED prop the
 * component's schema example rather than letting it resolve to nothing. The
 * test that earns this suite its keep is the falsy one: that guard used raw
 * PHP truthiness, so a provider that legitimately resolved `0`, `'0'` or
 * FALSE had its answer thrown away and replaced by the example — the same
 * silent substitution fixed everywhere else in the value pipeline, surviving
 * in the one place the falsy sweep did not reach.
 *
 * The fixture's two flavours of empty matter here. `produce: ''` means the
 * provider DECLINED and the seeded example rides through untouched, which
 * never reaches the guard. `produce_empty: TRUE` means the provider actively
 * resolved nothing, which is the only configuration that exercises the
 * fallback on a component whose schema declares examples.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::getDefaultValue()
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::isProvidedValueEmpty()
 * @see \Drupal\Tests\neo_alchemist\Kernel\RequiredPropFalsyOverrideTest
 */
#[Group('neo_alchemist')]
class GetDefaultValueRequiredFallbackTest extends KernelTestBase {

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
   * The schema example the required `label` prop falls back to.
   */
  private const EXAMPLE = 'EXAMPLE LABEL';

  /**
   * Builds the required-prop probe with one mode provider on `label`.
   *
   * No override is supplied and preview mode is left off, so the resolved
   * value is entirely the product of the provider search plus the required
   * fallback under test.
   *
   * @param array $settings
   *   Settings for the `na_mode_first` provider.
   */
  private function buildComponent(array $settings): Component {
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
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_required_probe')
      ->set('settings.props.label.plugins.label', [
        'na_mode_first' => [
          'id' => 'na_mode_first',
          'settings' => $settings,
        ],
      ])
      ->save();
    $storage->resetCache(['na_required_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_required_probe');
    return $component;
  }

  /**
   * A required prop keeps a resolved '0' instead of the schema example.
   *
   * Red against the pre-fix guard: `!'0'` is TRUE, so the provider's answer
   * was discarded and every visitor saw the component's placeholder text
   * where the site's real content said zero.
   */
  public function testRequiredPropKeepsResolvedZero(): void {
    $component = $this->buildComponent(['produce' => '0']);

    $values = $component->getPropValues();

    $this->assertSame('0', $values['label'] ?? NULL, 'The provider-resolved "0" survived on a required prop.');
    $this->assertNotSame(self::EXAMPLE, $values['label'] ?? NULL, 'The schema example did not replace it.');
  }

  /**
   * A required prop still falls back to the example when nothing resolved.
   *
   * The invariant half: narrowing the guard to isProvidedValueEmpty() must not
   * cost a required prop its placeholder, or components would start handing
   * SDC a missing required prop.
   */
  public function testRequiredPropStillFallsBackToExampleWhenGenuinelyEmpty(): void {
    $component = $this->buildComponent(['produce_empty' => TRUE]);

    $values = $component->getPropValues();

    $this->assertSame(self::EXAMPLE, $values['label'] ?? NULL, 'A required prop that resolved nothing fell back to the schema example.');
  }

  /**
   * A declining provider leaves the seeded example in place.
   *
   * Distinguishes the two empties: this path never reaches the required
   * fallback at all, because the value never became empty in the first place.
   * Stated so a future reader does not mistake it for proof of the fallback.
   */
  public function testDecliningProviderLeavesTheSeededExample(): void {
    $component = $this->buildComponent(['produce' => '']);

    $values = $component->getPropValues();

    $this->assertSame(self::EXAMPLE, $values['label'] ?? NULL, 'The seeded schema example rode through an untouched provider search.');
  }

}
