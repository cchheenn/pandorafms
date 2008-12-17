<?php

// Pandora FMS - the Flexible Monitoring System
// ============================================
// Copyright (c) 2008 Artica Soluciones Tecnologicas, http://www.artica.es
// Please see http://pandora.sourceforge.net for full contribution list

// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation for version 2.
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

require_once ("include/config.php");
require_once ("include/functions_incidents.php");

check_login ();

if (! give_acl ($config['id_user'], 0, "IR")) {
	audit_db($config['id_user'],$config["remote_addr"], "ACL Violation","Trying to access incident viewer");
	require ("general/noaccess.php");
	exit;
}

// Take input parameters

// Offset adjustment
if (isset($_GET["offset"])) {
	$offset = get_parameter_get ("offset");
} else {
	$offset = 0;
}

// Check action. Try to get author and group
$action = get_parameter_get ("action");

if ($action == "mass") {
	$id_inc = get_parameter_post ("id_inc", array ());
	$delete_btn = get_parameter_post ("delete_btn", -1);
	$own_btn = get_parameter_post ("own_btn", -1);
	
	foreach ($id_inc as $incident) {
		if (give_acl ($config['id_user'], get_incidents_group ($incident), "IM") || get_incidents_author ($incident) == $config["id_user"] || get_incidents_owner ($incident) == $config["id_user"]) {
			continue;
		}
		audit_db ($config["id_user"],$config["remote_addr"],"ACL Forbidden","Mass-update or deletion of incident");
		require ("general/noaccess.php");
		exit;
	}
	
	if ($delete_btn != -1) {
		$result = delete_incidents ($id_inc);
		print_error_message ($result, __('Incident successfully deleted'), __('There was a problem deleting incident'));	
	}
	if ($own_btn != -1) {
		$result = process_incidents_chown ($id_inc, $config["id_user"]);
		print_error_message ($result, __('Incident successfully owned'), __('There was a problem becoming owner of incident'));	
	}

} elseif ($action == "update") {
	$id_inc = get_parameter ("id_inc", 0);
	$author = get_incidents_author ($id_inc);
	$owner = get_incidents_owner ($id_inc);
	$grupo = get_incidents_group ($id_inc);
	
	if ($author != $config["id_user"] && $owner != $config["id_user"] && !give_acl ($config['id_user'], $grupo, "IM")) { // Only admins (manage incident) or owners/creators can modify incidents
		audit_db ($author, $config["remote_addr"], "ACL Forbidden", "Update incident #".$id_inc);
		require ("general/noaccess.php");
		exit;
	}
	
	$titulo = get_parameter_post ("titulo");
	$descripcion = get_parameter_post ("descripcion");
	$origen = get_parameter_post ("origen_form");
	$prioridad = get_parameter_post ("prioridad_form", 0);
	$estado = get_parameter_post ("estado_form", 0);
	$grupo = get_parameter_post ("grupo_form", 1);
	$usuario = get_parameter_post ("usuario_form", $config["id_user"]);
	
	$sql = sprintf ("UPDATE tincidencia SET titulo = '%s', origen = '%s', estado = %d, id_grupo = %d, id_usuario = '%s', prioridad = %d, descripcion = '%s', id_lastupdate = '%s' WHERE id_incidencia = %d", 
					$titulo, $origen, $estado, $grupo, $usuario, $prioridad, $descripcion, $config["id_user"], $id_inc);
	$result = process_sql ($sql);

	if ($result !== false) {
		audit_db ($config["id_user"], $config["remote_addr"], "Incident updated","User ".$config['id_user']." updated incident #".$id_inc);
	}
	
	print_error_message ($result, __('Incident successfully updated'), __('There was a problem updating incident'));
	
} elseif ($action == "insert") {
	//Create incident
	$grupo = get_parameter_post ("grupo_form", 1);
	
	if (!give_acl ($config['id_user'], $grupo, "IW")) {
		audit_db ($config["id_user"], $config["remote_addr"], "ACL Forbidden", "User ".$config["id_user"]." tried to update incident");
		require ("general/noaccess.php");
		exit;
	}

	// Read input variables
	$titulo = get_parameter_post ("titulo"); 
	$descripcion = get_parameter_post ("descripcion");
	$origen = get_parameter_post ("origen_form");
	$prioridad = get_parameter_post ("prioridad_form");
	$id_creator = $config['id_user'];
	$estado = get_parameter_post ("estado_form");
	$sql = sprintf ("INSERT INTO tincidencia (inicio, actualizacion, titulo, descripcion, id_usuario, origen, estado, prioridad, id_grupo, id_creator) VALUES 
					(NOW(), NOW(), '%s', '%s', '%s', '%s', %d, %d, '%s', '%s')", $titulo, $descripcion, $config["id_user"], $origen, $estado, $prioridad, $grupo, $config["id_user"]);
	$id_inc = process_sql ($sql, "insert_id");

	if ($id_inc === false) {
		echo '<h3 class="error">'.__('Error creating incident').'</h3>';		
	} else {
		audit_db ($config["id_user"], $config["remote_addr"], "Incident created", "User ".$config["id_user"]." created incident #".$id_inc);
	}
}

// Search
$filter = "";

$texto = (string) get_parameter ("texto", "");
if ($texto != "") 
	$filter .= sprintf (" AND (titulo LIKE '%%%s%%' OR descripcion LIKE '%%%s%%')", $texto, $texto);

$usuario = (string) get_parameter ("usuario", "All");
if ($usuario != "All") 
	$filter .= sprintf (" AND id_usuario = '%s'", $usuario);

$estado = (int) get_parameter ("estado", -1);
if ($estado != -1) //-1 = All
	$filter .= sprintf (" AND estado = %d", $estado);

$grupo = (int) get_parameter ("grupo", 1);
if ($grupo != 1) {
	$filter .= sprintf (" AND id_grupo = %d", $grupo);
	if (give_acl ($config['id_user'], $grupo, "IM") == 0) {
		audit_db ($config["id_user"],$config["remote_addr"],"ACL Forbidden","User tried to read incidents from group without access");
		no_permission ();
	}
}

$prioridad = (int) get_parameter ("prioridad", -1);
if ($prioridad != -1) //-1 = All
	$filter .= sprintf (" AND prioridad = %d", $prioridad);

$offset = (int) get_parameter ("offset", 0);
$groups = get_user_groups ($config["id_user"], "IR");

//Select incidencts where the user has access to ($groups from
//get_user_groups), array_keys for the id, implode to pass to SQL
$sql = "SELECT * FROM tincidencia WHERE 
	id_grupo IN (".implode (",",array_keys ($groups)).")".$filter." 
	ORDER BY actualizacion DESC LIMIT ".$offset.",".$config["block_size"];

$result = get_db_all_rows_sql ($sql);
if (empty ($result)) {
	$result = array ();
	$count = 0;
} else {
	$count = count ($result);
}

echo '<h2>'.__('Incident management').' &gt; '.__('Manage incidents').'</h2>
<form name="visualizacion" method="POST" action="index.php?sec=incidencias&sec2=operation/incidents/incident">
<table class="databox" cellpadding="4" cellspacing="4" width="700px"><tr>
<td valign="middle">
<h3>'.__('Filter').'</h3>';

$fields = get_incidents_status ();
$fields[-1] = __('All incidents');

print_select ($fields, "estado", $estado, 'javascript:this.form.submit();', '', '', false, false, false, 'w155');

//Legend
echo '</td><td valign="middle"><noscript>';
print_submit_button (__('Show'), 'submit-estado', false, 'class="sub" border="0"');

echo '</noscript></td><td rowspan="5" class="f9" style="padding-left: 30px; vertical-align: top;"><h3>'.__('Status').'</h3>';
foreach (get_incidents_status () as $id => $str) {
	print_incidents_status_img ($id);
	echo ' - ' . $str . '<br />';
}

echo '</td><td rowspan="5" class="f9" style="padding-left: 30px; vertical-align: top;"><h3>'.__('Priority').'</h3>';
foreach (get_incidents_priorities () as $id => $str) {
	print_incidents_priority_img ($id);
	echo ' - ' . $str . '<br />';
}

echo '</td></tr><tr><td>';

$fields = get_incidents_priorities ();
$fields[-1] = __('All priorities');

print_select ($fields, "prioridad", $prioridad, 'javascript:this.form.submit();', '','',false,false,false,'w155');

echo '</td><td valign="middle"><noscript>';
print_submit_button (__('Show'), 'submit-prioridad', false, 'class="sub" border="0"');
echo '</noscript></td></tr><tr><td>';

print_select ($groups, "grupo", $grupo, 'javascript:this.form.submit();','','',false,false,false,'w155');

echo '</td><td valign="middle"><noscript>';
print_submit_button (__('Show'), 'submit-grupo', false, 'class="sub" border="0"');
echo '</noscript>';

// Pass search parameters for possible future filter searching by user
print_input_hidden ("usuario", $usuario);
print_input_hidden ("texto", $texto);

echo "</td></tr></table></form>";

if ($count < 1) {
	echo '<div class="nf">'.__('No incidents match your search filter').'</div><br />';
} else {
	// TOTAL incidents
	$url = "index.php?sec=incidencias&sec2=operation/incidents/incident";

	$estado = -1;

	// add form filter values for group, priority, state, and search fields: user and text
	if ($grupo != -1)
		$url .= "&grupo=".$grupo;
	if ($prioridad != -1)
		$url .= "&prioridad=".$prioridad;
	if ($estado != -1)
		$url .= "&estado=".$estado;
	if ($usuario != '')
		$url .= "&usuario=".$usuario;
	if ($texto != '')
		$url .= "&texto=".$texto;

	// Show pagination
	pagination ($count, $url, $offset);
	echo '<br />';
	
	// Show headers
	$table->width = 750;
	$table->class = "databox";
	$table->cellpadding = 4;
	$table->cellspacing = 4;
	$table->head = array ();
	$table->data = array ();
	$table->size = array ();
	$table->align = array ();

	$table->head[0] = __('ID');
	$table->head[1] = __('Status');
	$table->head[2] = __('Incident');
	$table->head[3] = __('Priority');
	$table->head[4] = __('Group');
	$table->head[5] = __('Updated');
	$table->head[6] = __('Source');
	$table->head[7] = __('Owner');
	$table->head[8] = 'X';
	
	$table->size[0] = 43;
	$table->size[7] = 50;
	
	$table->align[1] = "center";
	$table->align[3] = "center";
	$table->align[4] = "center";
	$table->align[8] = "center";
	
	foreach ($result as $row) {
		$data = array();

		$data[0] = '<a href="index.php?sec=incidencias&sec2=operation/incidents/incident_detail&id='.$row["id_incidencia"].'">'.$row["id_incidencia"].'</a>';
		$attach = get_incidents_attach ($row["id_incidencia"]);
		
		if (!empty ($attach))
			$data[0] .= '&nbsp;&nbsp;<img src="images/file.png" align="middle" />';
				
		$data[1] = print_incidents_status_img ($row["estado"], true);
		
		$data[2] = '<a href="index.php?sec=incidencias&sec2=operation/incidents/incident_detail&id='.$row["id_incidencia"].'">'.safe_input (substr ($row["titulo"],0,45)).'</a>';
		
		$data[3] = print_incidents_priority_img ($row["prioridad"], true);																																																													
		
		$data[4] = print_group_icon ($row["id_grupo"], true);
	
		$data[5] = print_timestamp ($row["actualizacion"], true);
		
		$data[6] = $row["origen"];	
		
		$data[7] = print_username ($row["id_usuario"], true);
		
		if (give_acl ($config["id_user"], $row["id_grupo"], "IM") || $config["id_user"] == $row["id_usuario"] || $config["id_user"] == $row["id_creator"]) {
			$data[8] = print_checkbox ("id_inc[]", $row["id_incidencia"], false, true);
		} else {
			$data[8] = '';
		}
		
		array_push ($table->data, $data);
	}
	
	echo '<form method="post" action="'.$url.'&action=mass" style="margin-bottom: 0px;">';
	print_table ($table);
	if (give_acl ($config["id_user"], 0, "IM")) {
		echo '<div style="text-align:right; float:right; padding-right: 30px;">';
		print_submit_button (__('Delete incidents'), 'delete_btn', false, 'class="sub delete"');
		print_submit_button (__('Become owner'), 'own_btn', false, 'class="sub upd"');
		echo '</div>';
	}
	echo '</form>';
	unset ($table);
}

if (give_acl ($config["id_user"], 0, "IW")) {
	echo '<div style="text-align:right; float:right; padding-right: 30px;">';
	echo '<form method="post" action="index.php?sec=incidencias&sec2=operation/incidents/incident_detail&insert_form=1">';
	print_submit_button (__('Create incident'), 'crt', false, 'class="sub next"');
	echo '</form>';
	echo '</div>';
}
echo '<div style="clear:both">&nbsp;</div>';
?>
