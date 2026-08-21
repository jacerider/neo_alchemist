<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Declaring the processing-mode interface is enough to be configured.
 *
 * A producer used to wire the "Processing" mode four times over: implement the
 * interface, mix in the trait, append processingModeDefaultConfiguration() to
 * its own defaults, and call buildProcessingModeForm() from its own form. The
 * last two were unenforced, so forgetting either shipped a plugin that looked
 * configured and was not. The base now merges the default configuration and
 * wires the form whenever the plugin implements the interface, so a plugin that
 * declares the interface (through the trait) and does nothing else is correctly
 * configured. That is what SearchProbeProvider is: the production base plus the
 * production trait, overriding neither the defaults nor the config form.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginBase::setConfiguration()
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginBase::buildConfigurationForm()
 * @see \Drupal\Tests\neo_alchemist\Unit\SearchProbeProvider
 */
#[Group('neo_alchemist')]
class ComponentValueProcessingModeAutowireTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * A provider that declares the mode interface and does nothing else with it.
   */
  private function bareProvider(): SearchProbeProvider {
    $provider = new SearchProbeProvider('probe', ['group' => 'providers'], $this->shapeDouble([]), []);
    $provider->setStringTranslation($this->getStringTranslationStub());
    return $provider;
  }

  /**
   * The base merges the mode default without the plugin appending it.
   */
  public function testDefaultConfigurationCarriesTheMode(): void {
    $configuration = $this->bareProvider()->getConfiguration();

    $this->assertArrayHasKey('processing_mode', $configuration, 'The base merged the mode default configuration; the plugin never appended it.');
    $this->assertSame(ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND, $configuration['processing_mode']);
  }

  /**
   * The base wires the mode select without the plugin calling the builder.
   */
  public function testConfigurationFormCarriesTheModeSelect(): void {
    $complete = [];
    $form = $this->bareProvider()->buildConfigurationForm([], new FormState(), $complete);

    $this->assertArrayHasKey('processing_mode', $form, 'The base wired the mode form; the plugin never called buildProcessingModeForm().');
    $this->assertSame('select', $form['processing_mode']['#type']);
    $this->assertSame(-10, $form['processing_mode']['#weight'], 'The mode select sorts above the plugin settings.');
  }

}
