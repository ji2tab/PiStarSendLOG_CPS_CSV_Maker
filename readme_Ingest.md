# WPSD Ingest プラグイン仕様書・取扱説明書

---

## 1. 概要

WPSD Ingest は、Pi-Star 等のデジタル音声ゲートウェイから送信される通信ログ（JSON形式）を、WordPress の REST API で受信し、RadioID データベース（user.csv）の情報を付加してサーバー内に保存するプラグインです。

**主な役割**  
送信されてきたコールサインから、DMR ID、氏名、住所（市区町村・州・国）を自動補完し、ノードごとのフォルダに整理して保存します。

**運用環境例**  
http://log.forums.gr.jp/ （篠田様運用環境）

---

## 2. システム仕様（Technical Specifications）

### 2.1 REST API エンドポイント

- URL: `https://log.forums.gr.jp/wp-json/wpsd/v1/ingest`
- Method: `POST`
- 認証方式: カスタム HTTP ヘッダーによるトークン認証  
  `X-WPSD-Token: <token>`

---

### 2.2 データ照合（RadioID 連携）

- **ローカルキャッシュ方式**  
  外部サーバーへの負荷を減らすため、radioid.net から `user.csv` を自動的にダウンロードし、以下のパスに保存します。

  `wp-content/uploads/wpsd/_cache/user.csv`

- **複数取得元フォールバック**  
  radioid.net / www.radioid.net / database.radioid.net を順に試行

- **API フォールバック**  
  CSV 未取得・取得失敗時は RadioID REST API を使用

- **キャッシュ保持**  
  WordPress Transient API を使用し、コールサインごとの照合結果を 12 時間保持

---

### 2.3 データ保存仕様

- 保存パス:  
  `wp-content/uploads/wpsd/{Node名}/inbox-YYYY-MM-DDTHH-mm-ss.json`

- 重複管理:  
  同一コールサインのデータが存在する場合、タイムスタンプが **最新のもののみを保持** し、それ以前のファイルは自動的に削除されます。

- 自動付与フィールド（サーバー側）:
  - `dmr_id`
  - `first_name`
  - `last_name`
  - `city`
  - `state`
  - `country`
  - `timestamp`（JST, `YYYY-MM-DD HH:MM:SS`）
  - `timestamp_local`（ISO8601, JST）

---

## 3. インストール・設定方法

### 3.1 インストール

1. WordPress 管理画面
2. 「プラグイン」→「新規追加」→ 本プログラムをアップロード
3. 有効化

※ 有効化と同時に、RadioID `user.csv` を **毎日1回更新する WP-Cron タスク** が登録されます。

---

### 3.2 基本設定（Token Map）

管理画面:

「設定」→「WPSD Ingest」

設定項目:

- **Token**  
  任意の英数字文字列（クライアント側と一致させる）

- **Node**  
  保存先フォルダ名になります（例: `JI2TAB-Repeater`）  
  英数字＋ハイフン推奨

- 複数ノード登録可能

---

### 3.3 ツール画面（管理・メンテナンス）

管理画面:

「ツール」→「WPSD Ingest Tools」

利用可能機能:

- 特定コールサインの Transient キャッシュ削除
- 全コールサインの Transient キャッシュ削除
- RadioID `user.csv` の削除
- RadioID `user.csv` の手動再取得

---

## 4. クライアント側（Pi-Star 等）の設定例

### 4.1 接続設定

- Endpoint:  
  `https://log.forums.gr.jp/wp-json/wpsd/v1/ingest`

- Authentication Header:  
  `X-WPSD-Token: <設定したトークン>`

---

### 4.2 送信 JSON 例

```json
{
  "callsign": "JI2TAB",
  "dmr": {
    "slot": 2,
    "src": "RF",
    "dst": "44000"
  }
}
```

---

## 5. 生成される JSON の例（保存後）

```json
{
  "callsign": "JI2TAB",
  "dmr_id": "4401234",
  "first_name": "TARO",
  "last_name": "YAMADA",
  "city": "Nagoya",
  "state": "Aichi",
  "country": "Japan",
  "timestamp": "2026-04-07 14:32:10",
  "timestamp_local": "2026-04-07T14:32:10.123+09:00",
  "dmr": {
    "slot": 2,
    "src": "RF",
    "dst": "44000"
  }
}
```

---

## 6. バージョン履歴

- **v1.6.2**  
  RadioID CSV ローカルキャッシュ参照機能の実装  
  管理画面ツール（キャッシュ管理・CSV管理）の追加

- **v1.5.0**  
  REST API エンドポイントの安定化  
  トークン認証方式の実装

---
