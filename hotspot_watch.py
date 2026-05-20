#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
DMR Hotspot Watcher - Production Release
WPSD / MMDVMの生ログをリアルタイムに監視し、交信確定時（end of voice）に
WordPressエンドポイントへJSONペイロードを送信する常駐デーモン。
"""

import os
import time
import json
import re
import urllib.request
import urllib.error
import subprocess
from pathlib import Path
from datetime import datetime

# =========================================================================
# ⚙️ 設定項目（環境依存の固定値）
# =========================================================================
SERVER_HOST  = "jj2yyk.forums.gr.jp"
API_TOKEN    = "Zn3d9vs2PZu35Hm"
NODE_NAME    = "yyk-tgif"

ENDPOINT     = f"https://{SERVER_HOST}/wp-json/hotspot/ingest"
LOG_DIR      = "/var/log/pi-star"
MMDVM_CONF   = "/etc/mmdvmhost"
DMRGW_CONF   = "/etc/dmrgateway"
VERSION      = "v5.5.0 [Production Release]"

# =========================================================================
# 1. TGルーティング・設定解析ロジック
# =========================================================================
def _iter_sections(path: str):
    if not os.path.isfile(path):
        return
    current, buf = "", []
    try:
        for raw in Path(path).read_text(encoding="utf-8", errors="replace").splitlines():
            m = re.match(r"^\[(.+?)\]\s*$", raw)
            if m:
                yield current, buf
                current, buf = m.group(1).strip(), []
            else:
                buf.append(raw)
        yield current, buf
    except OSError:
        return

def _kv(line: str):
    s = line.strip()
    if not s or s.startswith(("#", ";")):
        return None
    if "=" not in s:
        return None
    k, _, v = s.partition("=")
    return k.strip(), v.split("#", 1)[0].strip()

def get_dynamic_tgs():
    """設定ファイルからホームTG（復帰先TG）を自動抽出してネットラベルにマッピング"""
    for conf_path in [DMRGW_CONF, MMDVM_CONF]:
        for section, lines in _iter_sections(conf_path):
            if not section.startswith("DMR Network"):
                continue
            is_tgif = False
            rewrite = None
            for line in lines:
                kv = _kv(line)
                if not kv:
                    continue
                k, v = kv
                if k == "Address" and "tgif.network" in v:
                    is_tgif = True
                elif k.startswith("TGRewrite") and rewrite is None:
                    rewrite = v
            if is_tgif and rewrite:
                parts = rewrite.split(",")
                parsed_r = re.sub(r"\D", "", parts[3]) if len(parts) > 3 else ""
                if parsed_r:
                    return f"TGIF{parsed_r}"
    return "TGIF168"

# =========================================================================
# 2. 端末情報（健康状態）取得ロジック
# =========================================================================
def get_device_stats():
    """Pi-Star端末の健康状態（CPU温度・メモリ・ディスク使用量）を取得"""
    stats = {
        "cpu_temp": "---",
        "memory_usage": "---",
        "disk_free": "---"
    }
    try:
        if os.path.exists("/sys/class/thermal/thermal_zone0/temp"):
            with open("/sys/class/thermal/thermal_zone0/temp", "r") as t_f:
                temp_raw = int(t_f.read().strip())
                stats["cpu_temp"] = f"{temp_raw / 1000.0:.1f}°C"

        res_mem = subprocess.check_output("free -m", shell=True).decode("utf-8")
        lines_mem = res_mem.splitlines()
        if len(lines_mem) > 1:
            parts = lines_mem[1].split()
            total, used = float(parts[1]), float(parts[2])
            stats["memory_usage"] = f"{(used / total) * 100.0:.1f}%"

        res_df = subprocess.check_output("df -h /", shell=True).decode("utf-8")
        lines_df = res_df.splitlines()
        if len(lines_df) > 1:
            parts = lines_df[1].split()
            stats["disk_free"] = f"{parts[3]} free ({parts[4]} used)"
    except Exception:
        pass
    return stats

# =========================================================================
# 3. ログ解析および送信ロジック
# =========================================================================
def log_out(msg):
    """systemdのjournalctlで遅延なく表示させるための即時出力関数"""
    print(msg, flush=True)

def post_json(data):
    """WordPressへJSONペイロードをPOST送信"""
    payload = json.dumps(data).encode('utf-8')
    headers = {
        "Content-Type": "application/json",
        "X-Hotspot-Token": API_TOKEN
    }
    req = urllib.request.Request(ENDPOINT, data=payload, headers=headers, method='POST')
    try:
        with urllib.request.urlopen(req, timeout=10) as res:
            # 送信成功時のコンソール表示整形
            dmr_info = data.get('dmr', {})
            dev_info = data.get('device_info', {})
            raw_src = str(dmr_info.get('src', 'rf')).lower()
            src_display = "📡 [RF]" if raw_src == "rf" else "🌐 [NET]"

            log_out(f" 🟢 WP送信成功 [{res.status}]: {data.get('callsign','?')} {src_display} ➔ {data.get('net_label','?')} (TG: {dmr_info.get('dst','?')}) [{dev_info.get('cpu_temp','?')}/Mem:{dev_info.get('memory_usage','?')}]")
            return True
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8')
        log_out(f" 🔴 WP送信失敗 [HTTP {e.code}]: {body[:120]}")
    except Exception as e:
        log_out(f" ❌ 通信エラー: {str(e)}")
    return False

# --- WPSDログ専用 パーサー群 ---
def parse_slot(line):
    m = re.search(r"Slot (\d+),", line)
    return m.group(1) if m else "2"

def parse_callsign(line):
    m = re.search(r"from\s+(\S+)\s+to", line, re.IGNORECASE)
    if m: return m.group(1).upper()
    m = re.search(r"from\s+(\S+)", line, re.IGNORECASE)
    return m.group(1).upper() if m else ""

def parse_src(line):
    m = re.search(r"received\s+(RF|NETWORK)", line, re.IGNORECASE)
    return (m.group(1).upper() if m else "RF")

def parse_dst(line):
    m = re.search(r"to\s+(?:TG|PC|REF)\s+(\d+)", line, re.IGNORECASE)
    return m.group(1) if m else "1"

def send_session(s):
    """セッションバッファの内容を整形して送信"""
    formatted_time = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    current_net_label = get_dynamic_tgs()
    device_info = get_device_stats()

    payload = {
        "node":        NODE_NAME,
        "source_node": NODE_NAME,
        "net_label":   current_net_label,
        "callsign":    s['callsign'],
        "timestamp":   formatted_time,
        "dmr": {
            "slot": int(s['slot']),
            "src":  s['src'].lower(),
            "dst":  s['dst']
        },
        "device_info": device_info
    }
    post_json(payload)

# =========================================================================
# 4. メインループ（リアルタイム監視）
# =========================================================================
def main():
    log_out("=========================================================")
    log_out(f"   Hotspot Watcher {VERSION}")
    log_out("=========================================================")
    log_out(f" 送信先エンドポイント : {ENDPOINT}")
    log_out(f" 識別ノード名        : {NODE_NAME}")
    dynamic_net_label = get_dynamic_tgs()
    log_out(f" 動的検出ネットラベル : {dynamic_net_label}")
    log_out("=========================================================")

    prev_sizes = {}
    session_buffer = {}

    try:
        # 初期化：既存ログのサイズを取得（過去のログ送信をスキップ）
        for fname in os.listdir(LOG_DIR):
            if fname.startswith("MMDVM-") and fname.endswith(".log"):
                path = os.path.join(LOG_DIR, fname)
                prev_sizes[fname] = os.path.getsize(path)
    except Exception as ex:
        log_out(f"❌ 初期化エラー: {str(ex)}")

    log_out("\n📡 WPSDリアルタイム監視中（バックグラウンド稼働）...")

    while True:
        try:
            time.sleep(0.5)

            for fname in os.listdir(LOG_DIR):
                if not (fname.startswith("MMDVM-") and fname.endswith(".log")):
                    continue
                path = os.path.join(LOG_DIR, fname)
                size = os.path.getsize(path)
                last_size = prev_sizes.get(fname, 0)

                # ログファイルのローテーションまたは縮小検知
                if size <= last_size:
                    if size < last_size:
                        prev_sizes[fname] = size
                    continue

                with open(path, 'r', encoding='utf-8', errors='ignore') as f:
                    f.seek(last_size)
                    for line in f:
                        # [話始め または 遅延検知] バッファに記憶
                        if ("voice header" in line) or ("late entry" in line):
                            slot      = parse_slot(line)
                            from_call = parse_callsign(line)
                            if from_call:
                                session_buffer[slot] = {
                                    "slot":     slot,
                                    "src":      parse_src(line),
                                    "dst":      parse_dst(line),
                                    "callsign": from_call,
                                }
                        
                        # [話し終わり または ロスト] バッファから取り出して確定送信
                        elif (("end of voice" in line)
                              or ("transmission lost" in line)
                              or ("watchdog has expired" in line)):

                            slot = parse_slot(line)
                            if slot not in session_buffer:
                                continue

                            s = session_buffer[slot]
                            end_call = parse_callsign(line)
                            
                            # 終了時のコールサインが異なる場合（稀なケース）の上書き補正
                            if end_call and end_call != s['callsign']:
                                s = dict(s)
                                s['callsign'] = end_call
                                s['src'] = parse_src(line)
                                s['dst'] = parse_dst(line)

                            send_session(s)
                            del session_buffer[slot]

            # 読み込み終わったサイズを記録
            prev_sizes[fname] = size

        except KeyboardInterrupt:
            log_out("\n👋 監視を終了します。")
            break
        except Exception as ex:
            log_out(f"❌ ループエラー: {str(ex)}")
            time.sleep(5)

if __name__ == "__main__":
    main()
