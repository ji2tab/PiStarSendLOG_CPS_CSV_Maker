#!/bin/bash

# スクリプト名: mmwatchset_2.0.sh
# 概要: MMDVMのログを監視し、外部APIへ送信するPythonスクリプトを自動展開・サービス登録する。
# 使用法: sudo ./mmwatchset_2.0.sh <ノード名> <APIトークン>
# 特徴: 外部ライブラリ(requests等)不要、古いOS(Buster)対応、Permission Denied回避済み。

# --- 1. 実行権限と引数のチェック ---
# rootユーザー(sudo)で実行されているか確認します。
if [ "$EUID" -ne 0 ]; then
  echo "❌ エラー: このスクリプトはroot権限で実行する必要があります。"
  echo "使用例: sudo ./mmwatchset_2.0.sh MY-NODE-01 YOUR_TOKEN"
  exit 1
fi

# 引数が2つ（ノード名とトークン）正しく渡されているか確認します。
if [ -z "$2" ]; then
  echo "⚠️ エラー: 引数が不足しています。"
  echo "使用法: ./mmwatchset_2.0.sh <ノード名> <APIトークン>"
  exit 1
fi

NODE_NAME="$1"
API_TOKEN="$2"
PYTHON_SCRIPT_PATH="/root/mmdvm_watch.py"
SERVICE_FILE="/etc/systemd/system/mmdvm_watch.service"

echo "================================================="
echo "  MMDVM Watcher 2.0 完全自動設定を開始します"
echo "================================================="

# --- 2. ファイルシステムを書き込み可能にする ---
# Pi-Starは通常読み取り専用(ro)のため、一時的に書き込み可能(rw)にします。
echo "➡️ ファイルシステムを読み書き可能 (rpi-rw) に設定中..."
rpi-rw

# --- 3. Pythonスクリプトの生成 ---
# sudo tee を使うことで、/root/ フォルダへの書き込み権限問題を確実に回避します。
# 外部ライブラリを使わず、標準の urllib.request を使用するように設計しています。
echo "➡️ Python監視スクリプトを生成中: ${PYTHON_SCRIPT_PATH}"

sudo tee "${PYTHON_SCRIPT_PATH}" > /dev/null << EOF
# coding: utf-8
import os, time, json, re, socket, logging, sys
from datetime import datetime, timezone, timedelta
import urllib.request, urllib.error

# --- 基本設定 ---
ENDPOINT = "https://log.forums.gr.jp/wp-json/wpsd/v1/ingest"
TOKEN = "${API_TOKEN}"   # シェルスクリプトの第2引数から埋め込み
NODE_NAME = "${NODE_NAME}" # シェルスクリプトの第1引数から埋め込み
LOG_DIR = "/var/log/pi-star"
STATE_FILE = os.path.join(LOG_DIR, ".mmdvm_watch.state")

# ログ出力の設定（journalctlで確認可能）
logging.basicConfig(level=logging.INFO, format='[%(asctime)s] %(levelname)s: %(message)s', datefmt='%Y-%m-%d %H:%M:%S')

# MMDVMログを解析するための正規表現（音声開始時と終了時）
HEADER_PATTERN = re.compile(r'(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2}.\d{3})\s+DMR\s+Slot\s+(\d+),\s+received\s+(RF|NETWORK)\s+voice\s+header\s+from\s+(.+?)\s+to\s+(TG|PC|REF)\s+(\d+|ALL)', re.IGNORECASE)
END_PATTERN = re.compile(r'(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2}.\d{3})\s+DMR\s+Slot\s+(\d+),\s+received\s+(RF|NETWORK)\s+end\s+of\s+voice\s+transmission\s+from\s+(.+?)\s+to\s+(TG|PC|REF)\s+(\d+|ALL),?.*', re.IGNORECASE)

def post_json(data):
    """
    urllibを使ってAPIへJSONデータをPOST送信します。
    外部ライブラリ(requests)に依存しないため、OSが古くても動作します。
    """
    payload = json.dumps(data).encode('utf-8')
    headers = {
        "Content-Type": "application/json; charset=utf-8",
        "X-WPSD-Token": TOKEN,
        "Authorization": "Bearer " + TOKEN
    }
    req = urllib.request.Request(ENDPOINT, data=payload, headers=headers, method='POST')
    try:
        with urllib.request.urlopen(req, timeout=8) as response:
            if response.status == 200:
                logging.info("Successfully POSTed data for " + data.get('callsign', 'Unknown'))
                return True
    except Exception as e:
        logging.error("POST failed: " + str(e))
    return False

def load_state():
    """前回のログ読み込み位置をロードします。"""
    if os.path.exists(STATE_FILE):
        try:
            with open(STATE_FILE, 'r') as f: return json.load(f)
        except: pass
    return {}

def save_state(state):
    """現在のログ読み込み位置を保存します。"""
    try:
        with open(STATE_FILE, 'w') as f: json.dump(state, f)
    except: pass

def create_session_payload(header_line, end_line):
    """ログの2行から送信用のJSONデータを作成します。時刻はJSTに変換します。"""
    mh = HEADER_PATTERN.search(header_line)
    me = END_PATTERN.search(end_line)
    if not mh or not me: return None
    date_str, time_str, slot, src, callsign, dst_type, dst = mh.groups()[:7]
    try:
        # ログはUTCのため、9時間足してJSTに変換
        dt_utc = datetime.strptime(date_str + ' ' + time_str, '%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=timezone.utc)
        dt_jst = dt_utc + timedelta(hours=9)
    except: return None
    return {
        "type": "session", "node": NODE_NAME, "timestamp": dt_jst.strftime("%Y-%m-%d %H:%M:%S"),
        "timestamp_local": dt_jst.isoformat(), "callsign": callsign.upper(),
        "dmr": {"slot": int(slot), "src": src.upper(), "callsign": callsign.upper(), "dst_type": dst_type.upper(), "dst": dst},
        "raw": {"header": header_line.strip(), "end": end_line.strip()}
    }

def main():
    logging.info("MMDVM Watcher 2.0 for " + NODE_NAME + " started.")
    prev_files = load_state()
    session_buffer = {} # 音声開始ヘッダーを一時保持するバッファ
    while True:
        time.sleep(2) # 2秒おきにログを確認
        try:
            for file in os.listdir(LOG_DIR):
                if not re.match(r'MMDVM-\d{4}-\d{2}-\d{2}\.log', file): continue
                path = os.path.join(LOG_DIR, file)
                curr_size = os.path.getsize(path)
                prev_size = prev_files.get(file, 0)
                
                # 新しいログが書き込まれた場合のみ処理
                if curr_size > prev_size:
                    with open(path, 'r', encoding='utf-8', errors='ignore') as f:
                        f.seek(prev_size)
                        for line in f:
                            if 'voice header' in line:
                                m = HEADER_PATTERN.search(line)
                                if m: session_buffer[m.group(5)+"_"+m.group(3)] = line
                            elif 'end of voice' in line:
                                m = END_PATTERN.search(line)
                                if m:
                                    key = m.group(5)+"_"+m.group(3)
                                    if key in session_buffer:
                                        payload = create_session_payload(session_buffer[key], line)
                                        if payload: post_json(payload)
                                        del session_buffer[key]
                    prev_files[file] = curr_size
                    save_state(prev_files)
        except Exception as e:
            time.sleep(5)

if __name__ == "__main__":
    main()
EOF

# スクリプトに実行権限を付与します。
chmod +x "${PYTHON_SCRIPT_PATH}"

# --- 4. systemdサービスファイルの生成 ---
# OS起動時に自動でプログラムが開始されるように設定します。
echo "➡️ サービス設定ファイルを生成中: ${SERVICE_FILE}"
sudo tee "${SERVICE_FILE}" > /dev/null << EOF
[Unit]
Description=MMDVM Log Watcher 2.0
After=network-online.target
Wants=network-online.target

[Service]
User=root
Group=root
# Python3でスクリプトを実行
ExecStart=/usr/bin/python3 ${PYTHON_SCRIPT_PATH}
# 異常終了時に5秒おきに自動再起動
Restart=always
RestartSec=5
StandardOutput=inherit
StandardError=inherit
SyslogIdentifier=mmdvm_watch

[Install]
WantedBy=multi-user.target
EOF

# --- 5. サービスの有効化と起動 ---
echo "➡️ サービスを有効化・起動中..."
systemctl daemon-reload
systemctl enable mmdvm_watch.service
systemctl restart mmdvm_watch.service

# --- 6. 読み取り専用に戻す ---
# SDカードの寿命保護のため、roに戻します。
rpi-ro

echo "================================================="
echo "  ✅ 全設定が完了しました！"
echo "  ノード名: ${NODE_NAME}"
echo "  ステータス確認: systemctl status mmdvm_watch.service"
echo "  リアルタイムログ確認: sudo journalctl -u mmdvm_watch.service -f"
echo "================================================="
