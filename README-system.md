# DMR Hotspot Log System — システム全体ドキュメント

**システム名:** DMR Hotspot Log System  
**最終更新:** 2026-04-14  
**作成者:** JI2TAB / あいちデジタル通信ハムクラブ JJ2YYK

---

## システム概要

DMR（Digital Mobile Radio）ホットスポットへの交信ログをリアルタイムでWebサイトに表示し、DMRユーザーデータベースのCSVファイルを無線機へダウンロード提供するシステムです。

Pi-Star（Raspberry Pi）上の監視サービスと、WordPressプラグイン群が連携して動作します。

---

## 構成コンポーネントと現行バージョン

| ファイル | 種別 | バージョン | 動作環境 |
|---|---|---|---|
| `hotspot_setup.sh` | シェルスクリプト | 2.2.2 | Pi-Star（Raspberry Pi） |
| `hotspot-total-v2.php` | WordPressプラグイン | 2.4.12 | WordPress（Webサーバー） |
| `dmr-radio-csv-maker.php` | WordPressプラグイン | 1.4.3 | WordPress（Webサーバー） |

---

## データの流れ

### 1. 交信ログの流れ

```
PTTを押して離す
  → MMDVMがログに記録（/var/log/pi-star/MMDVM-YYYY-MM-DD.log）
  → hotspot_watch.py が2秒ごとに監視・検出
  → JSON形式でWordPress REST APIへPOST送信
      {node, source_node, net_label, callsign, timestamp, dmr{slot,src,dst}}
  → hotspot-total-v2.php が受信
      ↓ トークン認証
      ↓ radioid.net user.csv でコールサインから名前を補完
      ↓ NW優先ロジックで保存（5秒以内の同時受信はNWを優先）
      ↓ uploads/hotspot/{ノード名}/inbox-YYYYMMDD-HHmmss.json に保存
  → Webページで30秒ごとに自動更新して表示
```

### 2. CSVダウンロードの流れ

```
radioid.net → user.csv（毎日1回・WP-Cronで自動取得）
  → uploads/hotspot/_cache/user.csv に保存

ユーザーがCSVダウンロードページにアクセス
  → コールサイン・DMR IDを入力
  → user.csv と照合して認証
  → 対象国を選択
  → 通常CSV または H1用CSV を生成・ダウンロード
  → ダウンロードログに記録（download_log.csv）
```

### 3. セットアップスクリプトの配信

```
Pi-Star管理者
  → wget https://jj2yyk.forums.gr.jp/wp-json/hotspot/setup.sh
  → hotspot_setup.sh を取得・実行
  → hotspot_watch.py と systemdサービスを自動生成・登録
```

---

## NW優先ロジック

複数のPi-Starから同一コールサインのデータが届く場合の保存ルールです。

| 既存データ | 新着データ | 時間差 | 結果 |
|---|---|---|---|
| RF | NW | — | NWで上書き |
| NW | RF | **5秒以内** | 既存NWを維持（同時受信と判断） |
| NW | RF | **5秒以上** | RFで上書き（新しい交信と判断） |
| RF | RF | — | 新しいRFで上書き |

---

## ファイル・フォルダ構成

### WordPress側（Webサーバー）

```
wp-content/
  plugins/
    hotspot-total-v2/
      hotspot-total-v2.php        ← 交信ログ受信・表示・CSV管理・setup.sh配信
    dmr-radio-csv-maker/
      dmr-radio-csv-maker.php     ← CSVダウンロード提供
  uploads/
    hotspot/
      _cache/
        user.csv                  ← radioid.netから毎日取得するユーザーDB
        download_log.csv          ← CSVダウンロード履歴
      {ノード名}/                  ← 例: yyk-tgif/
        inbox-YYYYMMDD-HHmmss.json  ← 交信ログ（1交信1ファイル）
```

### Pi-Star側（Raspberry Pi）

```
/root/
  hotspot_setup.sh      ← セットアップスクリプト（wgetで取得）
  hotspot_watch.py      ← MMDVMログ監視・送信スクリプト（自動生成）
/etc/systemd/system/
  hotspot_watch.service ← systemdサービス定義（自動生成）
/var/log/pi-star/
  MMDVM-YYYY-MM-DD.log  ← MMDVMが出力する交信ログ（監視対象）
```

---

## APIエンドポイント一覧

| エンドポイント | メソッド | 認証 | 用途 |
|---|---|---|---|
| `/wp-json/hotspot/ingest` | POST | X-Hotspot-Tokenヘッダー | Pi-Starから交信ログを受信 |
| `/wp-json/hotspot/setup.sh` | GET | なし | hotspot_setup.shをwgetで配信 |
| `/wp-admin/admin-ajax.php` | POST | nonce | 表示テーブルのAJAX自動更新 |

---

## セットアップ順序

### WordPress側

1. `hotspot-total-v2.php` を `wp-content/plugins/hotspot-total-v2/` に配置して有効化
2. 「設定 → Hotspot 設定」でトークンとノード名を設定
3. 「今すぐ取得」ボタンでradioid.netのuser.csvを取得
4. `dmr-radio-csv-maker.php` を `wp-content/plugins/dmr-radio-csv-maker/` に配置して有効化
5. 交信ログ表示ページに `[hotspot_log_display]` を追加
6. CSVダウンロードページに `[dmr_csv_maker]` を追加

### Pi-Star側

```bash
rpi-rw
wget -O /root/hotspot_setup.sh https://jj2yyk.forums.gr.jp/wp-json/hotspot/setup.sh
chmod +x /root/hotspot_setup.sh
sudo /root/hotspot_setup.sh
```

セットアップ時の入力項目：

| 番号 | 項目 | 例 |
|---|---|---|
| 1 | 接続先サーバー | `jj2yyk.forums.gr.jp` |
| 2 | ノード名 | `yyk-tgif` |
| 3 | APIトークン | （管理者から案内） |
| 4 | ネットワーク表示名 | `TGIF168` / `XLX834Z`（英数半角10文字以内） |

### 動作確認

```bash
# サービス状態確認
sudo systemctl status hotspot_watch

# リアルタイムログ確認
sudo journalctl -u hotspot_watch -f
```

---

## 必要環境

### WordPress側

| 項目 | 要件 |
|---|---|
| WordPress | 5.0以上 |
| PHP | 7.4以上 |
| パーマリンク設定 | 「基本」以外（REST API使用のため） |
| ファイル書き込み権限 | `wp-content/uploads/hotspot/` |

### Pi-Star側

| 項目 | 要件 |
|---|---|
| OS | Pi-Star / WPSD |
| Python | 3.x |
| ネットワーク | インターネット接続必須 |

---

## 各コンポーネントの詳細

### hotspot_setup.sh（v2.2.2）

Pi-Star上でMMDVMログを監視し、交信データをWordPressへ送信するサービスをセットアップするシェルスクリプトです。実行すると `hotspot_watch.py` と `hotspot_watch.service` を自動生成・登録します。

**主な特徴：**
- 起動時に既存ログをスキップ（過去交信の再送信を防止）
- 複数Pi-Starから同一ノードへの送信に対応
- `source_node`・`net_label` フィールドで送信元・ネットワークを識別

→ 詳細: `README.md` / `INSTALL.md`

---

### hotspot-total-v2.php（v2.4.12）

交信ログの受信・保存・表示を担うWordPressプラグインです。

**主な特徴：**
- REST APIでPi-Starからの交信データを受信・保存
- radioid.netのuser.csvでコールサインから名前を自動補完
- NW優先ロジック（5秒以内の同時受信はNWを優先）
- 30秒ごとのAJAX自動更新でリアルタイム表示
- キャッシュ無効化対応
- `hotspot_setup.sh` のwget配信エンドポイント内蔵

→ 詳細: `README-hotspot-total-v2.md`

---

### dmr-radio-csv-maker.php（v1.4.3）

radioid.netのuser.csvをもとに、無線機用コンタクトリストCSVを生成・提供するWordPressプラグインです。

**主な特徴：**
- コールサイン・DMR IDによる認証（radioid.net登録ユーザーのみ）
- 国別フィルター対応
- 通常CSV・H1用CSV（Hytera H1等）の2形式を提供
- H1用CSVは無線機画面での視認性を優先した列配置
- ダウンロード履歴を管理画面で確認可能

→ 詳細: `README-dmr-radio-csv-maker.md`

---

## ドキュメント一覧

| ファイル | 対象 | 内容 |
|---|---|---|
| `README-system.md`（本ファイル） | システム全体 | 全体構成・相関・セットアップ順序 |
| `README.md` | hotspot_setup.sh | シェルスクリプトの概要・目的・送信データ形式 |
| `INSTALL.md` | hotspot_setup.sh | インストール手順・トラブルシューティング |
| `README-hotspot-total-v2.md` | hotspot-total-v2.php | プラグインの機能・設定・仕様・変更履歴 |
| `README-dmr-radio-csv-maker.md` | dmr-radio-csv-maker.php | プラグインの機能・認証・CSV仕様 |

---

## 変更履歴（システム全体）

| 日付 | 変更内容 |
|---|---|
| 2026-04-14 | キャッシュ無効化・AJAX自動更新（30秒）実装（v2.4.12） |
| 2026-04-14 | NW優先ロジックに5秒タイムアウト追加（v2.4.11） |
| 2026-04-14 | 起動時の過去ログスキップ処理追加（v2.2.2/v2.4.10） |
| 2026-04-14 | ネットワーク表示名（net_label）をSrc列に表示（v2.4.9） |
| 2026-04-14 | セットアップにnet_label入力項目追加（v2.2.2） |
| 2026-04-14 | source_node・net_labelフィールド追加（v2.2.1/v2.4.8） |
| 2026-04-14 | NW優先保存ロジック実装（v2.4.6） |
| 2026-04-10 | 通し番号（No.列）追加、表幅改善（v2.4.4） |
| 2026-04-10 | ログ表示UIスマート化・バッジ表示（v2.4.0） |
| 2026-04-10 | setup.sh wgetエンドポイント追加（v2.3.0） |
| 2026-04-08 | システム初版リリース |

---

*あいちデジタル通信ハムクラブ JJ2YYK*
