<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the component registry sources.
 */
final class LibrarySettingsForm extends ConfigFormBase {

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * The registry client.
   *
   * @var \Drupal\neo_alchemist_library\Registry\RegistryClient
   */
  protected $registryClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var \Drupal\neo_alchemist_library\Form\LibrarySettingsForm $instance */
    $instance = parent::create($container);
    $instance->themeHandler = $container->get('theme_handler');
    $instance->registryClient = $container->get('neo_alchemist_library.registry_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'neo_alchemist_library_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['neo_alchemist_library.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('neo_alchemist_library.settings');

    $form['registries'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Registry base URLs'),
      '#description' => $this->t('One registry base URL per line. The base URL must contain a <code>registry.json</code> index and an <code>r/&lt;name&gt;.json</code> bundle per component. Earlier entries take priority. A local filesystem path is also accepted for development.'),
      '#default_value' => implode("\n", $config->get('registries') ?? []),
      '#rows' => 4,
    ];

    $options = ['' => $this->t('— Site default theme —')];
    foreach ($this->themeHandler->listInfo() as $machine => $theme) {
      $options[$machine] = $theme->info['name'] ?? $machine;
    }
    $form['default_theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Default target theme'),
      '#description' => $this->t('Where components install when no theme is specified. Defaults to the site default theme.'),
      '#options' => $options,
      '#default_value' => $config->get('default_theme') ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $registries = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $form_state->getValue('registries')) as $line) {
      $line = trim($line);
      if ($line !== '') {
        $registries[] = rtrim($line, '/');
      }
    }

    $this->config('neo_alchemist_library.settings')
      ->set('registries', $registries)
      ->set('default_theme', (string) $form_state->getValue('default_theme'))
      ->save();

    // Drop cached registry documents so new sources take effect immediately.
    $this->registryClient->clearCache();

    parent::submitForm($form, $form_state);
  }

}
