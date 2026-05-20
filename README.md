
---

# DMR Hotspot Watcher (for Pi-Star / WPSD)

DMR（Digital Mobile Radio）ホットスポット環境（Pi-Star / WPSD）から、交信確定時のログをリアルタイムで検知し、WordPress 等の外部サーバーへ送信する軽量な監視デーモンです。

DMRユーザーデータベース（CSV）と連携するサーバー側のプラグイン（`hotspot-receiver` 等）と組み合わせることで、自局のダッシュボードに「名前（団体名）付き」で超高速な交信履歴を構築できます。

## 🌟 特徴

* **WPSDログ完全対応:** WPSD特有のスペース形式や遅延検知（late entry）を正規表現で自動吸収。
* **フライング送信の防止:** 話し始め（`voice header`）ではなく、交信確定時（`end of voice` / `transmission lost`）をトリガーに1回だけクリーンに送信します。
* **超軽量な常駐プロセス:** Python のファイルシーク監視（0.5秒間隔）を使用し、Raspberry Pi の CPU・メモリにほとんど負荷をかけません。
* **全自動インストール:** ワンライナーコマンド一発で、環境構築から systemd へのサービス登録・自動起動まで完了します。

---

## 📥 インストール方法

Pi-Star または WPSD の SSHターミナル（コンソール）にログインし、以下の1行コマンド（ワンライナー）を貼り付けて実行してください。

```bash
curl -sSL https://raw.githubusercontent.com/ji2tab/PiStarSendLOG_CPS_CSV_Maker/main/install.sh | sudo bash

```

### セットアップの流れ

実行すると、自動的にファイルシステムの書き込み権限（`rpi-rw`）が解放され、スクリプトのダウンロードが行われます。
途中で以下の情報を聞かれますので、環境に合わせて入力してください。

1. **WordPressサーバーのドメイン名** (例: `jj2yyk.forums.gr.jp`)
2. **このホットスポットの識別ノード名** (例: `yyk-tgif`)
3. **APIトークン** (WordPress側で設定したセキュアなパスワード文字列)

入力完了後、`hotspot_watch.service` として自動的にバックグラウンド起動します。

---

## 🗑️ アンインストール方法

システムから監視デーモンを完全に削除し、元の状態に戻したい場合は、以下のコマンドを実行してください。

```bash
curl -sSL https://raw.githubusercontent.com/ji2tab/PiStarSendLOG_CPS_CSV_Maker/main/uninstall.sh | sudo bash

```

実行すると、サービスの停止、systemdからの登録解除、および関連するすべてのスクリプトファイルが自動的に削除されます。

---

## ⚙️ 動作確認とログの表示

インストール後、サービスが正常に動作しているか確認するには、以下のコマンドを実行します。
リアルタイムで送信状況や、デバイスの健康状態（CPU温度 / メモリ使用率）が確認できます。

```bash
# リアルタイムログの表示 (終了するには Ctrl + C)
sudo journalctl -u hotspot_watch -f

```

**ログの出力例:**

```text
 🟢 WP送信成功 [200]: JI2TAB 📡 [RF] ➔ TGIF168 (TG: 1) [45.1°C/Mem:24.5%]

```

サービスの再起動や手動停止を行う場合は以下のコマンドを使用します。

```bash
sudo systemctl restart hotspot_watch
sudo systemctl stop hotspot_watch

```

---

## 📄 ライセンス

This project is licensed under the GPLv2 License.

*Created by JI2TAB / あいちデジタル通信ハムクラブ JJ2YYK*
