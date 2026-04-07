# MMDVM Watcher 2.0
API Ingestion Tool for Pi-Star

---

## ■ 概要
MMDVMのログをリアルタイムで監視し、DMR通信イベントを解析して外部APIへ送信するツールです。  
OSが古くライブラリが入らないPi-Star環境でも確実に動作するよう設計されています。

---

## ■ 引数の付け方とルール

スクリプト実行時に「ノード名」と「APIトークン」を指定します。

### 基本書式
```bash
sudo ./mmwatchset_2.0.sh [ノード名] [APIトークン]
```

### 第1引数：ノード名 (NODE_NAME)
識別用の名前（コールサインや設置場所）

例:
```
JA1YO-ND
TOKYO-RPT
```

### 第2引数：APIトークン (API_TOKEN)
送信用の認証トークン

例:
```
UguaDxA2ZMCr8sn...
```

---

## ■ 実際の使い方

### パターンA：初期設定
```bash
sudo ./mmwatchset_2.0.sh JA1YO abc123
```

### パターンB：ノード名変更
```bash
sudo ./mmwatchset_2.0.sh MOBILE abc123
```

### パターンC：トークン更新
```bash
sudo ./mmwatchset_2.0.sh MOBILE 新しいトークン文字列
```

---

## ■ 運用コマンド

### 状態確認
```bash
systemctl status mmdvm_watch.service
```

### ログ監視
```bash
sudo journalctl -u mmdvm_watch.service -f
```
終了: Ctrl + C

### 設定確認
```bash
sudo grep -E "TOKEN|NODE_NAME" /root/mmdvm_watch.py
```

---

## ■ 生成ファイル

```
/root/mmdvm_watch.py
/etc/systemd/system/mmdvm_watch.service
/var/log/pi-star/.mmdvm_watch.state
```

---

## ■ バージョン履歴

### v2.0 (2026-04-07)
- 外部ライブラリ依存を排除（urllib.request）
- OSリポジトリ問題を解消
- 権限エラー修正
- ノード名の動的設定対応
- UTC→JST変換機能追加

---

## ■ 注意事項

- sudo必須
- 引数は半角スペース区切り
- ネット未接続時: POST failed
- 作業後は rpi-ro に戻ります
