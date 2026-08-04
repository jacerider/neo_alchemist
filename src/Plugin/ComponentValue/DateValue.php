<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formats a timestamp or date string for display.
 *
 * Sits after any producer that yields a date: an entity field (a `created` /
 * `changed` / `timestamp` field arrives as a raw UNIX integer, since
 * StringShape does not coerce), a `datetime` field (an ISO 8601 string), or a
 * value from a query/reference provider.
 *
 * The `token` modifier can also render a formatted date, but only off the host
 * entity and only by naming the source inside the template — it cannot format
 * an incoming `[value]`. This runs on whatever the pipeline hands it, whatever
 * sourced it.
 */
#[ComponentValue(
  id: 'date',
  label: new TranslatableMarkup('Format as Date'),
  description: new TranslatableMarkup('Format a timestamp or date string for display.'),
  group: 'modifiers',
  inline: TRUE,
  prop_types: [
    ComponentShapePluginInterface::STRING,
    ComponentShapePluginInterface::INTEGER,
  ],
  weight: 12,
)]
final class DateValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The format key that reveals the custom PHP date pattern field.
   */
  private const FORMAT_CUSTOM = 'custom';

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The entity type manager service.
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
    DateFormatterInterface $date_formatter,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'format' => self::FORMAT_CUSTOM,
      // Not one of the shipped date formats renders a bare date — they all
      // append a time — so a component asking for "December 4, 2025" needs the
      // custom pattern. Default to it rather than to a format nobody wants.
      'custom_format' => 'F j, Y',
      'timezone' => '',
    ];
  }

  /**
   * Get the available date format options.
   *
   * @return array
   *   Format labels keyed by machine name, with the custom escape hatch last.
   */
  protected function getFormatOptions(): array {
    $options = [];
    /** @var \Drupal\Core\Datetime\DateFormatInterface $format */
    foreach ($this->entityTypeManager->getStorage('date_format')->loadMultiple() as $format) {
      $options[$format->id()] = $this->t('@label (@example)', [
        '@label' => $format->label(),
        '@example' => $this->dateFormatter->format(0, 'custom', $format->getPattern(), 'UTC'),
      ]);
    }
    asort($options);
    $options[self::FORMAT_CUSTOM] = $this->t('Custom…');
    return $options;
  }

  /**
   * Configuration form for the value modifier plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $selector = ':input[name$="[format]"]';

    $form['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Date format'),
      '#options' => $this->getFormatOptions(),
      '#default_value' => $this->configuration['format'],
      '#required' => TRUE,
    ];
    $form['custom_format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom format'),
      '#description' => $this->t('A PHP date format string — see the <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener noreferrer">format characters</a>. For example <code>F j, Y</code> renders <em>December 4, 2025</em>.'),
      '#default_value' => $this->configuration['custom_format'],
      '#states' => [
        'visible' => [$selector => ['value' => self::FORMAT_CUSTOM]],
        'required' => [$selector => ['value' => self::FORMAT_CUSTOM]],
      ],
    ];
    $form['timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Time zone'),
      '#options' => ['' => $this->t("- Site/user default -")] + \DateTimeZone::listIdentifiers(),
      '#default_value' => $this->configuration['timezone'],
    ];

    return $form;
  }

  /**
   * Form validation for the value modifier plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    if ($form_state->getValue('format') === self::FORMAT_CUSTOM && trim((string) $form_state->getValue('custom_format')) === '') {
      $form_state->setError($form['custom_format'], $this->t('A custom format is required when the format is set to Custom.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    $timestamp = $this->toTimestamp($value);
    if ($timestamp === NULL) {
      // Nothing date-shaped came through; leave it for whatever runs next
      // rather than replacing it with the epoch.
      return $value;
    }

    $format = (string) $this->configuration['format'];
    $timezone = ((string) $this->configuration['timezone']) ?: NULL;

    if ($format === self::FORMAT_CUSTOM) {
      return $this->dateFormatter->format($timestamp, 'custom', (string) $this->configuration['custom_format'], $timezone);
    }
    return $this->dateFormatter->format($timestamp, $format, NULL, $timezone);
  }

  /**
   * Coerce an incoming value to a UNIX timestamp.
   *
   * @param mixed $value
   *   A UNIX timestamp (int or numeric string, as created/changed fields
   *   deliver it) or any string strtotime() understands, which covers the ISO
   *   8601 strings datetime and daterange fields deliver.
   *
   * @return int|null
   *   The timestamp, or NULL when the value is not a date.
   */
  private function toTimestamp(mixed $value): ?int {
    if (!is_scalar($value)) {
      return NULL;
    }
    if (is_bool($value)) {
      return NULL;
    }
    if (is_numeric($value)) {
      return (int) $value;
    }
    $parsed = strtotime((string) $value);
    return $parsed === FALSE ? NULL : $parsed;
  }

}
