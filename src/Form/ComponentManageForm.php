<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
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
    $form_state->set('neo_component_static', TRUE);

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

    // $form['modal'] = [
    //   '#type' => 'neo_modal',
    //   '#title' => $this->t('Component Properties'),
    //   '#close' => $this->t('Save'),
    // ];

    // $form['modal']['textfield'] = [
    //   '#type' => 'textfield',
    //   '#title' => $this->t('Title'),
    //   '#default_value' => $this->entity->label(),
    // ];

    $form['props'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        'name' => $this->t('Property'),
        'required' => $this->t('Required'),
        'editable' => $this->t('Editable'),
      ],
    ];

    /** @var \Drupal\neo_alchemist\FieldMatcher $matcher */
    $matcher = \Drupal::service('neo_alchemist.field_matcher');
    $form['values'] = [
      '#parents' => ['values'],
    ];

    foreach ($this->entity->getPropShapes() as $propName => $shape) {
      // if ($propName === 'email' || TRUE) {
      //   $matches = $matcher->getMatches($shape);
      //   ksm($matches);
      // }
      $row = [];
      $row['name']['#markup'] = $shape->getTitle() . ' <small>(' . $shape->getName() . ')</small>';
      $row['require'] = [
        '#type' => 'checkbox',
        // '#default_value' => $shape->isRequired(),
        // '#disabled' => TRUE,
      ];
      $row['editable'] = [
        '#type' => 'checkbox',
        // '#default_value' => $shape->isRequired(),
        // '#disabled' => TRUE,
      ];

      $form['props'][$propName] = $row;

      $form['values'][$propName] = $shape->getForm($form['values'], $form_state);
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
    $actions['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#limit_validation_errors' => [],
      '#submit' => ['::submitForm', '::reset'],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    foreach ($this->entity->getPropShapes() as $propName => $shape) {
      $shape->validateForm($form['values'][$propName], $form_state, $form_state->getValue([
        'values',
        $propName,
      ], []));
      $shape->setFieldItemValue($form_state->getValue([
        'values',
        $propName,
      ], []));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $defaults = [];
    foreach ($this->entity->getPropShapes() as $propName => $shape) {
      $value = $shape->massageFormValues($form, $form_state, $form_state->getValue([
        'values',
        $propName,
      ], []));
      if ($value !== $shape->getFieldItemDefaultValue()) {
        $defaults['props'][$propName]['field_type'] = $shape->getFieldType();
        $defaults['props'][$propName]['default_value'] = $value;
      }
    }
    ksm($defaults);
    $this->entity->set('defaults', $defaults);
    $result = parent::save($form, $form_state);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function reset(array $form, FormStateInterface $form_state): int {
    $defaults = [];
    $this->entity->set('defaults', $defaults);
    $result = parent::save($form, $form_state);
    return $result;
  }

}
