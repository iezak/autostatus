<?php
/**
 * Internal timer actions (start/stop).
 */

include ("../../../inc/includes.php");

Session::checkLoginUser();

header('Content-Type: application/json; charset=utf-8');

$token = $_POST['_glpi_csrf_token'] ?? '';
if (!Session::validateCSRFToken($token)) {
   echo json_encode([
      'ok' => false,
      'message' => __('Invalid CSRF token', 'autostatus'),
   ]);
   exit;
}

include_once __DIR__ . '/../hook.php';

$action = (string)($_POST['action'] ?? '');
$tasks_id = (int)($_POST['items_id'] ?? 0);
$itemtype = (string)($_POST['itemtype'] ?? '');

$result = [
   'ok' => false,
   'message' => __('Invalid request', 'autostatus'),
];

if ($action === 'start') {
   $result = plugin_autostatus_timer_start($tasks_id, $itemtype);
} else if ($action === 'stop') {
   $result = plugin_autostatus_timer_stop($tasks_id, $itemtype);
}

if (!isset($result['state'])) {
   $result['state'] = plugin_autostatus_timer_get_state($tasks_id, $itemtype);
}

echo json_encode($result);
