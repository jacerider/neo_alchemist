<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentAccess;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\neo_alchemist\ComponentAccessInterface;
use Drupal\neo_alchemist\ComponentAccessPluginBase;
use Drupal\neo_icon\IconTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_access.
 */
#[ComponentAccess(
  id: 'role',
  label: new TranslatableMarkup('Role'),
  description: new TranslatableMarkup('Check if the user has a specific role.'),
)]
final class RoleAccess extends ComponentAccessPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
  use IconTranslationTrait;

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
  public function defaultConfiguration() {
    return [
      'ops' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();

    $roles = $this->getRoles();
    foreach (ComponentAccessInterface::OPS as $op => $info) {
      $config = $this->configuration['ops'][$op] ?? [];
      if ($config) {
        $opRoles = array_intersect_key($roles, array_flip(array_filter($config['roles'])));
        $summary[] = strtoupper($info['label']);
        $summary[] = '- ' . $this->t('Roles: @roles', [
          '@roles' => implode(', ', $opRoles),
        ]);
        $summary[] = '- ' . $this->t('Match: @match', [
          '@match' => $config['match'] === 'any' ? $this->t('Any') : $this->t('All'),
        ]);
      }
    }

    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $roles = $this->getRoles();

    $form['ops'] = [
      '#type' => 'container',
    ];
    foreach (ComponentAccessInterface::OPS as $op => $info) {
      $row = [
        '#type' => 'details',
        '#title' => $this->adminIcon($info['label']),
        '#description' => $info['description'],
        '#description_display' => 'before',
        '#open' => !empty($this->configuration['ops'][$op]),
      ];
      $row['roles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Roles'),
        '#options' => $roles,
        '#default_value' => $this->configuration['ops'][$op]['roles'] ?? [],
      ];
      $row['match'] = [
        '#type' => 'radios',
        '#title' => $this->t('Match'),
        '#options' => [
          'any' => $this->t('Has any of the selected roles'),
          'all' => $this->t('Has all of the selected roles'),
        ],
        '#default_value' => $this->configuration['ops'][$op]['match'] ?? 'any',
        '#required' => TRUE,
      ];
      $form['ops'][$op] = $row;
    }
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    foreach (ComponentAccessInterface::OPS as $op => $info) {
      $roles = array_filter($form_state->getValue(['ops', $op, 'roles'], []));
      if ($roles) {
        $form_state->setValue(['ops', $op, 'roles'], $roles);
      }
      else {
        $form_state->unsetValue(['ops', $op]);
      }
    }
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

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    if (!empty($this->configuration['ops'][$op])) {
      $roles = $account->getRoles();
      $hasRoles = FALSE;
      $config = $this->configuration['ops'][$op];
      switch ($config['match']) {
        case 'any':
          $hasRoles = !empty(array_intersect($roles, $config['roles']));
          break;

        case 'all':
          $hasRoles = empty(array_diff($config['roles'], $roles));
          break;
      }
      if (!$hasRoles) {
        return AccessResult::forbidden()->cachePerPermissions();
      }
    }
    return AccessResult::neutral();
  }

}
