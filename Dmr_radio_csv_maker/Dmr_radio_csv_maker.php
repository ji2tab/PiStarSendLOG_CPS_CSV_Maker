<?php
/*
Plugin Name: DMR Radio CSV Maker
Description: hotspot/_cache/user.csv から通常CSV・H1用CSVを生成してダウンロード。ショートコード: [dmr_csv_maker]
Version: 1.4.3
Author: JI2TAB
Requires at least: 5.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit;

// ── CSVパス ───────────────────────────────────────────────────
function hcm_csv_path() {
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'hotspot/_cache/user.csv';
}


// ── ダウンロードログ ──────────────────────────────────────────
function hcm_log_path() {
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'hotspot/_cache/download_log.csv';
}

function hcm_write_log($callsign, $dmr_id, $country, $type) {
    $log_path = hcm_log_path();
    $is_new   = !file_exists($log_path);
    $fh = fopen($log_path, 'a');
    if (!$fh) return;
    if ($is_new) {
        fputcsv($fh, ['日時(JST)', 'コールサイン', 'DMR ID', '対象国', '種別', 'IPアドレス']);
    }
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '不明';
    $ip = sanitize_text_field(explode(',', $ip)[0]);
    fputcsv($fh, [
        wp_date('Y-m-d H:i:s'),
        $callsign,
        $dmr_id,
        $country !== '' ? $country : '全件',
        $type === 'h1' ? 'H1用CSV' : '通常CSV',
        $ip,
    ]);
    fclose($fh);
}

// ── user.csvから国リストを抽出 ────────────────────────────────
function hcm_get_countries() {
    $csv_path = hcm_csv_path();
    if (!file_exists($csv_path)) return [];
    $fh = fopen($csv_path, 'r');
    if (!$fh) return [];
    $header = fgetcsv($fh);
    $idx    = array_flip($header);
    $countries = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!isset($row[$idx['COUNTRY']])) continue;
        $c = trim($row[$idx['COUNTRY']]);
        if ($c !== '') $countries[$c] = true;
    }
    fclose($fh);
    $list = array_keys($countries);
    sort($list);
    return $list;
}

// ── ユーザー抽出（国フィルター付き） ─────────────────────────
function hcm_get_users($country_filter = '') {
    $csv_path = hcm_csv_path();
    if (!file_exists($csv_path)) return [];
    $fh = fopen($csv_path, 'r');
    if (!$fh) return [];
    $header = fgetcsv($fh);
    $idx    = array_flip($header);
    $users  = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!isset($row[$idx['COUNTRY']])) continue;
        $country = trim($row[$idx['COUNTRY']]);
        if ($country_filter !== '' && $country !== $country_filter) continue;
        $users[] = [
            'RADIO_ID'   => $row[$idx['RADIO_ID']]   ?? '',
            'CALLSIGN'   => $row[$idx['CALLSIGN']]   ?? '',
            'FIRST_NAME' => $row[$idx['FIRST_NAME']] ?? '',
            'LAST_NAME'  => $row[$idx['LAST_NAME']]  ?? '',
            'CITY'       => $row[$idx['CITY']]        ?? '',
            'STATE'      => $row[$idx['STATE']]       ?? '',
            'COUNTRY'    => $country,
        ];
    }
    fclose($fh);
    return $users;
}

// ── 文字列クリーン（元コード guc_clean_value 準拠） ──────────
// カンマ・ダブルクォートを除去、マルチバイト文字を除去
function hcm_clean_value($value) {
    $value = str_replace(',', ' ', $value);
    $value = str_replace('"', '', $value);
    // 全角・マルチバイト文字を除去
    $value = preg_replace('/[\x{3000}-\x{9FFF}\x{FF00}-\x{FFEF}]/u', '', $value);
    return $value;
}

// ── 英数字・スペースのみに変換（H1用追加クリーン） ───────────
function hcm_alnum($value) {
    $value = preg_replace('/[^A-Za-z0-9 ]/', ' ', $value);
    $value = preg_replace('/ +/', ' ', $value);
    return trim($value);
}

// ── 20文字切り詰め ────────────────────────────────────────────
function hcm_trim20($value) {
    return mb_substr($value, 0, 20, 'UTF-8');
}

// ── ヘッダー送信 ──────────────────────────────────────────────
function hcm_send_headers($filename) {
    if (ob_get_length()) @ob_end_clean();
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}

// ── コールサイン・DMR ID照合 ─────────────────────────────────
function hcm_verify_user($callsign, $dmr_id) {
    $csv_path = hcm_csv_path();
    if (!file_exists($csv_path)) return false;
    $fh = fopen($csv_path, 'r');
    if (!$fh) return false;
    $header = fgetcsv($fh);
    $idx    = array_flip($header);
    $found  = false;
    while (($row = fgetcsv($fh)) !== false) {
        $csv_call = strtoupper(trim($row[$idx['CALLSIGN']] ?? ''));
        $csv_id   = trim($row[$idx['RADIO_ID']] ?? '');
        if ($csv_call === strtoupper(trim($callsign)) && $csv_id === trim($dmr_id)) {
            $found = true;
            break;
        }
    }
    fclose($fh);
    return $found;
}

// ── ショートコード ────────────────────────────────────────────
add_shortcode('dmr_csv_maker', function($atts) {
    $csv_path      = hcm_csv_path();
    $exists        = file_exists($csv_path);
    $last_modified = get_option('hts_csv_last_modified');
    $updated       = get_option('hts_csv_updated');

    ob_start();
    ?>
    <div class="hcm-wrap">
    <style>
        .hcm-wrap { max-width: 560px; margin: 0 auto; font-family: sans-serif; }
        .hcm-wrap h2 { font-size: 20px; margin-bottom: 12px; }
        .hcm-info { color: #555; font-size: 13px; margin-bottom: 16px; }
        .hcm-auth-form { background: #f9f9f9; border: 1px solid #ddd; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .hcm-auth-form p { margin-top: 0; font-size: 15px; }
        .hcm-auth-row { margin-bottom: 14px; }
        .hcm-auth-row label { display: block; font-weight: bold; margin-bottom: 6px; font-size: 15px; }
        .hcm-auth-row input[type=text] { width: 100%; padding: 8px; font-size: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .hcm-error { color: #c00; background: #fff0f0; border: 1px solid #f5c6cb; border-radius: 4px; padding: 10px 14px; margin-bottom: 16px; }
        .hcm-success { color: #1a7a1a; background: #f0fff0; border: 1px solid #b2dfb2; border-radius: 4px; padding: 10px 14px; margin-bottom: 16px; }
        .hcm-dl-form { margin-bottom: 16px; }
        .hcm-dl-form label { font-weight: bold; margin-right: 8px; }
        .hcm-dl-form select { font-size: 15px; padding: 6px 10px; border-radius: 4px; border: 1px solid #ccc; }
        .hcm-btn {
            display: inline-block; padding: 10px 20px; margin: 4px 8px 4px 0;
            font-size: 15px; border-radius: 5px; text-decoration: none;
            color: #fff; background: #2271b1; cursor: pointer; border: none;
        }
        .hcm-btn:hover { background: #135e96; color: #fff; }
        .hcm-btn-submit { width: 100%; margin-top: 16px; font-size: 16px; padding: 12px; }
        .hcm-spec { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 13px; }
        .hcm-spec th, .hcm-spec td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; }
        .hcm-spec th { background: #f4f4f4; }
    </style>

    <h2>DMR Radio CSV Maker</h2>

    <?php if (!$exists): ?>
        <p style="color:red;">user.csv が見つかりません。管理者にお問い合わせください。</p>
        </div><?php return ob_get_clean();
    endif; ?>

    <p class="hcm-info">
        radioid.net 公開日時: <strong><?php echo $last_modified ? wp_date('Y-m-d H:i', $last_modified) : '不明'; ?></strong><br>
        取得日時: <?php echo $updated ? wp_date('Y-m-d H:i', $updated) : '不明'; ?>

    </p>

    <?php
    $auth_ok    = false;
    $auth_error = '';
    $callsign   = '';
    $dmr_id     = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['hcm_auth_nonce']) &&
        wp_verify_nonce($_POST['hcm_auth_nonce'], 'hcm_auth')) {

        $callsign = strtoupper(sanitize_text_field($_POST['hcm_callsign'] ?? ''));
        $dmr_id   = sanitize_text_field($_POST['hcm_dmr_id'] ?? '');

        if (!$callsign || !$dmr_id) {
            $auth_error = 'コールサインとDMR IDの両方を入力してください。';
        } elseif (!hcm_verify_user($callsign, $dmr_id)) {
            $auth_error = 'コールサインまたはDMR IDが一致しません。';
        } else {
            $auth_ok = true;
        }
    }

    if (!$auth_ok): ?>

        <?php if ($auth_error): ?>
            <p class="hcm-error"><?php echo esc_html($auth_error); ?></p>
        <?php endif; ?>

        <div class="hcm-auth-form">
            <p>ダウンロードにはコールサインとDMR IDによる認証が必要です。</p>
            <form method="post">
                <?php wp_nonce_field('hcm_auth', 'hcm_auth_nonce'); ?>
                <div class="hcm-auth-row">
                    <label>コールサイン</label>
                    <input type="text" name="hcm_callsign" value="<?php echo esc_attr($callsign); ?>" autocomplete="off">
                </div>
                <div class="hcm-auth-row">
                    <label>DMR ID</label>
                    <input type="text" name="hcm_dmr_id" value="<?php echo esc_attr($dmr_id); ?>" autocomplete="off">
                </div>
                <button type="submit" class="hcm-btn hcm-btn-submit">認証してダウンロード画面へ</button>
            </form>
        </div>

    <?php else:
        $countries   = hcm_get_countries();
        $selected    = isset($_POST['hcm_country']) ? sanitize_text_field($_POST['hcm_country']) : 'Japan';
        $nonce       = wp_create_nonce('hcm_download');
        $current_url = get_permalink();
        $base_normal = add_query_arg([
            'hcm_action'   => 'normal',
            'hcm_nonce'    => $nonce,
            'hcm_callsign' => $callsign,
            'hcm_dmr_id'   => $dmr_id,
        ], $current_url);
        $base_h1 = add_query_arg([
            'hcm_action'   => 'h1',
            'hcm_nonce'    => $nonce,
            'hcm_callsign' => $callsign,
            'hcm_dmr_id'   => $dmr_id,
        ], $current_url);
    ?>

        <p class="hcm-success">✅ 認証成功：<strong><?php echo esc_html($callsign); ?></strong></p>

        <form method="post" class="hcm-dl-form">
            <?php wp_nonce_field('hcm_auth', 'hcm_auth_nonce'); ?>
            <input type="hidden" name="hcm_callsign" value="<?php echo esc_attr($callsign); ?>">
            <input type="hidden" name="hcm_dmr_id"   value="<?php echo esc_attr($dmr_id); ?>">
            <label for="hcm_country">対象国：</label>
            <select name="hcm_country" id="hcm_country" onchange="this.form.submit()">
                <option value="">── 全件 ──</option>
                <?php foreach ($countries as $c): ?>
                    <option value="<?php echo esc_attr($c); ?>" <?php selected($selected, $c); ?>>
                        <?php echo esc_html($c); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <p>選択中：<strong><?php echo $selected !== '' ? esc_html($selected) : '全件'; ?></strong></p>

        <p>
            <a id="hcm-btn-normal" class="hcm-btn" href="#">通常CSV ダウンロード</a>
            <a id="hcm-btn-h1"     class="hcm-btn" href="#">H1用CSV ダウンロード</a>
        </p>
        <script>
        (function() {
            var baseNormal = <?php echo json_encode($base_normal); ?>;
            var baseH1     = <?php echo json_encode($base_h1); ?>;
            var sel        = document.getElementById('hcm_country');
            var btnNormal  = document.getElementById('hcm-btn-normal');
            var btnH1      = document.getElementById('hcm-btn-h1');
            function updateLinks() {
                var country = encodeURIComponent(sel.value);
                btnNormal.href = baseNormal + '&hcm_country=' + country;
                btnH1.href     = baseH1     + '&hcm_country=' + country;
            }
            updateLinks();
            sel.addEventListener('change', function() { updateLinks(); });
        })();
        </script>

        <table class="hcm-spec">
            <thead><tr><th>種別</th><th>列構成</th></tr></thead>
            <tbody>
                <tr>
                    <td>通常CSV</td>
                    <td>RADIO_ID, CALLSIGN, FIRST_NAME, LAST_NAME, CITY, STATE, COUNTRY</td>
                </tr>
                <tr>
                    <td>H1用CSV</td>
                    <td>Contacts Alias, Call Type, Call ID, City, Province, Country<br>
                        （7桁RADIO_IDのみ・英数字変換・City/Province 16文字切り詰め）</td>
                </tr>
            </tbody>
        </table>

    <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

// ── ダウンロード処理 ──────────────────────────────────────────
add_action('init', function() {
    if (!isset($_GET['hcm_action']) || !isset($_GET['hcm_nonce'])) return;
    if (!wp_verify_nonce($_GET['hcm_nonce'], 'hcm_download')) wp_die('不正なリクエストです。');

    $action  = sanitize_text_field($_GET['hcm_action']);
    $country = isset($_GET['hcm_country']) ? sanitize_text_field($_GET['hcm_country']) : 'Japan';
    $users   = hcm_get_users($country);
    if (!$users) wp_die('該当するユーザーがいません。');

    $suffix = $country !== '' ? '_' . preg_replace('/[^A-Za-z0-9]/', '_', $country) : '_ALL';

    // ── 通常CSV ──────────────────────────────────────────────
    if ($action === 'normal') {
        $dl_callsign = sanitize_text_field($_GET['hcm_callsign'] ?? '');
        $dl_dmr_id   = sanitize_text_field($_GET['hcm_dmr_id']   ?? '');
        hcm_write_log($dl_callsign, $dl_dmr_id, $country, 'normal');
        hcm_send_headers('dmr_user' . $suffix . '_' . wp_date('YmdHis') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['RADIO_ID','CALLSIGN','FIRST_NAME','LAST_NAME','CITY','STATE','COUNTRY']);
        foreach ($users as $u) {
            fputcsv($out, [
                $u['RADIO_ID'],
                $u['CALLSIGN'],
                $u['FIRST_NAME'],
                $u['LAST_NAME'],
                $u['CITY'],
                $u['STATE'],
                $u['COUNTRY'],
            ]);
        }
        fclose($out);
        exit;
    }

    // ── H1用CSV ──────────────────────────────────────────────
    // 実機エクスポート準拠:
    //   列順: Contacts Alias, Call Type, Call ID, City, Province, Country
    //   改行: CRLF
    if ($action === 'h1') {
        $dl_callsign = sanitize_text_field($_GET['hcm_callsign'] ?? '');
        $dl_dmr_id   = sanitize_text_field($_GET['hcm_dmr_id']   ?? '');
        hcm_write_log($dl_callsign, $dl_dmr_id, $country, 'h1');
        hcm_send_headers('dmr_h1' . $suffix . '_' . wp_date('YmdHis') . '.csv');

        // ヘッダー行（CRLF）
        echo "Contacts Alias,Call Type,Call ID,City,Province,Country\r\n";

        foreach ($users as $u) {
            // Call ID: 7桁の数字のみ
            $call_id = trim($u['RADIO_ID']);
            if (!preg_match('/^[0-9]{7}$/', $call_id)) continue;

            // Contacts Alias = CALLSIGN + RADIO_ID
            $alias    = hcm_alnum($u['CALLSIGN'] . ' ' . $call_id);
            // City     = FIRST_NAME（16文字切り詰め）
            $city     = mb_substr(hcm_alnum($u['FIRST_NAME']), 0, 16, 'UTF-8');
            // Province = CITY のみ（16文字切り詰め）
            $province = mb_substr(hcm_alnum($u['CITY']), 0, 16, 'UTF-8');
            // Country  = COUNTRY + STATE
            $country  = hcm_alnum($u['COUNTRY'] . ' ' . $u['STATE']);

            $fields = [$alias, 'Private Call', $call_id, $city, $province, $country];
            $cleaned = [];
            foreach ($fields as $f) {
                $f = str_replace(',', ' ', (string)$f);
                $f = str_replace('"', '', $f);
                $cleaned[] = trim($f);
            }
            echo implode(',', $cleaned) . "\r\n";
        }
        exit;
    }
});

// ── 管理画面：ダウンロードログ ───────────────────────────────
add_action('admin_menu', function() {
    add_options_page(
        'DMR Radio CSV Maker',
        'DMR Radio CSV Maker',
        'manage_options',
        'dmr-radio-csv-maker',
        'hcm_admin_page'
    );
});

function hcm_admin_page() {
    $log_path = hcm_log_path();
    $csv_path = hcm_csv_path();
    $last_modified = get_option('hts_csv_last_modified');
    $updated       = get_option('hts_csv_updated');
    ?>
    <div class="wrap">
        <h1>DMR Radio CSV Maker</h1>

        <h2>user.csv 状態</h2>
        <table class="form-table">
            <tr>
                <th>radioid.net 公開日時</th>
                <td><?php echo $last_modified ? wp_date('Y-m-d H:i', $last_modified) : '不明'; ?></td>
            </tr>
            <tr>
                <th>取得日時</th>
                <td>
                    <?php echo $updated ? wp_date('Y-m-d H:i', $updated) : '不明'; ?>
                </td>
            </tr>
        </table>

        <h2 style="margin-top:30px;">ダウンロードログ</h2>
        <?php if (!file_exists($log_path)): ?>
            <p>まだダウンロードログはありません。</p>
        <?php else:
            $rows = [];
            $fh = fopen($log_path, 'r');
            $header = fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) $rows[] = $row;
            fclose($fh);
            $rows = array_reverse($rows); // 新しい順
        ?>
            <p>合計 <strong><?php echo count($rows); ?></strong> 件</p>
            <table class="widefat fixed striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <?php foreach ($header as $h): ?>
                            <th><?php echo esc_html($h); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?php echo esc_html($cell); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}