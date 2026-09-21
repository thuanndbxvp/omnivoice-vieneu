# -*- coding: utf-8 -*-
"""Repack app-v1.0.0.zip with dedicated single license domain and upload to Cloudflare R2."""

import json
import os
import sys
import time
import zipfile
from pathlib import Path

if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

from r2_tool import (
    BASE_DIR,
    DIST_DIR,
    APP_VERSION,
    load_r2_config,
    get_s3_client,
    compute_sha256,
    upload_file,
)

zip_path = DIST_DIR / f"app-v{APP_VERSION}.zip"
print("=" * 60)
print(f"DÓNG GÓI LẠI APP CODE VỚI DOMAIN 89GLOBALMEDIA.ONLINE (KHÔNG FALLBACK)")
print("=" * 60)

include_dirs = ["src", "omnivoice_voice_library", "vieneu_voice_library"]
include_files = ["main_secure.py", "main.py", "main2.py", "Applogo.png", "favicon.ico", "requirements.txt"]

with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
    for fname in include_files:
        p = BASE_DIR / fname
        if p.exists():
            zf.write(p, arcname=f"app/{fname}")

    for dname in include_dirs:
        dp = BASE_DIR / dname
        if dp.exists():
            for root, _, files in os.walk(dp):
                if any(skip in root for skip in ["__pycache__", ".git"]):
                    continue
                for f in files:
                    fp = Path(root) / f
                    rel = fp.relative_to(BASE_DIR)
                    zf.write(fp, arcname=f"app/{rel.as_posix()}")

app_size = zip_path.stat().st_size
app_sha = compute_sha256(zip_path)
size_mb = app_size / (1024 * 1024)
print(f"  -> Đã đóng gói xong: {zip_path.name} ({size_mb:.2f} MB)")
print(f"  -> SHA256 mới: {app_sha}")

# Upload to R2
conf = load_r2_config()
client = get_s3_client(conf)
bucket = conf["bucket"]

upload_file(client, zip_path, bucket, f"app/app-v{APP_VERSION}.zip", "application/zip")
print("  -> Upload app-v1.0.0.zip lên R2 thành công!")

# Update manifest.json
manifest_path = DIST_DIR / "manifest.json"
manifest_data = json.loads(manifest_path.read_text("utf-8"))
manifest_data["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime())
manifest_data["app_package"]["sha256"] = app_sha
manifest_data["app_package"]["size_bytes"] = app_size

manifest_path.write_text(json.dumps(manifest_data, indent=2, ensure_ascii=False), encoding="utf-8")
upload_file(client, manifest_path, bucket, "manifest.json", "application/json")
print("  -> Upload manifest.json mới lên R2 thành công!")
print("=" * 60)
