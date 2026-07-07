<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentAccessFactory;
use Drupal\neo_alchemist\ComponentGroupPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentForm extends EntityForm {

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
   * The component group plugin manager.
   *
   * @var \Drupal\neo_alchemist\ComponentGroupPluginManager
   */
  protected $componentGroupManager;

  /**
   * The component access factory.
   *
   * @var \Drupal\neo_alchemist\ComponentAccessFactory
   */
  protected $accessFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.neo_component_group'),
      $container->get('neo_component.access.factory'),
    );
  }

  /**
   * PatternEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager service.
   * @param \Drupal\neo_alchemist\ComponentGroupPluginManager $component_group_manager
   *   The component group plugin manager.
   * @param \Drupal\neo_alchemist\ComponentAccessFactory $access_factory
   *   The component access factory.
   */
  public function __construct(
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityTypeManagerInterface $entity_type_manager,
    ComponentGroupPluginManager $component_group_manager,
    ComponentAccessFactory $access_factory,
  ) {
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
    $this->componentGroupManager = $component_group_manager;
    $this->accessFactory = $access_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $component = $this->entity->getComponentDefinition();

    $form = parent::form($form, $form_state);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->isNew() ? $component['name'] : $this->entity->label(),
      '#required' => TRUE,
    ];

    $entity_types = $this->entityTypeManager->getDefinitions();
    $target_entity_type_id = $this->entity->getTargetEntityTypeId();
    $options = [];
    foreach ($entity_types as $type) {
      if ($type instanceof ContentEntityTypeInterface) {
        $options[$type->id()] = $type->getLabel();
      }
    }
    asort($options);
    $form['target_entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity Type'),
      '#description' => $this->t('Scope this component to a specific entity type.'),
      '#default_value' => $target_entity_type_id,
      '#options' => $options,
      '#empty_option' => $this->t('- All -'),
      '#disabled' => !$this->entity->isNew(),
      '#ajax' => [
        'callback' => '::ajaxReplaceTargetBundles',
        'wrapper' => 'component-target-entity-bundles',
      ],
    ];

    $form['target_entity_bundle'] = [
      '#type' => 'container',
      '#prefix' => '<div id="component-target-entity-bundles">',
      '#suffix' => '</div>',
    ];
    if ($target_entity_type_id && isset($entity_types[$target_entity_type_id])) {
      $target_entity_type = $entity_types[$target_entity_type_id];
      if ($target_entity_type->hasKey('bundle') && ($bundles = $this->entityTypeBundleInfo->getBundleInfo($target_entity_type_id))) {
        $options = array_map(
          fn ($bundle) => $bundle['label'],
          $bundles
        );
        asort($options);
        $form['target_entity_bundle'] = [
          '#type' => 'select',
          '#title' => $this->t('Entity Bundle'),
          '#description' => $this->t('Scope this component to a specific %label type bundle.', [
            '%label' => $target_entity_type->getLabel(),
          ]),
          '#default_value' => $this->entity->getTargetEntityBundle(),
          '#options' => $options,
          '#empty_option' => $this->t('- All -'),
          '#disabled' => !$this->entity->isNew(),
        ] + $form['target_entity_bundle'];
      }
    }

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->isNew() ? ($component['description'] ?? '') : $this->entity->get('description'),
    ];

    $options = array_map(
      fn ($definition) => $definition['label'],
      $this->componentGroupManager->getDefinitions()
    );
    $form['group'] = [
      '#type' => 'select',
      '#title' => $this->t('Group'),
      '#options' => $options,
      '#default_value' => $this->entity->getGroup(),
      '#required' => TRUE,
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status(),
    ];

    return $form;
  }

  /**
   * Handles switching the type selector.
   */
  public function ajaxReplaceTargetBundles($form, FormStateInterface $form_state) {
    return $form['target_entity_bundle'];
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    // Components created in the "special" group are protected by default, so
    // only users with the "use protected components" permission can manage
    // them.
    if ($this->entity->isNew() && $this->entity->getGroup() === 'special' && !$this->hasProtectedAccess()) {
      $access = $this->accessFactory->get($this->entity);
      $access->setPluginId('protected');
      $this->entity->setAccess($access);
      $this->messenger()->addStatus($this->t('This component has been protected by default because it is in the "special" group. Only users with the "use protected components" permission can manage this component.'));
    }

    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new component %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated component %label.', $message_args),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

  /**
   * Determines whether the component already has a protected access instance.
   *
   * @return bool
   *   TRUE if a "protected" access plugin is already configured.
   */
  protected function hasProtectedAccess(): bool {
    foreach ($this->entity->getAccessInstances() as $access) {
      if ($access->getPluginId() === 'protected') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
