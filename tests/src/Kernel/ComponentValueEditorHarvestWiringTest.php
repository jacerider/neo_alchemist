<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Value\ComponentValuePanelBuilder;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
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

  use SdcPreviewStoreTestTrait;

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
   * Builds the component workspace's preview editor.
   *
   * @param \Drupal\Core\Form\FormState|null $formState
   *   Returns the form state, for driving validation.
   * @param \Drupal\Core\Entity\EntityFormInterface|null $formObject
   *   Returns the form object.
   * @param \Drupal\neo_alchemist\ComponentInterface|null $component
   *   Returns the component the workspace is editing.
   *
   * @return array
   *   The built form.
   */
  private function buildPreviewEditor(?FormState &$formState = NULL, &$formObject = NULL, &$component = NULL): array {
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
    $this->resetPreviewValues($component);

    $formObject = $this->container->get('entity_type.manager')
      ->getFormObject('neo_component', 'preview_value');
    $formObject->setEntity($component);
    $formState = new FormState();
    return $this->container->get('form_builder')->buildForm($formObject, $formState);
  }

  /**
   * Builds the on-page instance editor over the default layout's leaf.
   *
   * @param \Drupal\Core\Form\FormState|null $formState
   *   Returns the form state, for driving validation.
   * @param \Drupal\Core\Entity\EntityFormInterface|null $formObject
   *   Returns the form object.
   * @param \Drupal\neo_alchemist\ComponentInstanceInterface|null $instance
   *   Returns the placed instance being edited.
   *
   * @return array
   *   The built form.
   */
  private function buildInstanceEditor(?FormState &$formState = NULL, &$formObject = NULL, &$instance = NULL): array {
    $entity = $this->createTestEntity();
    $instance = $entity->get(static::FIELD_NAME)->first()->getComponent(static::SEED_UUID);
    $this->assertNotNull($instance, 'Premise: the default layout placed the leaf.');

    $formObject = $this->container->get('entity_type.manager')
      ->getFormObject('entity_test', 'alchemist');
    $formObject->setEntity($entity);
    $formState = new FormState();
    $formState->set('neo_component_instance', $instance);
    return $this->container->get('form_builder')->buildForm($formObject, $formState);
  }

  /**
   * The preview workspace writes the harvest to its preview overrides.
   *
   * Overrides are the workspace's only storage — the form never saves — so a
   * harvest that did not reach them would leave the developer typing into a
   * form that forgets on the next request.
   */
  public function testPreviewEditorWritesHarvestToPreviewValues(): void {
    $form = $this->buildPreviewEditor($formState, $formObject, $component);
    $this->assertFalse($this->hasPreviewValues($component), 'Premise: nothing is overridden yet.');
    $this->assertArrayHasKey('text', $form['values'], 'Premise: the workspace offers the prop.');

    $formState->setValues(['values' => $this->stringSubmission('text', 'TYPED IN WORKSPACE')]);
    $formObject->validateForm($form, $formState);

    $overrides = $this->getPreviewValues($component);
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
    $form = $this->buildInstanceEditor($formState, $formObject, $instance);
    $this->assertSame(
      'SEED TEXT',
      $instance->getValues()['props']['text']['value']['value'] ?? NULL,
      'Premise: the instance starts on its authored value.',
    );
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

  /**
   * Both editors publish the DOM ids their client matches on.
   *
   * The client keeps no literal copy of either id: component-ajax-form.ts
   * reads both from these settings and returns early when they are absent,
   * which is how it tells "no editor on this page" from "one I cannot find".
   * An editor that stopped publishing them would therefore not throw — it
   * would quietly stop refreshing the preview on every keystroke, which is
   * precisely the class of silent breakage this seam exists to prevent.
   */
  public function testBothEditorsPublishTheClientDomContract(): void {
    $expected = [
      'formId' => ComponentValuePanelBuilder::FORM_ID,
      'refreshId' => ComponentValuePanelBuilder::REFRESH_ID,
    ];
    $editors = [
      'workspace' => $this->buildPreviewEditor(),
      'on-page' => $this->buildInstanceEditor(),
    ];

    foreach ($editors as $name => $form) {
      $this->assertSame(
        $expected,
        $form['#attached']['drupalSettings']['neoAlchemist']['valueEditor'] ?? NULL,
        sprintf('The %s editor publishes both ids.', $name),
      );
      $this->assertContains(
        'neo_alchemist/component.ajax.form',
        $form['#attached']['library'],
        sprintf('The %s editor loads the behavior that reads them.', $name),
      );
      $this->assertSame(
        $expected['formId'],
        $form['#id'],
        sprintf('The %s editor stamps the published id on its own form.', $name),
      );
    }
  }

}
