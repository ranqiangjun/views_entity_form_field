<?php

namespace Drupal\views_entity_form_field\Plugin\views\field;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\views\Entity\Render\EntityTranslationRenderTrait;
use Drupal\views\Plugin\DependentWithRemovalPluginInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\field\UncacheableFieldHandlerTrait;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a views form element for an entity field widget.
 *
 * @ViewsField("entity_form_field")
 */
class EntityFormField extends FieldPluginBase implements CacheableDependencyInterface, DependentWithRemovalPluginInterface {

  use EntityTranslationRenderTrait;
  use PluginDependencyTrait;
  use UncacheableFieldHandlerTrait;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * The field type manager.
   *
   * @var FieldTypePluginManagerInterface
   */
  protected $fieldTypeManager;

  /**
   * The field widget plugin manager.
   *
   * @var \Drupal\Core\Field\WidgetPluginManager
   */
  protected $fieldWidgetManager;

  /**
   * The loaded field widgets.
   *
   * @var \Drupal\Core\Field\WidgetInterface[]
   */
  protected $fieldWidgets;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new EditQuantity object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Field\WidgetPluginManager
   *   The field widget plugin manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entity_field_manager, EntityManagerInterface $entity_manager, WidgetPluginManager $field_widget_manager, LanguageManagerInterface $language_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityFieldManager = $entity_field_manager;
    $this->entityManager = $entity_manager;
    $this->fieldWidgetManager = $field_widget_manager;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('entity.manager'),
      $container->get('plugin.manager.field.widget'),
      $container->get('language_manager')
    );
  }

  /**
   * Returns the entity manager.
   *
   * @return \Drupal\Core\Entity\EntityManagerInterface
   *   The entity manager service.
   */
  protected function getEntityManager() {
    return $this->entityManager;
  }

  /**
   * The field type plugin manager.
   *
   * This is loaded on-demand, since it's only needed during configuration.
   *
   * @return \Drupal\Core\Field\FieldTypePluginManagerInterface
   *   The field type plugin manager.
   */
  protected function getFieldTypeManager() {
    if (is_null($this->fieldTypeManager)) {
      $this->fieldTypeManager = \Drupal::service('plugin.manager.field.field_type');
    }
    return $this->fieldTypeManager;
  }

  /**
   * Get the entity type ID for this views field instance.
   *
   * @return string
   *   The entity type ID.
   */
  protected function getEntityTypeId() {
    if (is_null($this->entityTypeId)) {
      $this->entityTypeId = $this->getEntityType();
    }
    return $this->entityTypeId;
  }

  /**
   * Collects the definition of field.
   *
   * @param string $bundle
   *   The bundle to load the field definition for.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition. Null if not set.
   */
  protected function getBundleFieldDefinition($bundle = NULL) {
    $bundle = (!is_null($bundle)) ? $bundle : reset($this->definition['bundles']);
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($this->getEntityTypeId(), $bundle);
    return array_key_exists($this->definition['field_name'], $field_definitions) ? $field_definitions[$this->definition['field_name']] : NULL;
  }

  /**
   * Returns an array of applicable widget options for a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return string[]
   *   An array of applicable widget options.
   */
  protected function getPluginApplicableOptions(FieldDefinitionInterface $field_definition) {
    $options = $this->fieldWidgetManager->getOptions($field_definition->getType());
    $applicable_options = [];
    foreach ($options as $option => $label) {
      $plugin_class = DefaultFactory::getPluginClass($option, $this->fieldWidgetManager->getDefinition($option));
      if ($plugin_class::isApplicable($field_definition)) {
        $applicable_options[$option] = $label;
      }
    }
    return $applicable_options;
  }

  /**
   * Returns the default field widget ID for a specific field type.
   *
   * @param string $field_type
   *   The field type ID.
   *
   * @return null|string
   *   The default field widget ID. Null otherwise.
   */
  protected function getPluginDefaultOption($field_type) {
    $definition = $this->getFieldTypeManager()->getDefinition($field_type, FALSE);
    return ($definition && isset($definition['default_widget'])) ? $definition['default_widget'] : NULL;
    }

  /**
   * Gets a bundle-specific field widget instance.
   *
   * @param null|string $bundle
   *   The bundle to load the plugin for.
   *
   * @return null|\Drupal\Core\Field\WidgetInterface
   *   The field widget plugin if it is set. Null otherwise.
   */
  protected function getPluginInstance($bundle = NULL) {
    // Cache the created instance per bundle.
    $bundle = (!is_null($bundle)) ? $bundle : reset($this->definition['bundles']);
    if (!isset($this->fieldWidgets[$bundle]) && $field_definition = $this->getBundleFieldDefinition($bundle)) {
      $this->fieldWidgets[$bundle] = $this->fieldWidgetManager->getInstance([
        'field_definition' => $field_definition,
        'form_mode' => 'views_view',
        'prepare' => FALSE,
        'configuration' => $this->options['plugin'],
      ]);
    }
    return $this->fieldWidgets[$bundle];
  }

  /**
   * {@inheritdoc}
   */
  protected function getLanguageManager() {
    return $this->languageManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getView() {
    return $this->view;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->getEntityTranslationRenderer()->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $field_definition = $this->getBundleFieldDefinition();
    $field_storage_definition = $field_definition->getFieldStorageDefinition();

    return Cache::mergeTags(
      $field_definition instanceof CacheableDependencyInterface ? $field_definition->getCacheTags() : [],
      $field_storage_definition instanceof CacheableDependencyInterface ? $field_storage_definition->getCacheTags() : []
    );
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $this->dependencies = parent::calculateDependencies();

    // Add the module providing the configured field storage as a dependency.
    if (($field_definition = $this->getBundleFieldDefinition()) && $field_definition instanceof EntityInterface) {
      $this->dependencies['config'][] = $field_definition->getConfigDependencyName();
    }
    if (!empty($this->options['type'])) {
      // Add the module providing the formatter.
      $this->dependencies['module'][] = $this->fieldWidgetManager->getDefinition($this->options['type'])['provider'];

      // Add the formatter's dependencies.
      if (($formatter = $this->getPluginInstance()) && $formatter instanceof DependentPluginInterface) {
        $this->calculatePluginDependencies($formatter);
      }
    }

    return $this->dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function onDependencyRemoval(array $dependencies) {
    // See if this handler is responsible for any of the dependencies being
    // removed. If this is the case, indicate that this handler needs to be
    // removed from the View.
    $remove = FALSE;
    // Get all the current dependencies for this handler.
    $current_dependencies = $this->calculateDependencies();
    foreach ($current_dependencies as $group => $dependency_list) {
      // Check if any of the handler dependencies match the dependencies being
      // removed.
      foreach ($dependency_list as $config_key) {
        if (isset($dependencies[$group]) && array_key_exists($config_key, $dependencies[$group])) {
          // This handlers dependency matches a dependency being removed,
          // indicate that this handler needs to be removed.
          $remove = TRUE;
          break 2;
        }
      }
    }
    return $remove;
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['plugin']['contains']['type']['default'] = $this->getPluginDefaultOption($this->getBundleFieldDefinition()->getType());
    $options['plugin']['contains']['settings']['default'] = [];
    $options['plugin']['contains']['third_party_settings']['default'] = [];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $field_definition = $this->getBundleFieldDefinition();

    $form['plugin'] = [
      'type' => [
        '#type' => 'select',
        '#title' => $this->t('Widget type'),
        '#options' => $this->getPluginApplicableOptions($field_definition),
        '#default_value' => $this->options['plugin']['type'],
        '#attributes' => ['class' => ['field-plugin-type']],
        '#ajax' => [
          'url' => views_ui_build_form_url($form_state),
        ],
        '#submit' => [[$this, 'submitTemporaryForm']],
        '#executes_submit_callback' => TRUE,
      ],
      'settings_edit_form' => [],
    ];

    // Generate the settings form and allow other modules to alter it.
    if ($plugin = $this->getPluginInstance()) {
      $settings_form = $plugin->settingsForm($form, $form_state);

      // Adds the widget third party settings forms.
      $third_party_settings_form = [];
      foreach ($this->moduleHandler->getImplementations('field_widget_third_party_settings_form') as $module) {
        $third_party_settings_form[$module] = $this->moduleHandler->invoke($module, 'field_widget_third_party_settings_form', [
          $plugin,
          $field_definition,
          'views_view',
          $form,
          $form_state,
        ]);
      }

      if ($settings_form || $third_party_settings_form) {
        $form['plugin']['#cell_attributes'] = ['colspan' => 3];
        $form['plugin']['settings_edit_form'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Widget settings'),
          '#attributes' => ['class' => ['field-plugin-settings-edit-form']],
          'settings' => $settings_form,
          'third_party_settings' => $third_party_settings_form,
        ];
        $form['#attributes']['class'][] = 'field-plugin-settings-editing';
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitFormCalculateOptions(array $options, array $form_state_options) {
    // When we change the formatter type we don't want to keep any of the
    // previous configured formatter settings, as there might be schema
    // conflict.
    unset($options['settings']);
    $options = $form_state_options + $options;
    if (!isset($options['settings'])) {
      $options['settings'] = [];
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);

    $options = &$form_state->getValue('options');
    $options['plugin']['settings'] = isset($options['plugin']['settings_edit_form']['settings']) ? array_intersect_key($options['plugin']['settings_edit_form']['settings'], $this->fieldWidgetManager->getDefaultSettings($options['plugin']['type'])) : [];
    $options['plugin']['third_party_settings'] = isset($options['plugin']['settings_edit_form']['third_party_settings']) ? $options['plugin']['settings_edit_form']['third_party_settings'] : [];
    unset($options['plugin']['settings_edit_form']);
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $row, $field = NULL) {
    return '<!--form-item-' . $this->options['id'] . '--' . $row->index . '-->';
  }

  /**
   * Form constructor for the views form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsForm(array &$form, FormStateInterface $form_state) {
    $field_name = $this->definition['field_name'];

    // Initialize form values.
    $form['#after_build'][] = [$this, 'viewsFormAfterBuild'];
    $form['#cache']['max-age'] = 0;
    $form['#tree'] = TRUE;
    $form += ['#parents' => []];

    // Set the handler to save the entities, once per relationship.
    if (!isset($form['actions']['submit']['#submit'][$this->getEntityTypeId()])) {
      $form['actions']['submit']['#submit'][$this->getEntityTypeId()] = [$this, 'saveEntities'];
    }

    // Only add the buttons if there are results.
    if (!empty($this->getView()->result)) {
      $form[$this->options['id']]['#tree'] = TRUE;
      $form[$this->options['id']]['#entity_form_field'] = TRUE;
      foreach ($this->getView()->result as $row_index => $row) {
        // Initialize this row and column.
        $form[$this->options['id']][$row_index]['#parents'] = [$this->options['id']];
        $form[$this->options['id']][$row_index]['#tree'] = TRUE;

        $entity = $this->getEntityTranslation($this->getEntity($row), $row);

        // Load field definition based on current entity bundle.
        $field_definition = $this->getBundleFieldDefinition($entity->bundle());
        if ($field_definition && $field_definition->isDisplayConfigurable('form')) {
          $items = $entity->get($field_name)->filterEmptyItems();

          // Add Widget to views-form nested in the correct row and space.
          $form[$this->options['id']][$row_index][$field_name]['#parents'] = [$this->options['id'], $row_index];
          $form[$this->options['id']][$row_index][$field_name]['#tree'] = FALSE;

          // Get widget's subform state
          $subform_state = SubformState::createForSubform($form[$this->options['id']][$row_index][$field_name], $form, $form_state);

          // Add widget to form and add field overrides.
          $form[$this->options['id']][$row_index][$field_name] = $this->getPluginInstance()->form($items, $form[$this->options['id']][$row_index][$field_name], $subform_state);
          $form[$this->options['id']][$row_index][$field_name]['#access'] = $items->access('edit');
          $form[$this->options['id']][$row_index][$field_name]['#title_display'] = 'invisible';
          $form[$this->options['id']][$row_index][$field_name]['widget']['#title_display'] = 'invisible';
          array_pop($form[$this->options['id']][$row_index][$field_name]['#parents']);
        }
      }
    }
  }

  /**
   * Form element #after_build callback: Updates the entity with submitted data.
   *
   * Updates the internal $this->entity object with submitted values when the
   * form is being rebuilt (e.g. submitted via AJAX), so that subsequent
   * processing (e.g. AJAX callbacks) can rely on it.
   */
  public function viewsFormAfterBuild(array $element, FormStateInterface $form_state) {
    $field_name = $this->definition['field_name'];
    // Add this field's value back to the entity for each row.
    foreach ($this->getView()->result as $row_index => $row) {
      $entity = $this->getEntity($row);
      if ($entity->hasField($field_name) &&$this->getBundleFieldDefinition($entity->bundle())->isDisplayConfigurable('form')) {
        $this->getPluginInstance($entity->bundle())->extractFormValues($entity->get($field_name)->filterEmptyItems(), $element[$this->options['id']][$row_index][$field_name], $form_state);
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function viewsFormValidate(array &$form, FormStateInterface $form_state) {
    $field_name = $this->definition['field_name'];

    // Validate this field on each row.
    foreach ($this->getView()->result as $row_index => $row) {
      $entity = $this->getEntityTranslation($this->getEntity($row), $row);

      // Load field definition based on current entity bundle.
      $field_definition = $this->getBundleFieldDefinition($entity->bundle());
      if ($field_definition && $field_definition->isDisplayConfigurable('form')) {

        // Add violations to field widget.
        $violations = $entity->get($field_name)->validate();
        if ($violations->count() > 0) {
          $this->getPluginInstance($entity->bundle())->flagErrors($entity->get($field_name)->filterEmptyItems(), $violations, $form[$this->options['id']][$row_index][$field_name], $form_state);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewsFormSubmit(array &$form, FormStateInterface $form_state) {
    // Do nothing.
  }

  /**
   * Save the view's entities.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function saveEntities(array &$form, FormStateInterface $form_state) {
    $storage = $this->getEntityManager()->getStorage($this->getEntityTypeId());
    foreach ($this->getView()->result as $row_index => $row) {
      $entity = $this->getEntityTranslation($this->getEntity($row), $row);
      $storage->save($entity);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do nothing.
  }

}
