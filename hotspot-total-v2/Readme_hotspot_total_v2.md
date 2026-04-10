# Hotspot Total Solution V2

**プラグイン名:** Hotspot Total Solution V2  
**バージョン:** 2.2.0  
**対象環境:** WordPress 5.0以上 / PHP 7.4以上  
**作成者:** JI2TAB / あいちデジタル通信ハムクラブ JJ2YYK

---

## 概要

DMR（Digital Mobile Radio）ホットスポットの交信ログをWordPressサイトで受信・保存・表示するためのWordPressプラグインです。

Pi-Star（またはWPSD）上で動作する監視スクリプト（`hotspot_watch.py`）と連携し、交信が発生するたびにデータを受け取り、ページ上にリアルタイムで表示します。あわせてradioid.netのユーザーデータベース（user.csv）と連携し、コールサインから名前を自動表示します。

---

## 機能一覧

| 機能 | 内容 |
|---|---|
| **交信ログ受信API** | Pi-StarからのJSONデータをREST APIで受信・保存 |
| **交信ログ表示** | ショートコードでページに交信状況を表示 |
| **RadioID CSV連携** | radioid.netからuser.csvを取得し名前を自動表示 |
| **CSV自動更新** | WP-Cronで毎日1回user.csvを自動取得 |
| **管理画面** | トークン・ノード設定、CSV取得状況の確認・手動更新 |

---

## 仕組み

```
Pi-Star (hotspot_watch.py)
    │
    │ POST /wp-json/hotspot/ingest
    ▼
WordPress (hotspot-total-v2.php)
    │
    ├─ トークン認証
    ├─ radioid.net user.csv で名前を検索
    ├─ JSONファイルとして保存
    │   wp-content/uploads/hotspot/{ノード名}/inbox-YYYYMMDD-HHmmss.json
    │
    └─ ショートコード [hotspot_log_display] でページに表示
```

---

## インストール

1. `hotspot-total-v2.php` を `wp-content/plugins/hotspot-total-v2/` に配置
2. WordPress管理画面の「プラグイン」から有効化
3. 「設定 → Hotspot 設定」でトークンとノード名を設定
4. 「今すぐ取得」ボタンでradioid.netのCSVを取得
5. 表示したいページに `[hotspot_log_display]` を追加

---

## 管理画面の設定項目

### APIエンドポイント
受信URLが表示されます。Pi-Star側の設定で使用します。
```
https://yoursite.example.com/wp-json/hotspot/ingest
```

### トークンマップ
Pi-Starから送られてくるトークンと、対応するノード名（フォルダ名）のペアを設定します。

| 項目 | 説明 |
|---|---|
| トークン | Pi-Star側で設定した認証トークン |
| ノード名 | JSONファイルの保存フォルダ名（例: `yyk-tgif`） |

### 規定の表示ノード
ショートコードでノード指定を省略した場合に使用するノード名を設定します。

### RadioID CSV
radioid.netからのCSV取得状況と更新日時を表示します。「今すぐ更新」で手動取得できます。

---

## ショートコード

### 基本使用（規定ノードを使用）
```
[hotspot_log_display]
```

### ノードを指定する場合
```
[hotspot_log_display node="yyk-tgif"]
```

### 表示内容
| 列 | 内容 |
|---|---|
| DATE | 交信日付 |
| TIME | 交信時刻 |
| Callsign | コールサイン |
| Src | RF（無線）/ NW（ネットワーク） |
| Name | 氏名（radioid.net CSVより） |

同一コールサインは最新の1件のみ表示します。

---

## データ保存形式

受信した交信データはJSON形式で以下のパスに保存されます。

```
wp-content/uploads/hotspot/{ノード名}/inbox-YYYYMMDD-HHmmss.json
```

### JSONの例
```json
{
  "node": "yyk-tgif",
  "callsign": "JJ2YYK",
  "name": "Aichi Digital Comm Ham Club",
  "timestamp": "2026-04-10 09:30:15",
  "timestamp_local": "2026-04-10 09:30:16",
  "dmr": {
    "slot": 2,
    "src": "RF",
    "dst": "168"
  }
}
```

---

## RadioID CSV連携

- 取得元: `https://radioid.net/static/user.csv`
- 保存先: `wp-content/uploads/hotspot/_cache/user.csv`
- 自動更新: 毎日1回（WP-Cron）
- 手動更新: 管理画面「今すぐ更新」ボタン
- 表示: radioid.netの公開日時と取得日時を管理画面に表示

---

## 必要環境

| 項目 | 要件 |
|---|---|
| WordPress | 5.0以上 |
| PHP | 7.4以上 |
| パーマリンク設定 | 「基本」以外に設定（REST APIを使用するため） |
| ファイル書き込み権限 | `wp-content/uploads/hotspot/` への書き込みが必要 |

---

## 関連プラグイン・ツール

| 名前 | 役割 |
|---|---|
| `dmr-radio-csv-maker.php` | user.csvから通常CSV・H1用CSVを生成・ダウンロード |
| `hotspot_setup.sh` | Pi-Star側の監視サービスをセットアップするシェルスクリプト |

---

## 注意事項

- パーマリンク設定を変更した場合は「設定 → パーマリンク」で一度「変更を保存」を押してください（APIルートが再生成されます）
- トークンは推測されにくい文字列を設定してください
- user.csvは16MBを超える大きなファイルです。サーバーのタイムアウト設定によっては取得に失敗することがあります

---

*あいちデジタル通信ハムクラブ JJ2YYK*