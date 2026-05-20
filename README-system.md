リポジトリ内の4ファイル完全分離構成、WPSDログの不規則なスペース分割の吸収、および交信終了（`end of voice`）同期タイミングといった最新のシステム仕様をすべて網羅し、ASCIIアートによるフォルダ構成・相関図を組み込んだ決定版の **`README-system.md`** を再作成しました。

これをそのままシステム全体のマスタードキュメントとして上書き保存してご活用ください。

---

# DMR Hotspot Log System — システム全体仕様書 (README-system.md)

**システム名:** DMR Hotspot Log System (WPSD対応・DBカラム自動解析版)

**最終更新:** 2026-05-20

**作成者:** JI2TAB / あいちデジタル通信ハムクラブ JJ2YYK

---

## 📋 1. システム概要

本システムは、DMR（Digital Mobile Radio）ホットスポットの交信ログをWPSD/Pi-Star環境からリアルタイムに収集し、Webサイト上にユーザー名（団体名）付きで動的表示するとともに、無線機用のコンタクトリストCSVデータを自動生成・配信する統合システムです。

最新バージョンでは、従来のようにテキストファイル（`user.csv`）を毎回パースする方式を廃止し、親プラグイン（Master Base）がMySQLデータベースに直接インポートした名簿テーブル（`wp_dmrcm_users` 等）を全自動で探索・解析して100%確実に名前解決（Name解決）を行うアーキテクチャへ進化しました。

---

## 📁 2. システムファイル・フォルダ一覧

GitHubリポジトリ（`PiStarSendLOG_V2`）およびWordPressプラグインディレクトリ内は、機能保守性を最大化するため以下の**4つの独立したプラグインファイル**およびPi-Star側スクリプトに完全分離・整理されています。

```text
[WordPress側（Webサーバー）]
wp-content/
└── plugins/
    ├── hotspot-receiver/
    │   └── hotspot-receiver.php       # 交信ログ受信・DB自動解析・JSON保存
    ├── hotspot-display/
    │   └── hotspot-display.php        # [hotspot_log_display] ショートコード・AJAX更新
    ├── dmr-radio-csv-maker/
    │   └── Dmr_radio_csv_maker.php    # 無線機用CSV生成コアエンジン
    └── dmr-radio-csv-downloader/
        └── Dmr_radio_csv_downloader.php # ユーザー認証・CSVダウンロードUI
wp-content/uploads/
└── hotspot/
    └── {ノード名}/                     # 例: yyk-tgif/
        └── inbox-YYYYMMDD-HHmmss.json # 完全同期された交信ログ（1交信1ファイル）

[Pi-Star / WPSD側（Raspberry Pi）]
/root/
├── hotspot_setup.sh                    # 環境自動構築シェルスクリプト
└── hotspot_watch_test.py               # WPSDログ常駐監視・確定時送信スクリプト
/etc/systemd/system/
└── hotspot_watch.service               # 監視スクリプトを自動常駐化するユニット定義
/var/log/pi-star/
└── MMDVM-YYYY-MM-DD.log                # MMDVM/WPSDが出力する生ログ（監視対象）

```

---

## 🔄 3. システムコンポーネント相関図

WPSDでの交信終了から、MySQLデータベースでの自動名前解決を経て、Webブラウザへリアルタイム表示されるまでの相関関係です。

```text
 +--------------------------------------------------------------------------------+
 |                          Pi-Star / WPSD (Raspberry Pi)                         |
 |                                                                                |
 |  [無線機 / ネットワーク]                                                       |
 |         │                                                                      |
 |         ▼ PTT離す (交信終了)                                                   |
 |  +──────────────────────+                                                      |
 |  │ MMDVM生ログファイル  │                                                      |
 |  │ (MMDVM-YYYY-MM-DD)   │                                                      |
 |  +──────────┬───────────+                                                      |
 |             │                                                                  |
 |             │ (リアルタイム監視: "end of voice" を検知)                        |
 |             ▼                                                                  |
 |  +──────────────────────+                                                      |
 |  │hotspot_watch_test.py │                                                      |
 |  │       (v4.1.0)       │                                                      |
 |  +──────────┬───────────+                                                      |
 +-------------│------------------------------------------------------------------+
               │
               │ [HTTPS POST送信] (コールサイン情報をJSONでパケット化)
               │ エンドポイント: /wp-json/hotspot/ingest
               ▼
 +--------------------------------------------------------------------------------+
 |                           WordPressサーバー (Web)                              |
 |                                                                                |
 |  ======================= 【ログ受信 & 名前解決処理】 =======================   |
 |                                                                                |
 |  +──────────────────────+                                                      |
 |  │ hotspot-receiver.php │                                                      |
 |  │       (v4.2.0)       │                                                      |
 |  +──────────┬───────────+                                                      |
 |             │                                                                  |
 |             │ 🔍 [SQLクエリ] 自動巡回・改行ゴミ(\r)除去完全一致照合            |
 |             ▼                                                                  |
 |      [MySQLデータベース]                                                       |
 |      └── テーブル: wp_dmrcm_users (または wp_dmr_users)                         |
 |           ├── 列: callsign  ➔ 送信局のコールサイン                             |
 |           └── 列: name      ➔ CSV3列目の団体名・名前 (1列統合型)               |
 |             │                                                                  |
 |             │ ➔ 100%合致した【団体名・お名前】を引っ張り出す                   |
 |             ▼                                                                  |
 |  +──────────────────────────────────────────────────────────+                  |
 |  │ 💾 ログフォルダへのJSON書き出し                          │                  |
 |  │ uploads/hotspot/{ノード名}/inbox-YYYYMMDD-HHmmss.json     │                  |
 |  │ (中身: "callsign": "JJ2YYK", "name": "あいちデジタル...")│                  |
 |  +──────────────────────────────────────────────────────────+                  |
 |                                                                                |
 |                                                                                |
 |  ======================= 【フロントエンド表示処理】 =======================   |
 |                                                                                |
 |   [Webブラウザ (固定ページ)] 💻                                                 |
 |         │                                                                      |
 |         │ 🔄 30秒ごとの AJAX非同期通信 (`hld_display_refresh`)                 |
 |         ▼                                                                  |
 |  +──────────────────────+                                                      |
 |  │  hotspot-display.php │ ➔ ショートコード [hotspot_log_display]               |
 |  │       (v4.2.0)       │ ➔ 重複排除 & 最新順ソートしてHTMLテーブル化         |
 |  +----------------------+                                                      |
 |                                                                                |
 |  ====================== 【無線機用CSV配信エンジン】 ======================   |
 |                                                                                |
 |  +────────────────────────────+      +──────────────────────────────+          |
 |  │  Dmr_radio_csv_maker.php   │ ◄──► │ Dmr_radio_csv_downloader.php │          |
 |  │ (無線機幅最適化CSV生成)    │      │ (コールサイン・DMR IDユーザー認証)│          |
 |  +────────────────────────────+      +──────────────────────────────+          |
 +--------------------------------------------------------------------------------+

```

---

## 🔄 4. 詳細なデータの流れ

### ① 交信終了ログの発生から表示まで

1. **交信終了の記録:** 無線局がPTTを離すと、WPSD/MMDVMが `/var/log/pi-star/MMDVM-YYYY-MM-DD.log` に `end of voice` または `transmission lost` を記録します。
2. **ログの検知と送信:** `hotspot_watch_test.py` が、WPSD特有の不規則なスペース分割を正規表現で吸収しながらログを検知。話し始めでのフライング送信を行わず、交信が確定した瞬間にJSONパケットを構築してWordPressの REST API（`/wp-json/hotspot/ingest`）へPOST送信します。
3. **名簿DBによる動的自動解決 (`hotspot-receiver.php`):**
* 受信したコールサインをもとに、MySQLデータベース内から `LIKE '%dmr%'` で名簿テーブル（`wp_dmrcm_users` または `wp_dmr_users`）を自動探索。
* 対象テーブルの列構造（`name` または `full_name`）をその場で動的解析します。
* RadioID.netからインポートされた名簿特有の、コールサイン末尾に含まれる不可視の改行ゴミ（`\r`）をSQLの `REPLACE` 処理で完全破壊し、クリーンな状態で突合します。
* CSV3列目に格納されている「団体名（`TokaiDigitalCommunicationHAMClub`等）」や「ファーストネーム」を丸ごと1列統合型で抽出。
* `wp-content/uploads/hotspot/{ノード名}/inbox-*.json` へクリーンに保存します。


4. **フロントエンドでのリアルタイム描画 (`hotspot-display.php`):**
ショートコード `[hotspot_log_display]` が配置されたWebページは、30秒ごとにバックグラウンド（AJAX）で最新のJSONファイルを読み込み、最新の交信順（重複コールサインは最新1件に集約）に並び替えて名前付きでHTMLテーブルを出力します。

### ② CSVダウンロードの流れ

1. 親プラグインがインポートしたマスターデータをMySQL（`wp_dmrcm_users`）に安全に保持。
2. ユーザーがWebUI上でコールサインとDMR IDを入力すると、DBレコードと完全一致照合して認証を行います。
3. `Dmr_radio_csv_maker.php` がHytera H1などの無線機表示幅に最適化された列配置（名前・団体名を優先した独自レイアウト）のカスタムCSVを動的生成し、`Dmr_radio_csv_downloader.php` を通じて安全にユーザーへ配信します。

---

## 📊 5. 名簿データベースの正確な保持構造

`hotspot-receiver.php` および `hotspot-display.php` がダイレクトに読みに行くMySQLテーブルの物理構造は以下の通りです。

| カラム名（物理名） | 格納データ型 | 説明・最新の吸収ロジック |
| --- | --- | --- |
| **`callsign`** | VARCHAR等 | 無線局のコールサイン。末尾にインポート時の `\r`（改行コード）が含まれている場合があるため、プラグイン側で完全除去して照合。 |
| **`name`** または **`full_name`** | VARCHAR等 | ユーザー名・クラブ団体名。RadioID.netの**user.csvの3列目データが1つの列に丸ごと格納**されている（4列目のLAST_NAMEは空のため、この列のみで名前解決が完了する）。 |
| **`radio_id`** | INT / VARCHAR | 7桁のDMR ID番号。 |

---

## 🛠️ 6. 各コンポーネントの変更履歴

| 日付 | 該当コンポーネント | 変更・修正内容（バージョン） |
| --- | --- | --- |
| **2026-05-20** | **hotspot-receiver.php (v4.2.0)**<br>

<br>**hotspot-display.php (v4.2.0)** | **【最新・名簿DB完全直結化】**<br>

<br>・テキストキャッシュ方式を廃止し、MySQLの `wp_dmrcm_users` テーブルへのダイレクト検索ロジックを両ファイルに実装。<br>

<br>・`\r` ゴミによる一致判定空振りをSQLレベルで完全解決。<br>

<br>・CSV3列目の統合型団体名（`TokaiDigitalCommunicationHAMClub`等）が `---` にならず100%出力されるよう二重のガードロジックを構築。<br>

<br>・表示と受信の処理を完全に2ファイルへ分離。 |
| **2026-05-19** | **hotspot_watch_test.py (v4.1.0)** | **【最新・WPSDログ同期＆フライング防止】**<br>

<br>・WPSDログの可変スペースを正規表現（`re`モジュール）で完全吸収。<br>

<br>・`voice header` での送信を廃止し、`end of voice` / `transmission lost`（交信確定時）に1発だけクリーンに送る仕様へ修正。 |
| 2026-04-14 | hotspot-total-v2.php (v2.4.12) | 30秒ごとの自動リロード（AJAX）およびキャッシュ無効化ヘッダーの試験導入。 |
| 2026-04-14 | hotspot_setup.sh (v2.2.2) | ネットワーク識別用の `net_label`（英数10文字以内）入力項目の追加。 |
| 2026-04-08 | システム全般 | システム初版リリース。旧Pi-Star形式のJSONパケットによる運用開始。 |

---

*あいちデジタル通信ハムクラブ JJ2YYK / JI2TAB*
