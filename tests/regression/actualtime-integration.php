<?php
/**
 * AutoStatus owns its integration with the optional ActualTime plugin.
 * ACTUALTIME_PLUGIN_DIR=../actualtime php tests/regression/actualtime-integration.php
 */
$actualtime = getenv('ACTUALTIME_PLUGIN_DIR');
if (!$actualtime) { throw new RuntimeException('Set ACTUALTIME_PLUGIN_DIR to the ActualTime source directory'); }
define('ACTUALTIME_FIXTURE_ONLY', true);
require rtrim($actualtime, '/') . '/tests/regression/manualtimer.php';
class ITILFollowup extends CommonDBTM {}
function eventFixture(): void {
    resetFixture();
    Session::$user = 42;
    CommonDBTM::$records[PluginActualtimeConfig::class][1] += [
        'showtimerpopup' => 1, 'showtimerinbox' => 1, 'autoopenrunning' => 0, 'displayinfofor' => 0,
    ];
}
function nativeAction(string $action): array {
    $method = $action . 'Timer';
    return PluginActualtimeTask::$method(10, TicketTask::class, PluginActualtimeTask::WEB);
}
require __DIR__ . '/../../hook.php';
require __DIR__ . '/../../setup.php';
class Config {
    public static $values = [];
    public static function getConfigurationValues($context) { return self::$values; }
    public static function setConfigurationValues($context, $values) { self::$values = array_merge(self::$values, $values); }
}
class AutoStatusMemoryDB extends MemoryDB {
    public function request($query) {
        if (!isset($query['INNER JOIN']['glpi_tickettasks'])) { return parent::request($query); }
        check($query['INNER JOIN']['glpi_tickettasks']['ON'] === ['glpi_plugin_actualtime_tasks' => 'items_id', 'glpi_tickettasks' => 'id'], 'Timer query joins the task foreign key');
        $matches = static function ($row, $conditions) use (&$matches) {
            foreach ($conditions as $field => $value) {
                if ($field === 'NOT') { if ($matches($row, $value)) { return false; } }
                elseif (($row[$field] ?? null) !== $value) { return false; }
            }
            return true;
        };
        $rows = [];
        foreach ($this->timers as $timer) {
            $task = CommonDBTM::$records[TicketTask::class][$timer['items_id']] ?? null;
            if ($task === null) { continue; }
            $row = [];
            foreach ($timer as $key => $value) { $row['glpi_plugin_actualtime_tasks.' . $key] = $value; }
            foreach ($task as $key => $value) { $row['glpi_tickettasks.' . $key] = $value; }
            if ($matches($row, $query['WHERE'])) { $rows[] = $row; }
        }
        return new ArrayIterator(array_slice($rows, 0, $query['LIMIT'] ?? count($rows)));
    }
}
function integrationFixture(array $config = []): void {
    global $DB, $PLUGIN_HOOKS;
    eventFixture();
    $DB = new AutoStatusMemoryDB();
    Config::$values = $config + ['actualtime_enabled' => 1];
    CommonDBTM::$records[Ticket::class][1]['status'] = 4;
    CommonDBTM::$records[Ticket::class][2] = ['id' => 2, 'status' => 4, 'is_deleted' => 0];
    CommonDBTM::$records[TicketTask::class][11] = CommonDBTM::$records[TicketTask::class][10];
    CommonDBTM::$records[TicketTask::class][11]['id'] = 11;
    CommonDBTM::$records[TicketTask::class][12] = CommonDBTM::$records[TicketTask::class][10];
    CommonDBTM::$records[TicketTask::class][12]['id'] = 12;
    CommonDBTM::$records[TicketTask::class][12]['tickets_id'] = 2;
    plugin_init_autostatus();
    Plugin::$listener = static function ($name, $data) use (&$PLUGIN_HOOKS) {
        foreach ($PLUGIN_HOOKS[$name] ?? [] as $callback) { $callback($data); }
    };
}
function ticketStatus(int $id = 1): int { return CommonDBTM::$records[Ticket::class][$id]['status']; }

integrationFixture();
check(nativeAction('start')['type'] === 'info' && ticketStatus() === 2, 'Real ActualTime start changes the real AutoStatus ticket status');
PluginActualtimeTask::startTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
nativeAction('pause');
check(ticketStatus() === 2, 'Pausing one of two tasks on the same ticket keeps it in progress');
PluginActualtimeTask::pauseTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
check(ticketStatus() === 4, 'Pausing the last task sets the ticket pending');
nativeAction('start');
check(ticketStatus() === 2, 'Resume restores in progress');
PluginActualtimeTask::startTimer(12, TicketTask::class, PluginActualtimeTask::WEB);
PluginActualtimeTask::startTimer(10, ProjectTask::class, PluginActualtimeTask::WEB);
nativeAction('stop');
check(ticketStatus() === 4 && ticketStatus(2) === 2, 'Another ticket and a same-ID project timer do not block the selected ticket pause status');
check(CommonDBTM::$records[TicketTask::class][10]['state'] === 2 && PluginActualtimeTask::checkTimerActive(12, TicketTask::class), 'Native completion and the other active timer are preserved');

integrationFixture();
nativeAction('start');
CommonDBTM::$records[TicketTask::class][11]['users_id_tech'] = 7;
Session::$user = 7;
PluginActualtimeTask::startTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
Session::$user = 42;
nativeAction('pause');
check(ticketStatus() === 2, 'Another technician on this ticket keeps it in progress');
Session::$user = 7;
PluginActualtimeTask::stopTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
check(ticketStatus() === 4, 'Last technician completion applies pending');

foreach ([5, 6] as $status) {
    integrationFixture();
    nativeAction('start');
    CommonDBTM::$records[Ticket::class][1]['status'] = $status;
    nativeAction('pause');
    check(ticketStatus() === $status, 'Closing a timer does not reopen solved/closed ticket ' . $status);
}
integrationFixture(['actualtime_enabled' => 0]);
nativeAction('start');
check(ticketStatus() === 4, 'Disabled integration leaves ticket status alone');
integrationFixture(['actualtime_allowed_statuses_start' => '1']);
nativeAction('start');
check(ticketStatus() === 4, 'Start filter is independent of task creation');
integrationFixture(['actualtime_status_running' => 3, 'actualtime_status_stopped' => 1]);
nativeAction('start');
check(ticketStatus() === 3, 'Custom running status applied');
nativeAction('pause');
check(ticketStatus() === 1, 'Custom stopped status applied');
integrationFixture();
$DB->failCommit = true;
nativeAction('start');
check(ticketStatus() === 4 && $DB->timers === [], 'Failed commit changes neither timers nor ticket status');
echo "OK: real ActualTime + AutoStatus integration (GLPI adapters, no live database).\n";

foreach ([2, 4] as $status) {
    integrationFixture(['followup_split_by_author' => 0, 'onfollowup_status' => $status]);
    CommonDBTM::$records[TicketTask::class] = [];
    $followup = new ITILFollowup();
    $followup->fields = ['itemtype' => 'Ticket', 'items_id' => 1, 'is_private' => 0, 'users_id' => 42];
    plugin_autostatus_item_add_followup($followup);
    check(plugin_autostatus_get_ticket_state(1)['status'] === $status, 'AutoStatus reads its followup result without tasks: ' . $status);
}
integrationFixture();
$result = nativeAction('start');
check(!isset($result['runtime']['parent_status']), 'ActualTime runtime contains no ticket-status presentation');
check(plugin_autostatus_get_ticket_state(1)['status'] === 2, 'Ticket status presentation is provided by AutoStatus');
echo "OK: $checks AutoStatus integration checks (real plugin classes, GLPI adapters).\n";
