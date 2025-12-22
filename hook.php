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
 * Internal timer (AutoStatus)
 * =========================
 */

function plugin_autostatus_timer_table(): string {
   return 'glpi_plugin_autostatus_timers';
}

function plugin_autostatus_timer_ensure_table(): void {
   global $DB;

   $table = plugin_autostatus_timer_table();
   if ($DB->tableExists($table)) {
      return;
   }

   $default_charset = DBConnection::getDefaultCharset();
   $default_collation = DBConnection::getDefaultCollation();
   $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

   $query = "CREATE TABLE IF NOT EXISTS {$table} (
      `id` int {$default_key_sign} NOT NULL auto_increment,
      `itemtype` varchar(255) NOT NULL,
      `items_id` int {$default_key_sign} NOT NULL DEFAULT '0',
      `actual_begin` TIMESTAMP NULL DEFAULT NULL,
      `actual_end` TIMESTAMP NULL DEFAULT NULL,
      `users_id` int {$default_key_sign} NOT NULL,
      `actual_actiontime` int {$default_key_sign} NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `item` (`itemtype`, `items_id`),
      KEY `users_id` (`users_id`)
   ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset}
   COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
   $DB->doQueryOrDie($query, $DB->error());
}

function plugin_autostatus_timer_is_enabled(): bool {
   $conf = plugin_autostatus_get_config();
   return !empty($conf['actualtime_enabled']);
}

function plugin_autostatus_timer_get_state(int $tasks_id, string $itemtype): array {
   global $DB;

   $state = [
      'running' => false,
      'running_user_id' => 0,
      'start_ts' => 0,
      'total_completed' => 0,
      'total' => 0,
   ];

   if ($tasks_id <= 0 || $itemtype === '') {
      return $state;
   }

   $table = plugin_autostatus_timer_table();

   $completed = 0;
   $req = $DB->request([
      'SELECT' => ['actual_actiontime'],
      'FROM'   => $table,
      'WHERE'  => [
         'items_id' => $tasks_id,
         'itemtype' => $itemtype,
         [
            'NOT' => ['actual_end' => null],
         ],
      ],
   ]);
   foreach ($req as $row) {
      $completed += (int)($row['actual_actiontime'] ?? 0);
   }

   $running_req = $DB->request([
      'SELECT' => ['actual_begin', 'users_id'],
      'FROM'   => $table,
      'WHERE'  => [
         'items_id' => $tasks_id,
         'itemtype' => $itemtype,
         'actual_end' => null,
      ],
      'LIMIT'  => 1,
   ]);
   if ($row = $running_req->current()) {
      $state['running'] = true;
      $state['running_user_id'] = (int)($row['users_id'] ?? 0);
      $state['start_ts'] = (int)strtotime((string)($row['actual_begin'] ?? ''));
   }

   $state['total_completed'] = $completed;
   $state['total'] = $completed;
   if ($state['running'] && $state['start_ts'] > 0) {
      $state['total'] += max(0, time() - $state['start_ts']);
   }

   return $state;
}

function plugin_autostatus_timer_ticket_has_running_timer(int $tickets_id): bool {
   if ($tickets_id <= 0) {
      return false;
   }

   global $DB;
   $table = plugin_autostatus_timer_table();
   $tickets_id = (int)$tickets_id;

   $query = "SELECT COUNT(*) AS c
      FROM `{$table}` t
      INNER JOIN `glpi_tickettasks` tt ON (tt.id = t.items_id)
      WHERE t.itemtype = 'TicketTask'
        AND tt.tickets_id = {$tickets_id}
        AND t.actual_end IS NULL";

   $res = $DB->query($query);
   if (!$res) {
      return false;
   }
   $row = $DB->fetchAssoc($res);
   $c = (int)($row['c'] ?? 0);
   return ($c > 0);
}

function plugin_autostatus_timer_start(int $tasks_id, string $itemtype): array {
   if (!plugin_autostatus_timer_is_enabled()) {
      return ['ok' => false, 'message' => __('Timer disabled', 'autostatus')];
   }

   if ($itemtype !== 'TicketTask' && $itemtype !== TicketTask::class) {
      return ['ok' => false, 'message' => __('Unsupported itemtype', 'autostatus')];
   }

   $tasks_id = (int)$tasks_id;
   if ($tasks_id <= 0) {
      return ['ok' => false, 'message' => __('Invalid task', 'autostatus')];
   }

   $task = new TicketTask();
   if (!$task->getFromDB($tasks_id)) {
      return ['ok' => false, 'message' => __('Task not found', 'autostatus')];
   }

   if (isset($task->fields['users_id_tech'])) {
      if ((int)$task->fields['users_id_tech'] !== Session::getLoginUserID()) {
         return ['ok' => false, 'message' => __('Technician not in charge of the task', 'autostatus')];
      }
   }

   if (!$task->can($tasks_id, UPDATE)) {
      return ['ok' => false, 'message' => __('No permission to update task', 'autostatus')];
   }

   $state = plugin_autostatus_timer_get_state($tasks_id, 'TicketTask');
   if (!empty($state['running'])) {
      return ['ok' => false, 'message' => __('Timer already running', 'autostatus'), 'state' => $state];
   }

   global $DB;
   $table = plugin_autostatus_timer_table();
   $DB->insert($table, [
      'items_id'     => $tasks_id,
      'itemtype'     => 'TicketTask',
      'actual_begin' => date('Y-m-d H:i:s'),
      'users_id'     => Session::getLoginUserID(),
      'actual_end'   => null,
      'actual_actiontime' => 0,
   ]);

   $tickets_id = plugin_autostatus_get_ticket_id_from_tickettask($tasks_id);
   if ($tickets_id > 0) {
      $conf = plugin_autostatus_get_config();
      $target = (int)($conf['actualtime_status_running'] ?? 0);
      plugin_autostatus_apply_ticket_status($tickets_id, $target, 'actualtime_start');
   }

   $state = plugin_autostatus_timer_get_state($tasks_id, 'TicketTask');
   return ['ok' => true, 'message' => __('Timer started', 'autostatus'), 'state' => $state];
}

function plugin_autostatus_timer_stop(int $tasks_id, string $itemtype): array {
   if (!plugin_autostatus_timer_is_enabled()) {
      return ['ok' => false, 'message' => __('Timer disabled', 'autostatus')];
   }

   if ($itemtype !== 'TicketTask' && $itemtype !== TicketTask::class) {
      return ['ok' => false, 'message' => __('Unsupported itemtype', 'autostatus')];
   }

   $tasks_id = (int)$tasks_id;
   if ($tasks_id <= 0) {
      return ['ok' => false, 'message' => __('Invalid task', 'autostatus')];
   }

   global $DB;
   $table = plugin_autostatus_timer_table();
   $running_req = $DB->request([
      'SELECT' => ['id', 'actual_begin', 'users_id'],
      'FROM'   => $table,
      'WHERE'  => [
         'items_id' => $tasks_id,
         'itemtype' => 'TicketTask',
         'actual_end' => null,
      ],
      'LIMIT'  => 1,
   ]);

   $running_row = $running_req->current();
   if (!$running_row) {
      return ['ok' => false, 'message' => __('No running timer', 'autostatus')];
   }

   if ((int)($running_row['users_id'] ?? 0) !== Session::getLoginUserID()) {
      return ['ok' => false, 'message' => __('Only the user who started can stop the timer', 'autostatus')];
   }

   $begin = (string)($running_row['actual_begin'] ?? '');
   $seconds = 0;
   if ($begin !== '') {
      $seconds = max(0, (strtotime(date('Y-m-d H:i:s')) - strtotime($begin)));
   }

   $DB->update($table, [
      'actual_end'        => date('Y-m-d H:i:s'),
      'actual_actiontime' => $seconds,
   ], [
      'id' => (int)$running_row['id'],
   ]);

   $tickets_id = plugin_autostatus_get_ticket_id_from_tickettask($tasks_id);
   if ($tickets_id > 0) {
      $conf = plugin_autostatus_get_config();
      if (!empty($conf['actualtime_stop_only_if_no_timer'])) {
         if (!plugin_autostatus_timer_ticket_has_running_timer($tickets_id)) {
            $target = (int)($conf['actualtime_status_stopped'] ?? 0);
            plugin_autostatus_apply_ticket_status($tickets_id, $target, 'actualtime_stop');
         }
      } else {
         $target = (int)($conf['actualtime_status_stopped'] ?? 0);
         plugin_autostatus_apply_ticket_status($tickets_id, $target, 'actualtime_stop');
      }
   }

   $state = plugin_autostatus_timer_get_state($tasks_id, 'TicketTask');
   return ['ok' => true, 'message' => __('Timer stopped', 'autostatus'), 'state' => $state];
}

function plugin_autostatus_timer_post_form($params): void {
   if (!plugin_autostatus_timer_is_enabled()) {
      return;
   }

   $item = $params['item'] ?? null;
   if (!is_object($item) || !method_exists($item, 'getType')) {
      return;
   }

   $itemtype = $item->getType();
   if ($itemtype !== TicketTask::class && $itemtype !== 'TicketTask') {
      return;
   }

   $task_id = (int)$item->getID();
   if ($task_id <= 0) {
      return;
   }

   if (isset($item->fields['users_id_tech']) && (int)$item->fields['users_id_tech'] !== Session::getLoginUserID()) {
      return;
   }

   if (!$item->can($task_id, UPDATE)) {
      return;
   }

   global $CFG_GLPI;
   $state = plugin_autostatus_timer_get_state($task_id, 'TicketTask');
   $token = Session::getNewCSRFToken();
   $url = $CFG_GLPI['root_doc'] . '/plugins/autostatus/front/timer.php';

   $running = $state['running'] ? '1' : '0';
   $start_ts = (int)$state['start_ts'];
   $total_completed = (int)$state['total_completed'];

   echo "<div class='autostatus-timer-box' data-autostatus-timer='1'"
      . " data-url='" . Html::clean($url) . "'"
      . " data-task-id='{$task_id}'"
      . " data-itemtype='TicketTask'"
      . " data-running='{$running}'"
      . " data-start-ts='{$start_ts}'"
      . " data-total='{$total_completed}'"
      . " data-token='{$token}'>";

   echo "<div class='b'>" . __('Timer', 'autostatus') . "</div>";
   echo "<div class='autostatus-timer-display'>00:00:00</div>";
   echo "<div class='autostatus-timer-actions' style='margin-top:6px'>";
   echo "<button type='button' class='btn btn-primary btn-sm autostatus-timer-start' data-action='start'>" . __('Start') . "</button> ";
   echo "<button type='button' class='btn btn-primary btn-sm autostatus-timer-stop' data-action='stop'>" . __('Stop') . "</button>";
   echo "</div>";
   echo "<div class='autostatus-timer-message' style='margin-top:6px;opacity:.8'></div>";
   echo "</div>";
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
 * ActualTime direct call (when ActualTime writes to DB without GLPI hooks).
 * Accepts a task id and itemtype (we only handle TicketTask).
 */
function plugin_autostatus_actualtime_notify_start(int $tasks_id, string $itemtype): void {
   if ($itemtype !== 'TicketTask') {
      return;
   }
   if (!plugin_autostatus_actualtime_is_available()) {
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

function plugin_autostatus_actualtime_notify_stop(int $tasks_id, string $itemtype): void {
   if ($itemtype !== 'TicketTask') {
      return;
   }
   if (!plugin_autostatus_actualtime_is_available()) {
      return;
   }
   $tickets_id = plugin_autostatus_get_ticket_id_from_tickettask($tasks_id);
   if ($tickets_id <= 0) {
      return;
   }
   $conf = plugin_autostatus_get_config();
   if (!empty($conf['actualtime_stop_only_if_no_timer'])) {
      if (plugin_autostatus_ticket_has_running_timer($tickets_id)) {
         return;
      }
   }
   $target = (int)($conf['actualtime_status_stopped'] ?? 0);
   plugin_autostatus_apply_ticket_status($tickets_id, $target, 'actualtime_stop');
}

/**
 * Hook: called when ActualTime creates a timer entry (usually means START).
 * $item is expected to be instance of PluginActualtimeTask (but we keep it untyped).
 */
function plugin_autostatus_item_add_actualtime_task($item): void {
   $log_path = GLPI_ROOT . '/plugins/autostatus/inc/autostatus.log';
   $available = plugin_autostatus_actualtime_is_available();
   $log_line = "AT start hook: itemtype=" . ($item->fields['itemtype'] ?? '') .
      " items_id=" . ($item->fields['items_id'] ?? '') .
      " begin=" . ($item->fields['actual_begin'] ?? '') .
      " end=" . ($item->fields['actual_end'] ?? '') .
      " available=" . ($available ? '1' : '0') . "\n";
   file_put_contents($log_path, $log_line, FILE_APPEND);
   if (!$available) {
      return;
   }

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
   $log_path = GLPI_ROOT . '/plugins/autostatus/inc/autostatus.log';
   $available = plugin_autostatus_actualtime_is_available();
   $log_line = "AT update hook: itemtype=" . ($item->fields['itemtype'] ?? '') .
      " items_id=" . ($item->fields['items_id'] ?? '') .
      " updates=" . json_encode($item->updates ?? null) .
      " available=" . ($available ? '1' : '0') . "\n";
   file_put_contents($log_path, $log_line, FILE_APPEND);
   if (!$available) {
      return;
   }

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
