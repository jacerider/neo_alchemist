<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Theme\Registry;

/**
 * Provides a factory for image objects.
 */
class ComponentSlotFactory {

  /**
   * The slot manager.
   *
   * @var \Drupal\neo_alchemist\ComponentSlotPluginManager
   */
  protected $slotManager;

  /**
   * The slot template locator.
   *
   * @var \Drupal\neo_alchemist\ComponentSlotTemplateLocator|null
   */
  protected ?ComponentSlotTemplateLocator $templateLocator;

  /**
   * The element info manager.
   *
   * @var \Drupal\Core\Render\ElementInfoManagerInterface|null
   */
  protected ?ElementInfoManagerInterface $elementInfo;

  /**
   * The theme registry.
   *
   * @var \Drupal\Core\Theme\Registry|null
   */
  protected ?Registry $themeRegistry;

  /**
   * Constructs a new ComponentSlotFactory object.
   *
   * @param \Drupal\neo_alchemist\ComponentSlotPluginManager $slot_manager
   *   The slot manager.
   * @param \Drupal\neo_alchemist\ComponentSlotTemplateLocator|null $template_locator
   *   The slot template locator.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface|null $element_info
   *   The element info manager.
   * @param \Drupal\Core\Theme\Registry|null $theme_registry
   *   The theme registry.
   */
  public function __construct(ComponentSlotPluginManager $slot_manager, ?ComponentSlotTemplateLocator $template_locator = NULL, ?ElementInfoManagerInterface $element_info = NULL, ?Registry $theme_registry = NULL) {
    $this->slotManager = $slot_manager;
    $this->templateLocator = $template_locator;
    $this->elementInfo = $element_info;
    $this->themeRegistry = $theme_registry;
  }

  /**
   * Constructs a new Slot object.
   */
  public function get(ComponentInterface $component, string $name, array $schema, array $settings): ComponentSlot {
    return new ComponentSlot($this->slotManager, $component, $name, $schema, $settings, $this->templateLocator, $this->elementInfo, $this->themeRegistry);
  }

}
