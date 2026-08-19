<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Each value editor feeds the harvest to its own sink.
 *
 * The harvester being correct is not sufficient. It deliberately produces no
 * side effects, so what a submitted value actually *becomes* is decided by the
 * two lines that follow the call — stored values on a placed instance, and
 * cache-backed preview overrides in the component workspace. Those two lines
 * are the only thing that genuinely differs between the editors, and they are
 * exactly what the extraction moved a call boundary in front of.
 *
 * @see \Drupal\neo_alchemist\ComponentPropValueHarvester::harvest()
 * @see \Drupal\Tests\neo_alchemist\Kernel\ComponentPropValueHarvestTest
 *   Covers the other half: the rules the harvest applies before returning.
 */
#[Group('neo_alchemist')]
class ComponentValueEditorHarvestWiringTest extends HybridFieldKernelTestBase {

  use ValueEditorFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The base class has already installed the user schema.
    $this->installValueEditorHost();
    // The alchemist form classes are attached to any entity type carrying a
    // neo_component_tree field, and the base class creates that field after
    // the definitions were first built.
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
  }

  /**
   * The preview workspace writes the harvest to its preview overrides.
   *
   * Overrides are the workspace's only storage — the form never saves — so a
   * harvest that did not reach them would leave the developer typing into a
   * form that forgets on the next request.
   */
  public function testPreviewEditorWritesHarvestToPreviewValues(): void {
    $component = Component::create([
      'label' => 'Preview wiring fixture',
      'description' => 'Preview wiring fixture',
      'component' => 'neo_alchemist_test:na_leaf',
      'status' => TRUE,
      // Production falls back to a node placeholder for a component bound to
      // no host type; a Kernel test this small names one instead.
      'target_entity_type' => 'entity_test_with_bundle',
      'target_entity_bundle' => 'main',
    ]);
    $component->save();
    $component->setPreview(TRUE);
    $component->resetPreviewValues();
    $this->assertFalse($component->hasPreviewValues(), 'Premise: nothing is overridden yet.');

    $formObject = $this->container->get('entity_type.manager')
      ->getFormObject('neo_component', 'preview_value');
    $formObject->setEntity($component);
    $formState = new FormState();
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    $this->assertArrayHasKey('text', $form['values'], 'Premise: the workspace offers the prop.');

    $formState->setValues(['values' => $this->stringSubmission('text', 'TYPED IN WORKSPACE')]);
    $formObject->validateForm($form, $formState);

    $overrides = $component->getPreviewValues();
    $this->assertSame(
      'TYPED IN WORKSPACE',
      $overrides['props']['text']['value']['value'] ?? NULL,
      'What the developer typed is what the next request reads back.',
    );
  }

  /**
   * The instance editor writes the harvest to the placed instance's values.
   *
   * ::save() massages and persists whatever ::validateForm() left on the
   * instance, so a harvest that stopped short of it would report success and
   * store the previous value.
   */
  public function testInstanceEditorWritesHarvestToInstanceValues(): void {
    $entity = $this->createTestEntity();
    $item = $entity->get(static::FIELD_NAME)->first();
    $instance = $item->getComponent(static::SEED_UUID);
    $this->assertNotNull($instance, 'Premise: the default layout placed the leaf.');
    $this->assertSame(
      'SEED TEXT',
      $instance->getValues()['props']['text']['value']['value'] ?? NULL,
      'Premise: the instance starts on its authored value.',
    );

    $formObject = $this->container->get('entity_type.manager')
      ->getFormObject('entity_test', 'alchemist');
    $formObject->setEntity($entity);
    $formState = new FormState();
    $formState->set('neo_component_instance', $instance);
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    $this->assertArrayHasKey('text', $form['values'], 'Premise: the editor offers the prop.');

    $formState->setValues([
      'status' => 1,
      'values' => $this->stringSubmission('text', 'TYPED ON PAGE'),
    ]);
    $formObject->validateForm($form, $formState);

    $this->assertSame(
      'TYPED ON PAGE',
      $instance->getValues()['props']['text']['value']['value'] ?? NULL,
      'The harvest reached the instance, which is what ::save() then persists.',
    );
  }

}
