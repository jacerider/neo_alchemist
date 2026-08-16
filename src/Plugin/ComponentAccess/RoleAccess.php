<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentAccess;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\neo_alchemist\ComponentAccessInterface;
use Drupal\neo_alchemist\ComponentAccessOpsMatchPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_access.
 */
#[ComponentAccess(
  id: 'role',
  label: new TranslatableMarkup('Role'),
  description: new TranslatableMarkup('Check if the user has specific role(s).'),
)]
final class RoleAccess extends ComponentAccessOpsMatchPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

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
    ComponentAccessInterface $access,
    array $configuration,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $access, $configuration);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['access'],
      $configuration['settings'],
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getValueKey(): string {
    return 'roles';
  }

  /**
   * {@inheritdoc}
   */
  protected function getValueLabel(): string {
    return 'Roles';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildSelectionElement(array $default): array {
    return [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#options' => $this->getRoles(),
      '#default_value' => $default,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function summarizeSelection(array $selected): string {
    $labels = array_intersect_key($this->getRoles(), array_flip(array_filter($selected)));
    return implode(', ', $labels);
  }

  /**
   * {@inheritdoc}
   */
  protected function accountMatches(AccountInterface $account, array $selected, string $match): bool {
    $roles = $account->getRoles();
    return match ($match) {
      'all' => empty(array_diff($selected, $roles)),
      default => !empty(array_intersect($roles, $selected)),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function getForbiddenReason(): string {
    return 'You do not have the required roles to access this content.';
  }

  /**
   * {@inheritdoc}
   *
   * Varies on roles, not permissions: two accounts can hold identical
   * permissions through different roles, and this plugin reads the roles.
   */
  protected function getAccessCacheContexts(): array {
    return ['user.roles'];
  }

  /**
   * Returns a list of available roles.
   *
   * @return array
   *   An associative array of role IDs and labels.
   */
  protected function getRoles() {
    $roles = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      $roles[$role->id()] = $role->label();
    }
    return $roles;
  }

}
