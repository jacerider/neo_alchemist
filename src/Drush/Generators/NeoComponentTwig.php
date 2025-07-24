<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Drush\Generators;

use Drupal\Core\Render\Markup;

/**
 * Class NeoComponentTwig.
 *
 * This class is responsible for generating Twig templates for Neo components.
 */
class NeoComponentTwig {

  /**
   * The prop for the Twig template.
   *
   * @var array
   */
  protected array $prop;

  /**
   * The parents for the Twig template.
   *
   * @var array
   */
  protected array $parents;

  /**
   * The Twig template.
   *
   * @var string|array
   */
  protected $content;

  /**
   * The prefix for the Twig template.
   *
   * @var string|array
   */
  protected $prefix;

  /**
   * The suffix for the Twig template.
   *
   * @var string|array
   */
  protected $suffix;

  /**
   * The prop for the Twig template.
   *
   * @var array
   */
  public function __construct(array $prop, array $parents, ?array $defaults = []) {
    $this->prop = $prop;
    $this->parents = $parents;
    if ($defaults) {
      $this->content = $this->prepareDefault($defaults['content'] ?? NULL);
      $this->prefix = $this->prepareDefault($defaults['prefix'] ?? NULL);
      $this->suffix = $this->prepareDefault($defaults['suffix'] ?? NULL);
    }
  }

  /**
   * Prepare the default values for the Twig template.
   *
   * @param mixed $values
   *   The values to prepare.
   *
   * @return array
   *   The prepared values.
   */
  protected function prepareDefault(mixed $values) {
    $prepared = [];
    if (!empty($values)) {
      if (is_array($values)) {
        foreach ($values as $item) {
          $prepared[] = str_replace('%name%', $this->getName(), $item);
        }
      }
      else {
        $prepared = str_replace('%name%', $this->getName(), $values);
      }
    }
    return $prepared;
  }

  /**
   * Get the prop for the Twig template.
   *
   * @return array
   *   The prop for the Twig template.
   */
  public function getProp(): array {
    return $this->prop;
  }

  /**
   * Get the name for the Twig template.
   *
   * @return string
   *   The name for the Twig template.
   */
  public function getName(): string {
    $parents = implode('.', $this->parents);
    return $parents ? $parents . '.' . $this->prop['name'] : $this->prop['name'];
  }

  /**
   * Set the Twig template.
   *
   * @param string|array $content
   *   The Twig template.
   *
   * @return $this
   */
  public function setContent(string|array $content) {
    $this->content = $content;
    return $this;
  }

  /**
   * Set the prefix for the Twig template.
   *
   * @param string|array $prefix
   *   The prefix for the Twig template.
   *
   * @return $this
   */
  public function setPrefix(string|array $prefix) {
    $this->prefix = $prefix;
    return $this;
  }

  /**
   * Set the suffix for the Twig template.
   *
   * @param string|array $suffix
   *   The suffix for the Twig template.
   *
   * @return $this
   */
  public function setSuffix(string|array $suffix) {
    $this->suffix = $suffix;
    return $this;
  }

  /**
   * Get the Twig template.
   *
   * @return array
   *   The Twig template.
   */
  public function getTwig(): array {
    $twig = [
      'content' => NULL,
      'prefix' => NULL,
      'suffix' => NULL,
    ];
    if (isset($this->content)) {
      if (is_array($this->content)) {
        foreach ($this->content as $item) {
          $twig['content'][] = Markup::create($item);
        }
      }
      else {
        $twig['content'] = Markup::create($this->content);
      }
    }
    if (isset($this->prefix)) {
      if (is_array($this->prefix)) {
        foreach ($this->prefix as $item) {
          $twig['prefix'][] = Markup::create($item);
        }
      }
      else {
        $twig['prefix'] = Markup::create($this->prefix);
      }
    }
    if (isset($this->suffix)) {
      if (is_array($this->suffix)) {
        foreach ($this->suffix as $item) {
          $twig['suffix'][] = Markup::create($item);
        }
      }
      else {
        $twig['suffix'] = Markup::create($this->suffix);
      }
    }
    return $twig;
  }

}
