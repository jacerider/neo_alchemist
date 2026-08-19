<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Form\ComponentSlotForm;
use Drupal\neo_alchemist\Form\StagedPluginListInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The slot form's staged list: add, edit, update, cancel, remove and reorder.
 *
 * This form had no test at all, which is how it came to carry the defects the
 * component-admin-forms work found: a cancel submit handler with no callers, a
 * handler wired with different capitalisation from its four siblings, an
 * inverted Add/Edit pane title, and — the one that matters — no guard against
 * committing from a limited-validation submission. It survived that last one
 * because the value set its commit path iterates happens to be empty whenever
 * Cancel is on screen. The tests below assert the behaviour rather than the
 * accident.
 *
 * @see \Drupal\neo_alchemist\Form\ComponentSlotForm
 * @see \Drupal\neo_alchemist\Form\StagedPluginListTrait
 */
#[Group('neo_alchemist')]
class ComponentSlotFormUxTest extends KernelTestBase {

  /**
   * The slot the fixture component declares.
   */
  private const SLOT = 'body';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_alchemist']);
  }

  /**
   * A component whose SDC declares one slot.
   */
  private function component(): Component {
    $component = Component::create([
      'label' => 'Slot form fixture',
      'description' => 'Slot form fixture',
      'component' => 'neo_alchemist_test:na_slot_host',
      'status' => TRUE,
    ]);
    $component->save();
    return $component;
  }

  /**
   * Builds the slot form.
   *
   * @param \Drupal\neo_alchemist\Entity\Component $component
   *   The component.
   * @param \Drupal\Core\Form\FormState|null $formState
   *   A form state, pre-seeded with op state if the edit pane should open.
   * @param \Drupal\neo_alchemist\Form\ComponentSlotForm|null $formObject
   *   Returns the form object, for validateForm() calls.
   *
   * @return array
   *   The processed form.
   */
  private function buildSlotForm(Component $component, ?FormState $formState = NULL, ?ComponentSlotForm &$formObject = NULL): array {
    /** @var \Drupal\neo_alchemist\Form\ComponentSlotForm $formObject */
    $formObject = $this->container->get('entity_type.manager')->getFormObject('neo_component', 'slot');
    $formObject->setEntity($component);
    $formState = $formState ?? new FormState();
    $formState->addBuildInfo('args', [self::SLOT]);
    return $this->container->get('form_builder')->buildForm($formObject, $formState);
  }

  /**
   * The staged plugins of the fixture's slot, as stored on the component.
   */
  private function stagedPlugins(Component $component): array {
    return $component->getSetting('slots', [])[self::SLOT]['plugins'] ?? [];
  }

  /**
   * Adds one plugin through the form and returns its uuid.
   */
  private function addPlugin(Component $component, string $pluginId = 'na_note'): string {
    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $formState->setValues(['plugins' => ['add' => $pluginId]]);
    $formState->setTriggeringElement($form['plugins']['add']);
    $formObject->validateForm($form, $formState);
    return (string) $formState->get('uuid');
  }

  /**
   * A form state whose op opens one plugin's edit pane.
   */
  private function editOpState(string $uuid): FormState {
    $formState = new FormState();
    $formState->set('op', StagedPluginListInterface::OP_EDIT);
    $formState->set('uuid', $uuid);
    $formState->set('new', FALSE);
    return $formState;
  }

  /**
   * An empty slot shows the add select and no rows.
   */
  public function testEmptySlotOffersOnlyTheAddSelect(): void {
    $form = $this->buildSlotForm($this->component());

    $this->assertSame('select', $form['plugins']['add']['#type']);
    $this->assertArrayHasKey('na_note', $form['plugins']['add']['#options'], 'An applicable slot plugin is offered.');
    $this->assertFalse($form['plugins']['list']['#access'], 'An empty list is not rendered.');
  }

  /**
   * Picking from the add select stages the plugin and opens its pane.
   */
  public function testAddStagesThePluginAndOpensItsPane(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);

    $this->assertNotEmpty($uuid, 'The added plugin was given a uuid.');
    $this->assertTrue($this->stagedPlugins($component) !== [], 'The plugin is staged on the unsaved component.');
    $this->assertSame('na_note', $this->stagedPlugins($component)[$uuid]['plugin']);

    $form = $this->buildSlotForm($component, $this->editOpState($uuid));
    $this->assertArrayHasKey('form', $form['plugins'], 'The edit pane replaces the list.');
    $this->assertArrayNotHasKey('list', $form['plugins']);
    $this->assertArrayHasKey('note', $form['plugins']['form']['settings'], "The plugin's own settings are rendered.");
  }

  /**
   * The pane's title says Add for a new plugin and Edit for a stored one.
   *
   * It said the opposite: the ternary was inverted, so a site builder adding a
   * plugin was told they were editing it.
   */
  public function testEditPaneTitleMatchesWhatIsHappening(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);

    $newState = $this->editOpState($uuid);
    $newState->set('new', TRUE);
    $this->assertStringContainsString('Add', (string) $this->buildSlotForm($component, $newState)['plugins']['form']['#title']);
    $this->assertStringContainsString('Edit', (string) $this->buildSlotForm($component, $this->editOpState($uuid))['plugins']['form']['#title']);
  }

  /**
   * Update commits the open pane's settings and returns to the list.
   */
  public function testUpdateCommitsTheOpenPane(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);

    $formObject = NULL;
    $formState = $this->editOpState($uuid);
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $formState->setValues(['plugins' => ['settings' => ['note' => 'committed']]]);
    $formState->setTriggeringElement($form['plugins']['form']['actions']['update']);
    $formObject->validateForm($form, $formState);

    $this->assertSame(StagedPluginListInterface::OP_LIST, $formState->get('op'), 'Update returns to the list state.');
    $this->assertSame('committed', $this->stagedPlugins($component)[$uuid]['settings']['note']);
  }

  /**
   * Cancel on a just-added plugin discards it.
   */
  public function testCancelOnNewPluginDiscardsIt(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);
    $this->assertArrayHasKey($uuid, $this->stagedPlugins($component), 'Premise: it was staged.');

    $formObject = NULL;
    $formState = $this->editOpState($uuid);
    $formState->set('new', TRUE);
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $cancel = $form['plugins']['form']['actions']['cancel'];
    $this->assertSame([], $cancel['#limit_validation_errors'], 'Cancel must not submit the pane it is closing.');
    $formState->setTriggeringElement($cancel);
    $formObject->validateForm($form, $formState);

    $this->assertArrayNotHasKey($uuid, $this->stagedPlugins($component), 'The discarded plugin left the staged slot.');
  }

  /**
   * Cancel on a stored plugin closes the pane and keeps it.
   */
  public function testCancelOnStoredPluginKeepsIt(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);

    $formObject = NULL;
    $formState = $this->editOpState($uuid);
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $formState->setTriggeringElement($form['plugins']['form']['actions']['cancel']);
    $formObject->validateForm($form, $formState);

    $this->assertSame(StagedPluginListInterface::OP_LIST, $formState->get('op'));
    $this->assertArrayHasKey($uuid, $this->stagedPlugins($component));
  }

  /**
   * Remove drops the plugin from the staged slot.
   */
  public function testRemoveDropsThePlugin(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);

    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $formState->setTriggeringElement($form['plugins']['list'][$uuid]['ops']['remove']);
    $formObject->validateForm($form, $formState);

    $this->assertSame([], $this->stagedPlugins($component));
  }

  /**
   * A Twig key typed into a row is validated and folded into the slot.
   */
  public function testTwigKeyIsAppliedAndValidated(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);

    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $formState->setValues(['plugins' => ['list' => [$uuid => ['key' => 'lead']]]]);
    $formObject->validateForm($form, $formState);
    $this->assertSame('lead', $this->stagedPlugins($component)[$uuid]['key']);

    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $formState->setValues(['plugins' => ['list' => [$uuid => ['key' => 'Not A Key']]]]);
    $formObject->validateForm($form, $formState);
    $this->assertNotEmpty($formState->getErrors(), 'A key that cannot be a Twig variable is rejected.');
  }

  /**
   * A limited submission never applies the row values it did not submit.
   *
   * The rule the mold owns, stated as behaviour: with validation limited, the
   * submitted table arrives empty, and reading it would look like "every Twig
   * key was cleared". The form used to have no guard and survived only because
   * Cancel is on screen exactly when the list is not — so this drives the two
   * halves directly rather than through a UI state that hides the difference.
   */
  public function testLimitedTriggerDoesNotApplyRowValues(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);

    // Unlimited: the typed key lands.
    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $formState->setValues(['plugins' => ['list' => [$uuid => ['key' => 'lead']]]]);
    $formState->setTriggeringElement($form['plugins']['list'][$uuid]['ops']['edit']);
    $formObject->validateForm($form, $formState);
    $this->assertSame('lead', $this->stagedPlugins($component)[$uuid]['key'], 'Premise: an unlimited trigger applies row values.');

    // Limited: the same values are ignored, so the stored key survives.
    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $limited = $form['plugins']['list'][$uuid]['ops']['edit'];
    $limited['#limit_validation_errors'] = [];
    $formState->setValues(['plugins' => ['list' => [$uuid => ['key' => '']]]]);
    $formState->setTriggeringElement($limited);
    $formObject->validateForm($form, $formState);
    $this->assertSame('lead', $this->stagedPlugins($component)[$uuid]['key'], 'A limited submission cleared a key it never submitted.');
  }

  /**
   * The form says so once staged settings differ from what is saved.
   *
   * Nothing here persists until Save, and a site builder who has added a
   * plugin and navigated away has lost it — so the divergence has to be
   * visible. A freshly opened form must stay quiet: the baseline is snapshot
   * in the same normalized shape the staging writes, so a normalized-versus-
   * raw comparison cannot report a change nobody made.
   *
   * The message must sit inside the `plugins` container, since that subtree
   * is the whole of what ::refreshAjax() returns — at the form root it renders
   * only on a full page load, which is exactly when nothing is staged.
   */
  public function testStagedChangesAreAnnounced(): void {
    $component = $this->component();
    $fresh = $this->buildSlotForm($component);
    $this->assertArrayNotHasKey('unsaved', $fresh['plugins'], 'An untouched form has nothing to announce.');

    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildSlotForm($component, $formState, $formObject);
    $formState->setValues(['plugins' => ['add' => 'na_note']]);
    $formState->setTriggeringElement($form['plugins']['add']);
    $formObject->validateForm($form, $formState);

    $rebuilt = $this->buildSlotForm($component, $formState, $formObject);
    $this->assertArrayHasKey('unsaved', $rebuilt['plugins'], 'A staged addition is announced where the AJAX rebuild can show it.');
  }

  /**
   * The list's Edit and Remove buttons submit unlimited, on purpose.
   *
   * They share a form with the Twig key textfields, and those are values a
   * site builder has typed. Limiting here would silently discard them.
   */
  public function testListButtonsSubmitUnlimited(): void {
    $component = $this->component();
    $uuid = $this->addPlugin($component);
    $row = $this->buildSlotForm($component)['plugins']['list'][$uuid];

    foreach (['edit', 'remove'] as $op) {
      // FALSE, not absent: Button::getInfo() puts the key on every button and
      // FALSE is what "do not limit" looks like. That is the whole trap.
      $this->assertFalse($row['ops'][$op]['#limit_validation_errors'], sprintf('%s submits the typed keys with it.', $op));
    }
  }

}
