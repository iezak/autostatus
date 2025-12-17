<?php
/**
 * GLPI AutoStatus plugin
 * - Automatically changes ticket status when:
 *   - a ticket is created
 *   - a task is added
 *   - a followup is added
 *
 * Target: GLPI 10.0.x (tested conceptually for 10.0.19)
 */

define('PLUGIN_AUTOSTATUS_VERSION', '1.3.0');

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

      // Optional: integration with ActualTime plugin (timer start recorded in PluginActualtimeTask)
      'PluginActualtimeTask' => 'plugin_autostatus_item_add_actualtime_task',
   ];

   // Hooks: run when objects are updated (used for ActualTime timer stop)
   $PLUGIN_HOOKS['item_update']['autostatus'] = [
      'PluginActualtimeTask' => 'plugin_autostatus_item_update_actualtime_task',
   ];
}

function plugin_version_autostatus() {
   return [
      'name'           => 'AutoStatus',
      'version'        => PLUGIN_AUTOSTATUS_VERSION,
      'author'         => 'Vinicius + ChatGPT',
      'license'        => 'GPLv2+',
      'homepage'       => '',
      'requirements'   => [
         'glpi' => [
            'min' => '10.0.0',
            'max' => '11.0.0',
         ],
         'php'  => [
            'min' => '8.1'
         ]
      ]
   ];
}

function plugin_autostatus_check_prerequisites() {
   // Keep it permissive inside 10.x; GLPI will block incompatible versions anyway.
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


      // ActualTime integration (optional)
      'actualtime_enabled'                => 0,
      'actualtime_status_running'         => (defined('CommonITILObject::ASSIGNED') ? CommonITILObject::ASSIGNED : 2),
      'actualtime_status_stopped'         => (defined('CommonITILObject::WAITING') ? CommonITILObject::WAITING : 4),
      'actualtime_stop_only_if_no_timer'  => 1,
      'actualtime_allowed_statuses_start' => '',
      'actualtime_allowed_statuses_stop'  => '',

      // Optional: different status depending on who wrote the followup
      // - if author is the requester (users_id_recipient): use onfollowup_status_requester
      // - else (technician/other): use onfollowup_status_other
      'followup_split_by_author' => 1,
      'onfollowup_status_requester' => (defined('CommonITILObject::ASSIGNED') ? CommonITILObject::ASSIGNED : 2),
      'onfollowup_status_other'     => (defined('CommonITILObject::WAITING') ? CommonITILObject::WAITING : 4),
   ];

   // Store plugin configuration using core Config API.
   Config::setConfigurationValues('plugin:autostatus', $defaults);
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

      'actualtime_enabled', 'actualtime_status_running', 'actualtime_status_stopped', 'actualtime_stop_only_if_no_timer',
      'actualtime_allowed_statuses_start', 'actualtime_allowed_statuses_stop',

      'followup_split_by_author', 'onfollowup_status_requester', 'onfollowup_status_other',
   ]);
   return true;
}
