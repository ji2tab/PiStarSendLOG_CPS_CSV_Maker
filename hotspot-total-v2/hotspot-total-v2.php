<?php
/*
Plugin Name: Hotspot Total Solution V2
Description: 決定版：自動ファイル整理・高機能表示UI。ショートコード: [hotspot_log_display]
Version: 2.4.12
Author: JI2TAB
*/

if (!defined('ABSPATH')) exit;

// 1. 管理画面設定
add_action('admin_menu', function() {
    add_options_page('Hotspot 設定', 'Hotspot 設定', 'manage_options', 'hotspot-settings', 'hts_settings_page');
});

add_action('admin_init', function() {
    register_setting('hts_group', 'hotspot_token_map');
    register_setting('hts_group', 'hotspot_default_node');
});

function hts_settings_page() {
    $token_map = get_option('hotspot_token_map', [['token'=>'','node'=>'']]);
    ?>
    <div class="wrap">
        <h1>Hotspot Total Solution 設定</h1>
        <p>APIエンドポイント: <code><?php echo esc_url(rest_url('hotspot/ingest')); ?></code></p>
        <?php
        $csv = hts_csv_path();
        $updated = get_option('hts_csv_updated');
        if (file_exists($csv)) {
            $last_mod = get_option('hts_csv_last_modified');
            $mod_str  = $last_mod ? wp_date('Y-m-d H:i', $last_mod) : '不明';
            echo '<p>RadioID CSV: <strong>取得済み</strong>（radioid.net 公開日時: ' . $mod_str . ' / 取得日時: ' . wp_date('Y-m-d H:i', $updated) . '）';
            echo ' &nbsp;<a href="' . esc_url(admin_url('options-general.php?page=hotspot-settings&hts_fetch_csv=1')) . '" class="button button-small">今すぐ更新</a></p>';
        } else {
            echo '<p style="color:red;">RadioID CSVが未取得です。';
            echo ' <a href="' . esc_url(admin_url('options-general.php?page=hotspot-settings&hts_fetch_csv=1')) . '" class="button button-small">今すぐ取得</a></p>';
        }
        // 手動取得ボタン処理
        if (isset($_GET['hts_fetch_csv']) && current_user_can('manage_options')) {
            $result = hts_fetch_radioid_csv();
            if ($result) {
                $lm = get_option('hts_csv_last_modified');
                $lm_str = $lm ? wp_date('Y-m-d H:i', $lm) : '不明';
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo 'CSVの取得・更新に成功しました。radioid.net 公開日時: <strong>' . $lm_str . '</strong>';
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo 'CSV取得に失敗しました。サーバーのネット接続を確認してください。';
                echo '</p></div>';
            }
        }
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('hts_group'); ?>
            <table id="hts-table" class="widefat fixed striped" style="margin-bottom:20px;">
                <thead><tr><th>トークン</th><th>ノード名(フォルダ名)</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($token_map as $i => $e): ?>
                    <tr>
                        <td><input type="text" name="hotspot_token_map[<?php echo $i; ?>][token]" value="<?php echo esc_attr($e['token']); ?>" class="regular-text"></td>
                        <td><input type="text" name="hotspot_token_map[<?php echo $i; ?>][node]" value="<?php echo esc_attr($e['node']); ?>" class="regular-text"></td>
                        <td><button type="button" class="hts-remove button">削除</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" id="hts-add" class="button">行を追加</button>
            <p style="margin-top:20px;">規定の表示ノード: <input type="text" name="hotspot_default_node" value="<?php echo esc_attr(get_option('hotspot_default_node')); ?>" class="regular-text" placeholder="yyk-tgif"></p>
            <?php submit_button(); ?>
        </form>
        <script>
            document.getElementById('hts-add').addEventListener('click', function() {
                var t = document.querySelector('#hts-table tbody'), i = t.rows.length, r = t.insertRow();
                r.innerHTML = '<td><input type="text" name="hotspot_token_map['+i+'][token]" class="regular-text"></td><td><input type="text" name="hotspot_token_map['+i+'][node]" class="regular-text"></td><td><button type="button" class="hts-remove button">削除</button></td>';
            });
            document.addEventListener('click', function(e){ if(e.target.classList.contains('hts-remove')) e.target.closest('tr').remove(); });
        </script>
    </div>
    <?php
}


// ── RadioID CSV 管理 ──────────────────────────────────────────

// CSVキャッシュファイルのパスを返す
function hts_csv_path() {
    $dir = trailingslashit(wp_upload_dir()['basedir']) . 'hotspot/_cache';
    if (!file_exists($dir)) wp_mkdir_p($dir);
    return $dir . '/user.csv';
}

// CSVをダウンロードして保存（WP-Cronから呼ばれる）
function hts_fetch_radioid_csv() {
    $response = wp_remote_get('https://radioid.net/static/user.csv', ['timeout' => 60]);
    if (is_wp_error($response)) return false;
    $body = wp_remote_retrieve_body($response);
    if (strpos($body, 'RADIO_ID') === false) return false;
    file_put_contents(hts_csv_path(), $body);
    update_option('hts_csv_updated', time());

    // radioid.net の Last-Modified ヘッダーを保存
    $last_modified = wp_remote_retrieve_header($response, 'last-modified');
    if ($last_modified) {
        $ts = strtotime($last_modified);
        if ($ts) update_option('hts_csv_last_modified', $ts);
    }
    return true;
}
add_action('hts_daily_csv_update', 'hts_fetch_radioid_csv');

// WP-Cronスケジュール登録
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('hts_daily_csv_update')) {
        wp_schedule_event(time(), 'daily', 'hts_daily_csv_update');
    }
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('hts_daily_csv_update');
});

// コールサインでCSVを検索して名前を返す
function hts_lookup_name($callsign) {
    $csv = hts_csv_path();
    if (!file_exists($csv)) return '';

    $callsign = strtoupper(trim($callsign));
    $fh = fopen($csv, 'r');
    if (!$fh) return '';

    // ヘッダ行でカラム位置を確認
    // 形式: RADIO_ID,CALLSIGN,FIRST_NAME,LAST_NAME,CITY,STATE,COUNTRY,REMARKS
    $header = fgetcsv($fh);
    $col_call  = array_search('CALLSIGN',   $header);
    $col_first = array_search('FIRST_NAME', $header);
    $col_last  = array_search('LAST_NAME',  $header);
    if ($col_call === false) { fclose($fh); return ''; }

    $name = '';
    while (($row = fgetcsv($fh)) !== false) {
        if (!isset($row[$col_call])) continue;
        if (strtoupper(trim($row[$col_call])) === $callsign) {
            $first = isset($row[$col_first]) ? trim($row[$col_first]) : '';
            $last  = isset($row[$col_last])  ? trim($row[$col_last])  : '';
            $name  = trim($first . ' ' . $last);
            break;
        }
    }
    fclose($fh);
    return $name;
}

// 2. 受信API (物理削除ロジックを修正)
add_action('rest_api_init', function () {
    register_rest_route('hotspot', '/ingest', [
        'methods' => 'POST',
        'callback' => 'hts_api_handler',
        'permission_callback' => '__return_true'
    ]);
});

function hts_api_handler($request) {
    $token = $request->get_header('x-hotspot-token');
    $map = get_option('hotspot_token_map', []);
    $node = null;
    foreach($map as $ent) if(($ent['token'] ?? '') === $token) $node = sanitize_key($ent['node']);
    
    if(!$node) return new WP_Error('no_token', 'Invalid Token', ['status'=>403]);

    $data = $request->get_json_params();
    // コールサイン取得の優先順位を強化
    $callsign = strtoupper($data['callsign'] ?? ($data['dmr']['callsign'] ?? ''));
    if(!$callsign) return new WP_Error('no_call', 'No Callsign Received', ['status'=>400]);

    $data['callsign'] = $callsign;
    $data['name']      = hts_lookup_name($callsign); // RadioID CSV から氏名を付加
    $data['timestamp_local'] = current_time('mysql');

    $dir = trailingslashit(wp_upload_dir()['basedir']) . 'hotspot/' . $node;
    if(!file_exists($dir)) wp_mkdir_p($dir);

    // 新着データのSrcを取得
    $new_src = strtoupper($data['dmr']['src'] ?? 'RF');

    // 同じコールサインの既存ファイルを検索
    $files = glob($dir . '/inbox-*.json');
    $existing_file = null;
    $existing_src  = 'RF';
    foreach($files as $f) {
        $old_data = json_decode(file_get_contents($f), true);
        $old_call = strtoupper($old_data['callsign'] ?? ($old_data['dmr']['callsign'] ?? ''));
        if($old_call === $callsign) {
            $existing_file = $f;
            $existing_src  = strtoupper($old_data['dmr']['src'] ?? 'RF');
            break;
        }
    }

    // NW優先ルール（5秒以内の同時受信を想定）:
    // 既存がNWで新着がRF → 5秒以内なら既存NWを維持、5秒以上なら新しい交信としてRFで上書き
    if($existing_file && $existing_src === 'NETWORK' && $new_src !== 'NETWORK') {
        $existing_ts  = filemtime($existing_file);
        $diff_seconds = abs(time() - $existing_ts);
        if($diff_seconds <= 5) {
            return ['ok'=>true, 'node'=>$node, 'callsign'=>$callsign, 'action'=>'kept existing NW (within 5sec)'];
        }
        // 5秒以上経過 → 新しい交信としてRFで上書き
    }

    // 既存を削除して新着を保存
    if($existing_file) {
        @unlink($existing_file);
    }

    // 新規保存
    file_put_contents($dir.'/inbox-'.date('Ymd-His').'.json', wp_json_encode($data, JSON_UNESCAPED_UNICODE));
    return ['ok'=>true, 'node'=>$node, 'callsign'=>$callsign, 'action'=>'saved'];
}

// 3. 表示機能 (ショートコード)
add_shortcode('hotspot_log_display', function($atts) {
    // キャッシュ無効化
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    // WP Super Cache / W3 Total Cache 対策
    if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    if(!defined('DONOTCACHEDB'))   define('DONOTCACHEDB', true);

    $node = (isset($atts['node']) ? $atts['node'] : '') ?: get_option('hotspot_default_node');
    if(!$node) return '<p style="color:red;">ノードが設定されていません。設定画面で規定のノードを入力してください。</p>';

    $dir = trailingslashit(wp_upload_dir()['basedir']) . 'hotspot/' . $node;
    if(!file_exists($dir)) return '<p>ログフォルダが存在しません: '.esc_html($node).'</p>';

    $files = glob($dir . '/inbox-*.json');
    if(!$files) return '<p>ノード 「'.esc_html($node).'」 に表示できるログはありません。</p>';

    // 新しい順にソート
    usort($files, function($a, $b){ return filemtime($b) - filemtime($a); });

    // 同一コールサインは最新1件のみ残す
    $seen = [];
    $rows = [];
    foreach($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if(!$d) continue;
        $call = strtoupper($d['callsign'] ?? ($d['dmr']['callsign'] ?? ''));
        if(!$call || isset($seen[$call])) continue;
        $seen[$call] = true;
        $rows[] = $d;
    }

    $out  = '<style>';
    $out .= '.hts-log-wrap { font-family: sans-serif; width: 100% !important; display: block; }';
    $out .= '.hts-log-updated { font-size: 12px; color: #999; margin-bottom: 12px; }';
    $out .= '.hts-log-table { width: 100% !important; border-collapse: collapse; font-size: 14px; table-layout: fixed; }';
    $out .= '.hts-log-table th:nth-child(1) { width: 5%; text-align: center; }';  /* No. */
    $out .= '.hts-log-table th:nth-child(2) { width: 12%; }';  /* Callsign */
    $out .= '.hts-log-table th:nth-child(3) { width: auto; }'; /* Name - 残り幅を使う */
    $out .= '.hts-log-table th:nth-child(4) { width: 14%; }';  /* Src/Net */
    $out .= '.hts-log-table th:nth-child(5) { width: 14%; }';  /* Date */
    $out .= '.hts-log-table th:nth-child(6) { width: 12%; }';  /* Time */
    $out .= '.hts-no { text-align: center; color: #999; font-size: 12px; }';
    $out .= '.hts-log-table td { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }';
    $out .= '.hts-name { color: #444; white-space: normal; word-break: break-word; }';
    $out .= '.hts-log-table thead tr { border-bottom: 2px solid #2271b1; }';
    $out .= '.hts-log-table th { padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #2271b1; font-weight: 600; white-space: nowrap; }';
    $out .= '.hts-log-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.15s; }';
    $out .= '.hts-log-table tbody tr:hover { background: #f7fbff; }';
    $out .= '.hts-log-table td { padding: 10px 12px; vertical-align: middle; }';
    $out .= '.hts-call { font-weight: 700; font-size: 15px; letter-spacing: 0.04em; color: #1a1a1a; }';
    $out .= '.hts-name { color: #444; }';
    $out .= '.hts-time { color: #666; font-size: 13px; white-space: nowrap; }';
    $out .= '.hts-badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; }';
    $out .= '.hts-badge-rf { background: #e8f4e8; color: #2d7a2d; }';
    $out .= '.hts-badge-nw { background: #e8eef8; color: #2255aa; }';
    $out .= '</style>';

    $out .= '<div class="hts-log-wrap"><div id="hts-log-container" style="overflow-x:auto; width:100%;">';
    $out .= '<table class="hts-log-table">';
    $out .= '<thead><tr>';
    $out .= '<th>No.</th>';
    $out .= '<th>Callsign</th>';
    $out .= '<th>Name</th>';
    $out .= '<th>Src</th>';
    $out .= '<th>Date</th>';
    $out .= '<th>Time</th>';
    $out .= '</tr></thead><tbody>';

    foreach($rows as $i => $d) {
        $i = $i + 1; // 1始まりに
        $ts   = strtotime($d['timestamp_local'] ?? $d['timestamp']);
        $call = esc_html($d['callsign'] ?? ($d['dmr']['callsign'] ?? '---'));
        $raw_src     = strtoupper($d['dmr']['src'] ?? '');
        $is_nw       = ($raw_src === 'NETWORK');
        $net_label   = esc_html($d['net_label'] ?? '');
        $src_label   = $is_nw ? ($net_label ?: 'NW') : 'RF';
        $name        = esc_html($d['name'] ?? hts_lookup_name($d['callsign'] ?? '') ?: '---');
        $badge_class = $is_nw ? 'hts-badge-nw' : 'hts-badge-rf';

        $out .= '<tr>';
        $out .= '<td class="hts-no">' . $i . '</td>';
        $out .= '<td><span class="hts-call">' . $call . '</span></td>';
        $out .= '<td><span class="hts-name">' . $name . '</span></td>';
        $out .= '<td><span class="hts-badge ' . $badge_class . '">' . $src_label . '</span></td>';
        $out .= '<td><span class="hts-time">' . date('Y-m-d', $ts) . '</span></td>';
        $out .= '<td><span class="hts-time">' . date('H:i:s', $ts) . '</span></td>';
        $out .= '</tr>';
    }
    $out .= '</tbody></table></div></div>';

    // 30秒ごとにテーブル部分だけAJAXで更新
    $ajax_url = admin_url('admin-ajax.php');
    $out .= '<script>
(function() {
    var interval = 30000;
    var nonce = "' . wp_create_nonce('hts_refresh') . '";
    var node  = "' . esc_js($node) . '";
    function refreshLog() {
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "' . esc_url(admin_url('admin-ajax.php')) . '", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onload = function() {
            if (xhr.status === 200 && xhr.responseText) {
                var container = document.getElementById("hts-log-container");
                if (container) container.innerHTML = xhr.responseText;
            }
        };
        xhr.send("action=hts_refresh_log&nonce=" + nonce + "&node=" + encodeURIComponent(node));
    }
    setInterval(refreshLog, interval);
})();
</script>';

    return $out;
});

// ── AJAXログ更新ハンドラー ──────────────────────────────────
add_action('wp_ajax_hts_refresh_log',        'hts_ajax_refresh_log');
add_action('wp_ajax_nopriv_hts_refresh_log', 'hts_ajax_refresh_log');

function hts_ajax_refresh_log() {
    check_ajax_referer('hts_refresh', 'nonce');
    $node = sanitize_key($_POST['node'] ?? '');
    if (!$node) wp_die('');

    $dir   = trailingslashit(wp_upload_dir()['basedir']) . 'hotspot/' . $node;
    $files = glob($dir . '/inbox-*.json');
    if (!$files) { echo ''; wp_die(); }

    usort($files, function($a, $b){ return filemtime($b) - filemtime($a); });

    $seen = []; $rows = [];
    foreach ($files as $f) {
        $d    = json_decode(file_get_contents($f), true);
        if (!$d) continue;
        $call = strtoupper($d['callsign'] ?? '');
        if (!$call || isset($seen[$call])) continue;
        $seen[$call] = true;
        $rows[] = $d;
    }

    $out  = '<table class="hts-log-table">';
    $out .= '<thead><tr>';
    $out .= '<th>No.</th><th>Callsign</th><th>Name</th><th>Src</th><th>Date</th><th>Time</th>';
    $out .= '</tr></thead><tbody>';

    foreach ($rows as $i => $d) {
        $i    = $i + 1;
        $ts   = strtotime($d['timestamp_local'] ?? $d['timestamp']);
        $call = esc_html($d['callsign'] ?? '---');
        $raw_src   = strtoupper($d['dmr']['src'] ?? '');
        $is_nw     = ($raw_src === 'NETWORK');
        $net_label = esc_html($d['net_label'] ?? '');
        $src_label = $is_nw ? ($net_label ?: 'NW') : 'RF';
        $name      = esc_html($d['name'] ?? hts_lookup_name($d['callsign'] ?? '') ?: '---');
        $badge_class = $is_nw ? 'hts-badge-nw' : 'hts-badge-rf';

        $out .= '<tr>';
        $out .= '<td class="hts-no">' . $i . '</td>';
        $out .= '<td><span class="hts-call">' . $call . '</span></td>';
        $out .= '<td><span class="hts-name">' . $name . '</span></td>';
        $out .= '<td><span class="hts-badge ' . $badge_class . '">' . $src_label . '</span></td>';
        $out .= '<td><span class="hts-time">' . date('Y-m-d', $ts) . '</span></td>';
        $out .= '<td><span class="hts-time">' . date('H:i:s', $ts) . '</span></td>';
        $out .= '</tr>';
    }
    $out .= '</tbody></table>';
    echo $out;
    wp_die();
}

// ── hotspot_setup.sh 配信エンドポイント ──────────────────────
add_action('rest_api_init', function () {
    register_rest_route('hotspot', '/setup.sh', [
        'methods'             => 'GET',
        'callback'            => 'hts_serve_setup_sh',
        'permission_callback' => '__return_true',
    ]);
});

function hts_serve_setup_sh() {
    $script = <<<'SETUP_SH_EOF'
#!/bin/bash

# =================================================
# スクリプト名: hotspot_setup.sh
# バージョン: 2.2.2
# 概要: MMDVMログ監視サービスのセットアップ
# =================================================

# --- 1. root権限チェック ---
if [ "$EUID" -ne 0 ]; then
  echo "❌ エラー: このスクリプトはsudoで実行してください。"
  exit 1
fi

echo "================================================="
echo "   Hotspot Watcher v2.2.2 セットアップ"
echo "================================================="

# --- 2. ユーザー入力（デフォルト値なし・必須入力） ---
while true; do
  read -p "1. 接続先サーバー (例: yourserver.example.com): " SERVER_HOST
  [ -n "$SERVER_HOST" ] && break
  echo "   ❌ 接続先サーバーを入力してください。"
done

ENDPOINT="https://${SERVER_HOST}/wp-json/hotspot/ingest"

while true; do
  read -p "2. ノード名 (例: yournode): " NODE_NAME
  [ -n "$NODE_NAME" ] && break
  echo "   ❌ ノード名を入力してください。"
done

while true; do
  read -p "3. APIトークン (例: yourtoken): " API_TOKEN
  [ -n "$API_TOKEN" ] && break
  echo "   ❌ APIトークンを入力してください。"
done

while true; do
  read -p "4. ネットワーク表示名・英数半角10文字まで (例: TGIF168 / XLX834Z): " NET_LABEL
  [ -z "$NET_LABEL" ] && echo "   ❌ ネットワーク表示名を入力してください。" && continue
  if echo "$NET_LABEL" | grep -qP '^[A-Za-z0-9 ]{1,10}$'; then
    break
  fi
  echo "   ❌ 英数字・スペースのみ、10文字以内で入力してください。"
done

echo ""
echo "  接続先サーバー     : ${SERVER_HOST}"
echo "  エンドポイント     : ${ENDPOINT}"
echo "  ノード名           : ${NODE_NAME}"
echo "  APIトークン        : ${API_TOKEN}"
echo "  ネットワーク表示名 : ${NET_LABEL}"
echo ""
read -p "上記の設定で続行しますか？ [y/N]: " CONFIRM
case "$CONFIRM" in
  [yY]|[yY][eE][sS]) ;;
  *) echo "キャンセルしました。"; exit 0 ;;
esac

# --- 3. ファイルシステムを書き込み可能に ---
if command -v rpi-rw > /dev/null; then rpi-rw; fi

PYTHON_SCRIPT="/root/hotspot_watch.py"
SERVICE_FILE="/etc/systemd/system/hotspot_watch.service"

# --- 4. 既存サービスのクリーンアップ ---
echo "➡️ 既存サービスをクリーンアップ中..."
systemctl stop hotspot_watch 2>/dev/null
systemctl disable hotspot_watch 2>/dev/null
pkill -f hotspot_watch.py 2>/dev/null
sleep 1

# --- 5. Python監視スクリプトの生成 ---
echo "➡️ 監視スクリプトを生成中..."
cat << 'PYEOF' > "${PYTHON_SCRIPT}"
# coding: utf-8
import os, time, json, re, logging, urllib.request
from datetime import datetime, timezone, timedelta

# --- 基本設定（hotspot_setup.sh により自動生成） ---
ENDPOINT  = "ENDPOINT_PLACEHOLDER"
TOKEN     = "TOKEN_PLACEHOLDER"
NODE_NAME = "NODE_PLACEHOLDER"
NET_LABEL = "NETLABEL_PLACEHOLDER"
LOG_DIR  = "/var/log/pi-star"

logging.basicConfig(
    level=logging.INFO,
    format='[%(asctime)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

def post_json(data):
    payload = json.dumps(data).encode('utf-8')
    headers = {
        "Content-Type": "application/json",
        "X-Hotspot-Token": TOKEN
    }
    req = urllib.request.Request(ENDPOINT, data=payload, headers=headers, method='POST')
    try:
        with urllib.request.urlopen(req, timeout=10) as res:
            body = res.read().decode('utf-8')
            logging.info("Success [%d]: %s -> %s" % (res.status, data.get('callsign','?'), body[:60]))
            return True
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8')
        logging.error("POST Failed [HTTP %d]: %s" % (e.code, body[:120]))
    except Exception as e:
        logging.error("POST Failed: " + str(e))
    return False

HEADER_P = re.compile(
    r'(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2}\.\d{3})\s+DMR\s+Slot\s+(\d+),'
    r'\s+received\s+(RF|NETWORK)\s+voice\s+header\s+from\s+(.+?)\s+to\s+(TG|PC|REF)\s+(\d+|ALL)',
    re.IGNORECASE
)
END_P = re.compile(
    r'(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2}\.\d{3})\s+DMR\s+Slot\s+(\d+),'
    r'\s+received\s+(RF|NETWORK)\s+end\s+of\s+voice\s+transmission\s+from\s+(.+?)\s+to\s+(TG|PC|REF)\s+(\d+|ALL)',
    re.IGNORECASE
)

def main():
    logging.info("Hotspot Watcher v2.2.2 started. Node=%s" % NODE_NAME)
    session_buffer = {}

    # 起動時に既存ログファイルの現在サイズを記録（過去分をスキップ）
    prev_sizes = {}
    try:
        for fname in os.listdir(LOG_DIR):
            if fname.startswith("MMDVM-") and fname.endswith(".log"):
                path = os.path.join(LOG_DIR, fname)
                prev_sizes[fname] = os.path.getsize(path)
                logging.info("Skip existing: %s (%d bytes)" % (fname, prev_sizes[fname]))
    except Exception as ex:
        logging.error("Init error: " + str(ex))

    while True:
        time.sleep(2)
        try:
            for fname in os.listdir(LOG_DIR):
                if not (fname.startswith("MMDVM-") and fname.endswith(".log")):
                    continue
                path = os.path.join(LOG_DIR, fname)
                size = os.path.getsize(path)
                last_size = prev_sizes.get(fname, 0)

                if size > last_size:
                    with open(path, 'r', encoding='utf-8', errors='ignore') as f:
                        f.seek(last_size)
                        for line in f:
                            if 'voice header' in line:
                                m = HEADER_P.search(line)
                                if m:
                                    key = m.group(5) + "_" + m.group(3)
                                    session_buffer[key] = {
                                        'slot': m.group(3),
                                        'src':  m.group(4),
                                        'dst':  m.group(7),
                                        'call': m.group(5).upper()
                                    }
                            elif 'end of voice' in line:
                                m = END_P.search(line)
                                if m:
                                    key = m.group(5) + "_" + m.group(3)
                                    if key in session_buffer:
                                        s = session_buffer[key]
                                        dt_utc = datetime.strptime(
                                            m.group(1) + ' ' + m.group(2),
                                            '%Y-%m-%d %H:%M:%S.%f'
                                        ).replace(tzinfo=timezone.utc)
                                        dt_jst = dt_utc + timedelta(hours=9)
                                        payload = {
                                            "node":        NODE_NAME,
                                            "source_node": NODE_NAME,
                                            "net_label":   NET_LABEL,
                                            "callsign":    s['call'],
                                            "timestamp":   dt_jst.strftime("%Y-%m-%d %H:%M:%S"),
                                            "dmr": {
                                                "slot": int(s['slot']),
                                                "src":  s['src'],
                                                "dst":  s['dst']
                                            }
                                        }
                                        post_json(payload)
                                        del session_buffer[key]
                    prev_sizes[fname] = size
        except Exception as ex:
            logging.error("Loop error: " + str(ex))
            time.sleep(5)

if __name__ == "__main__":
    main()
PYEOF

# プレースホルダーを実際の値に置換
sed -i "s|ENDPOINT_PLACEHOLDER|${ENDPOINT}|g"   "${PYTHON_SCRIPT}"
sed -i "s|TOKEN_PLACEHOLDER|${API_TOKEN}|g"     "${PYTHON_SCRIPT}"
sed -i "s|NODE_PLACEHOLDER|${NODE_NAME}|g"      "${PYTHON_SCRIPT}"
sed -i "s|NETLABEL_PLACEHOLDER|${NET_LABEL}|g"  "${PYTHON_SCRIPT}"

chmod +x "${PYTHON_SCRIPT}"

# --- 6. システムサービスの登録 ---
echo "➡️ システムサービスを登録中..."
cat << EOF > "${SERVICE_FILE}"
[Unit]
Description=Hotspot Log Watcher Service
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
ExecStart=/usr/bin/python3 ${PYTHON_SCRIPT}
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# サービスの有効化と起動
systemctl daemon-reload
systemctl enable hotspot_watch.service
systemctl restart hotspot_watch.service

sleep 2
echo ""
echo "--- サービス状態 ---"
systemctl status hotspot_watch --no-pager -l

if command -v rpi-ro > /dev/null; then rpi-ro; fi

echo ""
echo "================================================="
echo "   ✅ セットアップ完了！"
echo "   送信先: ${ENDPOINT}"
echo ""
echo "   動作確認コマンド:"
echo "   sudo journalctl -u hotspot_watch -f"
echo "================================================="
SETUP_SH_EOF;

    // Content-Dispositionでダウンロードさせる
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="hotspot_setup.sh"');
    header('X-Content-Type-Options: nosniff');
    echo $script;
    exit;
}
