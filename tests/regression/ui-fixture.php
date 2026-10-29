<?php
/** CLI-only adapter for browser regressions; no production database is loaded. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('AUTOSTATUS_FIXTURE_ONLY', true);
require __DIR__ . '/actualtime.php';
fixture(['actualtime_enabled' => 0, 'followup_split_by_author' => 0]);
Plugin::$active = false; // The UI fixture deliberately has no ActualTime plugin.
$mode = $argv[1] ?? 'status';
$input = json_decode($argv[2] ?? '{}', true);
if (!empty($argv[3])) {
   [Config::$values, Ticket::$rows, TicketTask::$rows] = json_decode(base64_decode($argv[3]), true);
}
switch ($mode) {
   case 'status':
      $response = ['ticket' => plugin_autostatus_get_ticket_state($input['tickets_id'] ?? 1)];
      break;
   case 'set_status':
      Ticket::$rows[(int)($input['ticket'] ?? 1)]['status'] = (int)$input['status'];
      $response = true;
      break;
   case 'followup':
      Config::$values['onfollowup_status'] = !empty($input['requester']) ? 2 : 4;
      $item = new ITILFollowup();
      $item->fields = ['itemtype' => 'Ticket', 'items_id' => (int)($input['items_id'] ?? 1), 'users_id' => 42];
      plugin_autostatus_item_add_followup($item);
      $response = ['ok' => true];
      break;
   case 'task':
      $item = new TicketTask();
      $item->fields = ['id' => 10, 'tickets_id' => 1];
      plugin_autostatus_item_add_task($item);
      $response = ['ok' => true];
      break;
   case 'toggle':
      // GLPI owns task state changes; AutoStatus reads the resulting saved status.
      if (isset($input['saved_status'])) { Ticket::$rows[1]['status'] = (int)$input['saved_status']; }
      $response = ['state' => (int)($input['state'] ?? 2), 'label' => 'Task state'];
      break;
   case 'page':
      $status = Ticket::$rows[1]['status'];
      $response = '<!doctype html><html><head><meta charset="utf-8"><script src="/assets/jquery.js"></script><script src="/assets/select2.js"></script></head><body>';
      $response .= '<form id="itil-form" action="/front/ticket.form.php?id=1" data-track-changes="true"><input type="hidden" name="id" value="1">';
      $response .= '<div id="heading-main-item"><span class="status-recall"><i class="itilstatus status-' . $status . '"></i></span></div>';
      $response .= '<div class="form-field"><label for="status_1">Status</label><div class="field-container">';
      if (!empty($input['readonly'])) {
         $response .= '<i class="itilstatus status-' . $status . '"></i>' . Ticket::getStatus($status) . '<a id="reopen-link" href="#reopen">Reopen</a>';
      } else {
         $response .= '<select id="dropdown_status_main" name="status">';
         foreach (Ticket::getAllStatusArray() as $id => $label) { $response .= '<option value="' . $id . '"' . ($id === $status ? ' selected' : '') . '>' . $label . '</option>'; }
         $response .= '</select>';
      }
      $response .= '</div></div><textarea id="ticket-draft" name="content"></textarea></form>';
      $response .= '<form id="other-form"><select name="status"><option value="9">Unrelated</option></select></form><button class="close-edit-content">Close editor</button>';
      $response .= '<script src="/assets/adapter.js"></script><script src="/assets/autostatus.js"></script></body></html>';
      break;
   default:
      throw new RuntimeException('Unknown fixture mode');
}
echo json_encode(['response' => $response, 'state' => base64_encode(json_encode([Config::$values, Ticket::$rows, TicketTask::$rows]))]);
