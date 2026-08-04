<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;

/**
 * Populates a menu prop with share links for the component's host entity.
 *
 * Each enabled network becomes a menu item pointing at that network's share
 * endpoint, pre-filled with the host entity's absolute canonical URL and its
 * label. Because the links are derived from the entity rather than the current
 * route, they stay correct however the component is rendered — no `url` cache
 * context is needed and a render-cached component cannot leak another page's
 * URL.
 *
 * With "Native share" enabled an extra item is emitted carrying the Web Share
 * API payload as `share_native` / `share_title` / `share_text`. Those keys are
 * not part of the menu item schema, which does not forbid additional
 * properties, so they reach the component's twig untouched (the same mechanism
 * `in_active_trail` uses). Rendering them is the component's job — see the
 * neo_alchemist/share library.
 */
#[ComponentValue(
  id: 'share',
  label: new TranslatableMarkup('Share Links'),
  description: new TranslatableMarkup('Generate social share links for the entity this component is attached to.'),
  group: 'providers',
  ref_types: [
    'menu',
  ],
  weight: 6,
)]
final class ShareValue extends ComponentValuePluginBase implements ComponentValueProcessingModeInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The identifier of the native (Web Share API) pseudo-network.
   */
  public const NETWORK_NATIVE = 'native';

  /**
   * Placeholder values used when there is no saved entity to share.
   */
  private const PREVIEW_URL = 'https://example.com/';
  private const PREVIEW_TITLE = '[Page Title]';

  /**
   * The supported networks, keyed by machine name.
   *
   * Each network declares its human label, its default icon name and the share
   * endpoint template. `{url}` and `{title}` are replaced with the raw
   * URL-encoded entity URL and label. A network with no `uri` (native) is
   * handled client-side.
   *
   * @return array<string, array{label: \Drupal\Core\StringTranslation\TranslatableMarkup, icon: string, uri: string}>
   *   The network definitions.
   */
  protected function getNetworks(): array {
    return [
      'linkedin' => [
        'label' => $this->t('LinkedIn'),
        'icon' => 'linkedin',
        'uri' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
      ],
      'facebook' => [
        'label' => $this->t('Facebook'),
        'icon' => 'facebook',
        'uri' => 'https://www.facebook.com/sharer/sharer.php?u={url}',
      ],
      'x' => [
        'label' => $this->t('X'),
        'icon' => 'x-twitter',
        'uri' => 'https://twitter.com/intent/tweet?url={url}&text={title}',
      ],
      'whatsapp' => [
        'label' => $this->t('WhatsApp'),
        'icon' => 'whatsapp',
        'uri' => 'https://wa.me/?text={title}%20{url}',
      ],
      'reddit' => [
        'label' => $this->t('Reddit'),
        'icon' => 'reddit',
        'uri' => 'https://reddit.com/submit?url={url}&title={title}',
      ],
      'pinterest' => [
        'label' => $this->t('Pinterest'),
        'icon' => 'pinterest',
        'uri' => 'https://pinterest.com/pin/create/button/?url={url}&description={title}',
      ],
      'email' => [
        'label' => $this->t('Email'),
        'icon' => 'envelope',
        'uri' => 'mailto:?subject={title}&body={url}',
      ],
      self::NETWORK_NATIVE => [
        'label' => $this->t('Native share'),
        'icon' => 'share-alt',
        // Handled by the neo_alchemist/share library; the uri is set to the
        // raw entity URL in provideDefaultValue(), so the markup degrades to a
        // link to the page itself rather than a dead anchor.
        'uri' => '',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'networks' => ['linkedin', 'facebook', 'email'],
      'icons' => [],
      'new_window' => TRUE,
    ] + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * A menu prop's schema examples are editor scaffolding — invented links that
   * must never reach a visitor. This provider always acts, so "this entity
   * cannot be shared" is an answer rather than a decline, and only a claim
   * expresses that. Without it an entity with no canonical URL would fall
   * through to the component author's placeholder links.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $networks = $this->getNetworks();

    $form['networks'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Networks'),
      '#description' => $this->t('Each selected network becomes a link in the order shown here. <em>Native share</em> opens the device share sheet and requires the component to attach the <code>neo_alchemist/share</code> library; it is hidden on browsers without the Web Share API.'),
      '#options' => array_map(static fn(array $network) => $network['label'], $networks),
      '#default_value' => $this->configuration['networks'] ?? [],
    ];

    // Grouped for display, flattened back in configurationMassage().
    $form['icon_overrides'] = [
      '#type' => 'details',
      '#title' => $this->t('Icons'),
      '#description' => $this->t('Leave a field empty to use the built-in icon.'),
      '#open' => !empty(array_filter($this->configuration['icons'] ?? [])),
      '#tree' => TRUE,
    ];
    foreach ($networks as $id => $network) {
      $form['icon_overrides']['icons'][$id] = [
        '#type' => 'textfield',
        '#title' => $network['label'],
        '#default_value' => $this->configuration['icons'][$id] ?? '',
        '#placeholder' => $network['icon'],
        '#size' => 30,
      ];
    }

    $form['new_window'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open in a new window'),
      '#description' => $this->t('Applies to the network links. Email always opens in the same window so the mail client can take over.'),
      '#default_value' => !empty($this->configuration['new_window']),
    ];

    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationMassage(array $values, array $form, FormStateInterface $form_state): array {
    // Drop the unchecked boxes so the stored list is a clean sequence of the
    // enabled network ids.
    $values['networks'] = array_values(array_filter($values['networks'] ?? []));

    // The icon fields are grouped under a details element for display; store
    // them flat (matching defaultConfiguration()) and keep only real
    // overrides, so a later change to a built-in default still takes effect.
    $icons = $values['icon_overrides']['icons'] ?? $values['icons'] ?? [];
    unset($values['icon_overrides']);
    $values['icons'] = array_filter(array_map('trim', array_map('strval', $icons)));

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    $enabled = $this->configuration['networks'] ?? [];
    if (!$enabled) {
      return [];
    }

    [$shareUrl, $shareTitle] = $this->getShareTarget();
    if ($shareUrl === NULL) {
      return [];
    }

    $networks = $this->getNetworks();
    $encodedUrl = rawurlencode($shareUrl);
    $encodedTitle = rawurlencode($shareTitle);

    $items = [];
    foreach ($enabled as $id) {
      if (!isset($networks[$id])) {
        continue;
      }
      $network = $networks[$id];
      $title = $this->t('Share on @network', ['@network' => $network['label']]);
      if ($id === 'email') {
        $title = $this->t('Share by email');
      }
      elseif ($id === self::NETWORK_NATIVE) {
        $title = $this->t('Share');
      }

      // The native item never navigates, so its href is the page itself and
      // must stay a real URL rather than a query-encoded one.
      $uri = $id === self::NETWORK_NATIVE ? $shareUrl : strtr($network['uri'], [
        '{url}' => $encodedUrl,
        '{title}' => $encodedTitle,
      ]);

      // A mailto: link must stay in the same window — a new tab is opened and
      // immediately orphaned when the mail client takes over. The native item
      // never navigates at all.
      $newWindow = !empty($this->configuration['new_window'])
        && !in_array($id, ['email', self::NETWORK_NATIVE], TRUE);

      $item = [
        'title' => (string) $title,
        'description' => '',
        'icon' => $this->configuration['icons'][$id] ?? $network['icon'],
        'in_active_trail' => FALSE,
        'is_expanded' => FALSE,
        'is_collapsed' => FALSE,
        'url' => [
          'title' => (string) $title,
          'uri' => $uri,
          'options' => [],
          'target' => $newWindow ? '_blank' : '_self',
        ],
      ];

      if ($id === self::NETWORK_NATIVE) {
        // Undeclared keys the menu item schema permits; they survive to the
        // component's twig, which hands them to the Web Share API.
        $item['share_native'] = TRUE;
        $item['share_title'] = $shareTitle;
        $item['share_url'] = $shareUrl;
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * Resolve the URL and title to share.
   *
   * @return array{0: string|null, 1: string}
   *   The absolute share URL (NULL when there is nothing shareable) and the
   *   title that accompanies it.
   */
  private function getShareTarget(): array {
    $component = $this->shape->getComponent();
    $entity = $this->shape->getEntity();

    // getTargetEntity() never returns NULL — it fabricates an unsaved
    // placeholder when the component has no host — so an unsaved entity is the
    // real test for "nothing to share yet".
    if ($component->isPreview() || $entity->isNew()) {
      // Keep the links rendering in the editor preview, pointed at a harmless
      // placeholder, so the component does not look broken while it is being
      // configured.
      return [self::PREVIEW_URL, self::PREVIEW_TITLE];
    }

    $url = $this->getCanonicalUrl($entity);
    if ($url === NULL) {
      return [NULL, ''];
    }

    // The entity's label and alias both change the rendered links.
    $this->shape->addCacheableDependency($entity);

    return [$url, (string) $entity->label()];
  }

  /**
   * Build the absolute canonical URL of an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to link to.
   *
   * @return string|null
   *   The absolute URL, or NULL when the entity has no canonical route.
   */
  private function getCanonicalUrl(EntityInterface $entity): ?string {
    if (!$entity->hasLinkTemplate('canonical')) {
      return NULL;
    }
    try {
      // toString(TRUE) returns a GeneratedUrl carrying its own cacheability
      // rather than leaking it into whatever render context happens to be open.
      $generated = $entity->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
    }
    catch (\Exception) {
      return NULL;
    }
    $this->shape->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

}
