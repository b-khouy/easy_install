<?php

namespace Drupal\easy_install\Form;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\InfoParserException;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\PermissionHandlerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides module installation interface.
 *
 * The list of modules gets populated by module.info.yml files, which contain
 * each module's name, description, and information about which modules it
 * requires. See \Drupal\Core\Extension\InfoParser for info on module.info.yml
 * descriptors.
 *
 * @internal
 */
class PurgeConfigurationsForm extends FormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The expirable key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $keyValueExpirable;

  /**
   * The module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * The permission handler.
   *
   * @var \Drupal\user\PermissionHandlerInterface
   */
  protected $permissionHandler;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected $accessManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('module_installer'),
      $container->get('keyvalue.expirable')->get('easy_install_purgeconfigs'),
      $container->get('access_manager'),
      $container->get('current_user'),
      $container->get('user.permissions'),
      $container->get('extension.list.module')
    );
  }

  /**
   * Constructs a ModulesListForm object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $key_value_expirable
   *   The key value expirable factory.
   * @param \Drupal\Core\Access\AccessManagerInterface $access_manager
   *   Access manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\user\PermissionHandlerInterface $permission_handler
   *   The permission handler.
   * @param \Drupal\Core\Extension\ModuleExtensionList $extension_list_module
   *   The module extension list.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, KeyValueStoreExpirableInterface $key_value_expirable, AccessManagerInterface $access_manager, AccountInterface $current_user, PermissionHandlerInterface $permission_handler, ModuleExtensionList $extension_list_module) {
    $this->moduleExtensionList = $extension_list_module;
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->keyValueExpirable = $key_value_expirable;
    $this->accessManager = $access_manager;
    $this->currentUser = $current_user;
    $this->permissionHandler = $permission_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'system_modules';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    $distribution = drupal_install_profile_distribution_name();

    // Include system.admin.inc so we can use the sort callbacks.
    $this->moduleHandler->loadInclude('system', 'inc', 'system.admin');

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['table-filter', 'js-show'],
      ],
    ];

    $form['filters']['text'] = [
      '#type' => 'search',
      '#title' => $this->t('Filter modules'),
      '#title_display' => 'invisible',
      '#size' => 30,
      '#placeholder' => $this->t('Filter by name or description'),
      '#description' => $this->t('Enter a part of the uninstalled module name
        or description to delete its garbage configuration'),
      '#attributes' => [
        'class' => ['table-filter-text'],
        'data-table' => '#system-modules',
        'autocomplete' => 'off',
      ],
    ];

    // Sort all modules by their names.
    try {
      // The module list needs to be reset so that it can re-scan and include
      // any new modules that may have been added directly into the filesystem.
      $modules = $this->moduleExtensionList->reset()->getList();
      uasort($modules, [ModuleExtensionList::class, 'sortByName']);
    }
    catch (InfoParserException $e) {
      $this->messenger()->addError($this->t('Modules could not be listed due to an error: %error', ['%error' => $e->getMessage()]));
      $modules = [];
    }

    // Iterate over each of the modules.
    $form['modules']['#tree'] = TRUE;
    foreach ($modules as $filename => $module) {
      if (empty($module->info['hidden']) && !$module->status) {
        $package = $module->info['package'];
        $form['modules'][$package][$filename] = $this->buildRow($modules, $module, $distribution);
        $form['modules'][$package][$filename]['#parents'] = ['modules', $filename];
      }
    }

    // Add a wrapper around every package.
    foreach (Element::children($form['modules']) as $package) {
      $form['modules'][$package] += [
        '#type' => 'details',
        '#title' => $this->t("@package", ['@package' => $package]),
        '#open' => TRUE,
        '#theme' => 'system_modules_details',
        '#attributes' => ['class' => ['package-listing']],
        // Ensure that the "Core" package comes first.
        '#weight' => $package == 'Core' ? -10 : NULL,
      ];
    }

    // If testing modules are shown, collapse the corresponding package by
    // default.
    if (isset($form['modules']['Testing'])) {
      $form['modules']['Testing']['#open'] = FALSE;
    }

    // Lastly, sort all packages by title.
    uasort($form['modules'], ['\Drupal\Component\Utility\SortArray', 'sortByTitleProperty']);

    $form['#attached']['library'][] = 'core/drupal.tableresponsive';
    $form['#attached']['library'][] = 'system/drupal.system.modules';
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge Configurations'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Builds a table row for the system modules page.
   *
   * @param array $modules
   *   The list existing modules.
   * @param \Drupal\Core\Extension\Extension $module
   *   The module for which to build the form row.
   * @param string $distribution
   *   The Drupal distribution.
   *
   * @return array
   *   The form row for the given module.
   */
  protected function buildRow(array $modules, Extension $module, $distribution) {
    // Set the basic properties.
    $row['#required'] = [];
    $row['#requires'] = [];
    $row['#required_by'] = [];

    $row['name']['#markup'] = $module->info['name'];
    $row['description']['#markup'] = $this->t('@desc', ['@desc' => $module->info['description']]);
    $row['version']['#markup'] = $module->info['version'];

    // Generate link for module's help page. Assume that if a hook_help()
    // implementation exists then the module provides an overview page, rather
    // than checking to see if the page exists, which is costly.
    if ($this->moduleHandler->moduleExists('help') && $module->status && $this->moduleHandler->hasImplementations('help', $module->getName())) {
      $row['links']['help'] = [
        '#type' => 'link',
        '#title' => $this->t('Help'),
        '#url' => Url::fromRoute('help.page', ['name' => $module->getName()]),
        '#options' => [
          'attributes' => [
            'class' => [
              'module-link',
              'module-link-help',
            ],
            'title' => $this->t('Help'),
          ],
        ],
      ];
    }

    // Generate link for module's permission, if the user has access to it.
    if ($module->status && $this->currentUser->hasPermission('administer permissions') && $this->permissionHandler->moduleProvidesPermissions($module->getName())) {
      $row['links']['permissions'] = [
        '#type' => 'link',
        '#title' => $this->t('Permissions'),
        '#url' => Url::fromRoute('user.admin_permissions'),
        '#options' => [
          'fragment' => 'module-' . $module->getName(),
          'attributes' => [
            'class' => [
              'module-link',
              'module-link-permissions',
            ],
            'title' => $this->t('Configure permissions'),
          ],
        ],
      ];
    }

    // Generate link for module's configuration page, if it has one.
    if ($module->status && isset($module->info['configure'])) {
      $route_parameters = isset($module->info['configure_parameters']) ? $module->info['configure_parameters'] : [];
      if ($this->accessManager->checkNamedRoute($module->info['configure'], $route_parameters, $this->currentUser)) {
        $row['links']['configure'] = [
          '#type' => 'link',
          '#title' => $this->t('Configure <span class="visually-hidden">the @module module</span>', ['@module' => $module->info['name']]),
          '#url' => Url::fromRoute($module->info['configure'], $route_parameters),
          '#options' => [
            'attributes' => [
              'class' => ['module-link', 'module-link-configure'],
            ],
          ],
        ];
      }
    }

    // Present a checkbox for installing and indicating the status of a module.
    $row['enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Install'),
      '#default_value' => (bool) $module->status,
      '#disabled' => (bool) $module->status,
    ];

    // Disable the checkbox for required modules.
    if (!empty($module->info['required'])) {
      // Used when displaying modules that are required by the installation
      // profile.
      $row['enable']['#disabled'] = TRUE;
      $row['#required_by'][] = $distribution . (!empty($module->info['explanation']) ? ' (' . $module->info['explanation'] . ')' : '');
    }

    // Check the compatibilities.
    $compatible = TRUE;

    // Initialize an empty array of reasons why the module is incompatible. Add
    // each reason as a separate element of the array.
    $reasons = [];

    // Check the core compatibility.
    if ($module->info['core_incompatible']) {
      $compatible = FALSE;
      $reasons[] = $this->t('This version is not compatible with Drupal @core_version and should be replaced.', [
        '@core_version' => \Drupal::VERSION,
      ]);
      $row['#requires']['core'] = $this->t('Drupal Core (@core_requirement) (<span class="admin-missing">incompatible with</span> version @core_version)', [
        '@core_requirement' => isset($module->info['core_version_requirement']) ? $module->info['core_version_requirement'] : $module->info['core'],
        '@core_version' => \Drupal::VERSION,
      ]);
    }

    // Ensure this module is compatible with the currently installed
    // version of PHP.
    if (version_compare(phpversion(), $module->info['php']) < 0) {
      $compatible = FALSE;
      $required = $module->info['php'] . (substr_count($module->info['php'], '.') < 2 ? '.*' : '');
      $reasons[] = $this->t('This module requires PHP version @php_required and is incompatible with PHP version @php_version.', [
        '@php_required' => $required,
        '@php_version' => phpversion(),
      ]);
    }

    // If this module is not compatible, disable the checkbox.
    if (!$compatible) {
      $status = implode(' ', $reasons);
      $row['enable']['#disabled'] = TRUE;
      $row['description']['#markup'] = $status;
      $row['#attributes']['class'][] = 'incompatible';
    }

    // If this module requires other modules, add them to the array.
    /** @var \Drupal\Core\Extension\Dependency $dependency_object */
    foreach ($module->requires as $dependency => $dependency_object) {
      if (!isset($modules[$dependency])) {
        $row['#requires'][$dependency] = $this->t('@module (<span class="admin-missing">missing</span>)', ['@module' => $dependency]);
        $row['enable']['#disabled'] = TRUE;
      }
      // Only display visible modules.
      elseif (empty($modules[$dependency]->hidden)) {
        $name = $modules[$dependency]->info['name'];
        // Disable the module's checkbox if it is incompatible with the
        // dependency's version.
        if (!$dependency_object->isCompatible(str_replace(\Drupal::CORE_COMPATIBILITY . '-', '', $modules[$dependency]->info['version']))) {
          $row['#requires'][$dependency] = $this->t('@module (@constraint) (<span class="admin-missing">incompatible with</span> version @version)', [
            '@module' => $name,
            '@constraint' => $dependency_object->getConstraintString(),
            '@version' => $modules[$dependency]->info['version'],
          ]);
          $row['enable']['#disabled'] = TRUE;
        }
        // Disable the checkbox if the dependency is incompatible with this
        // version of Drupal core.
        elseif ($modules[$dependency]->info['core_incompatible']) {
          $row['#requires'][$dependency] = $this->t('@module (<span class="admin-missing">incompatible with</span> this version of Drupal core)', [
            '@module' => $name,
          ]);
          $row['enable']['#disabled'] = TRUE;
        }
        elseif ($modules[$dependency]->status) {
          $row['#requires'][$dependency] = $this->t('@module', ['@module' => $name]);
        }
        else {
          $row['#requires'][$dependency] = $this->t('@module (<span class="admin-disabled">disabled</span>)', ['@module' => $name]);
        }
      }
    }

    // If this module is required by other modules, list those, and then make it
    // impossible to disable this one.
    foreach ($module->required_by as $dependent => $version) {
      if (isset($modules[$dependent]) && empty($modules[$dependent]->info['hidden'])) {
        if ($modules[$dependent]->status == 1 && $module->status == 1) {
          $row['#required_by'][$dependent] = $this->t('@module', ['@module' => $modules[$dependent]->info['name']]);
          $row['enable']['#disabled'] = TRUE;
        }
        else {
          $row['#required_by'][$dependent] = $this->t('@module (<span class="admin-disabled">disabled</span>)', ['@module' => $modules[$dependent]->info['name']]);
        }
      }
    }

    return $row;
  }

  /**
   * Helper function for building a list of modules to install.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   An array of modules to install and their dependencies.
   */
  protected function buildModuleList(FormStateInterface $form_state) {
    // Build a list of modules to install.
    $modules = [
      'install' => [],
      'dependencies' => [],
      'experimental' => [],
    ];

    $data = $this->moduleExtensionList->getList();
    foreach ($data as $name => $module) {
      // If the module is installed there is nothing to do.
      if ($this->moduleHandler->moduleExists($name)) {
        continue;
      }
      // Required modules have to be installed.
      if (!empty($module->required)) {
        $modules['install'][$name] = $module->info['name'];
      }
      // Selected modules should be installed.
      elseif (($checkbox = $form_state->getValue(['modules', $name], FALSE)) && $checkbox['enable']) {
        $modules['install'][$name] = $data[$name]->info['name'];
        // Identify experimental modules.
        if ($data[$name]->info['package'] == 'Core (Experimental)') {
          $modules['experimental'][$name] = $data[$name]->info['name'];
        }
      }
    }

    // Add all dependencies to a list.
    foreach ($modules['install'] as $module => $value) {
      foreach (array_keys($data[$module]->requires) as $dependency) {
        if (!isset($modules['install'][$dependency]) && !$this->moduleHandler->moduleExists($dependency)) {
          $modules['dependencies'][$module][$dependency] = $data[$dependency]->info['name'];
          $modules['install'][$dependency] = $data[$dependency]->info['name'];

          // Identify experimental modules.
          if ($data[$dependency]->info['package'] == 'Core (Experimental)') {
            $modules['experimental'][$dependency] = $data[$dependency]->info['name'];
          }
        }
      }
    }

    // Make sure the install API is available.
    include_once DRUPAL_ROOT . '/core/includes/install.inc';

    // Invoke hook_requirements('install'). If failures are detected, make
    // sure the dependent modules aren't installed either.
    foreach (array_keys($modules['install']) as $module) {
      if (!drupal_check_module($module)) {
        unset($modules['install'][$module]);
        unset($modules['experimental'][$module]);
        foreach (array_keys($data[$module]->required_by) as $dependent) {
          unset($modules['install'][$dependent]);
          unset($modules['dependencies'][$dependent]);
        }
      }
    }

    return $modules;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve a list of modules to install and their dependencies.
    $modules = $this->buildModuleList($form_state);

    // Redirect to a confirmation form if needed.
    $route_name = 'easy_install.purge_configurations_confirm';
    // Write the list of changed module states into a key value store.
    $account = $this->currentUser()->id();
    $this->keyValueExpirable->setWithExpire($account, $modules, 60);

    // Redirect to the confirmation form.
    $form_state->setRedirect($route_name);

  }

}
