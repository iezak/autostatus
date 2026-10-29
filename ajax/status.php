<?php
/** Read-only ticket status for the AutoStatus UI. */
include '../../../inc/includes.php';
Session::checkLoginUser();
Html::header_nocache();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
   header('Allow: GET');
   http_response_code(405);
   echo json_encode(['error' => 'method_not_allowed']);
   exit;
}
if (!Plugin::isPluginActive('autostatus')) {
   http_response_code(403);
   echo json_encode(['error' => 'access_denied']);
   exit;
}

require_once __DIR__ . '/../hook.php';
try {
   echo json_encode(['ticket' => plugin_autostatus_get_ticket_state($_GET['tickets_id'] ?? null)]);
} catch (InvalidArgumentException $error) {
   http_response_code(400);
   echo json_encode(['error' => 'invalid_request']);
} catch (RuntimeException $error) {
   http_response_code(403);
   echo json_encode(['error' => 'access_denied']);
}
