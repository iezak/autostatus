<?php
/**
 * GLPI AutoStatus hooks.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Get plugin configuration (with defaults).
 */
function plugin_autostatus_get_config(): array {
   $conf = Config::getConfigurationValues('plugin:autostatus');

   // Ensure defaults exist even if config is missing keys
   $defaults = [
      'ignore_solved_closed'        => 1,

      'oncreate_allowed_statuses'   => '',
      'ontask_allowed_statuses'     => '',
      'onfollowup_allowed_statuses' => '',

      'oncreate_enabled'            => 1,
      'oncreate_status'             => (defined('CommonITILObject::INCOMING') ? CommonITILObject::INCOMING : 1),

      'ontask_enabled'              => 1,
      'ontask_status'               => (defined('CommonITILObject::ASSIGNED') ? CommonITILObject::ASSIGNED : 2),
      'ignore_private_tasks'        => 0,

      'onfollowup_enabled'          => 1,
      'onfollowup_status'           => (defined('CommonITILObject::WAITING') ? CommonITILObject::WAITING : 4),
      'ignore_private_followups'    => 0,


      // ActualTime integration (optional)
      'actualtime_enabled'                => 0,
      'actualtime_status_running'         => (defined('CommonITILObject::ASSIGNED') ? CommonITILObject::ASSIGNED : 2),
      'actualtime_status_stopped'         => (defined('CommonITILObject::WAITING') ? CommonITILObject::WAITING : 4),
      'actualtime_stop_only_if_no_timer'  => 1,
      'actualtime_allowed_statuses_start' => '',
      'actualtime_allowed_statuses_stop'  => '',

      'followup_split_by_author'    => 1,
      'onfollowup_status_requester' => (defined('CommonITILObject::ASSIGNED') ? CommonITILObject::ASSIGNED : 2),
      'onfollowup_status_other'     => (defined('CommonITILObject::WAITING') ? CommonITILObject::WAITING : 4),
   ];

   return array_merge($defaults, is_array($conf) ? $conf : []);
}

/**
 * Build allowed statuses array for tickets.
 */
function plugin_autostatus_get_ticket_statuses(): array {
   // Ticket::getAllStatusArray() is provided by GLPI and returns [status_id => label]
   $statuses = [];
   if (class_exists('Ticket') && method_exists('Ticket', 'getAllStatusArray')) {
      $statuses = Ticket::getAllStatusArray();
   }
   if (!is_array($statuses) || empty($statuses)) {
      // Fallback: minimal map (ids should match GLPI defaults)
      $statuses = [
         1 => __('New'),
         2 => __('Processing (assigned)'),
         3 => __('Processing (planned)'),
         4 => __('Pending'),
         5 => __('Solved'),
         6 => __('Closed'),
      ];
   }
   return $statuses;
}


/**
 * Parse CSV list of allowed current statuses.
 * Empty string means "allow any status".
 */
function plugin_autostatus_parse_csv_statuses(string $csv): array {
   $csv = trim($csv);
   if ($csv === '') {
      return [];
   }
   $parts = array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== '');
   $out = [];
   foreach ($parts as $p) {
      $out[] = (int)$p;
   }
   return array_values(array_unique($out));
}

/**
 * Check if current status passes filter.
 * If filter list is empty -> allow any.
 */
function plugin_autostatus_current_status_allowed(int $current, string $csv_filter): bool {
   $allowed = plugin_autostatus_parse_csv_statuses($csv_filter);
   if (empty($allowed)) {
      return true;
   }
   return in_array($current, $allowed, true);
}

/**
 * Determine if a given user should be considered "technician".
 *
 * Practical rule for GLPI 10.x:
 * - if user has at least one profile with interface = 'central' (technician interface),
 *   we consider them technician.
 *
 * For multi-entity environments, we keep it permissive:
 * - entities_id = ticket entity OR entities_id = 0 (root) OR is_recursive = 1
 */
function plugin_autostatus_is_technician_user(int $users_id, int $tickets_id = 0): bool {
   global $DB;

   if ($users_id <= 0) {
      return false;
   }

   // Optional: restrict check to profiles that apply to the ticket entity
   $entity_clause = "";
   if ($tickets_id > 0) {
      $ticket = new Ticket();
      if ($ticket->getFromDB($tickets_id)) {
         $entities_id = (int)($ticket->fields['entities_id'] ?? 0);
         $entity_clause = " AND (pu.entities_id = {$entities_id} OR pu.entities_id = 0 OR pu.is_recursive = 1)";
      }
   }

   // GLPI ticket right "Beeing in charge" / "Se tornar encarregado"
   // Ticket::OWN is the bit used for this sub-right.
   $own_right = (defined('Ticket::OWN') ? (int)Ticket::OWN : 32768);

   $query = "SELECT 1
      FROM `glpi_profiles_users` pu
      INNER JOIN `glpi_profilerights` pr ON (pr.profiles_id = pu.profiles_id)
      WHERE pu.users_id = {$users_id}
        AND pr.name = 'ticket'
        AND (pr.rights & {$own_right}) <> 0
        {$entity_clause}
      LIMIT 1";

   $res = $DB->query($query);
   if ($res && $DB->numrows($res) > 0) {
      return true;
   }
   return false;
}




/**
 * Apply status change on a ticket.
 */
function plugin_autostatus_apply_ticket_status(int $tickets_id, int $target_status, string $event = ''): void {
   if ($tickets_id <= 0) {
      return;
   }
   if ($target_status <= 0) { // 0 = do not change
      return;
   }

   $conf = plugin_autostatus_get_config();
   $ticket = new Ticket();
   if (!$ticket->getFromDB($tickets_id)) {
      return;
   }

   // Do not change if solved/closed (optional)
   if (!empty($conf['ignore_solved_closed'])) {
      $solved = defined('CommonITILObject::SOLVED') ? CommonITILObject::SOLVED : 5;
      $closed = defined('CommonITILObject::CLOSED') ? CommonITILObject::CLOSED : 6;
      if (in_array((int)$ticket->fields['status'], [$solved, $closed], true)) {
         return;
      }
   }


   // Apply only if current status is allowed for this event (optional filter)
   $current = (int)$ticket->fields['status'];
   if ($event === 'oncreate' && !plugin_autostatus_current_status_allowed($current, (string)$conf['oncreate_allowed_statuses'])) {
      return;
   }
   if ($event === 'ontask' && !plugin_autostatus_current_status_allowed($current, (string)$conf['ontask_allowed_statuses'])) {
      return;
   }
   if ($event === 'onfollowup' && !plugin_autostatus_current_status_allowed($current, (string)$conf['onfollowup_allowed_statuses'])) {
      return;
   }

   if ($event === 'actualtime_start' && !plugin_autostatus_current_status_allowed($current, (string)$conf['actualtime_allowed_statuses_start'])) {
      return;
   }
   if ($event === 'actualtime_stop' && !plugin_autostatus_current_status_allowed($current, (string)$conf['actualtime_allowed_statuses_stop'])) {
      return;
   }

   // Validate status
   $allowed = plugin_autostatus_get_ticket_statuses();
   if (!isset($allowed[$target_status])) {
      return;
   }

   // Avoid useless update
   if ((int)$ticket->fields['status'] === $target_status) {
      return;
   }

   // Update ticket status
   $input = [
      'id'     => $tickets_id,
      'status' => $target_status,

      // Do not send notifications for this automatic change? Uncomment if needed:
      // '_disablenotif' => true,

      // Add a private followup comment about the automatic change? (off by default)
      // '_add_validation' => 0,
   ];
   $ticket->update($input);
}

/**
 * Hook called when a ticket is created.
 */
function plugin_autostatus_item_add_ticket(Ticket $item): void {
   $conf = plugin_autostatus_get_config();
   if (empty($conf['oncreate_enabled'])) {
      return;
   }
   $target = (int)($conf['oncreate_status'] ?? 0);
   plugin_autostatus_apply_ticket_status((int)$item->fields['id'], $target, 'oncreate');
}

/**
 * Hook called when a ticket task is added.
 */
function plugin_autostatus_item_add_task(TicketTask $item): void {
   $conf = plugin_autostatus_get_config();
   if (empty($conf['ontask_enabled'])) {
      return;
   }

   // Optionally ignore private tasks
   if (!empty($conf['ignore_private_tasks'])) {
      $is_private = (int)($item->fields['is_private'] ?? 0);
      if ($is_private === 1) {
         return;
      }
   }

   $tickets_id = (int)($item->fields['tickets_id'] ?? 0);
   $target = (int)($conf['ontask_status'] ?? 0);
   plugin_autostatus_apply_ticket_status($tickets_id, $target, 'ontask');
}

/**
 * Hook called when a followup is added.
 */
function plugin_autostatus_item_add_followup(ITILFollowup $item): void {
   $conf = plugin_autostatus_get_config();
   if (empty($conf['onfollowup_enabled'])) {
      return;
   }

   // Optionally ignore private followups
   if (!empty($conf['ignore_private_followups'])) {
      $is_private = (int)($item->fields['is_private'] ?? 0);
      if ($is_private === 1) {
         return;
      }
   }

   // Followups can be linked to multiple ITIL items; we only handle Ticket
   $itemtype = (string)($item->fields['itemtype'] ?? '');
   if ($itemtype !== 'Ticket') {
      return;
   }

   $tickets_id = (int)($item->fields['items_id'] ?? 0);
   if ($tickets_id <= 0) {
      return;
   }

   /**
    * Split logic:
    * If enabled, choose status based on "technician or not" (profile interface = central),
    * independent of being requester/observer/etc.
    *
    * Backward compatible keys:
    * - followup_split_by_author (kept name, now means "split by technician")
    * - onfollowup_status_other     -> status when TECHNICIAN answers
    * - onfollowup_status_requester -> status when NON-TECH answers
    */
   $target = 0;
   if (!empty($conf['followup_split_by_author'])) {
      $author_id = (int)($item->fields['users_id'] ?? 0);

      if (plugin_autostatus_is_technician_user($author_id, $tickets_id)) {
         $target = (int)($conf['onfollowup_status_other'] ?? 0);
      } else {
         $target = (int)($conf['onfollowup_status_requester'] ?? 0);
      }
   } else {
      // Simple mode: always use this status on followup
      $target = (int)($conf['onfollowup_status'] ?? 0);
   }

   plugin_autostatus_apply_ticket_status($tickets_id, $target, 'onfollowup');
}


/**
 * =========================
 * ActualTime integration
 * =========================
 *
 * ActualTime stores timer sessions in table `glpi_plugin_actualtime_tasks`, commonly:
 *   id (PK), itemtype, items_id, actual_begin, actual_end, users_id, actual_actiontime
 *
 * We react to:
 * - item_add on PluginActualtimeTask: timer started (row created with actual_end NULL)
 * - item_update on PluginActualtimeTask: timer stopped (actual_end becomes NOT NULL)
 *
 * Reference: common schema seen in the field. (See e.g. CREATE TABLE sample in community discussions.)
 */

function plugin_autostatus_actualtime_is_available(): bool {
   // Must be enabled in our plugin config
   $conf = plugin_autostatus_get_config();
   if (empty($conf['actualtime_enabled'])) {
      return false;
   }

   // Must have plugin ActualTime active
   if (!class_exists('Plugin') || !Plugin::isPluginActive('actualtime')) {
      return false;
   }

   // Must have DB table (avoid SQL errors if plugin is missing/mis-installed)
   global $DB;
   if (isset($DB) && method_exists($DB, 'tableExists')) {
      if (!$DB->tableExists('glpi_plugin_actualtime_tasks')) {
         return false;
      }
   }

   return true;
}

/**
 * Convert a TicketTask id (glpi_tickettasks.id) into tickets_id.
 */
function plugin_autostatus_get_ticket_id_from_tickettask(int $tasks_id): int {
   if ($tasks_id <= 0) {
      return 0;
   }

   $task = new TicketTask();
   if (!$task->getFromDB($tasks_id)) {
      return 0;
   }
   return (int)($task->fields['tickets_id'] ?? 0);
}

/**
 * Check if there is at least one running ActualTime timer on a ticket (any task, any user).
 * Running timer = row where actual_end IS NULL.
 */
function plugin_autostatus_ticket_has_running_timer(int $tickets_id): bool {
   if ($tickets_id <= 0) {
      return false;
   }

   global $DB;

   // If table doesn't exist, assume no running timers (and avoid errors)
   if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_plugin_actualtime_tasks')) {
      return false;
   }

   $tickets_id = (int)$tickets_id;

   $query = "SELECT COUNT(*) AS c
      FROM `glpi_plugin_actualtime_tasks` at
      INNER JOIN `glpi_tickettasks` tt ON (tt.id = at.items_id)
      WHERE at.itemtype = 'TicketTask'
        AND tt.tickets_id = {$tickets_id}
        AND at.actual_end IS NULL";

   $res = $DB->query($query);
   if (!$res) {
      return false;
   }
   $row = $DB->fetchAssoc($res);
   $c = (int)($row['c'] ?? 0);
   return ($c > 0);
}

/**
 * Hook: called when ActualTime creates a timer entry (usually means START).
 * $item is expected to be instance of PluginActualtimeTask (but we keep it untyped).
 */
function plugin_autostatus_item_add_actualtime_task($item): void {
   if (!plugin_autostatus_actualtime_is_available()) {
      return;
   }
   Toolbox::logInFile(
      'autostatus.log',
      "AT start hook: itemtype=" . ($item->fields['itemtype'] ?? '') .
      " items_id=" . ($item->fields['items_id'] ?? '') .
      " begin=" . ($item->fields['actual_begin'] ?? '') .
      " end=" . ($item->fields['actual_end'] ?? '') . "\n"
   );

   // Expected columns: itemtype, items_id, actual_begin, actual_end
   $itemtype = (string)($item->fields['itemtype'] ?? '');
   if ($itemtype !== 'TicketTask') {
      return;
   }
   $tasks_id = (int)($item->fields['items_id'] ?? 0);
   if ($tasks_id <= 0) {
      return;
   }

   $actual_begin = (string)($item->fields['actual_begin'] ?? '');
   $actual_end   = (string)($item->fields['actual_end'] ?? '');

   // Treat as start if has begin and no end
   if ($actual_begin === '' || $actual_end !== '') {
      return;
   }

   $tickets_id = plugin_autostatus_get_ticket_id_from_tickettask($tasks_id);
   if ($tickets_id <= 0) {
      return;
   }

   $conf = plugin_autostatus_get_config();
   $target = (int)($conf['actualtime_status_running'] ?? 0);
   plugin_autostatus_apply_ticket_status($tickets_id, $target, 'actualtime_start');
}

/**
 * Hook: called when ActualTime updates a timer entry.
 * We detect STOP when actual_end becomes NOT NULL.
 *
 * Note: ActualTime can update actual_actiontime frequently; we intentionally ignore that.
 */
function plugin_autostatus_item_update_actualtime_task($item): void {
   if (!plugin_autostatus_actualtime_is_available()) {
      return;
   }
   Toolbox::logInFile(
      'autostatus.log',
      "AT update hook: itemtype=" . ($item->fields['itemtype'] ?? '') .
      " items_id=" . ($item->fields['items_id'] ?? '') .
      " updates=" . json_encode($item->updates ?? null) . "\n"
   );

   $itemtype = (string)($item->fields['itemtype'] ?? '');
   if ($itemtype !== 'TicketTask') {
      return;
   }
   $tasks_id = (int)($item->fields['items_id'] ?? 0);
   if ($tasks_id <= 0) {
      return;
   }

   $tickets_id = plugin_autostatus_get_ticket_id_from_tickettask($tasks_id);
   if ($tickets_id <= 0) {
      return;
   }

   // Detect changed fields (CommonDBTM provides $updates after update)
   $updates = $item->updates ?? null;
   if (!is_array($updates)) {
      return;
   }

   // STOP: actual_end changed and is now set
   if (array_key_exists('actual_end', $updates)) {
      $actual_end = (string)($item->fields['actual_end'] ?? '');

      // If it became empty, consider it a start/restart (rare)
      if ($actual_end === '') {
         $conf = plugin_autostatus_get_config();
         $target = (int)($conf['actualtime_status_running'] ?? 0);
         plugin_autostatus_apply_ticket_status($tickets_id, $target, 'actualtime_start');
         return;
      }

      $conf = plugin_autostatus_get_config();

      // Optional safety: don't move to "pending" if another timer is still running on the ticket
      if (!empty($conf['actualtime_stop_only_if_no_timer'])) {
         if (plugin_autostatus_ticket_has_running_timer($tickets_id)) {
            return;
         }
      }

      $target = (int)($conf['actualtime_status_stopped'] ?? 0);
      plugin_autostatus_apply_ticket_status($tickets_id, $target, 'actualtime_stop');
      return;
   }

   // START (rare): actual_begin changed and actual_end still empty
   if (array_key_exists('actual_begin', $updates)) {
      $actual_begin = (string)($item->fields['actual_begin'] ?? '');
      $actual_end   = (string)($item->fields['actual_end'] ?? '');
      if ($actual_begin !== '' && $actual_end === '') {
         $conf = plugin_autostatus_get_config();
         $target = (int)($conf['actualtime_status_running'] ?? 0);
         plugin_autostatus_apply_ticket_status($tickets_id, $target, 'actualtime_start');
      }
   }
}
