#!/bin/bash

# スクリプト名: mmwatchset.sh
# 実行権限: root (推奨)

# --- 実行前のチェック ---
if [ "$EUID" -ne 0 ]; then
  echo "❌ このスクリプトはroot権限で実行する必要があります。"
  exit 1
fi

if [ -z "$1" ]; then
  echo "⚠️ エラー: APIトークンを引数として指定してください。"
  echo "使用法: ./mmwatchset.sh <YOUR_API_TOKEN>"
  exit 1
fi

API_TOKEN="$1"
PYTHON_SCRIPT_PATH="/root/mmdvm_watch.py"
SERVICE_FILE="/etc/systemd/system/mmdvm_watch.service"

echo "================================================="
echo "  MMDVM Watcher サービス設定スクリプトを開始します"
echo "================================================="

# 1. ファイルシステムを読み書き可能にする (Pi-Star対策)
echo "➡️ ファイルシステムを読み書き可能 (rpi-rw) にします..."
rpi-rw

# 2. Pythonスクリプトの生成とトークンの埋め込み
echo "➡️ Pythonスクリプトを ${PYTHON_SCRIPT_PATH} に生成し、トークンを埋め込みます..."
# ヒアドキュメントでmmdvm_watch.pyのソースを生成
cat << EOF > "${PYTHON_SCRIPT_PATH}"
# coding: utf-8
import os
import time
import json
import re
import requests
from datetime import datetime, timezone, timedelta
import socket
import logging
import signal
import sys

# --- 設定 ---
ENDPOINT = "https://log.forums.gr.jp/wp-json/wpsd/v1/ingest"
TOKEN = "${API_TOKEN}"  # <-- ここに引数から受け取ったトークンを挿入
LOG_DIR = "/var/log/pi-star"
STATE_FILE = os.path.join(LOG_DIR, ".mmdvm_watch.state")

# --- ログ設定 ---
logging.basicConfig(
    level=logging.INFO,
    format='[%(asctime)s] %(levelname)s: %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

# --- 正規表現パターン ---
HEADER_PATTERN = re.compile(
    r'(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2}.\d{3})\s+DMR\s+Slot\s+(\d+),\s+received\s+(RF|NETWORK)\s+voice\s+header\s+from\s+(.+?)\s+to\s+(TG|PC|REF)\s+(\d+|ALL)',
    re.IGNORECASE
)
END_PATTERN = re.compile(
    r'(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2}.\d{3})\s+DMR\s+Slot\s+(\d+),\s+received\s+(RF|NETWORK)\s+end\s+of\s+voice\s+transmission\s+from\s+(.+?)\s+to\s+(TG|PC|REF)\s+(\d+|ALL),?.*',
    re.IGNORECASE
)

def load_state():
    if os.path.exists(STATE_FILE):
        try:
            with open(STATE_FILE, 'r') as f:
                return json.load(f)
        except (IOError, json.JSONDecodeError) as e:
            logging.error(f"Failed to load state file: {e}. Starting fresh.")
    return {}

def save_state(state):
    try:
        with open(STATE_FILE, 'w') as f:
            json.dump(state, f)
    except IOError as e:
        logging.error(f"Failed to save state file: {e}")

def create_session_payload(header_line, end_line):
    match_header = HEADER_PATTERN.search(header_line)
    match_end = END_PATTERN.search(end_line)
    if not match_header or not match_end:
        logging.error("Failed to match header or end line for payload creation.")
        return None
    date_str, time_str, slot, src, callsign, dst_type, dst = match_header.groups()[:7]
    try:
        # タイムゾーン処理: UTCでパースし、JST (+9h) に変換
        dt_utc = datetime.strptime(f'{date_str} {time_str}', '%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=timezone.utc)
        dt_jst = dt_utc + timedelta(hours=9)
    except ValueError:
        logging.error(f"Timestamp parsing failed for line: {header_line}")
        return None
    node_name = socket.gethostname()
    payload = {
        "type": "session",
        "node": node_name,
        "timestamp": dt_jst.strftime("%Y-%m-%d %H:%M:%S"),
        "timestamp_local": dt_jst.isoformat(),
        "dmr": {
            "slot": int(slot),
            "src": src.upper(),
            "callsign": callsign.upper(),
            "dst_type": dst_type.upper(),
            "dst": dst
        },
        "raw": {
            "header": header_line.strip(),
            "end": end_line.strip()
        },
        "callsign": callsign.upper(),
        "dmr_id": None,
        "name": None
    }
    if dst_type.upper() == "TG":
        if dst.isdigit():
            payload['dmr']['tg'] = int(dst)
        else:
            payload['dmr']['tg'] = dst
    return payload

def post_json(data):
    payload = json.dumps(data).encode('utf-8')
    headers = {
        "Content-Type": "application/json; charset=utf-8",
        "X-WPSD-Token": TOKEN,
        "Authorization": f"Bearer {TOKEN}",
    }
    # ENDPOINTが修正されていることを前提としたURL構築
    urls = [ENDPOINT]
    if ENDPOINT.endswith("/wpsd/v1/ingest"):
        base_url = ENDPOINT.rsplit("/wp-json", 1)[0]
        urls.append(f"{base_url}/?rest_route=/wpsd/v1/ingest")
    
    for url in urls:
        try:
            response = requests.post(url, data=payload, headers=headers, timeout=8)
            if response.status_code == 200:
                callsign = data.get('callsign', 'Unknown')
                logging.info(f"Successfully POSTed data for {callsign} to {url}")
                return True
            else:
                logging.warning(f"POST failed with status code {response.status_code} at {url}. Response: {response.text[:100]}...")
        except requests.exceptions.RequestException as e:
            logging.error(f"An error occurred during POST: {e}")
            return False # 最初のURLで失敗したらすぐに終了

    logging.error("POST failed after all retries.")
    return False

def signal_handler(sig, frame):
    logging.info("Stopping the watcher.")
    sys.exit(0)

def main():
    signal.signal(signal.SIGINT, signal_handler)
    if not os.path.isdir(LOG_DIR):
        logging.error(f"Log directory not found: {LOG_DIR}")
        return

    prev_files = load_state()
    session_buffer = {}

    logging.info(f"Watching directory: {LOG_DIR}")

    current_files_on_start = set(os.listdir(LOG_DIR))
    for file in current_files_on_start:
        filepath = os.path.join(LOG_DIR, file)
        if os.path.isfile(filepath) and re.match(r'MMDVM-\d{4}-\d{2}-\d{2}\.log', file):
            if file not in prev_files:
                prev_files[file] = os.path.getsize(filepath)
                logging.info(f"Initializing state for new log file {file} from its end.")
    save_state(prev_files)

    while True:
        time.sleep(2)
        current_files = set(os.listdir(LOG_DIR))

        added = current_files - set(prev_files.keys())
        removed = set(prev_files.keys()) - current_files

        for file in added:
            filepath = os.path.join(LOG_DIR, file)
            if os.path.isfile(filepath) and re.match(r'MMDVM-\d{4}-\d{2}-\d{2}\.log', file):
                prev_files[file] = os.path.getsize(filepath)
                logging.info(f"New log file detected: {file}. Monitoring from its end.")
                save_state(prev_files)

        for file in removed:
            logging.info(f"Log file removed: {file}")
            if file in prev_files:
                del prev_files[file]
                save_state(prev_files)

        for file in current_files.intersection(set(prev_files.keys())):
            filepath = os.path.join(LOG_DIR, file)

            if not re.match(r'MMDVM-\d{4}-\d{2}-\d{2}\.log', file):
                continue

            try:
                current_size = os.path.getsize(filepath)
            except OSError as e:
                logging.error(f"Error accessing file {filepath}: {e}")
                continue

            prev_size = prev_files.get(file, 0)

            if current_size > prev_size:
                with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                    f.seek(prev_size)
                    new_content = f.read()
                    for line in new_content.strip().splitlines():
                        if 'voice header' in line:
                            match = HEADER_PATTERN.search(line)
                            if match:
                                callsign = match.group(5)
                                slot = match.group(3)
                                session_key = f"{callsign}_{slot}"
                                session_buffer[session_key] = line
                        elif 'end of voice' in line:
                            match = END_PATTERN.search(line)
                            if match:
                                callsign = match.group(5)
                                slot = match.group(3)
                                session_key = f"{callsign}_{slot}"
                                if session_key in session_buffer:
                                    payload = create_session_payload(session_buffer[session_key], line)
                                    if payload:
                                        post_json(payload)
                                    del session_buffer[session_key]
                prev_files[file] = current_size
                save_state(prev_files)

            elif current_size < prev_size:
                logging.info(f"File truncated or rotated: {file}. Re-initializing monitoring.")
                prev_files[file] = current_size
                session_buffer.clear()
                save_state(prev_files)

if __name__ == "__main__":
    # requestsモジュールがなければ、エラーで終了する可能性がある
    # systemdで再起動するため問題は少ないが、動作環境依存となる
    main()
EOF

chmod +x "${PYTHON_SCRIPT_PATH}"
echo "✅ スクリプトの生成と権限設定が完了しました。"

# 3. systemdサービスファイルの生成と設定
echo "➡️ systemdサービスファイル ${SERVICE_FILE} を生成します..."
# ヒアドキュメントでサービスファイルを生成
cat << EOF > "${SERVICE_FILE}"
[Unit]
Description=MMDVM Log Watcher for WPSD Ingestion
After=network-online.target
Wants=network-online.target

[Service]
# /root/への書き込みが必要なため、rootで実行します
User=root
Group=root

# Pythonスクリプトの新しいフルパスを指定
ExecStart=/usr/bin/python3 ${PYTHON_SCRIPT_PATH}

# プロセスが予期せず終了した場合に自動的に再起動します
Restart=always
# 再起動までの待ち時間 (秒)
RestartSec=5

# 標準出力をシステムログに出力します
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=mmdvm_watch

[Install]
WantedBy=multi-user.target
EOF
echo "✅ サービスファイルの生成が完了しました。"

# 4. サービスの有効化と起動
echo "➡️ systemd設定を再読み込みし、サービスを有効化・起動します..."
systemctl daemon-reload
systemctl enable mmdvm_watch.service
systemctl restart mmdvm_watch.service

echo "✅ サービスの有効化と再起動が完了しました。"

# 5. ファイルシステムを読み取り専用に戻す
echo "➡️ ファイルシステムを読み取り専用 (rpi-ro) に戻します..."
rpi-ro

# 6. 最終ステータスの確認
echo "================================================="
echo "  設定が完了しました。サービスのステータスを確認します。"
echo "================================================="
systemctl status mmdvm_watch.service
echo ""
echo "ログの確認: sudo journalctl -u mmdvm_watch.service -f"


