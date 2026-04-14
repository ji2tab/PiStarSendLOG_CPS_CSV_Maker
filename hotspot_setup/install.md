# INSTALL.md — hotspot_setup.sh インストール手順

**対象バージョン:** 2.2.2  
**対象環境:** Pi-Star / WPSD（Raspberry Pi）

---

## 前提条件

作業前に以下を確認してください。

| 項目 | 確認内容 |
|---|---|
| Pi-Star / WPSD | 正常に動作していること |
| DMR接続 | TGIFまたはXLXリフレクターに接続済みであること |
| インターネット接続 | Pi-StarがWi-Fiまたは有線でネットに繋がっていること |
| 接続情報 | 管理者から接続先サーバー・ノード名・APIトークンを受け取っていること |
| SSHクライアント | Windows: TeraTerm / PuTTY / PowerShell など |

---

## ステップ1：SSHでPi-Starにログイン

ターミナルやSSHクライアントで接続します。

```bash
ssh pi-star@pi-star
```

パスワードを求められたら入力してください（デフォルト: `raspberry`）。

---

## ステップ2：ファイルシステムを書き込みモードに切り替え

Pi-Starはデフォルトで読み取り専用です。

```bash
rpi-rw
```

---

## ステップ3：セットアップスクリプトを取得

`wget` コマンドでスクリプトを直接ダウンロードします。

```bash
wget -O /root/hotspot_setup.sh https://jj2yyk.forums.gr.jp/wp-json/hotspot/setup.sh
```

ダウンロードが完了したら実行権限を付与します。

```bash
chmod +x /root/hotspot_setup.sh
```

---

## ステップ4：セットアップスクリプトを実行

```bash
sudo /root/hotspot_setup.sh
```

実行すると以下の4項目を順番に入力するよう求められます。

```
=================================================
   Hotspot Watcher v2.2.2 セットアップ
=================================================
1. 接続先サーバー (例: yourserver.example.com): jj2yyk.forums.gr.jp
2. ノード名 (例: yournode): yyk-tgif
3. APIトークン (例: yourtoken): （管理者から受け取ったトークン）
4. ネットワーク表示名 (例: TGIF TG168 / XLX834-Z): TGIF TG168
```

入力内容の確認が表示されます。問題なければ `y` を押してください。

```
  接続先サーバー     : jj2yyk.forums.gr.jp
  エンドポイント     : https://jj2yyk.forums.gr.jp/wp-json/hotspot/ingest
  ノード名           : yyk-tgif
  APIトークン        : xxxxxxxxx
  ネットワーク表示名 : TGIF TG168

上記の設定で続行しますか？ [y/N]: y
```

---

## ステップ5：セットアップ完了の確認

以下のメッセージが表示されれば完了です。

```
=================================================
   ✅ セットアップ完了！
   送信先: https://jj2yyk.forums.gr.jp/wp-json/hotspot/ingest

   動作確認コマンド:
   sudo journalctl -u hotspot_watch -f
=================================================
```

---

## ステップ6：動作確認

### サービスの状態確認

```bash
sudo systemctl status hotspot_watch
```

`Active: active (running)` と表示されれば正常です。

### リアルタイムログの確認

```bash
sudo journalctl -u hotspot_watch -f
```

PTTを押して離すと、以下のようなログが流れます。

```
[2026-04-10 09:30:15] Hotspot Watcher v2.2.2 started. Node=yyk-tgif
[2026-04-10 09:31:42] Success [200]: JJ2YYK -> {"ok":true,...}
```

ログの確認を終了するには `Ctrl + C` を押してください。

### 疎通テスト

Pi-Starから直接APIへの疎通テストも可能です。

```bash
curl -i -X POST "https://jj2yyk.forums.gr.jp/wp-json/hotspot/ingest" \
  -H "Content-Type: application/json" \
  -H "X-Hotspot-Token: （あなたのトークン）" \
  -d '{"node":"yyk-tgif","callsign":"JJ2ZAR"}'
```

`{"ok":true,...}` が返ってくれば正常です。

---

## 再セットアップの手順

設定を変更したい場合や再インストールが必要な場合は、以下の手順で行います。

```bash
rpi-rw
wget -O /root/hotspot_setup.sh https://jj2yyk.forums.gr.jp/wp-json/hotspot/setup.sh
chmod +x /root/hotspot_setup.sh
sudo /root/hotspot_setup.sh
```

スクリプトは実行時に既存のサービスを自動的にクリーンアップしてから再構築します。

---

## トラブルシューティング

### 「POST Failed」が出続ける場合

```bash
# 疎通テストで原因を確認
curl -i -X POST "https://jj2yyk.forums.gr.jp/wp-json/hotspot/ingest" \
  -H "Content-Type: application/json" \
  -H "X-Hotspot-Token: （あなたのトークン）" \
  -d '{"node":"yyk-tgif","callsign":"TEST"}'
```

| レスポンス | 原因 | 対処 |
|---|---|---|
| `{"ok":true}` | スクリプト側の問題 | サービスを再起動 |
| `403 Forbidden` | トークンが間違っている | 管理者に確認 |
| `404 Not Found` | エンドポイントが間違っている | 再セットアップ |
| 接続エラー | ネットワーク問題 | Pi-Starのインターネット接続を確認 |

### サービスが停止している場合

```bash
sudo systemctl restart hotspot_watch
sudo journalctl -u hotspot_watch -f
```

### ログファイルが見つからない場合

MMDVMのログ出力先を確認してください。

```bash
ls /var/log/pi-star/MMDVM-*.log
```

ファイルが存在しない場合はPi-StarのMMDVM設定を確認してください。

---

## 関連ドキュメント

- `README.md` — スクリプトの概要・目的・送信データ形式

---

*あいちデジタル通信ハムクラブ JJ2YYK*