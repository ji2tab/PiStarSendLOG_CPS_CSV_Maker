# hotspot_setup.sh

**バージョン:** 2.2.0  
**対象環境:** Pi-Star / WPSD（Raspberry Pi）  
**作成者:** JI2TAB / あいちデジタル通信ハムクラブ JJ2YYK

---

## 概要

DMR（Digital Mobile Radio）ホットスポットの交信ログを、自動的にWebサーバーへ送信するための監視サービスをセットアップするシェルスクリプトです。

Pi-Star（またはWPSD）が稼働するRaspberry Pi上で実行することで、MMDVMが出力する交信ログをリアルタイムに解析し、交信が発生するたびにWebサーバーのAPIへデータを送信します。

---

## 目的

アマチュア無線クラブやグループのWebサイトに、ホットスポットへのアクセス状況（交信局のコールサイン・日時・RF/NW区分など）をリアルタイムで表示することを目的としています。

セットアップを一度実行すれば、以後はRaspberry Piの起動と同時に自動的にサービスが立ち上がり、手動操作は不要です。

---

## 何をするのか

1. **Pythonスクリプトの生成**  
   MMDVMのログファイル（`/var/log/pi-star/MMDVM-*.log`）を常時監視するPythonプログラム（`/root/hotspot_watch.py`）を自動生成します。

2. **交信の検出**  
   送話ボタン（PTT）を押して離した瞬間に交信記録を検出します。  
   検出する情報はコールサイン・タイムスタンプ・スロット番号・RF/NW区分・送信先トークグループです。

3. **データの送信**  
   検出した交信データをJSON形式でWebサーバーのREST APIへ自動送信します。

4. **systemdサービスへの登録**  
   上記のPythonプログラムをsystemdサービス（`hotspot_watch.service`）として登録します。  
   Raspberry Piの再起動後も自動的にサービスが再開します。

---

## 生成・登録されるファイル

| ファイル | 説明 |
|---|---|
| `/root/hotspot_watch.py` | MMDVMログ監視・送信Pythonスクリプト |
| `/etc/systemd/system/hotspot_watch.service` | systemdサービス定義ファイル |

---

## 送信データの形式

交信1件につき、以下のJSON形式でAPIへPOST送信します。

```json
{
  "node": "yournode",
  "callsign": "JJ2YYK",
  "timestamp": "2026-04-10 09:30:15",
  "dmr": {
    "slot": 2,
    "src": "RF",
    "dst": "168"
  }
}
```

---

## 動作確認コマンド

```bash
# サービスの状態確認
sudo systemctl status hotspot_watch

# リアルタイムログの確認
sudo journalctl -u hotspot_watch -f
```

---

## 注意事項

- 本スクリプトはPi-Star / WPSDのファイルシステムを一時的に書き込みモード（`rpi-rw`）に切り替えます。セットアップ完了後は自動的に読み取り専用（`rpi-ro`）に戻します。
- 接続先サーバー・ノード名・APIトークンは管理者から個別にご案内します。
- インターネット接続が必要です。

---

## 関連ドキュメント

- `INSTALL.md` — インストール手順の詳細

---

*あいちデジタル通信ハムクラブ JJ2YYK*