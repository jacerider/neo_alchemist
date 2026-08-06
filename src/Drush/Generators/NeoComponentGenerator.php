<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Drush\Generators;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Render\Markup;
use Drupal\neo_alchemist\ComponentPropDefPluginManager;
use Drupal\neo_alchemist\ComponentShapePluginManager;
use DrupalCodeGenerator\Attribute\Generator;
use DrupalCodeGenerator\GeneratorType;
use DrupalCodeGenerator\Asset\AssetCollection;
use DrupalCodeGenerator\Command\BaseGenerator;
use DrupalCodeGenerator\InputOutput\Interviewer;
use DrupalCodeGenerator\Validator\RequiredMachineName;
use DrupalCodeGenerator\Utils;
use DrupalCodeGenerator\Validator\Required;
use DrupalCodeGenerator\Validator\Choice;
use DrupalCodeGenerator\Validator\Optional;
use Symfony\Component\Console\Question\Question;
use DrupalCodeGenerator\Asset\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates a Neo component.
 */
#[Generator(
  name: 'neo-component',
  description: 'Generates Neo component',
  aliases: ['neoac'],
  templatePath: __DIR__ . '/templates/component',
  type: GeneratorType::THEME_COMPONENT,
)]
final class NeoComponentGenerator extends BaseGenerator implements ContainerInjectionInterface, NeoComponentPropGeneratorInterface {

  use NeoComponentPropGeneratorTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly ComponentPropDefPluginManager $propDefManager,
    private readonly ComponentShapePluginManager $shapeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('module_handler'),
      $container->get('theme_handler'),
      $container->get('library.discovery'),
      $container->get('plugin.manager.neo_component_prop_def'),
      $container->get('plugin.manager.neo_component_shape'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function generate(array &$vars, AssetCollection $assets): void {
    $vars['name'] = 'Front';
    $this->askQuestions($vars);
    // $this->getExample($vars);
    $this->generateAssets($vars, $assets);

    // Clear all plugin caches.
    \Drupal::service('plugin.cache_clearer')->clearCachedDefinitions();
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
    $vars['component_has_css'] = $ir->confirm('Need CSS?', FALSE);
    $vars['component_has_js'] = $ir->confirm('Need JS?', FALSE);
    if ($ir->confirm('Need component props?')) {
      $vars['component_props'] = [];
      do {
        $prop = $this->askProp($vars, $ir);
        $vars['component_props'][] = $prop;
      } while ($ir->confirm('Root: Add another prop?'));
    }
    $vars['component_props'] = \array_filter($vars['component_props'] ?? []);

    $hasSpacing = !empty(array_filter($vars['component_props'], fn ($prop) => $prop['type'] === 'spacing'));
    if (!$hasSpacing) {
      $addSpacing = $ir->confirm('The spacing property is recommended for all components. Add it?', TRUE);
      if ($addSpacing) {
        $vars['component_props'][] = [
          'name' => 'spacing',
          'type' => 'spacing',
        ];
      }
    }

    $hasGap = !empty(array_filter($vars['component_props'], fn ($prop) => $prop['type'] === 'gap'));
    if (!$hasGap) {
      $addGap = $ir->confirm('The gap property is recommended for all components. Add it?', TRUE);
      if ($addGap) {
        $vars['component_props'][] = [
          'name' => 'gap',
          'type' => 'gap',
        ];
      }
    }

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
    if ($vars['component_has_css']) {
      $assets->addFile($component_path . '{component_machine_name}.css', 'styles.twig');
    }
    if ($vars['component_has_js']) {
      $assets->addFile($component_path . '{component_machine_name}.js', 'javascript.twig');
    }
    $assets->addFile($component_path . '{component_machine_name}.twig', 'template.twig');
    $assets->addFile($component_path . '{component_machine_name}.component.yml', 'component.twig');
    $assets->addFile($component_path . 'README.md', 'readme.twig');
    $contents = \file_get_contents($this->getTemplatePath() . \DIRECTORY_SEPARATOR . 'thumbnail.png');
    $thumbnail = new File($component_path . 'thumbnail.png');
    $thumbnail->content($contents);
    $assets[] = $thumbnail;
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

    $question = new Question('Library dependencies (optional). [Examples: core/once]');
    $question->setAutocompleterValues($library_ids);
    $question->setValidator(
      new Optional(new Choice($library_ids, 'Invalid library selected.')),
    );

    return $this->io()->askQuestion($question);
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

  /**
   * Provides an example of a component.
   *
   * @psalm-param array{component_machine_name: mixed, ...<array-key, mixed>} $vars
   *   The answers to the CLI questions.
   */
  protected function getExample(array &$vars): void {
    $vars['name'] = 'Front';
    $vars['machine_name'] = 'front';
    $vars['component_name'] = 'Wow';
    $vars['component_machine_name'] = 'wow';
    $vars['component_description'] = 'Wow component';
    $vars['component_libraries'] = ['core/drupal'];
    $vars['component_has_css'] = FALSE;
    $vars['component_has_js'] = FALSE;
    $vars['component_props'] = [];
    $vars['component_props'][] = [
      'title' => 'Heading',
      'name' => 'heading',
      'description' => 'The main heading of the component',
      'type' => 'heading',
      'examples' => [
        'supertitle' => 'Super title',
        'title' => 'Main title',
        'subtitle' => 'Subtitle',
      ],
      'twig_prefix' => Markup::create('{% if heading %}'),
      'twig' => [
        Markup::create("{{ heading.supertitle }}"),
        Markup::create("{{ heading.title }}"),
        Markup::create("{{ heading.subtitle }}"),
      ],
      'twig_suffix' => Markup::create('{% endif %}'),
    ];
    $vars['component_props'][] = [
      'title' => 'Prop 1',
      'name' => 'prop_1',
      'description' => 'This is prop 1',
      'type' => 'object',
      'examples' => [
        'image' => [
          'poops' => 'https://placehold.co/100x100.png',
        ],
      ],
      'twig' => [
        'prefix' => Markup::create('{% if prop_1 %}'),
        'suffix' => Markup::create('{% endif %}'),
      ],
      'properties' => [
        [
          'title' => 'Prop 1.1',
          'name' => 'prop_1_1',
          'description' => 'This is prop 1.1',
          'type' => 'string',
          'examples' => 'Value 1.1',
          'twig' => [
            'prefix' => Markup::create('{% if prop_1_1 %}'),
            'content' => Markup::create("{{ prop_1_1 }}"),
            'suffix' => Markup::create('{% endif %}'),
          ],
        ],
        [
          'title' => 'Prop 1.2',
          'name' => 'prop_1_2',
          'description' => 'This is prop 1.2',
          'type' => 'object',
          'examples' => [
            'prop_1_2_1' => 'Value 1.2.1',
            'prop_1_2_2' => 'Value 1.2.2',
          ],
          'properties' => [
            [
              'title' => 'Prop 1.2.1',
              'name' => 'prop_1_2_1',
              'description' => 'This is prop 1.2.1',
              'type' => 'string',
            ],
            [
              'title' => 'Prop 1.2.2',
              'name' => 'prop_1_2_2',
              'description' => 'This is prop 1.2.2',
              'type' => 'string',
            ],
          ],
        ],
        [
          'title' => 'Prop 1.3',
          'name' => 'prop_1_3',
          'description' => 'This is prop 1.3',
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'title' => 'Prop 1.3 item',
            'properties' => [
              [
                'title' => 'Prop 1.3.1',
                'name' => 'prop_1_3_1',
                'description' => 'This is prop 1.3.1',
                'type' => 'string',
              ],
            ],
          ],
        ],
      ],
    ];
    $vars['component_props'][] = [
      'title' => 'Prop 2',
      'name' => 'prop_2',
      'description' => 'This is prop 2',
      'type' => 'array',
      'examples' => [
        [
          'prop_2_1' => [
            'src' => 'https://placehold.co/100x100.png',
            'alt' => 'Example image',
            'width' => 100,
            'height' => 100,
          ],
        ],
      ],
      'items' => [
        'type' => 'object',
        'title' => 'Prop 2 item',
        'properties' => [
          [
            'title' => 'Prop 2.1',
            'name' => 'prop_2_1',
            'description' => 'This is prop 2.1',
            'type' => 'image',
            'examples' => [
              'src' => 'https://placehold.co/100x100.png',
              'alt' => 'Example image',
              'width' => 100,
              'height' => 100,
            ],
          ],
          [
            'title' => 'Prop 2.2',
            'name' => 'prop_2_2',
            'description' => 'This is prop 2.2',
            'type' => 'object',
            'properties' => [
              [
                'title' => 'Prop 2.2.1',
                'name' => 'prop_2_2_1',
                'description' => 'This is prop 2.2.1',
                'type' => 'image',
                'examples' => [
                  'src' => 'https://placehold.co/100x100.png',
                  'alt' => 'Example image',
                  'width' => 100,
                  'height' => 100,
                ],
              ],
              [
                'title' => 'Prop 2.2.2',
                'name' => 'prop_2_2_2',
                'description' => 'This is prop 2.2.2',
                'type' => 'link',
                'examples' => [
                  'title' => 'Example link',
                  'url' => 'internal:/',
                ],
              ],
            ],
          ],
        ],
      ],
    ];
    $vars['component_slots'] = [];
  }

}
