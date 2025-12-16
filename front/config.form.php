<?php
/**
 * Configuration page: Setup > Plugins > AutoStatus > Configure
 */
include ("../../../inc/includes.php");

Session::checkRight('config', UPDATE);

$plugin_context = 'plugin:autostatus';

// Load config + defaults
$conf = Config::getConfigurationValues($plugin_context);
if (!is_array($conf)) {
   $conf = [];
}

// Defaults (same as install)
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

$conf = array_merge($defaults, $conf);

// Status list
$statuses = [];
if (class_exists('Ticket') && method_exists('Ticket', 'getAllStatusArray')) {
   $statuses = Ticket::getAllStatusArray();
}
if (!is_array($statuses) || empty($statuses)) {
   $statuses = [
      1 => __('New'),
      2 => __('Processing (assigned)'),
      3 => __('Processing (planned)'),
      4 => __('Pending'),
      5 => __('Solved'),
      6 => __('Closed'),
   ];
}
$statusesWithNone = [0 => __('Do not change')] + $statuses;

// Helper to parse CSV into array for checkboxes
function autostatus_csv_to_array($csv) {
   $csv = trim((string)$csv);
   if ($csv === '') return [];
   $out = [];
   foreach (explode(',', $csv) as $p) {
      $p = trim($p);
      if ($p === '') continue;
      $out[] = (int)$p;
   }
   return array_values(array_unique($out));
}
function autostatus_array_to_csv($arr) {
   if (!is_array($arr) || empty($arr)) return '';
   $arr = array_map('intval', $arr);
   $arr = array_values(array_unique($arr));
   sort($arr);
   return implode(',', $arr);
}

// Save
if (isset($_POST['update'])) {
   $values = [
      'ignore_solved_closed'        => (int)($_POST['ignore_solved_closed'] ?? 1),

      'oncreate_allowed_statuses'   => autostatus_array_to_csv($_POST['oncreate_allowed_statuses'] ?? []),
      'ontask_allowed_statuses'     => autostatus_array_to_csv($_POST['ontask_allowed_statuses'] ?? []),
      'onfollowup_allowed_statuses' => autostatus_array_to_csv($_POST['onfollowup_allowed_statuses'] ?? []),

      'oncreate_enabled'            => (int)($_POST['oncreate_enabled'] ?? 0),
      'oncreate_status'             => (int)($_POST['oncreate_status'] ?? 0),

      'ontask_enabled'              => (int)($_POST['ontask_enabled'] ?? 0),
      'ontask_status'               => (int)($_POST['ontask_status'] ?? 0),
      'ignore_private_tasks'        => (int)($_POST['ignore_private_tasks'] ?? 0),

      'onfollowup_enabled'          => (int)($_POST['onfollowup_enabled'] ?? 0),
      'onfollowup_status'           => (int)($_POST['onfollowup_status'] ?? 0),
      'ignore_private_followups'    => (int)($_POST['ignore_private_followups'] ?? 0),

      'followup_split_by_author'    => (int)($_POST['followup_split_by_author'] ?? 0),
      'onfollowup_status_requester' => (int)($_POST['onfollowup_status_requester'] ?? 0),
      'onfollowup_status_other'     => (int)($_POST['onfollowup_status_other'] ?? 0),
   ];

   Config::setConfigurationValues($plugin_context, $values);
   Html::back();
   exit;
}

$oncreate_allowed = autostatus_csv_to_array($conf['oncreate_allowed_statuses']);
$ontask_allowed = autostatus_csv_to_array($conf['ontask_allowed_statuses']);
$onfollowup_allowed = autostatus_csv_to_array($conf['onfollowup_allowed_statuses']);

Html::header(__('AutoStatus', 'autostatus'), $_SERVER['PHP_SELF'], "config", "plugins");

echo "<div class='center'>";
echo "<form method='post' action='config.form.php'>";

// CSRF token (required for POST)
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<table class='tab_cadre_fixe'>";
echo "<tr><th colspan='4'>".__('AutoStatus - regras automáticas de status', 'autostatus')."</th></tr>";

echo "<tr class='tab_bg_2'><th colspan='4'>".__('Regras gerais', 'autostatus')."</th></tr>";
echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Segurança', 'autostatus')."</strong></td>";
echo "<td colspan='2'>";
Dropdown::showYesNo('ignore_solved_closed', $conf['ignore_solved_closed']);
echo " ".__('Não alterar tickets Solucionados/Fechados', 'autostatus');
echo "</td>";
echo "<td></td>";
echo "</tr>";

/**
 * Helper: render "apply only if current status is ..."
 */
function autostatus_render_allowed_statuses_row($name, $statuses, $selected, $help) {
   echo "<tr class='tab_bg_1'>";
   echo "<td><strong>".__('Aplicar somente se status atual for', 'autostatus')."</strong></td>";
   echo "<td colspan='2'>";
   echo "<div style='display:flex;flex-wrap:wrap;gap:10px;align-items:center'>";
   foreach ($statuses as $id => $label) {
      $id = (int)$id;
      // Don't show "Do not change" in filters
      if ($id <= 0) continue;
      $checked = in_array($id, $selected, true) ? "checked" : "";
      echo "<label style='white-space:nowrap'><input type='checkbox' name='{$name}[]' value='{$id}' {$checked}> ".Html::clean($label)."</label>";
   }
   echo "</div>";
   echo "<div class='small' style='margin-top:6px;opacity:.85'>".Html::clean($help)." ".__('Se nada for marcado, aplica em qualquer status (exceto Solucionado/Fechado, se habilitado).', 'autostatus')."</div>";
   echo "</td>";
   echo "<td></td>";
   echo "</tr>";
}

echo "<tr class='tab_bg_2'><th colspan='4'>".__('Quando o ticket é criado', 'autostatus')."</th></tr>";
echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Habilitar', 'autostatus')."</strong></td>";
echo "<td>";
Dropdown::showYesNo('oncreate_enabled', $conf['oncreate_enabled']);
echo "</td>";
echo "<td>";
Dropdown::showFromArray('oncreate_status', $statusesWithNone, ['value' => $conf['oncreate_status']]);
echo "</td>";
echo "<td>".__('Ex.: forçar status "Novo" mesmo quando o requerente já escolhe o técnico na abertura.', 'autostatus')."</td>";
echo "</tr>";
autostatus_render_allowed_statuses_row('oncreate_allowed_statuses', $statuses, $oncreate_allowed, __('Filtro opcional para não sobrescrever determinados status no momento da criação.', 'autostatus'));

echo "<tr class='tab_bg_2'><th colspan='4'>".__('Quando uma tarefa é adicionada (TicketTask)', 'autostatus')."</th></tr>";
echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Habilitar', 'autostatus')."</strong></td>";
echo "<td>";
Dropdown::showYesNo('ontask_enabled', $conf['ontask_enabled']);
echo "</td>";
echo "<td>";
Dropdown::showFromArray('ontask_status', $statusesWithNone, ['value' => $conf['ontask_status']]);
echo "</td>";
echo "<td>".__('Ex.: mudar para "Em atendimento" ao iniciar trabalho/registrar tempo (ActualTime).', 'autostatus')."</td>";
echo "</tr>";
echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Ignorar tarefas privadas', 'autostatus')."</strong></td>";
echo "<td colspan='2'>";
Dropdown::showYesNo('ignore_private_tasks', $conf['ignore_private_tasks']);
echo "</td>";
echo "<td></td>";
echo "</tr>";
autostatus_render_allowed_statuses_row('ontask_allowed_statuses', $statuses, $ontask_allowed, __('Aplicar a regra de tarefa somente para alguns status atuais.', 'autostatus'));

echo "<tr class='tab_bg_2'><th colspan='4'>".__('Quando um acompanhamento é adicionado (ITILFollowup)', 'autostatus')."</th></tr>";
echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Habilitar', 'autostatus')."</strong></td>";
echo "<td>";
Dropdown::showYesNo('onfollowup_enabled', $conf['onfollowup_enabled']);
echo "</td>";
echo "<td>";
Dropdown::showFromArray('onfollowup_status', $statusesWithNone, ['value' => $conf['onfollowup_status']]);
echo "</td>";
echo "<td>".__('Regra simples: sempre trocar para este status quando houver acompanhamento.', 'autostatus')."</td>";
echo "</tr>";

echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Dividir por técnico (perfil Central)', 'autostatus')."</strong></td>";
echo "<td colspan='2'>";
Dropdown::showYesNo('followup_split_by_author', $conf['followup_split_by_author']);
echo " <span class='small' style='opacity:.85'>".__('Se SIM: se quem respondeu tiver perfil com interface Central (técnico), usa um status; caso contrário, usa outro.', 'autostatus')."</span>";
echo "</td>";
echo "<td></td>";
echo "</tr>";

echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Status quando NÃO-técnico responde', 'autostatus')."</strong></td>";
echo "<td colspan='2'>";
Dropdown::showFromArray('onfollowup_status_requester', $statusesWithNone, ['value' => $conf['onfollowup_status_requester']]);
echo "</td>";
echo "<td></td>";
echo "</tr>";

echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Status quando Técnico responde', 'autostatus')."</strong></td>";
echo "<td colspan='2'>";
Dropdown::showFromArray('onfollowup_status_other', $statusesWithNone, ['value' => $conf['onfollowup_status_other']]);
echo "</td>";
echo "<td></td>";
echo "</tr>";

echo "<tr class='tab_bg_1'>";
echo "<td><strong>".__('Ignorar acompanhamentos privados', 'autostatus')."</strong></td>";
echo "<td colspan='2'>";
Dropdown::showYesNo('ignore_private_followups', $conf['ignore_private_followups']);
echo "</td>";
echo "<td></td>";
echo "</tr>";

autostatus_render_allowed_statuses_row('onfollowup_allowed_statuses', $statuses, $onfollowup_allowed, __('Aplicar a regra de acompanhamento somente para alguns status atuais.', 'autostatus'));

echo "<tr class='tab_bg_1'>";
echo "<td colspan='4' class='center'>";
echo "<button class='btn btn-primary' type='submit' name='update' value='1'>".__('Save')."</button>";
echo "</td>";
echo "</tr>";

echo "</table>";
echo "</form>";
echo "</div>";

Html::footer();
