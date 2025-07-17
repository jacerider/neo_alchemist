<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Drush\Generators;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use DrupalCodeGenerator\Attribute\Generator;
use DrupalCodeGenerator\GeneratorType;
use DrupalCodeGenerator\Asset\AssetCollection;
use DrupalCodeGenerator\Command\BaseGenerator;
use DrupalCodeGenerator\InputOutput\Interviewer;
use DrupalCodeGenerator\Validator\RequiredMachineName;
use Drush\Commands\AutowireTrait;
use DrupalCodeGenerator\Utils;
use DrupalCodeGenerator\Validator\Required;
use DrupalCodeGenerator\Validator\Choice;
use DrupalCodeGenerator\Validator\Optional;
use Symfony\Component\Console\Question\Question;

/**
 * Generates a Neo component.
 */
#[Generator(
  name: 'neo-component',
  description: 'Generates Neo component',
  aliases: ['neoac'],
  templatePath: __DIR__ . '/templates',
  type: GeneratorType::THEME_COMPONENT,
)]
final class NeoComponentGenerator extends BaseGenerator {

  use AutowireTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function generate(array &$vars, AssetCollection $assets): void {
    $this->askQuestions($vars);
    $this->generateAssets($vars, $assets);
  }

  /**
   * Collects user answers.
   */
  private function askQuestions(array &$vars): void {
    $ir = $this->createInterviewer($vars);
    $vars['machine_name'] = $ir->askMachineName();
    $vars['name'] = $ir->askName();

    $vars['component_name'] = $ir->ask('Component name', validator: new Required());
    $vars['component_machine_name'] = $ir->ask(
      'Component machine name',
      Utils::human2machine($vars['component_name']),
      new RequiredMachineName(),
    );

    $vars['component_description'] = $ir->ask('Component description (optional)');

    $vars['component_libraries'] = [];
    do {
      $library = $this->askLibrary();
      $vars['component_libraries'][] = $library;
    } while ($library !== NULL);

    $vars['component_libraries'] = \array_filter($vars['component_libraries']);
    $vars['component_has_css'] = $ir->confirm('Need CSS?');
    $vars['component_has_js'] = $ir->confirm('Need JS?');
    if ($ir->confirm('Need component props?')) {
      $vars['component_props'] = [];
      do {
        $prop = $this->askProp($vars, $ir);
        $vars['component_props'][] = $prop;
      } while ($ir->confirm('Add another prop?'));
    }
    $vars['component_props'] = \array_filter($vars['component_props'] ?? []);

    if ($ir->confirm('Need slots?')) {
      $vars['component_slots'] = [];
      do {
        $slot = $this->askSlot($vars, $ir);
        $vars['component_slots'][] = $slot;
      } while ($ir->confirm('Add another slot?'));
    }
    $vars['component_slots'] = \array_filter($vars['component_slots'] ?? []);
  }

  /**
   * Create the assets that the framework will write to disk later on.
   *
   * @psalm-param array{component_has_css: bool, component_has_js: bool} $vars
   *   The answers to the CLI questions.
   */
  private function generateAssets(array $vars, AssetCollection $assets): void {
    $component_path = 'components/{component_machine_name}/';

    print_r($this->getTemplatePath());
    // If ($vars['component_has_css']) {
    //   $assets->addFile($component_path . '{component_machine_name}.css', 'styles.twig');
    // }
    // if ($vars['component_has_js']) {
    //   $assets->addFile($component_path . '{component_machine_name}.js', 'javascript.twig');
    // }
    // $assets->addFile($component_path . '{component_machine_name}.twig', 'template.twig');
    // $assets->addFile($component_path . '{component_machine_name}.component.yml', 'component.twig');
    // $assets->addFile($component_path . 'README.md', 'readme.twig');.
    // $contents = \file_get_contents($this->getTemplatePath() . \DIRECTORY_SEPARATOR . 'thumbnail.png');
    // $thumbnail = new File($component_path . 'thumbnail.png');
    // $thumbnail->content($contents);
    // $assets[] = $thumbnail;.
  }

  /**
   * Prompts the user for a library.
   *
   * This helper gathers all the libraries from the system to allow autocomplete
   * and validation.
   *
   * @return string|null
   *   The library ID, if any.
   *
   * @todo Move this to interviewer.
   */
  private function askLibrary(): ?string {
    $extensions = [
      'core',
      ...\array_keys($this->moduleHandler->getModuleList()),
      ...\array_keys($this->themeHandler->listInfo()),
    ];
    $library_ids = \array_reduce(
      $extensions,
      fn (iterable $libs, $extension): array => \array_merge(
        (array) $libs,
        \array_map(static fn (string $lib): string => \sprintf('%s/%s', $extension, $lib),
        \array_keys($this->libraryDiscovery->getLibrariesByExtension($extension))),
      ),
      [],
    );

    $question = new Question('Library dependencies (optional). [Example: core/once]');
    $question->setAutocompleterValues($library_ids);
    $question->setValidator(
      new Optional(new Choice($library_ids, 'Invalid library selected.')),
    );

    return $this->io()->askQuestion($question);
  }

  /**
   * Asks for multiple questions to define a prop and its schema.
   *
   * @psalm-param array{component_machine_name: mixed, ...<array-key, mixed>} $vars
   *   The answers to the CLI questions.
   *
   * @return array
   *   The prop data, if any.
   */
  protected function askProp(array $vars, Interviewer $ir): array {
    $prop = [];
    $prop['title'] = $ir->ask('Prop title', '', new Required());
    $default = Utils::human2machine($prop['title']);
    $prop['name'] = $ir->ask('Prop machine name', $default, new RequiredMachineName());
    $prop['description'] = $ir->ask('Prop description (optional)');
    $choices = [
      'string' => 'String',
      'number' => 'Number',
      'boolean' => 'Boolean',
      'array' => 'Array',
      'object' => 'Object',
      'null' => 'Always null',
    ];
    $prop['type'] = $ir->choice('Prop type', $choices, 'String');
    if (!\in_array($prop['type'], ['string', 'number', 'boolean'])) {
      /** @psalm-var string $type */
      $type = $prop['type'];
      $component_schema_name = $vars['component_machine_name'] . '.component.yml';
      $this->io()->warning(\sprintf('Unable to generate full schema for %s. Please edit %s after generation.', $type, $component_schema_name));
    }
    return $prop;
  }

  /**
   * Asks for multiple questions to define a slot.
   *
   * @psalm-param array{component_machine_name: mixed, ...<array-key, mixed>} $vars
   *   The answers to the CLI questions.
   *
   * @return array
   *   The slot data, if any.
   */
  protected function askSlot(array $vars, Interviewer $ir): array {
    $slot = [];
    $slot['title'] = $ir->ask('Slot title', '', new Required());
    $default = Utils::human2machine($slot['title']);
    $slot['name'] = $ir->ask('Slot machine name', $default, new RequiredMachineName());
    $slot['description'] = $ir->ask('Slot description (optional)');
    return $slot;
  }

}
