<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Base class for neo_component_shape plugins.
 */
class ComponentShapeOption {

  /**
   * The default value of the component shape plugin option.
   *
   * @var bool
   */
  protected bool $defaultValue;

  /**
   * The value of the component shape plugin option.
   *
   * @var bool
   */
  protected bool $value;

  /**
   * The default access of the component shape plugin option.
   *
   * @var bool
   */
  protected bool $defaultAccess;

  /**
   * Whether the option is accessed.
   *
   * @var bool
   */
  protected bool $access;

  /**
   * The default locked value of the component shape plugin option.
   *
   * @var bool
   */
  protected ?bool $defaultLockedValue;

  /**
   * Whether the option is locked.
   *
   * @var bool|null
   */
  protected ?bool $lockedValue;

  /**
   * Flag indicating whether any forms connected to this option should show.
   *
   * @var bool
   */
  protected bool $formForceAccess = FALSE;

  /**
   * The log.
   *
   * @var array
   */
  protected array $log = [];

  /**
   * Constructs a new ComponentShapeOption object.
   *
   * @param bool $value
   *   The value of the component shape plugin option.
   * @param bool $access
   *   Whether the option is accessed.
   * @param bool|null $lockedValue
   *   (optional) The locked value. This value will override the base value.
   */
  public function __construct(bool $value, bool $access, ?bool $lockedValue = NULL) {
    $this->defaultValue = $value;
    $this->defaultAccess = $access;
    $this->defaultLockedValue = $lockedValue;
    $this->addLog('Initialized');
  }

  /**
   * Adds a log message.
   *
   * @param string $message
   *   The log message.
   */
  protected function addLog(string $message): void {
    if (isset($this->value)) {
      $value = $this->value ? '1' : '0';
    }
    else {
      $value = $this->defaultValue ? 'DEFAULT 1' : 'DEFAULT 0';
    }
    if (isset($this->access)) {
      $access = $this->access ? '1' : '0';
    }
    else {
      $access = $this->defaultAccess ? 'DEFAULT 1' : 'DEFAULT 0';
    }
    if (isset($this->lockedValue)) {
      $lockedValue = $this->lockedValue ? 'LOCKED TRUE' : 'LOCKED FALSE';
    }
    else {
      $lockedValue = $this->defaultLockedValue ? 'DEFAULT LOCKED TRUE' : 'DEFAULT LOCKED FALSE';
    }
    $this->log[] = $message . ' - ' . sprintf('Value %s | Access %s | %s.', $value, $access, $lockedValue);
  }

  /**
   * Gets the log.
   *
   * @return array
   *   The log.
   */
  public function getLog(): array {
    return $this->log;
  }

  /**
   * Sets the value of the component shape plugin option.
   *
   * @param bool $value
   *   The value of the component shape plugin option.
   * @param string|null $logMessage
   *   (optional) The log message.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setValue(bool $value, ?string $logMessage = NULL): self {
    $this->value = $value;
    if ($logMessage) {
      $this->addLog($logMessage);
    }
    return $this;
  }

  /**
   * Locks the value of the component shape plugin option.
   *
   * @param bool $value
   *   The value to lock.
   * @param string|null $logMessage
   *   (optional) The log message.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setLockedValue(bool $value, ?string $logMessage = NULL): self {
    if (!isset($this->lockedValue)) {
      $this->lockedValue = $value;
      if ($logMessage) {
        $this->addLog('setLockedValue: ' . $logMessage);
      }
    }
    return $this;
  }

  /**
   * Allow the option.
   *
   * @param bool $access
   *   Whether to access the option.
   * @param string|null $logMessage
   *   (optional) The log message.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setAccess($access = TRUE, ?string $logMessage = NULL): self {
    $this->access = $access;
    if ($logMessage) {
      $this->addLog('setAccess: ' . $logMessage);
    }
    return $this;
  }

  /**
   * Force the form to show.
   *
   * @param bool $value
   *   Whether to force the form to show.
   * @param string|null $logMessage
   *   (optional) The log message.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function alwaysShowForm(bool $value = TRUE, ?string $logMessage = NULL): self {
    $this->formForceAccess = $value;
    if ($logMessage) {
      $this->addLog('alwaysShowForm: ' . $logMessage);
    }
    return $this;
  }

  /**
   * Check if option is enabled.
   *
   * @return bool
   *   Will return TRUE if the option is enabled, FALSE otherwise.
   */
  public function isEnabled(): bool {
    $locked = $this->isLocked();
    if (!is_null($locked)) {
      return $locked;
    }
    return $this->value ?? $this->defaultValue;
  }

  /**
   * Check if option is disabled.
   *
   * @return bool
   *   Will return TRUE if the option is disabled, FALSE otherwise.
   */
  public function isDisabled(): bool {
    return !$this->isEnabled();
  }

  /**
   * Check if the option is locked.
   *
   * @return bool|null
   *   The locked value if the option is locked, NULL otherwise.
   */
  public function isLocked(): ?bool {
    return $this->lockedValue ?? $this->defaultLockedValue ?? NULL;
  }

  /**
   * Checks if the component shape plugin option is allowed.
   *
   * @return bool
   *   TRUE if the component shape plugin option is allowed, FALSE otherwise.
   */
  public function isAllowed(): bool {
    return $this->access ?? $this->defaultAccess;
  }

  /**
   * Checks if the form is force allowed.
   *
   * @return bool
   *   TRUE if the form is allowed, FALSE otherwise.
   */
  public function isFormForced(): bool {
    return $this->formForceAccess;
  }

}
