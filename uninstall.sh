#!/bin/bash
# =========================================================================
# DMR Hotspot Watcher - Uninstall Script (for Pi-Star / WPSD)
# Author: JI2TAB / JJ2YYK
# =========================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${RED}=========================================================${NC}"
echo -e "${RED}   DMR Hotspot Watcher - 自動アンインストーラー${NC}"
echo -e "${RED}=========================================================${NC}"

if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}エラー: このスクリプトは root 権限で実行してください。${NC}"
  exit 1
fi

# Pi-Star / WPSD の Read-Write モード化
echo -e "\n${YELLOW}[1/3] ファイルシステムの書き込み権限を有効化します...${NC}"
if command -v rpi-rw &> /dev/null; then
    rpi-rw
else
    mount -o remount,rw /
fi

INSTALL_DIR="/root"
SCRIPT_NAME="hotspot_watch.py"
SERVICE_NAME="hotspot_watch.service"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}"

# 1. サービスの停止と無効化
echo -e "\n${YELLOW}[2/3] サービスを停止・無効化しています...${NC}"
if systemctl is-active --quiet ${SERVICE_NAME}; then
    systemctl stop ${SERVICE_NAME}
    echo " -> サービスを停止しました。"
fi

if systemctl is-enabled --quiet ${SERVICE_NAME}; then
    systemctl disable ${SERVICE_NAME}
    echo " -> サービスの自動起動を無効化しました。"
fi

# 2. ファイルの削除
echo -e "\n${YELLOW}[3/3] 関連ファイルをシステムから削除しています...${NC}"

if [ -f "${SERVICE_FILE}" ]; then
    rm -f "${SERVICE_FILE}"
    echo " -> サービス定義ファイル (${SERVICE_FILE}) を削除しました。"
fi

if [ -f "${INSTALL_DIR}/${SCRIPT_NAME}" ]; then
    rm -f "${INSTALL_DIR}/${SCRIPT_NAME}"
    echo " -> Pythonスクリプト (${INSTALL_DIR}/${SCRIPT_NAME}) を削除しました。"
fi

# systemdの再読み込み
systemctl daemon-reload

echo -e "\n${GREEN}=========================================================${NC}"
echo -e "${GREEN} 🗑️ アンインストールが正常に完了しました。${NC}"
echo -e "${GREEN}=========================================================${NC}"
