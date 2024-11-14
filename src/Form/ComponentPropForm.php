<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentPropForm extends EntityForm {

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
   * The shape.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface
   */
  protected $shape;

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
  public function buildForm(array $form, FormStateInterface $form_state, string $prop = NULL) {
    $this->shape = $this->entity->getPropShape($prop);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['values'] = [
      '#parents' => ['values'],
    ];
    $form['values'] = $this->shape->getForm($form, $form_state);

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
    return 1;
  }

}
