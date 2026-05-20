<?php
/**
 * Plugin Name: DMR CSV Master Base
 * Plugin URI:   https://github.com/ji2tab
 * Description: DMR Radio ID データベース基盤プラグイン。RadioID.net user.csv を1日1回ストリーム取得（メモリ安全）。既定で日本局のみ取り込み低負荷化。radio_id / コールサイン両対応の名前解決 API を提供。
 * Version:      4.2.0
 * Author:       JI2TAB / JJ2YYK
 * License:      GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DMRCM_VERSION',   '4.2.0' );
define( 'DMRCM_TABLE',     'dmr_users' );
define( 'DMRCM_CACHE_TTL', HOUR_IN_SECONDS );
define( 'DMRCM_TIMEZONE',  'Asia/Tokyo' );

// 取得元（CSV主=実構造確認済み、不可なら JSON フォールバック）
// user.csv: ヘッダー有り RADIO_ID,CALLSIGN,FIRST_NAME,LAST_NAME,CITY,STATE,COUNTRY
//           FIRST_NAME にフルネーム/団体名が完全形、LAST_NAME は空
define( 'DMRCM_SOURCE_CSV',  'https://radioid.net/static/user.csv' );
define( 'DMRCM_SOURCE_JSON', 'https://radioid.net/static/users.json' );

// 既定の取り込み対象国（空=全世界）。オプションで上書き可。
define( 'DMRCM_DEFAULT_COUNTRY', 'Japan' );

// RadioID ポリシー遵守: アプリ名と連絡先を含む User-Agent を明示
define( 'DMRCM_USER_AGENT',  'JJ2YYK-Hotspot-NameResolver/' . DMRCM_VERSION . ' (+https://jj2yyk.forums.gr.jp; admin contact via site)' );

/* ==========================================================================
   メインクラス
   ========================================================================== */
class DMR_CSV_Maker_Master {

    /** @var string テーブル名（WP プレフィックス込み） */
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . DMRCM_TABLE;

        add_action( 'init',       array( $this, 'init_plugin' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_force_update' ) );
        add_action( 'admin_init', function () {
            register_setting( 'dmrcm_group', 'dmrcm_country_filter' );
        } );
        add_action( 'dmr_csv_daily_update_hook', array( $this, 'update_source' ) );
    }

    /* ------------------------------------------------------------------
       初期化 / テーブル作成
    ------------------------------------------------------------------ */
    public function init_plugin() {
        $this->maybe_create_table();
    }

    private function maybe_create_table() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // radio_id 主キー、callsign にインデックス（コールサイン検索を高速・低負荷化）
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            radio_id   VARCHAR(10)  NOT NULL,
            callsign   VARCHAR(20)  NOT NULL DEFAULT '',
            first_name VARCHAR(96)  NOT NULL DEFAULT '',
            last_name  VARCHAR(96)  NOT NULL DEFAULT '',
            country    VARCHAR(32)  NOT NULL DEFAULT '',
            updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (radio_id),
            KEY idx_callsign (callsign)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ------------------------------------------------------------------
       管理メニュー
    ------------------------------------------------------------------ */
    public function add_admin_menu() {
        add_menu_page(
            'DMR CSV Master',
            'DMR CSV 管理',
            'manage_options',
            'dmr-csv-settings',
            array( $this, 'render_admin_page' ),
            'dashicons-database',
            80
        );
    }

    /* ------------------------------------------------------------------
       管理画面 UI
    ------------------------------------------------------------------ */
    public function render_admin_page() {
        global $wpdb;
        $count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
        $last     = get_option( 'dmr_csv_last_updated', '' );
        $src      = get_option( 'dmr_csv_last_source', '' );
        $next_run = wp_next_scheduled( 'dmr_csv_daily_update_hook' );
        $jst      = new DateTimeZone( DMRCM_TIMEZONE );
        ?>
        <div class="wrap">
            <h1>📡 DMR Radio CSV Master
                <span style="font-size:13px;color:#888;font-weight:normal;">
                    v<?php echo esc_html( DMRCM_VERSION ); ?>
                </span>
            </h1>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;margin:20px 0;">
                <h2 style="margin-top:0;">🖥️ システムステータス</h2>
                <table class="widefat striped" style="max-width:680px;">
                    <tr>
                        <th style="width:200px;">データベース状態</th>
                        <td><?php echo $count > 0
                            ? '<span style="color:green;font-weight:bold;">✅ 正常稼働中</span>'
                            : '<span style="color:red;font-weight:bold;">❌ データ未取得</span>'; ?></td>
                    </tr>
                    <tr>
                        <th>登録件数</th>
                        <td><strong><?php echo number_format( $count ); ?></strong> 件</td>
                    </tr>
                    <tr>
                        <th>最終更新日時</th>
                        <td><?php echo $last
                            ? '<strong>' . esc_html( $last ) . '</strong>'
                            : '<span style="color:#999;">データがありません。手動取得を実行してください。</span>'; ?></td>
                    </tr>
                    <tr>
                        <th>取得ソース</th>
                        <td><?php echo $src ? '<code>' . esc_html( $src ) . '</code>' : '<span style="color:#999;">―</span>'; ?></td>
                    </tr>
                    <tr>
                        <th>次回自動更新</th>
                        <td><?php echo $next_run
                            ? '<strong>' . esc_html(
                                ( new DateTime( '@' . $next_run ) )
                                    ->setTimezone( $jst )
                                    ->format( 'Y年m月d日 H:i:s' )
                              ) . '</strong>'
                            : '<span style="color:#d63638;font-weight:bold;">⚠ スケジュール未登録（プラグインを再有効化してください）</span>'; ?></td>
                    </tr>
                </table>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;margin:20px 0;">
                <h2 style="margin-top:0;">🔄 データの強制取得</h2>
                <p>バックグラウンドで1日1回（早朝 AM4:00 JST）自動更新されます。すぐに最新データが必要な場合のみ以下を実行してください。</p>
                <p style="color:#d63638;">
                    <strong>⚠ RadioID.net の利用ポリシー上、取得は最小限にしてください。短時間に連打しないこと。取得・インポートには数十秒〜数分かかります。</strong>
                </p>
                <form method="post">
                    <?php wp_nonce_field( 'dmr_csv_force_update', 'dmr_csv_nonce' ); ?>
                    <input type="submit" name="dmr_csv_force_update"
                           class="button button-primary button-large"
                           value="🔄 今すぐ取得・更新する">
                </form>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;margin:20px 0;">
                <h2 style="margin-top:0;">🌐 取り込み範囲</h2>
                <p>ロリポップ等の共有サーバーでは全世界（約100万件）の取り込みは負荷・容量・タイムアウトの原因になります。<strong>表に出るのは日本局のみ</strong>のため、既定では日本に限定しています。</p>
                <form method="post" action="options.php">
                    <?php settings_fields( 'dmrcm_group' ); ?>
                    <?php $cf = get_option( 'dmrcm_country_filter', DMRCM_DEFAULT_COUNTRY ); ?>
                    <label style="display:block;margin-bottom:8px;">
                        <input type="radio" name="dmrcm_country_filter" value="Japan"
                            <?php checked( $cf, 'Japan' ); ?>>
                        日本局のみ取り込む（推奨・最軽量）
                    </label>
                    <label style="display:block;margin-bottom:8px;">
                        <input type="radio" name="dmrcm_country_filter" value=""
                            <?php checked( $cf, '' ); ?>>
                        全世界を取り込む（大容量・低速・共有サーバー非推奨）
                    </label>
                    <?php submit_button( '取り込み範囲を保存', 'secondary' ); ?>
                    <p class="description">保存後、上の「今すぐ取得・更新する」を1回押すと新しい範囲で再構築されます。</p>
                </form>
            </div>

            <div style="background:#f0f0f1;border:1px solid #ccd0d4;border-radius:4px;padding:16px;font-size:13px;color:#555;">
                <strong>プラグイン情報</strong><br>
                バージョン: <?php echo esc_html( DMRCM_VERSION ); ?> ／ 開発者: JI2TAB / JJ2YYK<br>
                取り込み範囲: <strong><?php echo esc_html( get_option( 'dmrcm_country_filter', DMRCM_DEFAULT_COUNTRY ) ?: '全世界' ); ?></strong><br>
                他プラグイン（Hotspot 受信システム等）が radio_id <strong>またはコールサイン</strong>からユーザー名を割り出すためのコア基盤として動作します。<br>
                提供API: <code>dmr_get_user_info($radio_id)</code> / <code>dmr_get_user_by_callsign($callsign)</code>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       強制取得ハンドラ
    ------------------------------------------------------------------ */
    public function handle_force_update() {
        if (
            ! isset( $_POST['dmr_csv_force_update'] )
            || ! check_admin_referer( 'dmr_csv_force_update', 'dmr_csv_nonce' )
            || ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        $result = $this->update_source();

        if ( $result === true ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible">'
                   . '<p><strong>✅ 最新データを取得し、データベースを更新しました。</strong></p></div>';
            } );
        } else {
            add_action( 'admin_notices', function () use ( $result ) {
                echo '<div class="notice notice-error is-dismissible">'
                   . '<p><strong>❌ 取得失敗:</strong> ' . esc_html( $result ) . '</p></div>';
            } );
        }
    }

    /* ------------------------------------------------------------------
       取得メイン（CSV優先 → 失敗時 JSON フォールバック）
       戻り値: true | エラーメッセージ(string)
    ------------------------------------------------------------------ */
    public function update_source() {
        $upload = wp_upload_dir();
        $dir    = $upload['basedir'] . '/dmr_csv_maker';
        wp_mkdir_p( $dir );

        // --- 1) CSV を試す（実構造確認済みの主ソース・ストリーム処理でメモリ安全） ---
        $tmp_csv = $dir . '/user.csv.tmp';
        $csv_ok  = $this->download_to( DMRCM_SOURCE_CSV, $tmp_csv );

        if ( $csv_ok === true && $this->looks_like_csv( $tmp_csv ) ) {
            $imported = $this->import_csv_to_db( $tmp_csv );
            if ( $imported === true ) {
                // CSV Downloader 互換のため取得した user.csv を正規ファイルとして残す
                @rename( $tmp_csv, $dir . '/user.csv' );
                $this->mark_updated( 'user.csv' );
                return true;
            }
            @unlink( $tmp_csv );
            // CSV インポート失敗 → JSON フォールバックへ
        } else {
            @unlink( $tmp_csv );
        }

        // --- 2) JSON フォールバック ---
        $tmp_json = $dir . '/users.json.tmp';
        $json_ok  = $this->download_to( DMRCM_SOURCE_JSON, $tmp_json );

        if ( $json_ok !== true || $this->looks_like_html( $tmp_json ) ) {
            @unlink( $tmp_json );
            return 'RadioID.net からの取得に失敗しました（CSV/JSONともに不可）: '
                 . ( is_string( $csv_ok ) ? $csv_ok : ( is_string( $json_ok ) ? $json_ok : 'HTML応答またはインポート失敗' ) );
        }

        $imported = $this->import_json_to_db( $tmp_json );
        @unlink( $tmp_json );
        if ( $imported !== true ) {
            return $imported;
        }

        // CSV Downloader 互換: DB から user.csv を再生成して残す
        $this->export_db_to_csv( $dir . '/user.csv' );
        $this->mark_updated( 'users.json' );
        return true;
    }

    /** 先頭を見て HTML エラーページか判定 */
    private function looks_like_html( $path ) {
        $fh = @fopen( $path, 'r' );
        if ( ! $fh ) return true;
        $head = (string) fread( $fh, 512 );
        fclose( $fh );
        return ( stripos( $head, '<html' ) !== false || stripos( $head, '<!doctype' ) !== false );
    }

    /**
     * DB の内容を CSV Downloader 互換形式で書き出す。
     * Downloader は user.csv をヘッダー無し・列順
     *  [0]radio_id [1]callsign [2]name(=first_name) [3]last_name [4]city [5]state [6]country
     * として読むため、その並びを維持する（city/state は保持していないので空）。
     */
    private function export_db_to_csv( $dest ) {
        global $wpdb;
        $fh = @fopen( $dest . '.tmp', 'w' );
        if ( ! $fh ) return;

        $offset = 0;
        $limit  = 2000;
        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT radio_id, callsign, first_name, last_name, country
                     FROM {$this->table} ORDER BY radio_id LIMIT %d OFFSET %d",
                    $limit, $offset
                ),
                ARRAY_A
            );
            if ( empty( $rows ) ) break;

            foreach ( $rows as $r ) {
                fputcsv( $fh, array(
                    $r['radio_id'],
                    $r['callsign'],
                    $r['first_name'],
                    $r['last_name'],
                    '',               // city（未保持）
                    '',               // state（未保持）
                    $r['country'],
                ) );
            }
            $offset += $limit;
        }
        fclose( $fh );
        @rename( $dest . '.tmp', $dest );
    }

    /** ダウンロード（ストリーム保存）。true | エラーメッセージ */
    private function download_to( $url, $dest ) {
        $url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'v=' . date( 'Y-m-d_His' );
        $res = wp_remote_get( $url, array(
            'timeout'    => 300,
            'stream'     => true,
            'filename'   => $dest,
            'user-agent' => DMRCM_USER_AGENT,
        ) );

        if ( is_wp_error( $res ) ) {
            return $res->get_error_message();
        }
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code && (int) $code !== 200 ) {
            return 'HTTP ' . $code;
        }
        if ( ! file_exists( $dest ) || filesize( $dest ) === 0 ) {
            return '空のレスポンス';
        }
        return true;
    }

    /** 先頭を見て HTML エラーページでないか確認 */
    private function looks_like_csv( $path ) {
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) return false;
        $line = (string) fgets( $fh );
        fclose( $fh );
        if ( stripos( $line, '<html' ) !== false || stripos( $line, '<!doctype' ) !== false ) {
            return false;
        }
        return ( strpos( $line, ',' ) !== false );
    }

    private function mark_updated( $source ) {
        $jst = new DateTimeZone( DMRCM_TIMEZONE );
        $dt  = new DateTime( 'now', $jst );
        update_option( 'dmr_csv_cache_version', time() );
        update_option( 'dmr_csv_last_updated',  $dt->format( 'Y年m月d日 H:i:s' ) );
        update_option( 'dmr_csv_last_source',   $source );
    }

    /* ------------------------------------------------------------------
       CSV → DB（ヘッダ有無の両対応）
    ------------------------------------------------------------------ */
    private function import_csv_to_db( $filepath ) {
        global $wpdb;
        @set_time_limit( 0 );

        $fh = fopen( $filepath, 'r' );
        if ( ! $fh ) return 'CSVファイルを開けませんでした。';

        $first = fgetcsv( $fh );
        if ( ! $first ) { fclose( $fh ); return 'CSVが空です。'; }

        // 1行目がヘッダか判定（RADIO_ID 等の文字列か、数値IDか）
        $upper      = array_map( 'strtoupper', array_map( 'trim', (array) $first ) );
        $has_header = in_array( 'RADIO_ID', $upper, true )
                   || in_array( 'CALLSIGN', $upper, true )
                   || ! ctype_digit( trim( (string) ( $first[0] ?? '' ) ) );

        if ( $has_header ) {
            $col = array(
                'radio_id'   => $this->find_col( $upper, array( 'RADIO_ID', 'ID' ),         0 ),
                'callsign'   => $this->find_col( $upper, array( 'CALLSIGN' ),                1 ),
                'first_name' => $this->find_col( $upper, array( 'FIRST_NAME','FIRSTNAME','NAME','FNAME' ), 2 ),
                'last_name'  => $this->find_col( $upper, array( 'LAST_NAME','LASTNAME','SURNAME','LNAME' ), 3 ),
                'country'    => $this->find_col( $upper, array( 'COUNTRY' ),                 6 ),
            );
            $start_row = null; // 次の fgetcsv から
        } else {
            // ヘッダ無し: 列順は RadioID 既定 (id,callsign,fname,lname,city,state,country)
            $col = array(
                'radio_id'   => 0,
                'callsign'   => 1,
                'first_name' => 2,
                'last_name'  => 3,
                'country'    => 6,
            );
            $start_row = $first; // 1行目もデータ
        }

        $wpdb->query( "TRUNCATE TABLE {$this->table}" );

        // 取り込み対象国（空=全世界）。大文字小文字を無視して部分一致。
        $country_filter = trim( (string) get_option( 'dmrcm_country_filter', DMRCM_DEFAULT_COUNTRY ) );
        $cf_lower       = $country_filter !== '' ? strtolower( $country_filter ) : '';

        $batch = array();
        $bsize = 200;

        $process = function ( $row ) use ( &$batch, $col, $wpdb, $cf_lower ) {
            if ( count( $row ) < 2 ) return;
            $rid = trim( (string) ( $row[ $col['radio_id'] ] ?? '' ) );
            if ( $rid === '' || ! ctype_digit( $rid ) ) return;

            $ctry = trim( (string) ( $row[ $col['country'] ] ?? '' ) );

            // 国フィルタ（既定: 日本のみ）。ロリポップ負荷・容量対策。
            if ( $cf_lower !== '' && stripos( $ctry, $cf_lower ) === false ) {
                return;
            }

            $batch[] = $wpdb->prepare(
                '(%s,%s,%s,%s,%s,NOW())',
                $rid,
                strtoupper( trim( (string) ( $row[ $col['callsign'] ]   ?? '' ) ) ),
                trim( (string) ( $row[ $col['first_name'] ] ?? '' ) ),
                trim( (string) ( $row[ $col['last_name'] ]  ?? '' ) ),
                $ctry
            );
        };

        if ( $start_row !== null ) {
            $process( $start_row );
        }
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            $process( $row );
            if ( count( $batch ) >= $bsize ) { $this->exec_batch( $batch ); $batch = array(); }
        }
        if ( ! empty( $batch ) ) $this->exec_batch( $batch );

        fclose( $fh );
        return true;
    }

    /* ------------------------------------------------------------------
       JSON → DB
       実構造: {"users":[{ "radio_id":1023007, "id":1023007,
                          "callsign":"VA3BOC", "name":"Hans Juergen",
                          "fname":"Hans Juergen", "surname":"",
                          "country":"Canada", "city":"...", "state":"..." }, ... ]}
       ※ name にフルネームが完全な形で入っているため name を最優先で使用。
    ------------------------------------------------------------------ */
    private function import_json_to_db( $filepath ) {
        global $wpdb;
        @set_time_limit( 0 );

        $raw = file_get_contents( $filepath );
        if ( $raw === false || $raw === '' ) return 'JSONファイルを読めませんでした。';

        $data = json_decode( $raw, true );
        unset( $raw ); // 大きな生文字列を即解放（ロリポップのメモリ対策）
        if ( ! is_array( $data ) ) return 'JSONの解析に失敗しました。';

        // 実構造は "users" 配列。形式差異に備え results / 直配列もフォールバック
        if ( isset( $data['users'] ) && is_array( $data['users'] ) ) {
            $rows = $data['users'];
        } elseif ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
            $rows = $data['results'];
        } else {
            $rows = $data;
        }
        unset( $data );

        if ( ! is_array( $rows ) || empty( $rows ) ) return 'JSONにユーザーデータが見つかりません。';

        $wpdb->query( "TRUNCATE TABLE {$this->table}" );

        $country_filter = trim( (string) get_option( 'dmrcm_country_filter', DMRCM_DEFAULT_COUNTRY ) );
        $cf_lower       = $country_filter !== '' ? strtolower( $country_filter ) : '';

        $batch = array();
        $bsize = 200;

        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) ) continue;

            // radio_id を主キーに（id と同値だが radio_id を優先）
            $rid = trim( (string) ( $r['radio_id'] ?? ( $r['id'] ?? '' ) ) );
            if ( $rid === '' || ! ctype_digit( $rid ) ) continue;

            $ctry = trim( (string) ( $r['country'] ?? '' ) );

            // 国フィルタ（既定: 日本のみ）
            if ( $cf_lower !== '' && stripos( $ctry, $cf_lower ) === false ) {
                continue;
            }

            $call = strtoupper( trim( (string) ( $r['callsign'] ?? '' ) ) );

            // name にフルネーム/団体名が完全形で入っている → 最優先。
            // 無い場合のみ fname (+ surname) を結合。
            $full = trim( (string) ( $r['name'] ?? '' ) );
            $fn   = trim( (string) ( $r['fname']   ?? '' ) );
            $ln   = trim( (string) ( $r['surname'] ?? '' ) );

            if ( $full !== '' ) {
                // first_name に full を格納（既存表示ロジックが first+last を
                // 結合するため、full をそのまま first に入れれば崩れない）
                $store_fn = $full;
                $store_ln = '';
            } else {
                $store_fn = $fn;
                $store_ln = $ln;
            }

            $batch[] = $wpdb->prepare(
                '(%s,%s,%s,%s,%s,NOW())',
                $rid, $call, $store_fn, $store_ln, $ctry
            );

            if ( count( $batch ) >= $bsize ) { $this->exec_batch( $batch ); $batch = array(); }
        }
        if ( ! empty( $batch ) ) $this->exec_batch( $batch );

        return true;
    }

    /** バッチ INSERT */
    private function exec_batch( array $rows ) {
        global $wpdb;
        $wpdb->query(
            "INSERT IGNORE INTO {$this->table}
                (radio_id, callsign, first_name, last_name, country, updated_at)
             VALUES " . implode( ',', $rows )
        );
    }

    /** ヘッダ配列から列インデックスを検索 */
    private function find_col( array $header, array $candidates, $default ) {
        foreach ( $candidates as $name ) {
            $i = array_search( $name, $header, true );
            if ( $i !== false ) return (int) $i;
        }
        return (int) $default;
    }
}

new DMR_CSV_Maker_Master();

/* ==========================================================================
   他プラグイン用 API
   ========================================================================== */

/**
 * radio_id からユーザ情報を返す（v2.x/v3.x と完全互換・既存プラグイン維持用）
 *
 * @param  string|int $radio_id
 * @return array|false ['radio_id','callsign','first_name','last_name','full_name'] | false
 */
if ( ! function_exists( 'dmr_get_user_info' ) ) {
    function dmr_get_user_info( $radio_id ) {
        global $wpdb;

        $radio_id = trim( (string) $radio_id );
        if ( $radio_id === '' ) return false;

        $ver       = (int) get_option( 'dmr_csv_cache_version', 0 );
        $cache_key = 'dmr_u_' . $radio_id . '_' . $ver;

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $table = $wpdb->prefix . DMRCM_TABLE;
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT radio_id, callsign, first_name, last_name
                 FROM {$table} WHERE radio_id = %s LIMIT 1",
                $radio_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            set_transient( $cache_key, false, 5 * MINUTE_IN_SECONDS );
            return false;
        }

        $result = array(
            'radio_id'   => $row['radio_id'],
            'callsign'   => $row['callsign'],
            'first_name' => $row['first_name'],
            'last_name'  => $row['last_name'],
            'full_name'  => trim( $row['first_name'] . ' ' . $row['last_name'] ),
        );

        set_transient( $cache_key, $result, DMRCM_CACHE_TTL );
        return $result;
    }
}

/**
 * コールサインからユーザ情報を返す（新設・Hotspot Receiver 用）
 * idx_callsign インデックスを使う軽量クエリ。結果は cache_version 付きでキャッシュ。
 *
 * @param  string $callsign
 * @return array|false ['radio_id','callsign','first_name','last_name','full_name'] | false
 */
if ( ! function_exists( 'dmr_get_user_by_callsign' ) ) {
    function dmr_get_user_by_callsign( $callsign ) {
        global $wpdb;

        $callsign = strtoupper( trim( (string) $callsign ) );
        if ( $callsign === '' ) return false;

        $ver       = (int) get_option( 'dmr_csv_cache_version', 0 );
        $cache_key = 'dmr_c_' . md5( $callsign ) . '_' . $ver;

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $table = $wpdb->prefix . DMRCM_TABLE;

        // インポート時に callsign は大文字正規化済み。インデックスを活かす等価比較。
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT radio_id, callsign, first_name, last_name
                 FROM {$table} WHERE callsign = %s LIMIT 1",
                $callsign
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            // 念のため空白差異対策の保険（1回・LIMIT 1）。失敗も短期キャッシュ。
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT radio_id, callsign, first_name, last_name
                     FROM {$table} WHERE UPPER(TRIM(callsign)) = %s LIMIT 1",
                    $callsign
                ),
                ARRAY_A
            );
        }

        if ( ! $row ) {
            set_transient( $cache_key, false, 30 * MINUTE_IN_SECONDS );
            return false;
        }

        $result = array(
            'radio_id'   => $row['radio_id'],
            'callsign'   => $row['callsign'],
            'first_name' => $row['first_name'],
            'last_name'  => $row['last_name'],
            'full_name'  => trim( $row['first_name'] . ' ' . $row['last_name'] ),
        );

        set_transient( $cache_key, $result, DMRCM_CACHE_TTL );
        return $result;
    }
}

/* ==========================================================================
   WP-Cron（毎日 AM4:00 JST）
   ========================================================================== */
register_activation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'dmr_csv_daily_update_hook' );

    $tz   = new DateTimeZone( DMRCM_TIMEZONE );
    $date = new DateTime( 'tomorrow 04:00:00', $tz );
    wp_schedule_event( $date->getTimestamp(), 'daily', 'dmr_csv_daily_update_hook' );

    ( new DMR_CSV_Maker_Master() )->init_plugin();
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'dmr_csv_daily_update_hook' );
} );
