<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Base class for neo_component_shape plugins.
 */
class ComponentShapeOption {

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
   * Flag indicating whether any forms connected to this option should show.
   *
   * @var bool
   */
  protected bool $formForceAccess = FALSE;

  /**
   * Whether the option is locked.
   *
   * @var bool|null
   */
  protected ?bool $lockedValue;

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
    $this->value = $value;
    $this->access = $access;
    $this->lockedValue = $lockedValue;
    $this->addLog('Initialized');
  }

  /**
   * Adds a log message.
   *
   * @param string $message
   *   The log message.
   */
  protected function addLog(string $message): void {
    $value = $this->value ? 'TRUE' : 'FALSE';
    $access = $this->access ? 'TRUE' : 'FALSE';
    $lockedValue = !isset($this->lockedValue) ? 'NOT LOCKED' : ($this->lockedValue ? 'LOCKED TRUE' : 'LOCKED FALSE');
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
  public function setValue(bool $value, string $logMessage = NULL): self {
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
  public function setLockedValue(bool $value, string $logMessage = NULL): self {
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
  public function setAccess($access = TRUE, string $logMessage = NULL): self {
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
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function alwaysShowForm(bool $value = TRUE, string $logMessage = NULL): self {
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
    if (isset($this->lockedValue)) {
      return $this->lockedValue;
    }
    return $this->value;
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
    return $this->lockedValue;
  }

  /**
   * Checks if the component shape plugin option is allowed.
   *
   * @return bool
   *   TRUE if the component shape plugin option is allowed, FALSE otherwise.
   */
  public function isAllowed(): bool {
    return $this->access;
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
