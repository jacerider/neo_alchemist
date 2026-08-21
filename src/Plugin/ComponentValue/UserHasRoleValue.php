<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use Drupal\neo_alchemist\Value\ComponentValueProvision;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'user_has_role',
  label: new TranslatableMarkup('User has Role'),
  description: new TranslatableMarkup('Check if current user has role.'),
  group: 'providers',
  ref_types: [
    ComponentShapePluginInterface::BOOLEAN,
  ],
  entity_types: ['*'],
  weight: -15,
)]
final class UserHasRoleValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProducerInterface {

  use DependencySerializationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $account;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    AccountInterface $account,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->account = $account;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'roles' => [],
      'match' => 'any',
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $roles = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      $roles[$role->id()] = $role->label();
    }
    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#options' => $roles,
      '#default_value' => $this->configuration['roles'],
      '#required' => TRUE,
    ];

    $form['match'] = [
      '#type' => 'radios',
      '#title' => $this->t('Match'),
      '#options' => [
        'any' => $this->t('Has any of the selected roles'),
        'all' => $this->t('Has all of the selected roles'),
      ],
      '#default_value' => $this->configuration['match'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('roles', array_filter($form_state->getValue('roles', [])));
  }

  /**
   * {@inheritdoc}
   *
   * A denied user vetoes: claim FALSE so the search halts and no fallback can
   * put a truthy value back and reveal the component. A granted user offers the
   * threaded value untouched, leaving the pipeline open.
   */
  public function provide(mixed $value): ComponentValueProvision {
    return $this->userHasRoles()
      ? ComponentValueProvision::offer($value)
      : ComponentValueProvision::claim(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $this->provide($value)->getValue();
  }

  /**
   * Whether the current user holds the configured roles for the match mode.
   *
   * An unrecognised match mode denies rather than granting — the safe direction
   * for an access-adjacent gate.
   *
   * @return bool
   *   TRUE if the user satisfies the configured role match, FALSE otherwise.
   */
  private function userHasRoles(): bool {
    $roles = $this->account->getRoles();
    return match ($this->configuration['match']) {
      'any' => !empty(array_intersect($roles, $this->configuration['roles'])),
      'all' => empty(array_diff($this->configuration['roles'], $roles)),
      default => FALSE,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

}
