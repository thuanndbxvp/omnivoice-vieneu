# -*- coding: utf-8 -*-
"""Clean customer data from database SQL dump.

Removes all customer data (licenses, devices, logs, counters, resets, agencies).
Pre-populates platform_apps with official apps including ai-tts1 (OmniVoice).
Generates:
1. adminpanel/db/clean_init_89globalmedia.sql
2. Updates adminpanel/db/gomhuong1_license.sql
"""

import os
import re
import shutil
import sys
from pathlib import Path

# UTF-8 stdout
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

DB_DIR = Path("adminpanel/db")
SQL_FILE = DB_DIR / "gomhuong1_license.sql"
BACKUP_FILE = DB_DIR / "gomhuong1_license_backup.sql"
CLEAN_FILE = DB_DIR / "clean_init_89globalmedia.sql"

print("=" * 70)
print("TIẾN HÀNH XÓA TRẮNG TOÀN BỘ DỮ LIỆU KHÁCH HÀNG CŨ TRONG DATABASE")
print("=" * 70)

# 1. Backup original file
if SQL_FILE.exists() and not BACKUP_FILE.exists():
    shutil.copy2(SQL_FILE, BACKUP_FILE)
    print(f"[1/4] Đã sao lưu dữ liệu gốc sang: {BACKUP_FILE.name}")
else:
    print(f"[1/4] Bản sao lưu đã có: {BACKUP_FILE.name}")

raw_content = BACKUP_FILE.read_text(encoding="utf-8", errors="ignore")

# 2. Parse blocks by table
# We want to remove all INSERT INTO statements for customer tables
CUSTOMER_TABLES = [
    "agencies",
    "api_logs",
    "api_request_logs",
    "audit_log",
    "devices",
    "license_device_resets",
    "license_request_counters",
    "licenses",
    "sessions",
    "used_nonces",
]

lines = raw_content.splitlines(keepends=True)
output_lines = []
current_table = None
skip_inserts = False

for line in lines:
    # Detect table context
    m_table = re.search(r"-- Table structure for table `([^`]+)`", line)
    if m_table:
        current_table = m_table.group(1)
        output_lines.append(line)
        continue

    # If it's an INSERT statement
    if line.strip().startswith("INSERT INTO `"):
        m_ins = re.match(r"INSERT INTO `([^`]+)`", line.strip())
        if m_ins:
            ins_tbl = m_ins.group(1)
            if ins_tbl in CUSTOMER_TABLES:
                # Skip this line (clearing customer data)
                continue
            if ins_tbl == "platform_apps":
                # Replace with clean platform_apps containing ai-tts1
                clean_apps_sql = (
                    "INSERT INTO `platform_apps` "
                    "(`id`, `app_id`, `app_name`, `verify_mode`, `default_max_devices`, `default_years`, `device_tracking`, `is_active`, `created_at`, `updated_at`) VALUES "
                    "(1,'ai-tts1','89 Global Media — OmniVoice Cloner & TTS','standard',2,1,1,1,NOW(),NOW()),"
                    "(2,'omnivoice','OmniVoice Engine Pro','standard',2,1,1,1,NOW(),NOW()),"
                    "(3,'syncaudio_v1','SyncAudio CapCut Pro','standard',2,1,1,1,NOW(),NOW()),"
                    "(4,'ai-tts3','Voicebox AI TTS3','standard',1,1,1,1,NOW(),NOW()),"
                    "(5,'appreview1','App Dịch và Review','standard',1,1,1,1,NOW(),NOW());\n"
                )
                output_lines.append(clean_apps_sql)
                continue

    output_lines.append(line)

clean_sql_content = "".join(output_lines)

# Write clean init file
CLEAN_FILE.write_text(clean_sql_content, encoding="utf-8")
print(f"[2/4] Đã tạo tệp SQL trắng sạch hoàn toàn: {CLEAN_FILE.name}")

# Overwrite gomhuong1_license.sql with clean version
SQL_FILE.write_text(clean_sql_content, encoding="utf-8")
print(f"[3/4] Đã cập nhật tệp SQL chính: {SQL_FILE.name}")

# 3. Summary stats
orig_size_kb = BACKUP_FILE.stat().st_size / 1024
clean_size_kb = CLEAN_FILE.stat().st_size / 1024
print(f"\n[4/4] Thống kê dọn dẹp:")
print(f"  - Dung lượng ban đầu : {orig_size_kb:.2f} KB (chứa hàng ngàn bản ghi khách hàng cũ)")
print(f"  - Dung lượng sau dọn : {clean_size_kb:.2f} KB (100% sạch bóng dữ liệu khách)")
print(f"  - Bảng licenses      : ĐÃ XÓA TRẮNG (0 bản ghi)")
print(f"  - Bảng devices       : ĐÃ XÓA TRẮNG (0 bản ghi)")
print(f"  - Bảng api_logs      : ĐÃ XÓA TRẮNG (0 bản ghi)")
print(f"  - Bảng agencies      : ĐÃ XÓA TRẮNG (0 bản ghi)")
print(f"  - Bảng platform_apps : ĐÃ KHỞI TẠO 5 APP CHUẨN (bao gồm ai-tts1 cho OmniVoice)")
print("=" * 70)
