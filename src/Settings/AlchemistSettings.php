<?php

namespace Drupal\neo_alchemist\Settings;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_settings\Plugin\SettingsBase;

/**
 * Module settings.
 *
 * @Settings(
 *   id = "neo_alchemist",
 *   label = @Translation("Alchemist"),
 *   config_name = "neo_alchemist.settings",
 *   menu_title = @Translation("Alchemist"),
 *   route = "/admin/config/neo/neo-alchemist",
 *   admin_permission = "administer neo_alchemist",
 *   variation_allow = false,
 *   variation_conditions = false,
 *   variation_ordering = false,
 * )
 */
class AlchemistSettings extends SettingsBase {

  /**
   * {@inheritdoc}
   *
   * Instance settings are settings that are set both in the base form and the
   * variation form. They are editable in both forms and the values are merged
   * together.
   */
  protected function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $form['anchor_override_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow anchors to be individually set in Heading shapes'),
      '#description' => $this->t('If not checked, anchors will be automatically generated from the Heading title.'),
      '#default_value' => $this->getValue('anchor_override_status'),
    ];

    $form['description_from_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Describe pages by their first rich text'),
      '#description' => $this->t('The [neo:description] token uses the first rich text in a page\'s components, cut at 160 characters, when nothing else describes the page. If not checked, it falls back to the site slogan.'),
      '#default_value' => $this->getValue('description_from_content') ?? TRUE,
    ];

    return $form;
  }

}
