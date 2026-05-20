<?php
/*
Plugin Name: DMR Radio CSV Downloader
Plugin URI: https://jj2yyk.forums.gr.jp/
Description: Master Baseプラグインのデータを元に、一般ユーザーへ認証付きでCSV（通常・H1用）を加工・配布するフロントエンドプラグインです。
Version: 1.0.1
Author: JI2TAB / JJ2YYK
*/

if (!defined('ABSPATH')) exit;

class DMR_CSV_Downloader_Front {
    private $upload_dir;
    private $cache_dir;
    private $master_csv;
    private $log_file;

    public function __construct() {
        $upload = wp_upload_dir();
        // Master Base側と同じディレクトリ構造を参照
        $this->upload_dir = $upload['basedir'] . '/dmr_csv_maker';
        $this->cache_dir  = $this->upload_dir . '/_cache';
        $this->master_csv = $this->upload_dir . '/user.csv'; 
        $this->log_file   = $this->upload_dir . '/download_log.csv';

        add_action('init', [$this, 'init_plugin']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_shortcode('dmr_csv_downloader', [$this, 'render_shortcode']);
        add_action('wp_loaded', [$this, 'handle_download_request']);
    }

    public function init_plugin() {
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            file_put_contents($this->cache_dir . '/.htaccess', "Deny from all");
        }
    }

    // =========================================================================
    // 管理画面（ダッシュボード）UIの構築
    // =========================================================================
    public function add_admin_menu() {
        add_submenu_page(
            'dmr-csv-settings', // Master Base（親メニュー）のSlug
            'ダウンロードログ', 
            'ダウンロードログ', 
            'manage_options', 
            'dmr-csv-downloader-logs', 
            [$this, 'render_log_page']
        );
    }

    public function render_log_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline" style="margin-bottom: 10px;">
                <span class="dashicons dashicons-list-view" style="font-size: 28px; width: 28px; height: 28px; margin-top: 3px; margin-right: 8px;"></span>
                DMR CSV ダウンロード履歴・監査ログ
            </h1>
            <hr class="wp-header-end">

            <div id="poststuff" style="margin-top: 20px;">
                <div class="postbox">
                    <div class="postbox-header"><h2 class="hndle"><span>📊 直近のダウンロード申請ログ（最新50件）</span></h2></div>
                    <div class="inside" style="padding: 0; margin: 0;">
                        <table class="wp-list-table widefat fixed strip table-view-list" style="border: none;">
                            <thead>
                                <tr>
                                    <th style="padding: 12px 10px; font-weight: bold;">日時</th>
                                    <th style="padding: 12px 10px; font-weight: bold;">認証コールサイン</th>
                                    <th style="padding: 12px 10px; font-weight: bold;">認証DMR ID</th>
                                    <th style="padding: 12px 10px; font-weight: bold;">対象国</th>
                                    <th style="padding: 12px 10px; font-weight: bold;">種別</th>
                                    <th style="padding: 12px 10px; font-weight: bold;">IPアドレス</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (file_exists($this->log_file)) {
                                    $logs = array_reverse(file($this->log_file)); // 新しい順に
                                    $count = 0;
                                    foreach ($logs as $log_line) {
                                        if ($count++ >= 50) break; // 最大50件
                                        $data = str_getcsv($log_line);
                                        echo '<tr>';
                                        echo '<td style="padding: 10px;">' . esc_html($data[0] ?? '-') . '</td>';
                                        echo '<td style="padding: 10px;"><strong>' . esc_html($data[1] ?? '-') . '</strong></td>';
                                        echo '<td style="padding: 10px;"><code>' . esc_html($data[2] ?? '-') . '</code></td>';
                                        echo '<td style="padding: 10px;">' . esc_html($data[3] ?? '-') . '</td>';
                                        echo '<td style="padding: 10px;">' . (($data[4] ?? '') === 'h1' ? '<span style="background:#0073aa; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px;">H1用</span>' : '<span style="background:#e5f5fa; color:#007cba; padding:2px 6px; border-radius:3px; font-size:11px;">通常</span>') . '</td>';
                                        echo '<td style="padding: 10px;">' . esc_html($data[5] ?? '-') . '</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="6" style="padding: 20px; text-align: center; color: #666;">まだダウンロード履歴はありません。</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // フロントエンド用：認証およびCSV生成・配信ロジック
    // =========================================================================
    private function authenticate_user($callsign, $dmr_id) {
        if (!file_exists($this->master_csv)) return false;

        $callsign = strtoupper(trim($callsign));
        $dmr_id = trim($dmr_id);

        if (($handle = fopen($this->master_csv, "r")) !== FALSE) {
            while (($data = fgetcsv($handle)) !== FALSE) {
                if (isset($data[0]) && isset($data[1])) {
                    if (trim($data[0]) === $dmr_id && strtoupper(trim($data[1])) === $callsign) {
                        fclose($handle);
                        return $data;
                    }
                }
            }
            fclose($handle);
        }
        return false;
    }

    private function generate_output_csv($type, $country = 'all') {
        $timestamp = date('YmdHis');
        $country_slug = ($country === 'all') ? 'Global' : preg_replace('/[^a-zA-Z0-9]/', '', $country);
        $filename = "dmr_{$type}_{$country_slug}_{$timestamp}.csv";
        $cache_path = $this->cache_dir . "/{$type}_{$country_slug}.csv";

        // キャッシュ（24時間有効）があれば再利用してサーバー負荷軽減
        if (file_exists($cache_path) && (time() - filemtime($cache_path) < 86400)) {
            return ['path' => $cache_path, 'name' => $filename];
        }

        $in = fopen($this->master_csv, "r");
        $out = fopen($cache_path, "w");

        // ヘッダー行の書き込み
        if ($type === 'h1') {
            fputcsv($out, ['Contacts Alias', 'Call Type', 'Call ID', 'City', 'Province', 'Country']);
        } else {
            fputcsv($out, ['RADIO_ID', 'CALLSIGN', 'FIRST_NAME', 'LAST_NAME', 'CITY', 'STATE', 'COUNTRY']);
        }

        // 行ごとのストリームフィルタリング処理
        while (($row = fgetcsv($in)) !== FALSE) {
            if ($country !== 'all' && (!isset($row[6]) || strpos($row[6], $country) === false)) continue;

            if ($type === 'h1') {
                $alias = "{$row[1]} {$row[0]}";
                $name  = mb_strimwidth($row[2] ?? '', 0, 16);
                $city  = mb_strimwidth($row[4] ?? '', 0, 16);
                $region = ($row[6] ?? '') . ' ' . ($row[5] ?? '');
                fputcsv($out, [$alias, 'Private Call', $row[0], $name, $city, $region]);
            } else {
                fputcsv($out, $row);
            }
        }
        fclose($in);
        fclose($out);

        return ['path' => $cache_path, 'name' => $filename];
    }

    public function handle_download_request() {
        if (isset($_POST['dmr_action']) && $_POST['dmr_action'] === 'front_download') {
            if (!wp_verify_nonce($_POST['dmr_nonce'], 'dmr_front_download')) wp_die('Security Check Failed');

            $callsign = sanitize_text_field($_POST['callsign']);
            $dmr_id   = sanitize_text_field($_POST['dmr_id']);
            $type     = ($_POST['type'] === 'h1') ? 'h1' : 'std';
            $country  = sanitize_text_field($_POST['country']);

            if ($this->authenticate_user($callsign, $dmr_id)) {
                $file_info = $this->generate_output_csv($type, $country);
                
                // 監査ログに記録
                $log_fp = fopen($this->log_file, 'a');
                fputcsv($log_fp, [date('Y-m-d H:i:s'), strtoupper($callsign), $dmr_id, $country, $type, $_SERVER['REMOTE_ADDR']]);
                fclose($log_fp);

                // ブラウザへ高速ストリーム配信（メモリバッファ節約）
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $file_info['name'] . '"');
                header('Content-Length: ' . filesize($file_info['path']));
                readfile($file_info['path']);
                exit;
            } else {
                set_transient('dmr_front_error', '認証に失敗しました。RadioID.netの登録コールサイン、またはDMR IDが一致しません。', 30);
                wp_redirect(remove_query_arg('dmr_err'));
                exit;
            }
        }
    }

    // =========================================================================
    // フロントエンド表示フォーム（ショートコード）
    // =========================================================================
    public function render_shortcode() {
        if (!file_exists($this->master_csv)) {
            return '<p style="color:red; font-weight:bold;">[DMR CSV] エラー: マスターデータベースが未構築です。管理画面から更新を行ってください。</p>';
        }

        $error_msg = get_transient('dmr_front_error');
        delete_transient('dmr_front_error');
        
        $master_time = filemtime($this->master_csv);
        
        ob_start();
        ?>
        <div class="dmr-downloader-card" style="border: 1px solid #ddd; padding: 25px; border-radius: 8px; background: #fafafa; max-width: 480px; margin: 20px auto; font-family: sans-serif; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <div style="text-align: center; margin-bottom: 20px;">
                <span class="dashicons dashicons-download" style="font-size: 36px; width:36px; height:36px; color: #0073aa;"></span>
                <h3 style="margin: 10px 0 5px 0; font-size: 1.3em; color: #333;">DMR Radio CSV ダウンロード</h3>
                <p style="font-size: 0.85em; color: #666; margin: 0;">RadioID.net データベースのカスタム抽出</p>
            </div>

            <?php if ($error_msg): ?>
                <div style="padding: 12px; background: #fbeaea; border-left: 4px solid #d63638; color: #d63638; font-size: 0.9em; margin-bottom: 15px; border-radius: 0 4px 4px 0;">
                    <?php echo esc_html($error_msg); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('dmr_front_download', 'dmr_nonce'); ?>
                <input type="hidden" name="dmr_action" value="front_download">
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 0.9em; font-weight: bold; margin-bottom: 5px; color: #444;">あなたのコールサイン (Callsign):</label>
                    <input type="text" name="callsign" required placeholder="例: JJ2YYK" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; text-transform: uppercase;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 0.9em; font-weight: bold; margin-bottom: 5px; color: #444;">あなたのDMR ID (Radio ID):</label>
                    <input type="text" name="dmr_id" required placeholder="例: 4400000" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 0.9em; font-weight: bold; margin-bottom: 5px; color: #444;">対象国のフィルタ (Country Filter):</label>
                    <select name="country" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; background: #fff;">
                        <option value="Japan" selected>日本国内のみ (Japan Only)</option>
                        <option value="all">全世界データ (Global - ※大容量)</option>
                    </select>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 20px;">
                    <button type="submit" name="type" value="std" style="flex: 1; padding: 12px; background: #ffffff; color: #333333; border: 1px solid #cccccc; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 1em; display: inline-block; text-align: center;">通常CSV</button>
                    
                    <button type="submit" name="type" value="h1" style="flex: 1; padding: 12px; background: #0073aa; color: #ffffff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 1em; display: inline-block; text-align: center;">H1用CSV</button>
                </div>
            </form>
            
            <div style="border-top: 1px solid #eee; margin-top: 20px; padding-top: 15px; text-align: center; font-size: 0.8em; color: #888; line-height: 1.5;">
                サーバー内マスター同期日: <?php echo date('Y/m/d H:i', $master_time); ?><br>
                ※不正アクセス防止のため、ダウンロードログを記録しています。
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
new DMR_CSV_Downloader_Front();
