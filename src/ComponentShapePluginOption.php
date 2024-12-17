<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Base class for neo_component_shape plugins.
 */
class ComponentShapePluginOption {

  /**
   * The value of the component shape plugin option.
   *
   * @var bool
   */
  protected bool $value;

  /**
   * Whether the option is accessed.
   *
   * @var bool
   */
  protected bool $access;

  /**
   * Whether the option is locked.
   *
   * @var bool|null
   */
  protected ?bool $lockedValue;

  /**
   * Constructs a new ComponentShapePluginOption object.
   *
   * @param bool $value
   *   The value of the component shape plugin option.
   * @param bool $access
   *   Whether the option is accessed.
   * @param bool|null $lockedValue
   *   (optional) The locked value. This value will override the base value.
   */
  public function __construct(bool $value, bool $access, ?bool $lockedValue = NULL) {
    $this->value = $value;
    $this->access = $access;
    $this->lockedValue = $lockedValue;
  }

  /**
   * Sets the value of the component shape plugin option.
   *
   * @param bool $value
   *   The value of the component shape plugin option.
   * @param bool $lock
   *   (optional) Whether to lock the option. Defaults to FALSE.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setValue(bool $value, bool $lock = FALSE): self {
    if ($lock) {
      $this->lockedValue = $value;
    }
    $this->value = $value;
    return $this;
  }

  /**
   * Gets the value of the component shape plugin option.
   *
   * @return bool
   *   The value of the component shape plugin option.
   */
  public function getValue(): bool {
    return $this->lockedValue ?? $this->value;
  }

  /**
   * Check if the option is locked.
   *
   * @return bool|null
   *   The locked value if the option is locked, NULL otherwise.
   */
  public function isLocked(): ?bool {
    return $this->lockedValue;
  }

  /**
   * Allow the option.
   *
   * @param bool $access
   *   Whether to access the option.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setAccess($access = TRUE): self {
    $this->access = $access;
    return $this;
  }

  /**
   * Checks if the component shape plugin option is allowed.
   *
   * @return bool
   *   TRUE if the component shape plugin option is allowed, FALSE otherwise.
   */
  public function access(): bool {
    return $this->access;
  }

}
