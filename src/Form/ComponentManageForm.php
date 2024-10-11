<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\PropShape\PropShape;
use Drupal\neo_alchemist\PropSource\PropSource;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentManageForm extends EntityForm {

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * PatternEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager service.
   */
  public function __construct(EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {

    $form = parent::form($form, $form_state);
    $form['#theme'] = 'neo_component_preview_form';

    $form['iframe'] = [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#attributes' => [
        'src' => $this->entity->toUrl('preview')->toString(),
        'width' => '100%',
        'height' => '300px',
        'frameborder' => '0',
        'class' => [
          'border-2',
        ],
      ],
    ];

    // Allow form alterations specific to XB component prop forms (currently
    // only "static prop sources").
    $form_state->set('is_xb_static_prop_source', TRUE);

    $component_plugin = $this->entity->getComponent();
    // $node = Node::load(1);
    $node = Node::create([
      'type' => 'page',
    ]);
    $form['#parents'] = [];
    $defaults = $this->entity->get('defaults');
    if (!empty($defaults['props'])) {
      foreach ($defaults['props'] as $sdc_prop_name => $prop_source_array) {
        assert(isset($component_plugin->metadata->schema['properties'][$sdc_prop_name]['title']));
        $label = $component_plugin->metadata->schema['properties'][$sdc_prop_name]['title'];
        $source = $this->entity->getDefaultStaticPropSource($sdc_prop_name);
        $form[$sdc_prop_name] = $source->formTemporaryRemoveThisExclamationExclamationExclamation($prop_source_array['field_widget'], $sdc_prop_name, $label, FALSE, $node, $form, $form_state);
        $form[$sdc_prop_name]['#weight'] = $prop_source_array['weight'] ?? 0;
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#submit' => ['::submitForm', '::save'],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $component_plugin = $this->entity->getComponent();
    $defaults = $this->entity->get('defaults');
    $overrides = $this->entity->get('overrides');
    if (!empty($defaults['props'])) {
      foreach ($defaults['props'] as $sdc_prop_name => $prop_source_array) {
        assert(isset($component_plugin->metadata->schema['properties'][$sdc_prop_name]['title']));
        $label = $component_plugin->metadata->schema['properties'][$sdc_prop_name]['title'];
        $source = $this->entity->getDefaultStaticPropSource($sdc_prop_name);
        $values = $source->massageFormValuesTemporaryRemoveThisExclamationExclamationExclamation($prop_source_array['field_widget'], $sdc_prop_name, $label, $form_state->getValue($sdc_prop_name), $form, $form_state);
        // $source->fieldItem->setValue($values);
        // $values = $values ? $source->evaluate(NULL) : NULL;
        $overrides['props'][$sdc_prop_name] = [
          'default_value' => $values,
          'expression' => $prop_source_array['expression'],
        ];
      }
    }
    $this->entity->set('overrides', $overrides);
    $result = parent::save($form, $form_state);
    return $result;
  }

}
