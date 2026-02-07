<?php
/**
 * Plugin Name: get-usercsv
 * Description: user.csvの「japan」ユーザーとWPSDログをマージしてCSV出力。ショートコードはボタン風ダウンロードリンクを表示。H1用CSVはUTF-8で出力し、2バイト文字削除、CityとProvinceは20文字で切り詰め。
 * Version: 1.2.3
 * Author: Copilot
 */
if (!defined('ABSPATH')) exit;

function guc_get_japan_users() {
    $upload_dir = wp_upload_dir();
    $csv_path = trailingslashit($upload_dir['basedir']) . 'wpsd/_cache/user.csv';
    if (!file_exists($csv_path)) return [];
    $fh = fopen($csv_path, 'r');
    $header = fgetcsv($fh);
    $idx = array_flip($header);
    $users = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (strtolower(trim($row[$idx['COUNTRY']])) === 'japan') {
            $users[] = [
                'RADIO_ID'   => $row[$idx['RADIO_ID']],
                'CALLSIGN'   => $row[$idx['CALLSIGN']],
                'FIRST_NAME' => $row[$idx['FIRST_NAME']],
                'LAST_NAME'  => $row[$idx['LAST_NAME']],
                'CITY'       => $row[$idx['CITY']],
                'STATE'      => $row[$idx['STATE']],
                'COUNTRY'    => $row[$idx['COUNTRY']],
            ];
        }
    }
    fclose($fh);
    return $users;
}

function guc_get_logs($selected_node) {
    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit($upload_dir['basedir']) . 'wpsd/' . $selected_node;
    if (!file_exists($base_dir)) return [];
    $files = glob($base_dir . '/inbox-*.json');
    $logs = [];
    foreach ($files as $file) {
        $json = json_decode(file_get_contents($file), true);
        if (!$json) continue;
        $logs[] = [
            'RADIO_ID'   => isset($json['dmr_id']) ? $json['dmr_id'] : '',
            'CALLSIGN'   => isset($json['callsign']) ? $json['callsign'] : '',
            'FIRST_NAME' => isset($json['first_name']) ? $json['first_name'] : '',
            'LAST_NAME'  => isset($json['last_name']) ? $json['last_name'] : '',
            'CITY'       => isset($json['city']) ? $json['city'] : '',
            'STATE'      => isset($json['state']) ? $json['state'] : '',
            'COUNTRY'    => isset($json['country']) ? $json['country'] : '',
        ];
    }
    return $logs;
}

function guc_merge_data($users, $logs) {
    $merged = [];
    foreach ($users as $u) {
        $key = trim($u['RADIO_ID']);
        if ($key !== '') $merged[$key] = $u;
    }
    foreach ($logs as $l) {
        $key = trim($l['RADIO_ID']);
        if ($key === '') continue;
        if (!isset($merged[$key])) $merged[$key] = $l;
    }
    return array_values($merged);
}

function guc_shortcode($atts) {
    $atts = shortcode_atts(['node' => ''], $atts);
    $node = sanitize_file_name($atts['node']);
    if (!$node) return '<p>ノードを指定してください。</p>';
    $url1 = wp_nonce_url(admin_url('admin-ajax.php?action=guc_export&node=' . $node), 'guc_export_' . $node);
    $url2 = wp_nonce_url(admin_url('admin-ajax.php?action=guc_export_h1&node=' . $node), 'guc_export_h1_' . $node);

    $css = '<style>.guc-version { display:inline-block; background:#eee; color:#2271b1; font-weight:bold; border-radius:5px; padding:4px 12px; margin-bottom:10px; font-size:15px; } .guc-btn { display:inline-block; background:#2271b1; color:#fff; border:none; border-radius:5px; padding:10px 18px; font-size:16px; text-decoration:none; margin:4px 8px; cursor:pointer; transition:background 0.2s; } .guc-btn:hover { background:#135e96; }</style>
    .guc-btn { display:inline-block; background:#2271b1; color:#fff; border:none; border-radius:5px; padding:10px 18px; font-size:16px; text-decoration:none; margin:4px 8px; cursor:pointer; transition:background 0.2s; }
    .guc-btn:hover { background:#135e96; }
    </style>';

    return $css . '<p><span class="guc-version">v1.2.3</span><br>
        <a class="guc-btn" href="' . esc_url($url1) . '">CSVダウンロード (UTF-8)</a>
        <a class="guc-btn" href="' . esc_url($url2) . '">H1用CSVダウンロード (UTF-8, 2バイト文字削除)</a>
    </p>';
}
add_shortcode('get_usercsv', 'guc_shortcode');

function guc_clean_value($value, $remove_multibyte = false) {
    $value = str_replace(',', ' ', $value);
    $value = str_replace('"', '', $value);
    if ($remove_multibyte) {
        $value = preg_replace('/[\x{3000}-\x{9FFF}\x{FF00}-\x{FFEF}]/u', '', $value);
    }
    return $value;
}

function guc_trim_value($value) {
    return mb_substr($value, 0, 20, 'UTF-8');
}

function guc_output_csv_line($fields, $remove_multibyte = false, $trim_city_province = False) {
    $cleaned = [];
    foreach ($fields as $index => $v) {
        $val = guc_clean_value($v, $remove_multibyte);
        if ($trim_city_province and ($index == 3 or $index == 4)) { // City or Province
            $val = guc_trim_value($val);
        }
        $cleaned[] = $val;
    }
    return implode(',', $cleaned) . "\n";
}

function guc_export_csv() {
    $node = sanitize_file_name($_GET['node']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'guc_export_' . $node)) wp_die('不正なリクエスト');
    $users = guc_get_japan_users();
    $logs = guc_get_logs($node);
    $merged = guc_merge_data($users, $logs);
    $csv = guc_output_csv_line(['RADIO_ID','CALLSIGN','FIRST_NAME','LAST_NAME','CITY','STATE','COUNTRY']);
    foreach ($merged as $row) {
        $csv .= guc_output_csv_line([$row['RADIO_ID'],$row['CALLSIGN'],$row['FIRST_NAME'],$row['LAST_NAME'],$row['CITY'],$row['STATE'],$row['COUNTRY']]);
    }
    $timestamp = date('YmdHis');
    $filename = 'user_contact_utf8_' . $timestamp . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csv;
    exit;
}
add_action('wp_ajax_guc_export', 'guc_export_csv');
add_action('wp_ajax_nopriv_guc_export', 'guc_export_csv');

function guc_export_h1_csv() {
    $node = sanitize_file_name($_GET['node']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'guc_export_h1_' . $node)) wp_die('不正なリクエスト');
    $users = guc_get_japan_users();
    $logs = guc_get_logs($node);
    $merged = guc_merge_data($users, $logs);
    $csv = guc_output_csv_line(['Contacts Alias','Call Type','Call ID','City','Province','Country'], true, true);
    foreach ($merged as $row) {
        $alias = $row['CALLSIGN']; // CALLSIGNのみ
        $call_type = 'Private Call';
        $call_id = $row['RADIO_ID'];
        $city = $row['FIRST_NAME'];
        $province = $row['CITY'] . ' ' . $row['STATE'];
        $country = $row['COUNTRY'];
        $csv .= guc_output_csv_line([$alias,$call_type,$call_id,$city,$province,$country], true, true);
    }
    $timestamp = date('YmdHis');
    $filename = 'h1_contacts_utf8_' . $timestamp . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csv;
    exit;
}
add_action('wp_ajax_guc_export_h1', 'guc_export_h1_csv');
add_action('wp_ajax_nopriv_guc_export_h1', 'guc_export_h1_csv');
?>
