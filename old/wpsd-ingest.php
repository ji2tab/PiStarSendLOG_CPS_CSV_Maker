<?php
/**
 * Plugin Name: WPSD Ingest
 * Description: REST endpoint to receive WPSD data and save as JSON. RadioID CSV (user.csv) をローカルキャッシュ参照。キャッシュ管理ツール付き。
 * Version: 1.6.2
 * Author: M365 Copilot
 */
if (!defined('ABSPATH')) exit;

/**
 * --- RadioID user.csv をダウンロードしてローカルに保存 ---
 */
function wpsd_fetch_radioid_csv() {
    $sources = array(
        'https://radioid.net/static/user.csv',
        'https://www.radioid.net/static/user.csv',
        'https://database.radioid.net/database/dumps/user.csv',
    );
    $upload_dir = wp_upload_dir();
    $cache_dir  = trailingslashit($upload_dir['basedir']) . 'wpsd/_cache';
    if (!file_exists($cache_dir)) { wp_mkdir_p($cache_dir); }
    $dest = $cache_dir . '/user.csv';

    $last_error = null;
    foreach ($sources as $src) {
        $response = wp_remote_get($src, ['timeout' => 20]);
        if (is_wp_error($response)) { $last_error = $response->get_error_message(); continue; }
        $body = wp_remote_retrieve_body($response);
        if (!$body) { $last_error = 'Empty body'; continue; }
        if (stripos($body, 'RADIO_ID,CALLSIGN') !== 0) { $last_error = 'Invalid header'; continue; }
        file_put_contents($dest, $body);
        update_option('wpsd_radioid_csv_last_updated', time());
        return $dest;
    }
    return new WP_Error('csv_fetch_error', 'user.csv の取得に失敗しました: ' . ($last_error ?: 'unknown'));
}

/**
 * --- RadioID CSV ルックアップ ---
 * name = FIRST_NAME、欠損は null。
 */
function get_dmr_info_with_cache($callsign) {
    $callsign = strtoupper(trim($callsign));
    if ($callsign === '') return null;

    $transient_key = 'dmr_info_' . md5($callsign);
    $cached = get_transient($transient_key);
    if ($cached !== false) return $cached;

    $upload_dir = wp_upload_dir();
    $csv_path   = trailingslashit($upload_dir['basedir']) . 'wpsd/_cache/user.csv';

    if (!file_exists($csv_path)) {
        $res = wpsd_fetch_radioid_csv();
        if (is_wp_error($res)) {
            $api_url  = 'https://radioid.net/api/dmr/user/?callsign=' . urlencode($callsign);
            $response = wp_remote_get($api_url, ['timeout' => 10]);
            if (is_wp_error($response)) return null;
            $json = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($json['results']) || count($json['results'])===0 || !isset($json['results'][0]['id'])) return null;
            $name = isset($json['results'][0]['name']) ? $json['results'][0]['name'] : null;
            $dmr_info = array(
                'dmr_id'     => $json['results'][0]['id'],
                'name'       => $name,
                'first_name' => null,
                'last_name'  => null,
                'city'       => null,
                'state'      => null,
                'country'    => null,
                'source'     => 'api'
            );
            set_transient($transient_key, $dmr_info, 12 * HOUR_IN_SECONDS);
            return $dmr_info;
        }
    }

    $fh = fopen($csv_path, 'r');
    if (!$fh) return null;
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return null; }
    $idx = array_flip($header);
    $need = array('RADIO_ID','CALLSIGN','FIRST_NAME','LAST_NAME','CITY','STATE','COUNTRY');
    foreach ($need as $col) { if (!isset($idx[$col])) { fclose($fh); return null; } }

    $dmr_info = null;
    while (($row = fgetcsv($fh)) !== false) {
        $row_cs = strtoupper(trim($row[$idx['CALLSIGN']]));
        if ($row_cs === $callsign) {
            $dmr_id = trim($row[$idx['RADIO_ID']]);
            $fn     = trim($row[$idx['FIRST_NAME']]);
            $ln     = trim($row[$idx['LAST_NAME']]);
            $city   = trim($row[$idx['CITY']]);
            $state  = trim($row[$idx['STATE']]);
            $country= trim($row[$idx['COUNTRY']]);
            $fn      = ($fn     === '') ? null : $fn;
            $ln      = ($ln     === '') ? null : $ln;
            $city    = ($city   === '') ? null : $city;
            $state   = ($state  === '') ? null : $state;
            $country = ($country=== '') ? null : $country;
            $name = $fn;
            $dmr_info = array(
                'dmr_id'     => $dmr_id,
                'name'       => $name,
                'first_name' => $fn,
                'last_name'  => $ln,
                'city'       => $city,
                'state'      => $state,
                'country'    => $country,
                'source'     => 'csv'
            );
            break;
        }
    }
    fclose($fh);

    if ($dmr_info) {
        set_transient($transient_key, $dmr_info, 12 * HOUR_IN_SECONDS);
        return $dmr_info;
    }
    return null;
}

/**
 * --- REST エンドポイント ---
 */
add_action('rest_api_init', function () {
    register_rest_route('wpsd/v1', '/ingest', array(
        'methods' => 'POST',
        'callback' => function($request) {
            $token    = $request->get_header('x-wpsd-token');
            $token_map = get_option('wpsd_ingest_token_map', []);
            $subdir = null;
            foreach ($token_map as $entry) {
                if (isset($entry['token']) && $entry['token'] === $token) { $subdir = sanitize_file_name($entry['node']); break; }
            }
            if (!$subdir) { return new WP_Error('unauthorized', 'Invalid token', array('status' => 403)); }

            $data = $request->get_json_params();
            if (!$data || !is_array($data)) { return new WP_Error('invalid', 'No JSON data', array('status' => 400)); }
            if (!isset($data['callsign']) && isset($data['dmr']['callsign'])) { $data['callsign'] = $data['dmr']['callsign']; }
            if (!isset($data['callsign']) || empty($data['callsign'])) { return new WP_Error('invalid', 'Missing callsign', array('status' => 400)); }

            $dmr_info = get_dmr_info_with_cache($data['callsign']);
            if (!$dmr_info || !isset($dmr_info['dmr_id'])) { return array('ok' => false, 'reason' => 'callsign not found'); }

            $data['dmr_id']     = $dmr_info['dmr_id'];
            $data['name']       = isset($dmr_info['name']) ? $dmr_info['name'] : null;
            $data['first_name'] = array_key_exists('first_name',$dmr_info) ? $dmr_info['first_name'] : null;
            $data['last_name']  = array_key_exists('last_name',$dmr_info)  ? $dmr_info['last_name']  : null;
            $data['city']       = array_key_exists('city',$dmr_info)       ? $dmr_info['city']       : null;
            $data['state']      = array_key_exists('state',$dmr_info)      ? $dmr_info['state']      : null;
            $data['country']    = array_key_exists('country',$dmr_info)    ? $dmr_info['country']    : null;

            $dt = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
            $data['timestamp']       = $dt->format('Y-m-d H:i:s');
            $data['timestamp_local'] = $dt->format('Y-m-d\TH:i:s.vP');

            $upload_dir = wp_upload_dir();
            $dir = trailingslashit($upload_dir['basedir']) . 'wpsd/' . $subdir;
            if (!file_exists($dir)) { wp_mkdir_p($dir); }

            foreach (glob($dir . '/inbox-*.json') as $file) {
                $existing = json_decode(file_get_contents($file), true);
                if (isset($existing['callsign']) && $existing['callsign'] === $data['callsign']) {
                    if (isset($existing['timestamp']) && $existing['timestamp'] < $data['timestamp']) {
                        @unlink($file);
                    }
                }
            }
            $filename = sprintf("inbox-%s.json", $dt->format('Y-m-d\TH-i-s'));
            file_put_contents($dir . '/' . $filename, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return array('ok' => true, 'stored' => $dir . '/' . $filename);
        },
        'permission_callback' => '__return_true'
    ));
});

/**
 * --- 管理画面（設定 + ツール） ---
 */
add_action('admin_menu', function () {
    // Settings Page
    add_options_page(
        'WPSD Ingest Settings', 'WPSD Ingest', 'manage_options', 'wpsd-ingest', function () {
            echo '<div class="wrap">';
            echo '<h1>WPSD Ingest Settings</h1>';
            echo '<form method="post" action="options.php">';
            settings_fields('wpsd_ingest');
            do_settings_sections('wpsd-ingest');
            submit_button();
            echo '</form>';
            $last = get_option('wpsd_radioid_csv_last_updated', 0);
            echo '<hr /><h2>RadioID user.csv</h2>';
            echo '<p>最終更新: ' . ($last ? esc_html(date('Y-m-d H:i:s', $last)) : '未取得') . '</p>';
            $url = wp_nonce_url(admin_url('admin-post.php?action=wpsd_csv_refresh'), 'wpsd_csv_refresh');
            echo '<p><a href="' . esc_url($url) . '" class="button button-secondary">手動更新</a></p>';
            echo '</div>';
        }
    );
    // Tools Page: Cache Clear
    add_management_page(
        'WPSD Ingest Tools', 'WPSD Ingest Tools', 'manage_options', 'wpsd-ingest-tools', function(){
            echo '<div class="wrap"><h1>WPSD Ingest Tools</h1>';
            echo '<h2>Transient キャッシュ削除</h2>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('wpsd_clear_transient', '_wpnonce');
            echo '<input type="hidden" name="action" value="wpsd_clear_transient" />';
            echo '<p><label>Callsign: <input type="text" name="callsign" /></label></p>';
            echo '<p><button class="button">このコールサインのキャッシュ削除</button></p>';
            echo '</form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:1em">';
            wp_nonce_field('wpsd_clear_all_transients', '_wpnonce');
            echo '<input type="hidden" name="action" value="wpsd_clear_all_transients" />';
            echo '<p><button class="button button-danger">すべてのコールサインキャッシュ削除</button></p>';
            echo '</form>';
            echo '<hr /><h2>user.csv キャッシュ操作</h2>';
            $upload_dir = wp_upload_dir();
            $csv_path = trailingslashit($upload_dir['basedir']).'wpsd/_cache/user.csv';
            echo '<p>CSV パス: '.esc_html($csv_path).'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:0.5em">';
            wp_nonce_field('wpsd_delete_csv_cache', '_wpnonce');
            echo '<input type="hidden" name="action" value="wpsd_delete_csv_cache" />';
            echo '<p><button class="button">user.csv を削除</button></p>';
            echo '</form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:0.5em">';
            wp_nonce_field('wpsd_csv_refresh', '_wpnonce');
            echo '<input type="hidden" name="action" value="wpsd_csv_refresh" />';
            echo '<p><button class="button button-secondary">user.csv を再取得</button></p>';
            echo '</form>';
            echo '</div>';
        }
    );
});

/** 設定: Token Map */
function wpsd_ingest_sanitize_token_map($input) {
    if (!is_array($input)) return [];
    $out = [];
    foreach ($input as $row) {
        if (!is_array($row)) continue;
        $token = isset($row['token']) ? sanitize_text_field($row['token']) : '';
        $node  = isset($row['node'])  ? sanitize_text_field($row['node'])  : '';
        if ($token === '' && $node === '') continue;
        $out[] = ['token' => $token, 'node' => $node];
    }
    return array_values($out);
}
add_action('admin_init', function () {
    register_setting('wpsd_ingest', 'wpsd_ingest_token_map', [
        'type' => 'array', 'sanitize_callback' => 'wpsd_ingest_sanitize_token_map', 'default' => [], 'show_in_rest' => false,
    ]);
    add_settings_section('wpsd_ingest_main', 'Main Settings', null, 'wpsd-ingest');
    add_settings_field('wpsd_ingest_token_map', 'Token Map', function () {
        $entries = get_option('wpsd_ingest_token_map', []);
        if (!is_array($entries)) $entries = [];
        $count = count($entries);
        if ($count === 0) { $entries[] = ['token' => '', 'node' => '']; $count = 1; }
        ?>
        <table class="widefat fixed striped" id="wpsd-token-table">
            <thead><tr><th style="width:45%">Token</th><th style="width:45%">Node</th><th style="width:10%">操作</th></tr></thead>
            <tbody>
            <?php foreach ($entries as $i => $entry):
                $token = isset($entry['token']) ? esc_attr($entry['token']) : '';
                $node  = isset($entry['node'])  ? esc_attr($entry['node'])  : '';
            ?>
                <tr>
                    <td><input type="text" name="wpsd_ingest_token_map[<?php echo esc_attr($i); ?>][token]" value="<?php echo $token; ?>" class="regular-text" /></td>
                    <td><input type="text" name="wpsd_ingest_token_map[<?php echo esc_attr($i); ?>][node]" value="<?php echo $node; ?>" class="regular-text" /></td>
                    <td><button type="button" class="button link-delete wpsd-remove-row" aria-label="この行を削除">削除</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:8px;"><button type="button" class="button button-secondary" id="wpsd-add-row">トークンを追加</button></p>
        <input type="hidden" id="wpsd-next-index" value="<?php echo esc_attr($count); ?>" />
        <script>(function(){
            function nextIndex(){ var el=document.getElementById('wpsd-next-index'); var idx=parseInt(el.value,10)||0; el.value=String(idx+1); return idx; }
            function rowHtml(i){ return '<tr>'+
                '<td><input type="text" name="wpsd_ingest_token_map['+i+'][token]" value="" class="regular-text" /></td>'+
                '<td><input type="text" name="wpsd_ingest_token_map['+i+'][node]" value="" class="regular-text" /></td>'+
                '<td><button type="button" class="button link-delete wpsd-remove-row" aria-label="この行を削除">削除</button></td>'+
                '</tr>'; }
            document.addEventListener('click',function(e){ if(e.target && e.target.classList.contains('wpsd-remove-row')){ e.preventDefault(); if(confirm('このトークン行を削除します。よろしいですか？')){ var tr=e.target.closest('tr'); if(tr) tr.remove(); } }});
            var addBtn=document.getElementById('wpsd-add-row'); if(addBtn){ addBtn.addEventListener('click',function(e){ e.preventDefault(); var tbody=document.querySelector('#wpsd-token-table tbody'); var idx=nextIndex(); tbody.insertAdjacentHTML('beforeend', rowHtml(idx)); }); }
        })();</script>
        <?php
    }, 'wpsd-ingest', 'wpsd_ingest_main');
});

/** 手動更新ハンドラ */
add_action('admin_post_wpsd_csv_refresh', function(){
    if (!current_user_can('manage_options')) wp_die('Permission denied');
    check_admin_referer('wpsd_csv_refresh');
    $res = wpsd_fetch_radioid_csv();
    $query = is_wp_error($res) ? array('wpsd_csv' => 'error') : array('wpsd_csv' => 'ok');
    wp_redirect(add_query_arg($query, wp_get_referer()));
    exit;
});

/** CSV 削除ハンドラ */
add_action('admin_post_wpsd_delete_csv_cache', function(){
    if (!current_user_can('manage_options')) wp_die('Permission denied');
    check_admin_referer('wpsd_delete_csv_cache');
    $upload_dir = wp_upload_dir();
    $csv_path = trailingslashit($upload_dir['basedir']).'wpsd/_cache/user.csv';
    if (file_exists($csv_path)) @unlink($csv_path);
    wp_redirect(add_query_arg(array('csv_deleted' => 1), wp_get_referer()));
    exit;
});

/** Transient 個別削除 */
add_action('admin_post_wpsd_clear_transient', function () {
    if (!current_user_can('manage_options')) wp_die('Permission denied');
    check_admin_referer('wpsd_clear_transient');
    $cs = isset($_POST['callsign']) ? strtoupper(trim($_POST['callsign'])) : '';
    if ($cs !== '') {
        $key = 'dmr_info_' . md5($cs);
        delete_transient($key);
    }
    wp_redirect(add_query_arg(array('cleared' => 1), wp_get_referer()));
    exit;
});

/** Transient 全削除 */
add_action('admin_post_wpsd_clear_all_transients', function () {
    if (!current_user_can('manage_options')) wp_die('Permission denied');
    check_admin_referer('wpsd_clear_all_transients');
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dmr_info_%' OR option_name LIKE '_transient_timeout_dmr_info_%'");
    wp_redirect(add_query_arg(array('cleared_all' => 1), wp_get_referer()));
    exit;
});

/** WP-Cron: 毎日更新 */
register_activation_hook(__FILE__, function(){
    if (!wp_next_scheduled('wpsd_cron_radioid_csv_fetch')) {
        wp_schedule_event(time() + 300, 'daily', 'wpsd_cron_radioid_csv_fetch');
    }
});
register_deactivation_hook(__FILE__, function(){ wp_clear_scheduled_hook('wpsd_cron_radioid_csv_fetch'); });
add_action('wpsd_cron_radioid_csv_fetch', function(){ wpsd_fetch_radioid_csv(); });
