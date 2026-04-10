# DMR Radio CSV Maker

**プラグイン名:** DMR Radio CSV Maker  
**バージョン:** 1.4.2  
**対象環境:** WordPress 5.0以上 / PHP 7.4以上  
**作成者:** JI2TAB / あいちデジタル通信ハムクラブ JJ2YYK

---

## 概要

radioid.netが公開するDMRユーザーデータベース（user.csv）をもとに、無線機へのコンタクトリストインポート用CSVファイルを生成・ダウンロードするWordPressプラグインです。

ダウンロードにはコールサインとDMR IDによる認証が必要で、radioid.netに登録されているユーザーのみが利用できます。不特定多数による無制限ダウンロードを防ぐ抑止機能を備えています。

---

## このプラグインについて

### なぜ「DMR Radio CSV Maker」なのか

radioid.netはDMRユーザーの登録データベースをCSV形式で公開しています。このCSVをそのまま無線機にインポートすることも可能ですが、**無線機の画面に表示される情報が必ずしも使いやすいとは限りません。**

たとえばHytera H1では、コンタクトリストの表示項目（Contacts Alias・City・Province・Country）のカラム名と、radioid.netのCSVのカラム名が一致していません。そのためそのままインポートしても、無線機の画面に意図した情報が表示されないことがあります。

### H1用CSVの考え方

本プラグインのH1用CSV出力では、**カラム名にとらわれず「無線機の画面で見たときに役立つ情報」を優先して各列に配置**しています。

具体的には以下のような成形を行っています：

| 無線機の表示項目 | 実際に入れているデータ | 理由 |
|---|---|---|
| Contacts Alias | コールサイン + DMR ID | 誰への呼び出しか一目でわかる |
| City | 名前（FIRST_NAME） | 相手の名前がすぐわかる |
| Province | 市区町村（CITY） | 相手のおおよその場所がわかる |
| Country | 国名 + 地域（COUNTRY + STATE） | 国・地域がわかる |

> **注意:** これはあくまでJJ2YYKクラブでの運用方針に基づく成形です。無線機や運用スタイルによって最適な配置は異なります。必要に応じて通常CSVをベースに独自に加工していただくことも可能です。

---

## 機能一覧

| 機能 | 内容 |
|---|---|
| **認証機能** | コールサインとDMR IDをradioid.netのCSVと照合 |
| **国フィルター** | 対象国をプルダウンで選択（全件または国別） |
| **通常CSV生成** | radioid.net形式のCSVを生成・ダウンロード |
| **H1用CSV生成** | Hytera H1等の無線機インポート形式のCSVを生成・ダウンロード |
| **ダウンロードログ** | 認証成功・ダウンロードの履歴を管理画面で確認 |
| **タイムスタンプ表示** | radioid.netのCSV公開日時と取得日時を表示 |

---

## 画面フロー

```
認証画面
  コールサイン入力
  DMR ID 入力
  └─ radioid.net CSVと照合
       │
       ├─ 一致 → ダウンロード画面
       │           国選択プルダウン
       │           [通常CSV] [H1用CSV] ダウンロードボタン
       │
       └─ 不一致 → エラーメッセージ → 再入力
```

---

## ショートコード

公開ページに以下を追加するだけで表示されます。

```
[dmr_csv_maker]
```

---

## 出力ファイルの仕様

### 通常CSV

radioid.netのオリジナル形式をそのまま出力します。

| 列 | 内容 |
|---|---|
| RADIO_ID | DMR ID |
| CALLSIGN | コールサイン |
| FIRST_NAME | 名前 |
| LAST_NAME | 苗字 |
| CITY | 市区町村 |
| STATE | 州・地域 |
| COUNTRY | 国 |

**ファイル名例:** `dmr_user_Japan_20260410123456.csv`

---

### H1用CSV

Hytera H1等の無線機へのインポートに対応した形式です。実機エクスポートデータと同一フォーマットで出力します。

| 列 | 元データ | 処理 |
|---|---|---|
| Contacts Alias | CALLSIGN + RADIO_ID | 英数字・スペースのみ |
| Call Type | 固定 | `Private Call` |
| Call ID | RADIO_ID | 7桁の数字のみ（それ以外スキップ） |
| City | FIRST_NAME | 英数字・スペースのみ・16文字切り詰め |
| Province | CITY | 英数字・スペースのみ・16文字切り詰め |
| Country | COUNTRY + STATE | 英数字・スペースのみ |

- 改行コード: CRLF（`\r\n`）
- 文字コード: UTF-8

**ファイル名例:** `dmr_h1_Japan_20260410123456.csv`

---

## 認証仕様

- コールサインとDMR IDの**両方が一致**した場合のみダウンロード可能
- 認証はアクセスのたびに毎回実施（セッション保持なし）
- ロック機能なし

---

## ダウンロードログ

認証成功・ダウンロードのたびに以下の情報を記録します。

| 項目 | 内容 |
|---|---|
| 日時(JST) | ダウンロード日時 |
| コールサイン | 認証に使用したコールサイン |
| DMR ID | 認証に使用したDMR ID |
| 対象国 | 選択した国（または「全件」） |
| 種別 | 通常CSV / H1用CSV |
| IPアドレス | アクセス元IPアドレス |

**保存先:** `wp-content/uploads/hotspot/_cache/download_log.csv`  
**確認場所:** WordPress管理画面「設定 → DMR Radio CSV Maker」

---

## user.csvについて

本プラグインは `Hotspot Total Solution V2`（hotspot-total-v2.php）が取得・管理するuser.csvを共用します。

| 項目 | 内容 |
|---|---|
| 取得元 | `https://radioid.net/static/user.csv` |
| 保存先 | `wp-content/uploads/hotspot/_cache/user.csv` |
| 自動更新 | 毎日1回（WP-Cronによる自動取得） |
| 手動更新 | `Hotspot Total Solution V2` の管理画面から実施 |
| 公開日時表示 | radioid.netのLast-Modifiedヘッダーを取得・表示 |

---

## 必要環境

| 項目 | 要件 |
|---|---|
| WordPress | 5.0以上 |
| PHP | 7.4以上 |
| 依存プラグイン | Hotspot Total Solution V2（user.csv管理） |
| パーマリンク設定 | 「基本」以外に設定 |

---

## インストール

1. `dmr-radio-csv-maker.php` を `wp-content/plugins/dmr-radio-csv-maker/` に配置
2. WordPress管理画面の「プラグイン」から有効化
3. 表示したいページに `[dmr_csv_maker]` を追加
4. `Hotspot Total Solution V2` でuser.csvを取得済みであることを確認

---

## 関連プラグイン・ツール

| 名前 | 役割 |
|---|---|
| `hotspot-total-v2.php` | user.csv取得・管理、交信ログ受信・表示 |
| `hotspot_setup.sh` | Pi-Star側の監視サービスセットアップ |

---

*あいちデジタル通信ハムクラブ JJ2YYK*