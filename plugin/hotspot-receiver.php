<?php
/**
 * Plugin Name: Hotspot Receiver (データ受取部)
 * Plugin URI:  https://github.com/ji2tab/PiStarSendLOG_V2
 * Description: [フォルダ集約・分離版] 外部ノードからの受信ログをREST APIで受け取り、単一JSONリングバッファへ格納します。名前解決はMaster Baseに委譲。DDNSドメイン(xlx168.mydns.jp)による動的IPホワイトリスト防壁を搭載。
 * Version:     1.2.0
 * Author:      JI2TAB / JJ2YYK
 * License:     GPL2
 */

if (!defined('ABSPATH')) exit;

if (!defined('HLD_MAX_LOG')) {
    define('HLD_MAX_LOG', 60);
}

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
// 名前解決（Master Base へ完全委譲）
// =========================================================================
if (!function_exists('hld_resolve_name')) {
    function hld_resolve_name($callsign) {
        $callsign = strtoupper(trim((string)$callsign));
        if ($callsign === '') return '---';

        if (function_exists('dmr_get_user_by_callsign')) {
            $info = dmr_get_user_by_callsign($callsign);
            if (is_array($info)) {
                $name = trim($info['full_name'] ?? '');
                if ($name === '') {
                    $name = trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? ''));
                }
                if ($name !== '') return $name;
            }
        }
        return '---';
    }
}

// =========================================================================
// ログ受信処理（REST API）
// =========================================================================
add_action('rest_api_init', function () {
    register_rest_route('hotspot', '/ingest', array(
        'methods'             => 'POST',
        'callback'            => 'hld_handle_ingest',
        'permission_callback' => '__return_true',
    ));
});

function hld_handle_ingest(WP_REST_Request $request) {
    // ── 1. 安全なアクセス元検証 (DDNS動的IPホワイトリスト防壁) ──
    $pi_star_ddns = 'xlx168.mydns.jp'; 
    
    // 現在のPi-Starの動的IPをリアルタイムDNS照会
    $current_allowed_ip = gethostbyname($pi_star_ddns);
    
    // アクセスしてきた送信元の実際のIP
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // 送信元IPがDDNSのIPと一致しない場合は、WordPressを動かさずに即座に完全遮断
    if ($current_allowed_ip !== $client_ip && $current_allowed_ip !== $pi_star_ddns) {
        return new WP_Error('forbidden_ip', 'Access denied. Unauthorized source IP.', array('status' => 403));
    }

    // ── 2. 従来のAPIトークン検証 ──
    $saved_token = get_option('hotspot_api_token');
    if (empty($saved_token)) {
        return new WP_Error('no_token_configured', 'APIトークン未設定', array('status' => 500));
    }

    $auth_header = $request->get_header('x-hotspot-token');
    $body_params = $request->get_json_params();

    $client_token = '';
    if (!empty($auth_header)) {
        $client_token = trim($auth_header);
    } elseif (isset($body_params['token'])) {
        $client_token = trim($body_params['token']);
    }

    if (!hash_equals((string)$saved_token, (string)$client_token)) {
        return new WP_Error('unauthorized', '認証エラー', array('status' => 401));
    }

    // ── 3. 各種サニタイズおよびデータパース ──
    $raw_node = $body_params['node'] ?? 'default';
    $node = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field($raw_node));
    if (strpos($node, 'yyg-') === 0) {
        $node = str_replace('yyg-', 'yyk-', $node);
    }
    
    if (empty($node)) {
        return new WP_Error('invalid_data', 'ノード名未指定', array('status' => 400));
    }

    $callsign = strtoupper(trim($body_params['callsign'] ?? ''));
    if ($callsign === '') {
        return rest_ensure_response(array('status' => 'skipped', 'reason' => 'no callsign'));
    }

    $resolved_name = hld_resolve_name($callsign);

    $raw_time = $body_params['timestamp'] ?? current_time('mysql');
    $ts_unix  = strtotime($raw_time);
    if (!$ts_unix) $ts_unix = current_time('timestamp');
    $formatted = date('Y-m-d H:i:s', $ts_unix);

    // Pythonから送信される端末健康状態(device_info)をパース
    $raw_device_info = $body_params['device_info'] ?? array();
    $device_info = array(
        'cpu_temp'     => sanitize_text_field($raw_device_info['cpu_temp'] ?? '---'),
        'memory_usage' => sanitize_text_field($raw_device_info['memory_usage'] ?? '---'),
        'disk_free'    => sanitize_text_field($raw_device_info['disk_free'] ?? '---')
    );

    $entry = array(
        'node'            => $node,
        'source_node'     => $body_params['source_node'] ?? $node,
        'net_label'       => sanitize_text_field($body_params['net_label'] ?? ''), 
        'callsign'        => $callsign,
        'timestamp'       => $formatted,
        'timestamp_local' => $formatted,
        'dmr'             => array(
            'slot' => isset($body_params['dmr']['slot']) ? (int)$body_params['dmr']['slot'] : 2,
            'src'  => strtolower($body_params['dmr']['src'] ?? 'rf'),
            'dst'  => sanitize_text_field($body_params['dmr']['dst'] ?? '1'),
        ),
        'name'            => $resolved_name,
        'device_info'     => $device_info
    );

    // ── 4. リングバッファ書き込み ──
    $logs = hld_read_log($node);
    $call = strtoupper(trim($entry['callsign'] ?? ''));
    $logs = array_values(array_filter($logs, function ($r) use ($call) {
        return strtoupper(trim($r['callsign'] ?? '')) !== $call;
    }));
    array_unshift($logs, $entry);
    hld_write_log($node, $logs);

    return rest_ensure_response(array('status' => 'success', 'name' => $resolved_name));
}
