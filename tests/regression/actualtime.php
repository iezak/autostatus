<?php

/** Run: php tests/regression/actualtime.php (isolated GLPI adapters). */
define('GLPI_ROOT', dirname(__DIR__, 2));
define('READ', 1);
set_error_handler(static function ($severity, $message, $file, $line) {
   throw new ErrorException($message, 0, $severity, $file, $line);
});
function __($message) { return $message; }
class Config {
   public static $values = [];
   public static function getConfigurationValues($context) { return self::$values; }
   public static function setConfigurationValues($context, $values) { self::$values = array_merge(self::$values, $values); }
   public function deleteConfigurationValues($context, $keys) { self::$values = array_diff_key(self::$values, array_flip($keys)); }
}
class Plugin {
   public static $active = true;
   public static $ownerActive = true;
   public static function isPluginActive($name) { return $name === 'autostatus' ? self::$ownerActive : self::$active; }
}
class Session {
   public static $user = 42;
   public static function getLoginUserID() { return self::$user; }
}
class Ticket {
   public static $rows = [];
   public static $updates = [];
   public static $denied = [];
   public $fields = [];
   public function getFromDB($id) { $this->fields = self::$rows[$id] ?? []; return $this->fields !== []; }
   public function can($id, $right) { return $this->getFromDB($id) && !in_array($id, self::$denied, true); }
   public static function getAllStatusArray() { return [1 => 'New', 2 => 'Processing (assigned)', 3 => 'Processing (planned)', 4 => 'Pending', 5 => 'Solved', 6 => 'Closed']; }
   public static function getStatus($value) { return self::getAllStatusArray()[$value]; }
   public static function getStatusClass($value) { return 'itilstatus status-' . $value; }
   public static function getAllowedStatusArray($current) { return self::getAllStatusArray(); }
   public function update($input) {
      self::$rows[$input['id']] = array_merge($this->fields, $input);
      self::$updates[] = $input;
      return true;
   }
}
class TicketTask {
   public static $rows = [];
   public $fields = [];
   public function getFromDB($id) { $this->fields = self::$rows[$id] ?? []; return $this->fields !== []; }
}
class ITILFollowup { public $fields = []; }
class TimerDB {
   public $timers = [];
   public $table = true;
   public function tableExists($name) { return $this->table; }
   public function request($query) {
      if ($query['FROM'] !== 'glpi_plugin_actualtime_tasks'
          || $query['INNER JOIN']['glpi_tickettasks']['ON'] !== ['glpi_plugin_actualtime_tasks' => 'items_id', 'glpi_tickettasks' => 'id']) {
         throw new RuntimeException('Unexpected join');
      }
      $matches = static function ($row, $conditions) use (&$matches) {
         foreach ($conditions as $field => $value) {
            if ($field === 'NOT') { if ($matches($row, $value)) { return false; } }
            elseif (($row[$field] ?? null) !== $value) { return false; }
         }
         return true;
      };
      $rows = [];
      foreach ($this->timers as $timer) {
         $task = TicketTask::$rows[$timer['items_id']] ?? null;
         if ($task === null) { continue; }
         $row = [];
         foreach ($timer as $key => $value) { $row['glpi_plugin_actualtime_tasks.' . $key] = $value; }
         foreach ($task as $key => $value) { $row['glpi_tickettasks.' . $key] = $value; }
         if ($matches($row, $query['WHERE'])) { $rows[] = $row; }
      }
      return new ArrayIterator(array_slice($rows, 0, $query['LIMIT']));
   }
}
require GLPI_ROOT . '/hook.php';
require GLPI_ROOT . '/setup.php';
$checks = 0;
function check($value, $message): void {
   global $checks;
   ++$checks;
   if (!$value) { throw new RuntimeException('FAIL: ' . $message); }
}
function fixture(array $config = []): void {
   global $DB;
   $DB = new TimerDB();
   Plugin::$active = true;
   Plugin::$ownerActive = true;
   Session::$user = 42;
   Config::$values = $config + ['actualtime_enabled' => 1];
   Ticket::$rows = [1 => ['id' => 1, 'status' => 4], 2 => ['id' => 2, 'status' => 4]];
   Ticket::$updates = [];
   Ticket::$denied = [];
   TicketTask::$rows = [
      10 => ['id' => 10, 'tickets_id' => 1],
      11 => ['id' => 11, 'tickets_id' => 1],
      12 => ['id' => 12, 'tickets_id' => 2],
   ];
}
function timer(int $task, ?string $begin = '2026-01-10 08:00:00', ?string $end = null, string $type = 'TicketTask', int $user = 42): array {
   return ['id' => $task, 'items_id' => $task, 'itemtype' => $type, 'users_id' => $user, 'actual_begin' => $begin, 'actual_end' => $end];
}
function event(string $action, int $task = 10, string $type = 'TicketTask'): void {
   plugin_autostatus_actualtime_timer_state_changed(['action' => $action, 'items_id' => $task, 'itemtype' => $type]);
}
function status(int $id = 1): int { return Ticket::$rows[$id]['status']; }

if (defined('AUTOSTATUS_FIXTURE_ONLY')) { return; }

fixture();
plugin_init_autostatus();
check($PLUGIN_HOOKS['actualtime_timer_state_changed']['autostatus'] === 'plugin_autostatus_actualtime_timer_state_changed', 'Custom hook is registered');
$DB->timers = [timer(10), timer(11, user: 99)];
event('start');
check(status() === 2 && status(2) === 4, 'Start changes only the selected ticket');
event('start');
check(count(Ticket::$updates) === 1, 'Repeated event avoids a redundant ticket update');
$DB->timers[0]['actual_end'] = '2026-01-10 08:30:00';
event('pause');
check(status() === 2, 'Another task and technician keep ticket in progress');
$DB->timers[1]['actual_end'] = '2026-01-10 08:30:00';
event('stop', 11);
check(status() === 4, 'Last timer completion applies stopped status');

fixture();
Ticket::$rows[1]['status'] = 2;
$DB->timers = [timer(11, null), timer(12), timer(10, type: 'ProjectTask')];
event('pause');
check(status() === 4, 'Placeholder, other ticket and other itemtype are excluded');
fixture();
event('start');
check(status() === 4, 'An unsaved or stale start cannot set in progress');
foreach (['start', 'pause', 'stop', 'unknown'] as $action) {
   fixture(['actualtime_enabled' => 0]);
   $DB->timers = [timer(10)];
   event($action);
   check(Ticket::$updates === [], 'Disabled integration: ' . $action);
}
foreach (['inactive plugin', 'missing table', 'missing task', 'other type', 'deleted ticket'] as $case) {
   fixture();
   $DB->timers = [timer(10)];
   if ($case === 'inactive plugin') { Plugin::$active = false; }
   if ($case === 'missing table') { $DB->table = false; }
   if ($case === 'missing task') { unset(TicketTask::$rows[10]); }
   if ($case === 'deleted ticket') { Ticket::$rows[1]['is_deleted'] = 1; }
   event('start', 10, $case === 'other type' ? 'ChangeTask' : 'TicketTask');
   check(Ticket::$updates === [], 'Ignored event: ' . $case);
}
foreach ([5, 6] as $closed) {
   fixture();
   Ticket::$rows[1]['status'] = $closed;
   $DB->timers = [timer(10)];
   event('start');
   $DB->timers = [];
   event('stop');
   check(status() === $closed, 'Solved/closed status protected: ' . $closed);
}
fixture(['actualtime_allowed_statuses_start' => '1,3']);
$DB->timers = [timer(10)];
event('start');
check(status() === 4, 'Start status filter blocks disallowed current status');
Config::$values['actualtime_allowed_statuses_start'] = '4';
event('start');
check(status() === 2, 'Start filter accepts allowed current status');
Config::$values['actualtime_allowed_statuses_stop'] = '1,3';
$DB->timers = [];
event('pause');
check(status() === 2, 'Pause has its own status filter');
Config::$values['actualtime_allowed_statuses_stop'] = '2';
event('pause');
check(status() === 4, 'Pause filter accepts allowed current status');
foreach ([0, 999] as $target) {
   fixture(['actualtime_status_running' => $target, 'actualtime_status_stopped' => $target]);
   $DB->timers = [timer(10)];
   event('start');
   $DB->timers = [];
   event('pause');
   check(Ticket::$updates === [], 'Do not change or invalid target: ' . $target);
}
fixture(['ignore_private_tasks' => 1]);
TicketTask::$rows[10]['is_private'] = 1;
$DB->timers = [timer(10)];
event('start');
check(status() === 4, 'Private task event honors the configured filter');
Ticket::$rows[1]['status'] = 2;
event('pause', 11);
check(status() === 2, 'A private running task still counts in the ticket-wide guard');
fixture(['actualtime_stop_only_if_no_timer' => 0]);
Ticket::$rows[1]['status'] = 2;
$DB->timers = [timer(11)];
event('pause');
check(status() === 4, 'Administrator can explicitly disable the running-timer guard');

fixture(['actualtime_status_running' => 3, 'onfollowup_status_requester' => 3]);
plugin_autostatus_install();
check(Config::$values['actualtime_status_running'] === 3 && Config::$values['onfollowup_status_requester'] === 3, 'Upgrade preserves existing preferences, including the old integration keys');
check(Config::$values['actualtime_stop_only_if_no_timer'] === 1, 'Install initializes only missing settings');
plugin_autostatus_uninstall();
check(!isset(Config::$values['actualtime_enabled']), 'Uninstall removes integration configuration');
Config::$values = [];
check(plugin_autostatus_get_config()['actualtime_enabled'] === 0, 'New integration stays opt-in until enabled');
fixture(['actualtime_enabled' => 0]);
Plugin::$active = false;
TicketTask::$rows = [];
$state = plugin_autostatus_get_ticket_state(1);
check($state['status'] === 4 && $state['id'] === 1, 'UI status works without ActualTime, tasks or timers');
check($state['label'] === 'Pending' && isset($state['options'][4]), 'UI labels and transitions come from the saved ticket');
foreach ([0, -1, 'invalid', '1 OR 1=1', []] as $invalid) {
   try { plugin_autostatus_get_ticket_state($invalid); check(false, 'Invalid ID was accepted'); }
   catch (InvalidArgumentException $error) { check(true, 'Invalid ID rejected'); }
}
foreach (['denied', 'deleted', 'missing', 'logged out'] as $case) {
   fixture();
   if ($case === 'denied') { Ticket::$denied = [1]; }
   if ($case === 'deleted') { Ticket::$rows[1]['is_deleted'] = 1; }
   if ($case === 'missing') { unset(Ticket::$rows[1]); }
   if ($case === 'logged out') { Session::$user = 0; }
   try { plugin_autostatus_get_ticket_state(1); check(false, 'Inaccessible status exposed'); }
   catch (InvalidArgumentException | RuntimeException $error) { check(true, 'Status omitted: ' . $case); }
}
echo "OK: $checks AutoStatus checks (GLPI adapters, no live database).\n";
