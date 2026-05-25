<?php
/**
 * Plugin Name: Hotspot Display (表示＆管理ツール部)
 * Plugin URI:  https://github.com/ji2tab/PiStarSendLOG_V2
 * Description: [フォルダ集約・分離版] 格納されたJSONログをショートコードでWEB表示（自動・手動更新付き）し、管理画面の「ログデータ管理」タブからデータのインライン編集や削除を行えます。（レスポンシブ強制＆Ajax・IDロックバグ修正版 v1.1.5）
 * Version:     1.1.5
 * Author:      JI2TAB / JJ2YYK
 * License:     GPL2
 */

if (!defined('ABSPATH')) exit;

if (!defined('HLD_MAX_LOG')) {
    define('HLD_MAX_LOG', 60);
}
define('HLD_AUTO_REFRESH_SEC', 60);

// =========================================================================
// 共通関数：パス解決 & リングバッファ I/O
// =========================================================================
if (!function_exists('hld_get_base_dir')) {
    function hld_get_base_dir() {
        $dir = WP_CONTENT_DIR . '/uploads/hotspot';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }
}

if (!function_exists('hld_log_path')) {
    function hld_log_path($node) {
        $dir = hld_get_base_dir();
        $safe_node = preg_replace('/[^a-zA-Z0-9_\-]/', '', $node);
        if (strpos($safe_node, 'yyg-') === 0) {
            $safe_node = str_replace('yyg-', 'yyk-', $safe_node);
        }
        return $dir . '/log-' . $safe_node . '.json';
    }
}

if (!function_exists('hld_read_log')) {
    function hld_read_log($node) {
        $path = hld_log_path($node);
        if (!file_exists($path)) return array();
        $arr = json_decode((string)file_get_contents($path), true);
        return is_array($arr) ? $arr : array();
    }
}

if (!function_exists('hld_write_log')) {
    function hld_write_log($node, array $logs) {
        if (count($logs) > HLD_MAX_LOG) {
            $logs = array_slice($logs, 0, HLD_MAX_LOG);
        }
        file_put_contents(
            hld_log_path($node),
            wp_json_encode($logs, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

// =========================================================================
// 管理画面設定 & ログ編集・削除ツール
// =========================================================================
add_action('admin_menu', function () {
    add_options_page('Hotspot 表示/受信設定', 'Hotspot 設定', 'manage_options', 'hotspot-display-settings', 'hld_settings_page');
});

add_action('admin_init', function () {
    register_setting('hld_group', 'hotspot_default_node');
    register_setting('hld_group', 'hotspot_api_token');
    
    if (isset($_POST['hld_manage_action']) && current_user_can('manage_options')) {
        hld_handle_log_management();
    }
});

function hld_settings_page() {
    $api_ok = function_exists('dmr_get_user_by_callsign');
    $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
    ?>
    <div class="wrap">
        <h1>Hotspot Receiver &amp; Display 設定 <span style="font-size:13px;color:#888;">[分離版 v1.1.5]</span></h1>

        <h2 class="nav-tab-wrapper">
            <a href="?page=hotspot-display-settings&tab=settings" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">基本設定</a>
            <a href="?page=hotspot-display-settings&tab=logs" class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">ログデータ管理</a>
        </h2>

        <?php if ($current_tab === 'settings') : ?>
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;margin:16px 0;max-width:680px;">
                <strong>名前解決エンジン:</strong>
                <?php echo $api_ok
                    ? '<span style="color:green;font-weight:bold;">&#10003; Master Base 連携 OK</span>'
                    : '<span style="color:#d63638;font-weight:bold;">&#10007; Master Base 未検出。「DMR CSV Master Base」を有効化してください。</span>'; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('hld_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">規定の表示ノード</th>
                        <td><input type="text" name="hotspot_default_node" value="<?php echo esc_attr(get_option('hotspot_default_node')); ?>" class="regular-text" placeholder="yyk-tgif"></td>
                    </tr>
                    <tr>
                        <th scope="row">APIトークン</th>
                        <td><input type="password" name="hotspot_api_token" value="<?php echo esc_attr(get_option('hotspot_api_token')); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

        <?php elseif ($current_tab === 'logs') : ?>
            <?php hld_render_log_management_page(); ?>
        <?php endif; ?>
    </div>
    <?php
}

function hld_render_log_management_page() {
    $dir = hld_get_base_dir();
    $nodes = array();
    if (is_dir($dir)) {
        $files = glob($dir . '/log-*.json');
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/^log-(.+)\.json$/', $filename, $matches)) {
                $nodes[] = $matches[1];
            }
        }
    }

    $raw_target = isset($_REQUEST['target_node']) ? sanitize_text_field($_REQUEST['target_node']) : '';
    $sanitized_target = preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw_target);
    if (strpos($sanitized_target, 'yyg-') === 0) {
        $sanitized_target = str_replace('yyg-', 'yyk-', $sanitized_target);
    }
    
    $default_node = get_option('hotspot_default_node') ?: ($nodes[0] ?? '');
    if (strpos($default_node, 'yyg-') === 0) {
        $default_node = str_replace('yyg-', 'yyk-', $default_node);
    }
    
    $selected_node = !empty($sanitized_target) ? $sanitized_target : $default_node;
    $logs = !empty($selected_node) ? hld_read_log($selected_node) : array();
    ?>
    <div style="margin-top:20px;">
        <form method="get" action="options-general.php" style="margin-bottom:20px; background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:4px;">
            <input type="hidden" name="page" value="hotspot-display-settings">
            <input type="hidden" name="tab" value="logs">
            <label for="target_node_select" style="font-weight:bold; margin-right:10px;">編集するノードを選択:</label>
            <select id="target_node_select" name="target_node" onchange="this.form.submit()">
                <?php if (empty($nodes)): ?>
                    <option value="">（ログファイルが存在しません）</option>
                <?php else: ?>
                    <?php foreach ($nodes as $n): 
                        $clean_n = (strpos($n, 'yyg-') === 0) ? str_replace('yyg-', 'yyk-', $n) : $n;
                    ?>
                        <option value="<?php echo esc_attr($clean_n); ?>" <?php selected($selected_node, $clean_n); ?>><?php echo esc_html($clean_n); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </form>

        <?php if (!empty($selected_node)): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h3>ノード 「<?php echo esc_html($selected_node); ?>」 の格納データ （全 <?php echo count($logs); ?> 件）</h3>
                <?php if (!empty($logs)): ?>
                    <form method="post" action="" onsubmit="return confirm('本当にこのノードのログをすべて削除しますか？');">
                        <?php wp_nonce_field('hld_bulk_delete_' . $selected_node, 'hld_manage_nonce'); ?>
                        <input type="hidden" name="hld_manage_action" value="bulk_delete">
                        <input type="hidden" name="target_node" value="<?php echo esc_attr($selected_node); ?>">
                        <input type="submit" class="button button-link-delete" style="color:#d63638;" value="✕ このノードの全ログを削除">
                    </form>
                <?php endif; ?>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('hld_update_logs_' . $selected_node, 'hld_manage_nonce'); ?>
                <input type="hidden" name="hld_manage_action" value="update_records">
                <input type="hidden" name="target_node" value="<?php echo esc_attr($selected_node); ?>">

                <table class="wp-list-table widefat fixed striped table-view-list" style="max-width:100%;">
                    <thead>
                        <tr>
                            <th style="width:50px;">No.</th>
                            <th style="width:120px;">Callsign</th>
                            <th>Name (編集可)</th>
                            <th style="width:130px;">Net Label (編集可)</th>
                            <th style="width:180px;">Timestamp (編集可)</th>
                            <th style="width:80px; text-align:center;">削除</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px; color:#888;">データがありません。ノード名またはファイルの配置場所をご確認ください。</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $index => $row): 
                                $call = strtoupper(trim($row['callsign'] ?? '---'));
                                $name = trim($row['name'] ?? '');
                                $net_label = trim($row['net_label'] ?? '');
                                $ts   = $row['timestamp_local'] ?? ($row['timestamp'] ?? '');
                            ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo esc_html($call); ?></strong></td>
                                    <td><input type="text" name="logs[<?php echo $index; ?>][name]" value="<?php echo esc_attr($name); ?>" class="large-text" style="margin:0;"></td>
                                    <td>
                                        <input type="text" name="logs[<?php echo $index; ?>][net_label]" value="<?php echo esc_attr($net_label); ?>" class="small-text" style="margin:0; width:90px;">
                                    </td>
                                    <td><input type="text" name="logs[<?php echo $index; ?>][timestamp]" value="<?php echo esc_attr($ts); ?>" class="large-text" style="margin:0; font-family:monospace;"></td>
                                    <td style="text-align:center;"><input type="checkbox" name="logs[<?php echo $index; ?>][delete]" value="1" style="border-color:#d63638;"></td>
                                    <input type="hidden" name="logs[<?php echo $index; ?>][callsign]" value="<?php echo esc_attr($call); ?>">
                                    <input type="hidden" name="logs[<?php echo $index; ?>][node]" value="<?php echo esc_attr($row['node'] ?? $selected_node); ?>">
                                    <input type="hidden" name="logs[<?php echo $index; ?>][source_node]" value="<?php echo esc_attr($row['source_node'] ?? ''); ?>">
                                    <input type="hidden" name="logs[<?php echo $index; ?>][slot]" value="<?php echo esc_attr($row['dmr']['slot'] ?? 2); ?>">
                                    <input type="hidden" name="logs[<?php echo $index; ?>][src]" value="<?php echo esc_attr($row['dmr']['src'] ?? 'rf'); ?>">
                                    <input type="hidden" name="logs[<?php echo $index; ?>][dst]" value="<?php echo esc_attr($row['dmr']['dst'] ?? '1'); ?>">
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if (!empty($logs)): ?>
                    <div style="margin-top:15px; text-align:right;">
                        <p style="color:#666; font-size:12px; margin-bottom:5px;">※ 削除チェックを入れた行は除外され、それ以外の行の変更が保存されます。</p>
                        <?php submit_button('変更内容を保存 (選択行の削除を含む)', 'primary', 'submit', false); ?>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function hld_handle_log_management() {
    $raw_node = isset($_POST['target_node']) ? sanitize_text_field($_POST['target_node']) : '';
    $node = preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw_node);
    if (strpos($node, 'yyg-') === 0) {
        $node = str_replace('yyg-', 'yyk-', $node);
    }
    if (empty($node)) return;

    $action = $_POST['hld_manage_action'];

    if ($action === 'bulk_delete') {
        check_admin_referer('hld_bulk_delete_' . $node, 'hld_manage_nonce');
        hld_write_log($node, array()); 
        add_settings_error('hld_group', 'bulk_deleted', "ノード「{$node}」のログを全削除しました。", 'updated');
    } 
    elseif ($action === 'update_records') {
        check_admin_referer('hld_update_logs_' . $node, 'hld_manage_nonce');
        $input_logs = $_POST['logs'] ?? array();
        $updated_logs = array();

        foreach ($input_logs as $item) {
            if (isset($item['delete']) && $item['delete'] == '1') {
                continue;
            }

            $item_node = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field($item['node']));
            if (strpos($item_node, 'yyg-') === 0) {
                $item_node = str_replace('yyg-', 'yyk-', $item_node);
            }

            $updated_logs[] = array(
                'node'            => $item_node,
                'source_node'     => sanitize_text_field($item['source_node']),
                'net_label'       => sanitize_text_field($item['net_label']), 
                'callsign'        => strtoupper(trim(sanitize_text_field($item['callsign']))),
                'timestamp'       => sanitize_text_field($item['timestamp']),
                'timestamp_local' => sanitize_text_field($item['timestamp']),
                'dmr'             => array(
                    'slot' => (int)$item['slot'],
                    'src'  => strtolower(sanitize_text_field($item['src'])),
                    'dst'  => sanitize_text_field($item['dst']),
                ),
                'name'            => sanitize_text_field($item['name']),
            );
        }
        hld_write_log($node, $updated_logs);
        add_settings_error('hld_group', 'logs_updated', "ノード「{$node}」のログを更新しました。", 'updated');
    }
}

// =========================================================================
// ショートコード表示 & AJAX更新処理
// =========================================================================
add_shortcode('hotspot_log_display', function ($atts) {
    nocache_headers();
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

    $raw_node = isset($atts['node']) ? sanitize_text_field($atts['node']) : get_option('hotspot_default_node');
    $node = preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw_node);
    if (strpos($node, 'yyg-') === 0) {
        $node = str_replace('yyg-', 'yyk-', $node);
    }
    if (!$node) return '<p style="color:red;">ノードが設定されていません。</p>';

    if (function_exists('hld_migrate_legacy')) {
        hld_migrate_legacy($node);
    }

    $rows  = hld_read_log($node);
    $nonce = wp_create_nonce('hld_refresh');
    $ajax  = esc_url(admin_url('admin-ajax.php'));

    ob_start();
    ?>
    <style>
    /* ========================================================
       Hotspot Log Display - レスポンシブ強制版 (v1.1.5)
       テーマCSSに勝つため !important を多用
       横スクロールを禁止し、長いセルは改行して必ず親幅に収める
       ======================================================== */

    /* 一番外側の枠：センター寄せ＆親幅に追従 */
    .hts-log-wrap{
        display: block !important;
        max-width: 1000px !important;     /* お好みで 800〜1200px */
        width: 100% !important;
        margin: 0 auto !important;
        padding: 0 8px !important;
        box-sizing: border-box !important;
        float: none !important;
        clear: both !important;
        overflow: visible !important;
    }

    /* IDロックを回避するための新しいクラス指定に変更 */
    .hts-log-wrap .hts-log-engine,
    .hts-log-engine{
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        padding: 0 !important;
        overflow-x: visible !important;   /* auto をやめる */
        overflow: visible !important;
        box-sizing: border-box !important;
    }

    /* テーブル本体：必ず親幅に収まる */
    .hts-log-wrap .hts-log-table{
        display: table !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;   /* 列幅をブラウザに勝手に決めさせない */
        font-size: 14px;
        line-height: 1.4;
        word-break: break-word;
    }

    /* 列幅を「比率」で指定（合計100%）。table-layout:fixed と組み合わせで効く */
    .hts-log-wrap .hts-log-table col.c-no   { width: 6%; }
    .hts-log-wrap .hts-log-table col.c-call { width: 22%; }
    .hts-log-wrap .hts-log-table col.c-name { width: 30%; }
    .hts-log-wrap .hts-log-table col.c-src  { width: 12%; }
    .hts-log-wrap .hts-log-table col.c-date { width: 16%; }
    .hts-log-wrap .hts-log-table col.c-time { width: 14%; }

    .hts-log-wrap .hts-log-table th,
    .hts-log-wrap .hts-log-table td{
        padding: 6px 8px !important;
        border-bottom: 1px solid #e3e3e3 !important;
        text-align: left;
        vertical-align: middle;
        white-space: normal !important;   /* 改行を許可 */
        word-break: break-word !important;
        overflow-wrap: anywhere !important;
        box-sizing: border-box !important;
    }
    .hts-log-wrap .hts-log-table thead th{
        background:#f5f5f5 !important;
        border-bottom: 2px solid #ccc !important;
        font-weight: 600;
    }

    /* 中身のspanも改行を許す（white-space:nowrap を上書き） */
    .hts-log-wrap .hts-call,
    .hts-log-wrap .hts-name,
    .hts-log-wrap .hts-time,
    .hts-log-wrap .hts-badge{
        white-space: normal !important;
        word-break: break-word !important;
        overflow-wrap: anywhere !important;
        display: inline-block;
        max-width: 100%;
    }

    .hts-log-wrap .hts-no   { text-align:right; color:#888; font-variant-numeric: tabular-nums; }
    .hts-log-wrap .hts-call { font-weight:700; font-family: ui-monospace, Menlo, Consolas, monospace; }
    .hts-log-wrap .hts-time { font-family: ui-monospace, Menlo, Consolas, monospace; }
    .hts-log-wrap .hts-badge{
        padding:2px 8px; border-radius:10px;
        font-size:12px; font-weight:600; line-height:1.4;
    }
    .hts-log-wrap .hts-badge-rf{ background:#e8f1fb; color:#1a5fb4; border:1px solid #b6d4f0; }
    .hts-log-wrap .hts-badge-nw{ background:#fdecec; color:#a4262c; border:1px solid #f3b7b7; }

    /* スマホ：狭い時はフォントを縮め、必要に応じて列幅を再配分 */
    @media (max-width: 720px){
        .hts-log-wrap{ padding: 0 4px !important; }
        .hts-log-wrap .hts-log-table{ font-size: 12px; }
        .hts-log-wrap .hts-log-table th,
        .hts-log-wrap .hts-log-table td{ padding: 4px 5px !important; }
        .hts-log-wrap .hts-log-table col.c-no   { width: 8%; }
        .hts-log-wrap .hts-log-table col.c-call { width: 24%; }
        .hts-log-wrap .hts-log-table col.c-name { width: 24%; }
        .hts-log-wrap .hts-log-table col.c-src  { width: 14%; }
        .hts-log-wrap .hts-log-table col.c-date { width: 16%; }
        .hts-log-wrap .hts-log-table col.c-time { width: 14%; }
    }
    @media (max-width: 480px){
        .hts-log-wrap .hts-log-table{ font-size: 11px; }
        .hts-log-wrap .hts-badge{ font-size: 10px; padding: 1px 5px; }
    }
    </style>
    <div class="hts-log-wrap">
        <div class="hts-toolbar" style="display:flex;justify-content:flex-end;align-items:center;gap:10px;margin:0 0 8px;">
            <span id="hts-status" style="font-size:12px;color:#888;"></span>
            <button id="hts-reload-btn" type="button" style="padding:6px 14px;border:1px solid #0073aa;background:#0073aa;color:#fff;border-radius:4px;cursor:pointer;font-size:13px;line-height:1;">&#x21bb; 今すぐ更新</button>
        </div>
        <div class="hts-log-engine">
            <?php echo hld_generate_table_html($rows); ?>
        </div>
    </div>
    <script>
    (function () {
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var node  = <?php echo wp_json_encode($node); ?>;
        var ajax  = <?php echo wp_json_encode($ajax); ?>;
        var intMs = <?php echo (int) HLD_AUTO_REFRESH_SEC; ?> * 1000;
        var btn   = document.getElementById('hts-reload-btn');
        var stat  = document.getElementById('hts-status');
        var busy  = false, timer = null;

        function fetchLog(manual) {
            if (busy) return;
            busy = true;
            if (manual && btn) { btn.disabled = true; btn.textContent = '更新中...'; }
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajax, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.timeout = 15000;
            xhr.onload = function () {
                busy = false;
                if (btn) { btn.disabled = false; btn.innerHTML = '&#x21bb; 今すぐ更新'; }
                if (xhr.status === 200 && xhr.responseText) {
                    // ID固定を解除し、クラスで要素を取得するように変更
                    var c = document.querySelector('.hts-log-engine');
                    if (c) c.innerHTML = xhr.responseText;
                    if (stat) {
                        var d = new Date();
                        stat.textContent = '最終更新 ' + ('0'+d.getHours()).slice(-2) + ':' + ('0'+d.getMinutes()).slice(-2) + ':' + ('0'+d.getSeconds()).slice(-2);
                    }
                }
            };
            xhr.onerror = xhr.ontimeout = function () {
                busy = false;
                if (btn) { btn.disabled = false; btn.innerHTML = '&#x21bb; 今すぐ更新'; }
                if (stat) stat.textContent = '更新失敗';
            };
            xhr.send('action=hld_refresh_log&nonce=' + encodeURIComponent(nonce) + '&node=' + encodeURIComponent(node));
        }

        if (btn) btn.addEventListener('click', function () { fetchLog(true); });

        function startTimer() {
            if (timer) return;
            timer = setInterval(function () {
                if (document.visibilityState === 'visible') fetchLog(false);
            }, intMs);
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') { fetchLog(false); startTimer(); }
        });
        startTimer();
    })();
    </script>
    <?php
    return ob_get_clean();
});

add_action('wp_ajax_hld_refresh_log', 'hld_ajax_refresh_log');
add_action('wp_ajax_nopriv_hld_refresh_log', 'hld_ajax_refresh_log');

function hld_ajax_refresh_log() {
    check_ajax_referer('hld_refresh', 'nonce');
    $raw_node = isset($_POST['node']) ? sanitize_text_field($_POST['node']) : '';
    $node = preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw_node);
    if (strpos($node, 'yyg-') === 0) {
        $node = str_replace('yyg-', 'yyk-', $node);
    }
    if (!$node) wp_die('');
    echo hld_generate_table_html(hld_read_log($node));
    wp_die();
}

// =========================================================================
// 7. テーブル出力マッピング（新ルール：TG条件付き判定）
// =========================================================================
function hld_generate_table_html($rows) {
    // <colgroup> で列幅をCSSの col.c-xxx に委ねる
    $out  = '<table class="hts-log-table">';
    $out .= '<colgroup>';
    $out .= '<col class="c-no"><col class="c-call"><col class="c-name"><col class="c-src"><col class="c-date"><col class="c-time">';
    $out .= '</colgroup>';
    $out .= '<thead><tr><th>No.</th><th>Callsign</th><th>Name</th><th>Src</th><th>Date</th><th>Time</th></tr></thead><tbody>';

    if (empty($rows)) {
        $out .= '<tr><td colspan="6" style="padding:18px;text-align:center;color:#888;">表示できるログはまだありません。</td></tr>';
        return $out . '</tbody></table>';
    }

    $dedup = array();
    $seen  = array();
    foreach ($rows as $d) {
        $c = strtoupper(trim($d['callsign'] ?? ''));
        if ($c === '' || isset($seen[$c])) continue;
        $seen[$c] = true;
        $dedup[]  = $d;
    }
    $rows = $dedup;

    foreach ($rows as $i => $d) {
        $raw_ts = !empty($d['timestamp_local']) ? $d['timestamp_local'] : ($d['timestamp'] ?? '');
        $ts     = !empty($raw_ts) ? strtotime($raw_ts) : time();

        $call      = esc_html(strtoupper(trim($d['callsign'] ?? '---')));
        $raw_src   = strtoupper(sanitize_text_field($d['dmr']['src'] ?? ''));
        $is_nw     = ($raw_src === 'NETWORK');
        
        // 宛先トークグループ（dst）を取得（デフォルトは1）
        $dst_tg    = trim($d['dmr']['dst'] ?? '1');

        // --- 条件分岐表示ルール（TG1ならシンプル表記、TG1以外なら結合表記） ---
        $base_label = $is_nw ? 'NET' : 'RF';
        if ($dst_tg === '1' || $dst_tg === '') {
            $src_label = $base_label;
        } else {
            $src_label = $base_label . $dst_tg;
        }

        $name = (!empty($d['name']) && $d['name'] !== '---') ? esc_html(trim($d['name'])) : '---';
        $badge_class = $is_nw ? 'hts-badge-nw' : 'hts-badge-rf';

        $out .= '<tr>';
        $out .= '<td class="hts-no">' . ($i + 1) . '</td>';
        $out .= '<td><span class="hts-call">' . $call . '</span></td>';
        $out .= '<td><span class="hts-name">' . $name . '</span></td>';
        $out .= '<td><span class="hts-badge ' . $badge_class . '">' . $src_label . '</span></td>';
        $out .= '<td><span class="hts-time">' . date('Y-m-d', $ts) . '</span></td>';
        $out .= '<td><span class="hts-time">' . date('H:i:s', $ts) . '</span></td>';
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

// =========================================================================
// 旧 inbox-*.json 移行ロジック（後方互換用）
// =========================================================================
if (!function_exists('hld_migrate_legacy')) {
    function hld_migrate_legacy($node) {
        $safe_node = preg_replace('/[^a-zA-Z0-9_\-]/', '', $node);
        if (strpos($safe_node, 'yyg-') === 0) {
            $safe_node = str_replace('yyg-', 'yyk-', $safe_node);
        }
        $base = hld_get_base_dir() . '/' . $safe_node;
        if (!is_dir($base)) return;

        $marker = $base . '/.migrated';
        if (file_exists($marker)) return;

        $files = glob($base . '/inbox-*.json');
        if (empty($files)) {
            @file_put_contents($marker, date('c'));
            return;
        }

        usort($files, function ($a, $b) { return filemtime($b) - filemtime($a); });

        $current = hld_read_log($node);

        $seen = array();
        foreach ($current as $r) {
            $c = strtoupper(trim($r['callsign'] ?? ''));
            if ($c !== '') $seen[$c] = true;
        }

        $legacy = array();
        foreach ($files as $f) {
            $d = json_decode((string)file_get_contents($f), true);
            if (!is_array($d)) continue;

            $call = strtoupper(trim($d['callsign'] ?? ''));
            if ($call === '' || isset($seen[$call])) continue;
            $seen[$call] = true;

            $name = trim($d['name'] ?? '');
            if (($name === '' || $name === '---') && function_exists('hld_resolve_name')) {
                $name = hld_resolve_name($call);
            }

            $legacy[] = array(
                'node'            => $node,
                'source_node'     => $d['source_node'] ?? $node,
                'net_label'       => $d['net_label'] ?? '',
                'callsign'        => $call,
                'timestamp'       => $d['timestamp'] ?? ($d['timestamp_local'] ?? ''),
                'timestamp_local' => $d['timestamp_local'] ?? ($d['timestamp'] ?? ''),
                'dmr'             => array(
                    'slot' => isset($d['dmr']['slot']) ? (int)$d['dmr']['slot'] : 2,
                    'src'  => strtolower($d['dmr']['src'] ?? 'rf'),
                    'dst'  => $d['dmr']['dst'] ?? '1',
                ),
                'name'            => $name,
            );

            if (count($current) + count($legacy) >= HLD_MAX_LOG) break;
        }

        if (!empty($legacy)) {
            hld_write_log($node, array_merge($current, $legacy));
        }

        @file_put_contents($marker, date('c'));
    }
}
