#!/bin/bash
# =========================================================================
# DMR Hotspot Watcher - Install Script (for Pi-Star / WPSD)
# Author: JI2TAB / JJ2YYK
# =========================================================================

# カラー出力の設定
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${GREEN}=========================================================${NC}"
echo -e "${GREEN}   DMR Hotspot Watcher - 自動インストーラー${NC}"
echo -e "${GREEN}=========================================================${NC}"

# 1. 権限と環境のチェック
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}エラー: このスクリプトは root 権限で実行してください。${NC}"
  echo "実行方法: sudo bash install.sh"
  exit 1
fi

if ! command -v python3 &> /dev/null; then
  echo -e "${RED}エラー: Python3 がインストールされていません。${NC}"
  exit 1
fi

# 2. Pi-Star / WPSD の Read-Write モード化
echo -e "\n${YELLOW}[1/4] ファイルシステムの書き込み権限を有効化します...${NC}"
if command -v rpi-rw &> /dev/null; then
    rpi-rw
    echo " -> rpi-rw を実行しました。"
else
    mount -o remount,rw /
    echo " -> mount -o remount,rw / を実行しました。"
fi

# 3. インストール先の設定
INSTALL_DIR="/root"
SCRIPT_NAME="hotspot_watch.py"
SERVICE_NAME="hotspot_watch.service"
GITHUB_RAW_URL="https://raw.githubusercontent.com/ji2tab/PiStarSendLOG_CPS_CSV_Maker/main/hotspot_watch.py"

# 4. スクリプトのダウンロード
echo -e "\n${YELLOW}[2/4] 最新の監視スクリプトをダウンロードします...${NC}"
wget -q -O "${INSTALL_DIR}/${SCRIPT_NAME}" "${GITHUB_RAW_URL}"

if [ ! -f "${INSTALL_DIR}/${SCRIPT_NAME}" ]; then
    echo -e "${RED}エラー: スクリプトのダウンロードに失敗しました。URLを確認してください。${NC}"
    exit 1
fi
chmod +x "${INSTALL_DIR}/${SCRIPT_NAME}"
echo " -> ダウンロードと権限付与が完了しました。"

# 5. ユーザー設定の対話入力
echo -e "\n${YELLOW}[3/4] 接続先サーバーの設定を行います${NC}"
read -p "WordPressサーバーのドメイン名 (例: jj2yyk.forums.gr.jp): " input_host
read -p "このホットスポットの識別ノード名 (例: yyk-tgif): " input_node
read -p "APIトークン (WordPress側で設定したパスワード): " input_token

# 空文字のデフォルト値処理
input_host=${input_host:-jj2yyk.forums.gr.jp}
input_node=${input_node:-yyk-tgif}
input_token=${input_token:-Zn3d9vs2PZu35Hm}

# ダウンロードしたPythonスクリプトの設定部分を置換
sed -i "s/^SERVER_HOST  = .*/SERVER_HOST  = \"${input_host}\"/" "${INSTALL_DIR}/${SCRIPT_NAME}"
sed -i "s/^NODE_NAME    = .*/NODE_NAME    = \"${input_node}\"/" "${INSTALL_DIR}/${SCRIPT_NAME}"
sed -i "s/^API_TOKEN    = .*/API_TOKEN    = \"${input_token}\"/" "${INSTALL_DIR}/${SCRIPT_NAME}"
echo " -> 設定を ${SCRIPT_NAME} に書き込みました。"

# 6. systemd サービスの登録
echo -e "\n${YELLOW}[4/4] バックグラウンドサービス (systemd) を登録・起動します...${NC}"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}"

cat <<EOF > ${SERVICE_FILE}
[Unit]
Description=DMR Hotspot Watcher Service
After=network.target mmdvmhost.service
Wants=network-online.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 ${INSTALL_DIR}/${SCRIPT_NAME}
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
User=root

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable ${SERVICE_NAME}
systemctl restart ${SERVICE_NAME}

# =========================================================================
# 完了メッセージと使い方ガイド
# =========================================================================
echo -e "\n${GREEN}=========================================================${NC}"
echo -e "${GREEN} 🎉 インストールが完了し、監視サービスが起動しました！${NC}"
echo -e "${GREEN}=========================================================${NC}"
echo -e "\n${CYAN}【動作確認の方法】${NC}"
echo -e " 以下のコマンドをコピーして実行すると、リアルタイムにログを確認できます。"
echo -e " (終了するにはキーボードの Ctrl + C を押してください)"
echo -e "   ${YELLOW}sudo journalctl -u hotspot_watch -f${NC}\n"

echo -e "${CYAN}【サービスの管理コマンド】${NC}"
echo -e " 状態の確認 : ${YELLOW}sudo systemctl status hotspot_watch${NC}"
echo -e " 再起動     : ${YELLOW}sudo systemctl restart hotspot_watch${NC}"
echo -e " 停止       : ${YELLOW}sudo systemctl stop hotspot_watch${NC}\n"

echo -e "※ アンインストールしたい場合は、提供されている uninstall.sh を実行してください。"
echo -e "${GREEN}=========================================================${NC}"
