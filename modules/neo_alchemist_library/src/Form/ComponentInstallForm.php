<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Form;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist_library\BuildAdvisor;
use Drupal\neo_alchemist_library\Registry\ComponentInstaller;
use Drupal\neo_alchemist_library\Registry\RegistryClient;
use Drupal\neo_alchemist_library\Registry\RegistryException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Installs a registry component from the admin UI.
 */
final class ComponentInstallForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly RegistryClient $registryClient,
    private readonly ComponentInstaller $installer,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly BuildAdvisor $buildAdvisor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist_library.registry_client'),
      $container->get('neo_alchemist_library.component_installer'),
      $container->get('theme_handler'),
      $container->get('neo_alchemist_library.build_advisor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'neo_alchemist_library_install';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $component = NULL): array {
    try {
      $item = $this->registryClient->getItem($component);
    }
    catch (RegistryException $e) {
      $form['error'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['error' => [$this->t('@error', ['@error' => $e->getMessage()])]],
      ];
      return $form;
    }

    $form['component'] = [
      '#type' => 'value',
      '#value' => $component,
    ];

    $form['summary'] = [
      '#type' => 'item',
      '#title' => $item->title,
      '#markup' => $item->description,
    ];

    // Pre-flight: warn before installing if the component references prop types
    // not defined on this site, so the user can decide ahead of time.
    $undefined = $this->installer->findUndefinedPropTypes($item);
    if ($undefined) {
      $form['undefined_props'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            $this->t('This component uses prop type(s) not defined on this site: %types. Those fields fall back to plain text in the editor until a module or theme provides the definition — the component still installs and renders.', ['%types' => implode(', ', $undefined)]),
          ],
        ],
        '#weight' => -10,
      ];
    }

    if ($item->registryDependencies) {
      $form['deps'] = [
        '#type' => 'item',
        '#title' => $this->t('Also installs'),
        '#markup' => implode(', ', $item->registryDependencies),
      ];
    }

    $form['name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Install as'),
      '#default_value' => $component,
      '#description' => $this->t('The machine name for the installed component. You can rename it to fit your project.'),
      '#required' => TRUE,
      '#machine_name' => [
        'exists' => [static::class, 'alwaysAvailable'],
        'standalone' => TRUE,
        'label' => $this->t('Install as'),
      ],
    ];

    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Target theme'),
      '#options' => $this->getThemeOptions(),
      '#default_value' => $this->defaultTheme(),
      '#required' => TRUE,
    ];

    $form['force'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Overwrite if it already exists'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Install'),
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $component = $form_state->getValue('component');
    try {
      $result = $this->installer->install(
        $component,
        $form_state->getValue('name'),
        $form_state->getValue('theme'),
        (bool) $form_state->getValue('force'),
      );
    }
    catch (RegistryException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }

    if ($result->installed) {
      $this->messenger()->addStatus($this->t('Installed @components into theme %theme.', [
        '@components' => implode(', ', $result->installed),
        '%theme' => $result->theme,
      ]));
    }
    if ($result->skipped) {
      $this->messenger()->addWarning($this->t('Skipped: @items', ['@items' => implode(', ', $result->skipped)]));
    }
    foreach ($result->warnings as $warning) {
      $this->messenger()->addWarning($warning);
    }
    if (!$result->hasChanges() && !$result->warnings) {
      $this->messenger()->addWarning($this->t('Nothing was installed.'));
    }
    elseif ($result->hasChanges()) {
      $this->messenger()->addStatus($this->buildAdvisor->guidanceMessage());
    }

    $form_state->setRedirect('neo_alchemist_library.browser');
  }

  /**
   * Machine-name "exists" callback — registry names never collide here.
   */
  public static function alwaysAvailable(string $name): bool {
    return FALSE;
  }

  /**
   * Builds the installed-theme options list.
   *
   * @return array<string, string>
   */
  protected function getThemeOptions(): array {
    $options = [];
    foreach ($this->themeHandler->listInfo() as $machine => $theme) {
      $options[$machine] = $theme->info['name'] ?? $machine;
    }
    asort($options);
    return $options;
  }

  /**
   * Resolves the default target theme.
   */
  protected function defaultTheme(): string {
    try {
      return $this->installer->resolveFrontTheme();
    }
    catch (RegistryException) {
      return '';
    }
  }

}
