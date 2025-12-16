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
