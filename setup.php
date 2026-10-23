<?php
/**
 * GLPI AutoStatus plugin
 * - Automatically changes ticket status when:
 *   - a ticket is created
 *   - a task is added
 *   - a followup is added
 *
 * Target: GLPI 10.0.x (reviewed against 10.0.26)
 */

define('PLUGIN_AUTOSTATUS_VERSION', '1.2.2');

function plugin_init_autostatus() {
   global $PLUGIN_HOOKS;

   // Declare CSRF compliance (GLPI >= 9.2). You must include _glpi_csrf_token in POST forms.
   $PLUGIN_HOOKS['csrf_compliant']['autostatus'] = true;

   // Show a configuration page (Setup > Plugins > AutoStatus > Configure)
   $PLUGIN_HOOKS['config_page']['autostatus'] = 'front/config.form.php';

   // Hooks: run when objects are added
   $PLUGIN_HOOKS['item_add']['autostatus'] = [
      'Ticket'       => 'plugin_autostatus_item_add_ticket',
      'TicketTask'   => 'plugin_autostatus_item_add_task',
      'ITILFollowup' => 'plugin_autostatus_item_add_followup',
   ];
}

function plugin_version_autostatus() {
   return [
      'name'           => 'AutoStatus',
      'version'        => PLUGIN_AUTOSTATUS_VERSION,
      'author'         => 'Vinicius + ChatGPT',
      'license'        => 'GPLv2+',
      'homepage'       => 'https://github.com/iezak/autostatus',
      'requirements'   => [
         'glpi' => [
            'min' => '10.0.0',
            'max' => '10.0.99',
         ],
         'php'  => [
            'min' => '8.1'
         ]
      ]
   ];
}

function plugin_autostatus_check_prerequisites() {
   // The hook behavior has been reviewed for GLPI 10.0.x only.
   if (version_compare(GLPI_VERSION, '10.0.0', '<')
       || version_compare(GLPI_VERSION, '10.1.0', '>=')
       || PHP_VERSION_ID < 80100) {
      echo 'AutoStatus requer GLPI 10.0.x e PHP 8.1 ou superior.';
      return false;
   }
   return true;
}

function plugin_autostatus_check_config($verbose = false) {
   return true;
}

function plugin_autostatus_install() {
   // Default values (0 means "do not change")
   $defaults = [
      // Global
      'ignore_solved_closed'     => 1,

      // Apply only if current status is in this list (CSV of status ids). Empty = any.
      'oncreate_allowed_statuses'   => '',
      'ontask_allowed_statuses'     => '',
      'onfollowup_allowed_statuses' => '',

      // Ticket created
      'oncreate_enabled'         => 1,
      'oncreate_status'          => (defined('CommonITILObject::INCOMING') ? CommonITILObject::INCOMING : 1),

      // Task added
      'ontask_enabled'           => 1,
      'ontask_status'            => (defined('CommonITILObject::ASSIGNED') ? CommonITILObject::ASSIGNED : 2),
      'ignore_private_tasks'     => 0,

      // Followup added
      'onfollowup_enabled'       => 1,
      'onfollowup_status'        => (defined('CommonITILObject::WAITING') ? CommonITILObject::WAITING : 4),
      'ignore_private_followups' => 0,

      // Optional: different status depending on who wrote the followup
      // - if author is the requester (users_id_recipient): use onfollowup_status_requester
      // - else (technician/other): use onfollowup_status_other
      'followup_split_by_author' => 1,
      'onfollowup_status_requester' => (defined('CommonITILObject::ASSIGNED') ? CommonITILObject::ASSIGNED : 2),
      'onfollowup_status_other'     => (defined('CommonITILObject::WAITING') ? CommonITILObject::WAITING : 4),
   ];

   // Do not overwrite an administrator's saved settings if the plugin is
   // reinstalled or its install hook is invoked during maintenance.
   $existing = Config::getConfigurationValues('plugin:autostatus');
   $missing = array_diff_key($defaults, is_array($existing) ? $existing : []);
   if ($missing !== []) {
      Config::setConfigurationValues('plugin:autostatus', $missing);
   }
   return true;
}

function plugin_autostatus_uninstall() {
   $config = new Config();
   $config->deleteConfigurationValues('plugin:autostatus', [
      'ignore_solved_closed',

      'oncreate_allowed_statuses', 'ontask_allowed_statuses', 'onfollowup_allowed_statuses',

      'oncreate_enabled', 'oncreate_status',

      'ontask_enabled', 'ontask_status', 'ignore_private_tasks',

      'onfollowup_enabled', 'onfollowup_status', 'ignore_private_followups',

      'followup_split_by_author', 'onfollowup_status_requester', 'onfollowup_status_other',
   ]);
   return true;
}
