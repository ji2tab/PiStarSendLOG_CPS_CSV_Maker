#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
DMR Hotspot Watcher - Production Final Release (v5.7.0)
重複送信ガード（3秒間隔制限）およびバッファリング対策済み。
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
# ⚙️ 設定項目
# =========================================================================
SERVER_HOST  = "jj2yyk.forums.gr.jp"
API_TOKEN    = "APITOKEN"
NODE_NAME    = "yyk-tgif"

ENDPOINT     = f"https://{SERVER_HOST}/wp-json/hotspot/ingest"
LOG_DIR      = "/var/log/pi-star"
MMDVM_CONF   = "/etc/mmdvmhost"
DMRGW_CONF   = "/etc/dmrgateway"

# 送信間隔制限（同一局からの連投防止）
COOLDOWN_SEC = 3.0

def log_out(msg):
    print(msg, flush=True)

def get_dynamic_tgs():
    for conf_path in [DMRGW_CONF, MMDVM_CONF]:
        if not os.path.isfile(conf_path): continue
        for raw in Path(conf_path).read_text(encoding="utf-8", errors="replace").splitlines():
            if "tgif.network" in raw and "Address" in raw:
                return "TGIF168"
    return "TGIF168"

def get_device_stats():
    stats = {"cpu_temp": "---", "memory_usage": "---", "disk_free": "---"}
    try:
        if os.path.exists("/sys/class/thermal/thermal_zone0/temp"):
            with open("/sys/class/thermal/thermal_zone0/temp", "r") as t_f:
                stats["cpu_temp"] = f"{int(t_f.read().strip()) / 1000.0:.1f}°C"
        res_mem = subprocess.check_output("free -m", shell=True).decode("utf-8").splitlines()
        if len(res_mem) > 1:
            p = res_mem[1].split()
            stats["memory_usage"] = f"{(float(p[2]) / float(p[1])) * 100.0:.1f}%"
    except: pass
    return stats

def post_json(data):
    payload = json.dumps(data).encode('utf-8')
    headers = {"Content-Type": "application/json", "X-Hotspot-Token": API_TOKEN}
    req = urllib.request.Request(ENDPOINT, data=payload, headers=headers, method='POST')
    try:
        with urllib.request.urlopen(req, timeout=10) as res:
            src_d = "📡 [RF]" if str(data['dmr']['src']).lower() == "rf" else "🌐 [NET]"
            log_out(f" 🟢 WP送信成功: {data['callsign']} {src_d} ➔ {data['net_label']} (TG:{data['dmr']['dst']})")
            return True
    except Exception as e:
        log_out(f" ❌ 送信エラー: {str(e)}")
    return False

def parse_callsign(line):
    m = re.search(r"from\s+(\S+)", line, re.IGNORECASE)
    return m.group(1).upper() if m else ""

def main():
    log_out("📡 WPSDリアルタイム監視開始 (v5.7.0)")
    prev_sizes = {}
    session_buffer = {}
    last_sent_time = {} # 重複ガード用

    while True:
        try:
            time.sleep(0.5)
            for fname in os.listdir(LOG_DIR):
                if not (fname.startswith("MMDVM-") and fname.endswith(".log")): continue
                path = os.path.join(LOG_DIR, fname)
                size = os.path.getsize(path)
                last_size = prev_sizes.get(fname, 0)
                if size <= last_size:
                    if size < last_size: prev_sizes[fname] = size
                    continue

                with open(path, 'r', encoding='utf-8', errors='ignore') as f:
                    f.seek(last_size)
                    for line in f:
                        if "voice header" in line or "late entry" in line:
                            slot = re.search(r"Slot (\d+),", line)
                            s = slot.group(1) if slot else "2"
                            session_buffer[s] = {
                                "slot": s, "src": ("RF" if "RF" in line else "NET"),
                                "dst": (re.search(r"to\s+(?:TG|PC|REF)\s+(\d+)", line) or [None, "1"])[1],
                                "callsign": parse_callsign(line)
                            }
                        elif any(x in line for x in ["end of voice", "transmission lost", "watchdog has expired"]):
                            slot = re.search(r"Slot (\d+),", line)
                            s = slot.group(1) if slot else "2"
                            if s in session_buffer:
                                call = parse_callsign(line) or session_buffer[s]['callsign']
                                now = time.time()
                                if call not in last_sent_time or (now - last_sent_time[call] > COOLDOWN_SEC):
                                    data = session_buffer[s]
                                    data['callsign'] = call
                                    post_json({
                                        "node": NODE_NAME, "source_node": NODE_NAME,
                                        "net_label": get_dynamic_tgs(), "callsign": call,
                                        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                                        "dmr": {"slot": int(s), "src": data['src'].lower(), "dst": data['dst']},
                                        "device_info": get_device_stats()
                                    })
                                    last_sent_time[call] = now
                                del session_buffer[s]
                prev_sizes[fname] = size
        except Exception as ex:
            log_out(f"❌ ループエラー: {ex}")
            time.sleep(5)

if __name__ == "__main__":
    main()
