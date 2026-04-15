#!/bin/bash

# =================================================
# スクリプト名: hotspot_setup.sh
# バージョン: 2.2.2
# 概要: MMDVMログ監視サービスのセットアップ
# =================================================

# --- 1. root権限チェック ---
if [ "$EUID" -ne 0 ]; then
  echo "❌ エラー: このスクリプトはsudoで実行してください。"
  exit 1
fi

echo "================================================="
echo "   Hotspot Watcher v2.2.2 セットアップ"
echo "================================================="

# --- 2. ユーザー入力（デフォルト値なし・必須入力） ---
while true; do
  read -p "1. 接続先サーバー (例: yourserver.example.com): " SERVER_HOST
  [ -n "$SERVER_HOST" ] && break
  echo "   ❌ 接続先サーバーを入力してください。"
done

ENDPOINT="https://${SERVER_HOST}/wp-json/hotspot/ingest"

while true; do
  read -p "2. ノード名 (例: yournode): " NODE_NAME
  [ -n "$NODE_NAME" ] && break
  echo "   ❌ ノード名を入力してください。"
done

while true; do
  read -p "3. APIトークン (例: yourtoken): " API_TOKEN
  [ -n "$API_TOKEN" ] && break
  echo "   ❌ APIトークンを入力してください。"
done

while true; do
  read -p "4. ネットワーク表示名・英数半角10文字まで (例: TGIF168 / XLX834Z): " NET_LABEL
  [ -z "$NET_LABEL" ] && echo "   ❌ ネットワーク表示名を入力してください。" && continue
  if echo "$NET_LABEL" | grep -qP '^[A-Za-z0-9 ]{1,10}$'; then
    break
  fi
  echo "   ❌ 英数字・スペースのみ、10文字以内で入力してください。"
done

echo ""
echo "  接続先サーバー     : ${SERVER_HOST}"
echo "  エンドポイント     : ${ENDPOINT}"
echo "  ノード名           : ${NODE_NAME}"
echo "  APIトークン        : ${API_TOKEN}"
echo "  ネットワーク表示名 : ${NET_LABEL}"
echo ""
read -p "上記の設定で続行しますか？ [y/N]: " CONFIRM
case "$CONFIRM" in
  [yY]|[yY][eE][sS]) ;;
  *) echo "キャンセルしました。"; exit 0 ;;
esac

# --- 3. ファイルシステムを書き込み可能に ---
if command -v rpi-rw > /dev/null; then rpi-rw; fi

PYTHON_SCRIPT="/root/hotspot_watch.py"
SERVICE_FILE="/etc/systemd/system/hotspot_watch.service"

# --- 4. 既存サービスのクリーンアップ ---
echo "➡️ 既存サービスをクリーンアップ中..."
systemctl stop hotspot_watch 2>/dev/null
systemctl disable hotspot_watch 2>/dev/null
pkill -f hotspot_watch.py 2>/dev/null
sleep 1

# --- 5. Python監視スクリプトの生成 ---
echo "➡️ 監視スクリプトを生成中..."
cat << 'PYEOF' > "${PYTHON_SCRIPT}"
# coding: utf-8
import os, time, json, re, logging, urllib.request
from datetime import datetime, timezone, timedelta

# --- 基本設定（hotspot_setup.sh により自動生成） ---
ENDPOINT  = "ENDPOINT_PLACEHOLDER"
TOKEN     = "TOKEN_PLACEHOLDER"
NODE_NAME = "NODE_PLACEHOLDER"
NET_LABEL = "NETLABEL_PLACEHOLDER"
LOG_DIR  = "/var/log/pi-star"

logging.basicConfig(
    level=logging.INFO,
    format='[%(asctime)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

def post_json(data):
    payload = json.dumps(data).encode('utf-8')
    headers = {
        "Content-Type": "application/json",
        "X-Hotspot-Token": TOKEN
    }
    req = urllib.request.Request(ENDPOINT, data=payload, headers=headers, method='POST')
    try:
        with urllib.request.urlopen(req, timeout=10) as res:
            body = res.read().decode('utf-8')
            logging.info("Success [%d]: %s -> %s" % (res.status, data.get('callsign','?'), body[:60]))
            return True
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8')
        logging.error("POST Failed [HTTP %d]: %s" % (e.code, body[:120]))
    except Exception as e:
        logging.error("POST Failed: " + str(e))
    return False

HEADER_P = re.compile(
    r'(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2}\.\d{3})\s+DMR\s+Slot\s+(\d+),'
    r'\s+received\s+(RF|NETWORK)\s+voice\s+header\s+from\s+(.+?)\s+to\s+(TG|PC|REF)\s+(\d+|ALL)',
    re.IGNORECASE
)
END_P = re.compile(
    r'(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2}\.\d{3})\s+DMR\s+Slot\s+(\d+),'
    r'\s+received\s+(RF|NETWORK)\s+end\s+of\s+voice\s+transmission\s+from\s+(.+?)\s+to\s+(TG|PC|REF)\s+(\d+|ALL)',
    re.IGNORECASE
)

def main():
    logging.info("Hotspot Watcher v2.2.2 started. Node=%s" % NODE_NAME)
    session_buffer = {}

    # 起動時に既存ログファイルの現在サイズを記録（過去分をスキップ）
    prev_sizes = {}
    try:
        for fname in os.listdir(LOG_DIR):
            if fname.startswith("MMDVM-") and fname.endswith(".log"):
                path = os.path.join(LOG_DIR, fname)
                prev_sizes[fname] = os.path.getsize(path)
                logging.info("Skip existing: %s (%d bytes)" % (fname, prev_sizes[fname]))
    except Exception as ex:
        logging.error("Init error: " + str(ex))

    while True:
        time.sleep(2)
        try:
            for fname in os.listdir(LOG_DIR):
                if not (fname.startswith("MMDVM-") and fname.endswith(".log")):
                    continue
                path = os.path.join(LOG_DIR, fname)
                size = os.path.getsize(path)
                last_size = prev_sizes.get(fname, 0)

                if size > last_size:
                    with open(path, 'r', encoding='utf-8', errors='ignore') as f:
                        f.seek(last_size)
                        for line in f:
                            if 'voice header' in line:
                                m = HEADER_P.search(line)
                                if m:
                                    key = m.group(5) + "_" + m.group(3)
                                    session_buffer[key] = {
                                        'slot': m.group(3),
                                        'src':  m.group(4),
                                        'dst':  m.group(7),
                                        'call': m.group(5).upper()
                                    }
                            elif 'end of voice' in line:
                                m = END_P.search(line)
                                if m:
                                    key = m.group(5) + "_" + m.group(3)
                                    if key in session_buffer:
                                        s = session_buffer[key]
                                        dt_utc = datetime.strptime(
                                            m.group(1) + ' ' + m.group(2),
                                            '%Y-%m-%d %H:%M:%S.%f'
                                        ).replace(tzinfo=timezone.utc)
                                        dt_jst = dt_utc + timedelta(hours=9)
                                        payload = {
                                            "node":        NODE_NAME,
                                            "source_node": NODE_NAME,
                                            "net_label":   NET_LABEL,
                                            "callsign":    s['call'],
                                            "timestamp":   dt_jst.strftime("%Y-%m-%d %H:%M:%S"),
                                            "dmr": {
                                                "slot": int(s['slot']),
                                                "src":  s['src'],
                                                "dst":  s['dst']
                                            }
                                        }
                                        post_json(payload)
                                        del session_buffer[key]
                    prev_sizes[fname] = size
        except Exception as ex:
            logging.error("Loop error: " + str(ex))
            time.sleep(5)

if __name__ == "__main__":
    main()
PYEOF

# プレースホルダーを実際の値に置換
sed -i "s|ENDPOINT_PLACEHOLDER|${ENDPOINT}|g"   "${PYTHON_SCRIPT}"
sed -i "s|TOKEN_PLACEHOLDER|${API_TOKEN}|g"     "${PYTHON_SCRIPT}"
sed -i "s|NODE_PLACEHOLDER|${NODE_NAME}|g"      "${PYTHON_SCRIPT}"
sed -i "s|NETLABEL_PLACEHOLDER|${NET_LABEL}|g"  "${PYTHON_SCRIPT}"

chmod +x "${PYTHON_SCRIPT}"

# --- 6. システムサービスの登録 ---
echo "➡️ システムサービスを登録中..."
cat << EOF > "${SERVICE_FILE}"
[Unit]
Description=Hotspot Log Watcher Service
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
ExecStart=/usr/bin/python3 ${PYTHON_SCRIPT}
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# サービスの有効化と起動
systemctl daemon-reload
systemctl enable hotspot_watch.service
systemctl restart hotspot_watch.service

sleep 2
echo ""
echo "--- サービス状態 ---"
systemctl status hotspot_watch --no-pager -l

if command -v rpi-ro > /dev/null; then rpi-ro; fi

echo ""
echo "================================================="
echo "   ✅ セットアップ完了！"
echo "   送信先: ${ENDPOINT}"
echo ""
echo "   動作確認コマンド:"
echo "   sudo journalctl -u hotspot_watch -f"
echo "================================================="
