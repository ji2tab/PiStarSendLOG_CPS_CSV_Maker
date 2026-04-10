# DMR Hotspot Log System

**システム名:** DMR Hotspot Log System  
**作成者:** JI2TAB / あいちデジタル通信ハムクラブ JJ2YYK

---

## システム概要

DMR（Digital Mobile Radio）ホットスポットへの交信ログをリアルタイムでWebサイトに表示し、DMRユーザーデータベースのCSVファイルを無線機へダウンロード提供するシステムです。

Pi-Star（Raspberry Pi）上の監視サービスと、WordPressプラグイン群が連携して動作します。

---

## 構成コンポーネント

| ファイル | 種別 | 動作環境 |
|---|---|---|
| `hotspot_setup.sh` | シェルスクリプト | Pi-Star（Raspberry Pi） |
| `hotspot-total-v2.php` | WordPressプラグイン | WordPress（Webサーバー） |
| `dmr-radio-csv-maker.php` | WordPressプラグイン | WordPress（Webサーバー） |

---

## システム構成図

```
【Pi-Star / Raspberry Pi】
┌─────────────────────────────────────┐
│  MMDVM（DMRホットスポット）          │
│    ↓ 交信発生                        │
│  /var/log/pi-star/MMDVM-*.log       │
│    ↓ 監視                            │
│  hotspot_watch.py                   │
│  （hotspot_setup.sh で生成・登録）   │
└──────────────┬──────────────────────┘
               │ POST /wp-json/hotspot/ingest
               │ JSON形式で交信データを送信
               ↓
【WordPress / Webサーバー】
┌─────────────────────────────────────────────────────┐
│  hotspot-total-v2.php                               │
│  ┌─────────────────────────────────────────────┐   │
│  │ REST API受信エンドポイント                   │   │
│  │  ↓ トークン認証                              │   │
│  │  ↓ radioid.net user.csv で名前を検索         │   │
│  │  ↓ JSONファイルとして保存                    │   │
│  │    uploads/hotspot/{ノード名}/inbox-*.json   │   │
│  └─────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────┐   │
│  │ WP-Cron（毎日1回）                          │   │
│  │  ↓ radioid.net から user.csv を取得・保存   │   │
│  │    uploads/hotspot/_cache/user.csv          │   │
│  └─────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────┐   │
│  │ ショートコード [hotspot_log_display]         │   │
│  │  → 交信ログをWebページに表示                │   │
│  └─────────────────────────────────────────────┘   │
│                                                     │
│  dmr-radio-csv-maker.php                           │
│  ┌─────────────────────────────────────────────┐   │
│  │ ショートコード [dmr_csv_maker]               │   │
│  │  → コールサイン・DMR IDで認証               │   │
│  │  → user.csv から国別CSVを生成・提供         │   │
│  │  → ダウンロードログを記録                   │   │
│  └─────────────────────────────────────────────┘   │
│                                                     │
│  GET /wp-json/hotspot/setup.sh                     │
│  （hotspot_setup.sh をwgetで配信）                  │
└─────────────────────────────────────────────────────┘
               ↑
【無線機ユーザー（ブラウザ）】
  コールサイン・DMR IDで認証
  → 通常CSV / H1用CSV をダウンロード
  → 無線機のコンタクトリストにインポート
```

---

## データの流れ

### 1. 交信ログの流れ
```
PTTを押して離す
  → MMDVMがログに記録
  → hotspot_watch.py が検出
  → WordPress REST APIへPOST送信
  → JSONファイルとして保存
  → Webページに表示（[hotspot_log_display]）
```

### 2. CSVダウンロードの流れ
```
radioid.net → user.csv取得（毎日1回・WP-Cron）
  → uploads/hotspot/_cache/user.csv に保存

ユーザーがCSVダウンロードページにアクセス
  → コールサイン・DMR IDを入力
  → user.csv と照合して認証
  → 国を選択
  → 通常CSV または H1用CSV を生成・ダウンロード
  → ダウンロードログに記録
```

### 3. セットアップスクリプトの配信
```
Pi-Star管理者
  → wget https://yoursite/wp-json/hotspot/setup.sh
  → hotspot_setup.sh を取得
  → 実行してhotspot_watch.py とサービスを設定
```

---

## ファイル・フォルダ構成

### WordPress側（Webサーバー）
```
wp-content/
  plugins/
    hotspot-total-v2/
      hotspot-total-v2.php      ← 交信ログ受信・表示・CSV管理
    dmr-radio-csv-maker/
      dmr-radio-csv-maker.php   ← CSVダウンロード提供
  uploads/
    hotspot/
      _cache/
        user.csv                ← radioid.netから取得したユーザーDB
        download_log.csv        ← CSVダウンロードログ
      {ノード名}/
        inbox-YYYYMMDD-HHmmss.json  ← 交信ログ（1交信1ファイル）
```

### Pi-Star側（Raspberry Pi）
```
/root/
  hotspot_setup.sh     ← セットアップスクリプト
  hotspot_watch.py     ← MMDVMログ監視・送信スクリプト（自動生成）
/etc/systemd/system/
  hotspot_watch.service  ← systemdサービス定義（自動生成）
/var/log/pi-star/
  MMDVM-YYYY-MM-DD.log   ← MMDVMが出力する交信ログ（監視対象）
```

---

## 各コンポーネントの役割

### hotspot_setup.sh
Pi-Star上でMMDVMログを監視し、交信データをWordPressへ送信するサービスをセットアップするシェルスクリプトです。  
実行すると `hotspot_watch.py` と `hotspot_watch.service` を自動生成・登録します。  
→ 詳細: `readme.md`

### hotspot-total-v2.php
交信ログの受信・保存・表示を担うWordPressプラグインです。  
Pi-StarからのPOSTを受け取り、radioid.netのCSVで名前を補完してJSONに保存します。  
またWP-Cronで毎日user.csvを自動取得し、`hotspot_setup.sh` のwget配信も担当します。  
→ 詳細: `Readme_hotspot_total_v2.md`

### dmr-radio-csv-maker.php
radioid.netのuser.csvをもとに、無線機用のコンタクトリストCSVを生成・提供するWordPressプラグインです。  
コールサインとDMR IDによる認証を経てダウンロードでき、ダウンロード履歴を管理画面で確認できます。  
→ 詳細: `Readme_dmr_radio_csv_maker.md`

---

## セットアップ順序

```
1. WordPress側の準備
   ├─ hotspot-total-v2.php をインストール・有効化
   ├─ 管理画面でトークン・ノード名を設定
   ├─ user.csv を手動取得（「今すぐ取得」ボタン）
   └─ dmr-radio-csv-maker.php をインストール・有効化

2. Pi-Star側の準備
   ├─ wget で hotspot_setup.sh を取得
   ├─ chmod +x して実行権限付与
   └─ sudo で実行（サーバー・ノード名・トークンを入力）

3. 動作確認
   ├─ PTTを押して離す
   ├─ journalctl -u hotspot_watch -f でログ確認
   └─ Webサイトのアクセス状況ページで表示確認
```

---

## APIエンドポイント一覧

| エンドポイント | メソッド | 用途 |
|---|---|---|
| `/wp-json/hotspot/ingest` | POST | Pi-Starから交信ログを受信 |
| `/wp-json/hotspot/setup.sh` | GET | hotspot_setup.shをwgetで配信 |

---

## 必要環境

### WordPress側
| 項目 | 要件 |
|---|---|
| WordPress | 5.0以上 |
| PHP | 7.4以上 |
| パーマリンク設定 | 「基本」以外 |
| ファイル書き込み権限 | `wp-content/uploads/hotspot/` |

### Pi-Star側
| 項目 | 要件 |
|---|---|
| OS | Pi-Star / WPSD |
| Python | 3.x |
| ネットワーク | インターネット接続必須 |

---

## ドキュメント一覧

| ファイル | 対象 | 内容 |
|---|---|---|
| `README.md`（本ファイル） | システム全体 | 全体構成・相関・セットアップ順序 |
| `readme.md` | hotspot_setup.sh | シェルスクリプトの概要・目的 |
| `Readme_hotspot_total_v2.md` | hotspot-total-v2.php | プラグインの機能・設定・仕様 |
| `Readme_dmr_radio_csv_maker.md` | dmr-radio-csv-maker.php | プラグインの機能・認証・CSV仕様 |

---

*あいちデジタル通信ハムクラブ JJ2YYK*
