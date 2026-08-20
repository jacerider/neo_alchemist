<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ConfiguredPlugin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Access\ComponentAccessFactory;
use Drupal\neo_alchemist\Access\ComponentAccessInterface;
use Drupal\neo_alchemist\Access\ComponentAccessPluginManager;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Access rules as a configured-plugin kind.
 *
 * An access rule is the minimal case: a plugin id and its settings, and
 * nothing else. It therefore adds no fields of its own to the shared form.
 */
final class AccessKind implements ConfiguredPluginKindInterface {

  use StringTranslationTrait;

  /**
   * Constructs the kind.
   *
   * @param \Drupal\neo_alchemist\Access\ComponentAccessPluginManager $manager
   *   The access plugin manager.
   * @param \Drupal\neo_alchemist\Access\ComponentAccessFactory $factory
   *   The access wrapper factory.
   */
  public function __construct(
    protected ComponentAccessPluginManager $manager,
    protected ComponentAccessFactory $factory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'access';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup|string {
    return $this->t('access');
  }

  /**
   * {@inheritdoc}
   */
  public function getManager(): ConfiguredPluginManagerBase {
    return $this->manager;
  }

  /**
   * {@inheritdoc}
   */
  public function create(ComponentInterface $component): ConfiguredPluginWrapperInterface {
    return $this->factory->get($component);
  }

  /**
   * {@inheritdoc}
   */
  public function load(ComponentInterface $component, string $uuid): ?ConfiguredPluginWrapperInterface {
    return $component->getAccess($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function stage(ComponentInterface $component, ConfiguredPluginWrapperInterface $wrapper): ConfiguredPluginWrapperInterface {
    assert($wrapper instanceof ComponentAccessInterface);
    return $component->setAccess($wrapper);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(ComponentInterface $component, string $uuid): void {
    $component->deleteAccess($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function pluginSettingsElementType(): string {
    return 'container';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ConfiguredPluginWrapperInterface $wrapper, array $ajax): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, ConfiguredPluginWrapperInterface $wrapper): void {}

}
