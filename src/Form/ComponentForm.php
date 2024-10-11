<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
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
    $component = $this->entity->getComponentDefinition();
    // ksm($component);
    // /** @var \Drupal\Core\Plugin\Context\ContextInterface[] $contexts */
    // $contexts = \Drupal::service('context.repository')->getAvailableContexts();
    // $options = [];
    // foreach ($contexts as $id => $context) {
    //   $options[$id] = $context->getContextDefinition()->getLabel() . ' (' . $id . ')';
    //   $dataDefinition = $context->getContextDefinition()->getDataDefinition();
    //   if ($dataDefinition instanceof EntityDataDefinitionInterface) {
    //     ksm($dataDefinition->getEntityTypeId());
    //   }
    //   // ksm($context->getContextDefinition()->getDataDefinition()->getLabel());
    // }
    // ksm($options);

    // ksm($this->entity->getComponentId());
    // // ksm(\Drupal::service('context.repository')->getAvailableContexts());
    // // ksm(\Drupal::service('context.repository')->getRuntimeContexts());

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
      if ($type instanceof ContentEntityTypeInterface && $type->hasLinkTemplate('canonical')) {
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
        'method' => 'replace',
      ],
    ];

    $form['target_entity_bundle'] = [
      '#type' => 'container',
      '#prefix' => '<div id="component-target-entity-bundles">',
      '#suffix' => '</div>',
    ];
    if ($target_entity_type_id) {
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
          '#required' => TRUE,
          '#options' => $options,
          '#empty_option' => $this->t('- All -'),
          '#disabled' => !$this->entity->isNew(),
        ] + $form['target_entity_bundle'];
      }
    }

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->isNew() ? $component['description'] : $this->entity->get('description'),
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
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new example %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated example %label.', $message_args),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
