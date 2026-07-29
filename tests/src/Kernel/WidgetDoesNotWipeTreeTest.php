<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * The widget's no-op guard: a form submit cannot wipe the stored tree.
 *
 * ComponentTreeWidget exists only so the field can be assigned a valid
 * widget on Manage form display; the tree is edited on the Alchemist layout
 * page. Its extractFormValues() is a deliberate no-op — extracting the
 * (empty) form values would clear the stored tree and props on every entity
 * form save. One line of guard, total field wipe if removed, so it gets its
 * own pin.
 *
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldWidget\ComponentTreeWidget
 */
#[Group('neo_alchemist')]
class WidgetDoesNotWipeTreeTest extends HybridFieldKernelTestBase {

  /**
   * Extracting empty form values leaves the populated list untouched.
   */
  public function testExtractFormValuesLeavesValueAlone(): void {
    // Custom mode so the entity owns an arbitrary tree.
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    $this->resetFieldCaches('na_region_host');

    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'authored-leaf', 'component' => 'na_leaf'],
          ],
        ],
        'props' => ['authored-leaf' => $this->leafProps('AUTHORED')],
      ],
    ]);
    $entity->save();

    $entity = $this->reloadEntity($entity);
    $list = $entity->get(static::FIELD_NAME);
    $before = $list->getValue();

    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $list->getFieldDefinition(),
      'form_mode' => 'default',
      'prepare' => TRUE,
      'configuration' => ['type' => 'neo_component_tree', 'settings' => [], 'third_party_settings' => []],
    ]);
    $this->assertNotNull($widget, 'Premise: the widget resolves.');

    // An entity form submit with nothing for this field.
    $widget->extractFormValues($list, [], new FormState());

    $this->assertSame($before, $list->getValue(), 'The stored value survived the extract untouched.');
    $tree = Json::decode($list->first()->getValue()['tree']);
    $this->assertSame(
      [['uuid' => 'authored-leaf', 'component' => 'na_leaf']],
      $tree[ComponentTreeStructure::ROOT_UUID] ?? NULL,
    );
  }

}
