MMDVM Watcher 2.0 - API Ingestion Tool for Pi-Star
■ 概要
MMDVMのログをリアルタイムで監視し、DMR通信イベントを解析して外部APIへ送信するツールです。
OSが古くライブラリが入らないPi-Star環境でも確実に動作するよう設計されています。
■ 引数（ひきすう）の付け方とルール
スクリプトを実行する際、後ろにスペースを空けて「ノード名」と「トークン」を入力します。
基本書式
sudo ./mmwatchset_2.0.sh ノード名 APIトークン
第1引数：ノード名 (NODE_NAME)
識別用の名前です。コールサインや設置場所を英数字で入力します。
例: JA1YO-ND, TOKYO-RPT
第2引数：APIトークン (API_TOKEN)
送信用の専用パスワード（鍵）です。
例: UguaDxA2ZMCr8sn...
■ 実際の使い方の例
パターンA：初めて設定する場合（コールサイン JA1YO、トークン abc123の場合）
sudo ./mmwatchset_2.0.sh JA1YO abc123
パターンB：名前だけ変えたい場合（トークンはそのままで名前を MOBILE に変更）
sudo ./mmwatchset_2.0.sh MOBILE abc123
パターンC：トークンを更新したい場合（名前はそのままで新しいトークンを入力）
sudo ./mmwatchset_2.0.sh MOBILE 新しいトークン文字列
■ 運用中のコマンドガイド
動作状態の確認（Active: active (running) なら正常）
systemctl status mmdvm_watch.service
送信ログのリアルタイム確認（PTTオフ後に送信メッセージが出ます）
sudo journalctl -u mmdvm_watch.service -f
※終了するには Ctrl + C を押してください。
設定内容の再確認
sudo grep -E "TOKEN|NODE_NAME" /root/mmdvm_watch.py
■ 生成されるファイル
/root/mmdvm_watch.py
プログラム本体
/etc/systemd/system/mmdvm_watch.service
自動起動設定
/var/log/pi-star/.mmdvm_watch.state
ログ読み込み位置の記録
■ バージョンアップ履歴
v2.0 (2026-04-07)
外部ライブラリ依存を排除（urllib.request版に刷新）
OS（Buster等）のリポジトリURL切れによるインストール不可問題を解消
/root/ ディレクトリへの書き込み権限エラーを修正
第一引数によるノード名の動的設定に対応
ログのUTC時刻をJST（日本時間）に自動変換して送信する機能を搭載
■ 注意事項
必ず「sudo」を付けて実行してください。
引数の間は必ず「半角スペース」で区切ってください。
ネット未接続時は「POST failed」と表示されます。
作業後は自動で「rpi-ro（読み取り専用）」に戻ります。
