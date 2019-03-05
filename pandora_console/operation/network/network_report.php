<?php
/**
 * Network explorer
 *
 * @package    Operations.
 * @subpackage Network explorer view.
 *
 * Pandora FMS - http://pandorafms.com
 * ==================================================
 * Copyright (c) 2005-2019 Artica Soluciones Tecnologicas
 * Please see http://pandorafms.org for full contribution list
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; version 2
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

check_login();

// ACL Check.
if (! check_acl($config['id_user'], 0, 'AR')) {
    db_pandora_audit(
        'ACL Violation',
        'Trying to access Network report.'
    );
    include 'general/noaccess.php';
    exit;
}

// Include JS timepicker.
ui_include_time_picker();

// Query params and other initializations.
$time_greater = get_parameter('time_greater', date(TIME_FORMAT));
$date_greater = get_parameter('date_greater', date(DATE_FORMAT));
$utimestamp_greater = strtotime($date_greater.' '.$time_greater);
$is_period = (bool) get_parameter('is_period', false);
$period = (int) get_parameter('period', SECONDS_1HOUR);
$time_lower = get_parameter('time_lower', date(TIME_FORMAT, ($utimestamp_greater - $period)));
$date_lower = get_parameter('date_lower', date(DATE_FORMAT, ($utimestamp_greater - $period)));
$utimestamp_lower = ($is_period) ? ($utimestamp_greater - $period) : strtotime($date_lower.' '.$time_lower);
if (!$is_period) {
    $period = ($utimestamp_greater - $utimestamp_lower);
}

$top = (int) get_parameter('top', 10);
$style_end = ($is_period) ? 'display: none;' : '';
$style_period = ($is_period) ? '' : 'display: none;';

// Build the table.
$table = new stdClass();
$table->class = 'databox';
$table->styleTable = 'width: 100%';
$table->style[0] = 'width: 40%';
$table->style[1] = 'width: 40%';
$table->style[2] = 'width: 20%';
$table->data['0']['0'] = __('Data to show').'&nbsp;&nbsp;';
$table->data['0']['0'] .= html_print_select(
    network_get_report_actions($is_network),
    'action',
    $action,
    '',
    '',
    0,
    true
);

$table->data['0']['1'] = __('Number of result to show').'&nbsp;&nbsp;';
$table->data['0']['1'] .= html_print_select(
    [
        '5'   => 5,
        '10'  => 10,
        '15'  => 15,
        '20'  => 20,
        '25'  => 25,
        '50'  => 50,
        '100' => 100,
        '250' => 250,
    ],
    'top',
    $top,
    '',
    '',
    0,
    true
);

$table->data['0']['2'] = __('Select period').'&nbsp;&nbsp;';
$table->data['0']['2'] .= html_print_checkbox(
    'is_period',
    1,
    ($is_period === true) ? 1 : 0,
    true,
    false,
    'network_report_click_period(event)'
);

$table->data['1']['0'] = __('Start date').'&nbsp;&nbsp;';
$table->data['1']['0'] .= html_print_input_text('date_greater', $date_greater, '', 10, 7, true);
$table->data['1']['0'] .= '&nbsp;&nbsp;';
$table->data['1']['0'] .= html_print_input_text('time_greater', $time_greater, '', 7, 8, true);

$table->data['1']['1'] = '<div id="end_date_container" style="'.$style_end.'">';
$table->data['1']['1'] .= __('End date').'&nbsp;&nbsp;';
$table->data['1']['1'] .= html_print_input_text('date_lower', $date_lower, '', 10, 7, true, $is_period);
$table->data['1']['1'] .= '&nbsp;&nbsp;';
$table->data['1']['1'] .= html_print_input_text('time_lower', $time_lower, '', 7, 8, true, $is_period);
$table->data['1']['1'] .= '</div>';

$table->data['1']['1'] .= '<div id="period_container" style="'.$style_period.'">';
$table->data['1']['1'] .= __('Time Period').'&nbsp;&nbsp;';
$table->data['1']['1'] .= html_print_input_text('period', $period, '', 7, 8, true, !$is_period);
$table->data['1']['1'] .= '</div>';

$table->data['1']['2'] = html_print_submit_button(
    __('Update'),
    'update',
    false,
    'class="sub upd"',
    true
);

echo '<form method="post">';
html_print_table($table);
echo '</form>';

// Print the data.
$data = [];
if ($is_network) {
    $data = network_matrix_get_top(
        $top,
        $action === 'talkers',
        $utimestamp_lower,
        $utimestamp_greater,
        ''
    );
}

unset($table);
$table = new stdClass();
$table->styleTable = 'width: 100%';
// Print the header.
$table->head = [];
$table->head['main'] = __('IP');
if (!$is_network) {
    $table->head['flows'] = __('Flows');
}

$table->head['pkts'] = __('Packets');
$table->head['bytes'] = __('Bytes');

// Print the data.
$table->data = [];
foreach ($data as $item) {
    $row = [];
    $row['main'] = $item['host'];
    $row['pkts'] = format_for_graph($item['sum_pkts'], 2);
    $row['bytes'] = format_for_graph(
        $item['sum_bytes'],
        2,
        '.',
        ',',
        1024,
        'B'
    );

    $table->data[] = $row;
}

html_print_table($table);

?>
<script>
// Configure jQuery timepickers.
$("#text-time_lower, #text-time_greater").timepicker({
    showSecond: true,
    timeFormat: '<?php echo TIME_FORMAT_JS; ?>',
    timeOnlyTitle: '<?php echo __('Choose time'); ?>',
    timeText: '<?php echo __('Time'); ?>',
    hourText: '<?php echo __('Hour'); ?>',
    minuteText: '<?php echo __('Minute'); ?>',
    secondText: '<?php echo __('Second'); ?>',
    currentText: '<?php echo __('Now'); ?>',
    closeText: '<?php echo __('Close'); ?>'
});

$("#text-date_lower, #text-date_greater").datepicker({dateFormat: "<?php echo DATE_FORMAT_JS; ?>"});
$.datepicker.setDefaults($.datepicker.regional[ "<?php echo get_user_language(); ?>"]);

function network_report_click_period(event) {
    var is_period = document.getElementById(event.target.id).checked;

    document.getElementById('period_container').style.display = !is_period ? 'none' : 'block';
    document.getElementById('end_date_container').style.display = is_period ? 'none' : 'block';

    document.getElementById('text-time_lower').disabled = is_period;
    document.getElementById('text-date_lower').disabled = is_period;
    document.getElementById('text-period').disabled = !is_period;
}
</script>
