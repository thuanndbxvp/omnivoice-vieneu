# -*- coding: utf-8 -*-
"""Rename runtime-cuda-modern-v1.zip to runtime-cuda-legacy-v1.zip on R2 and update manifest.json."""

import json
import sys
from pathlib import Path

# UTF-8 stdout
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

from r2_tool import load_r2_config, get_s3_client, DIST_DIR, upload_file

conf = load_r2_config()
client = get_s3_client(conf)
bucket = conf["bucket"]

print("=" * 60)
print("DIEU CHINH GOI VUA UPLOAD THANH CUDA-LEGACY TREN R2")
print("=" * 60)

# 1. Server-side copy on R2: runtime-cuda-modern-v1.zip -> runtime-cuda-legacy-v1.zip
print("\n[1/3] Dang thuc hien Server-side copy tren Cloudflare R2...")
copy_source = {"Bucket": bucket, "Key": "runtimes/runtime-cuda-modern-v1.zip"}
client.copy_object(CopySource=copy_source, Bucket=bucket, Key="runtimes/runtime-cuda-legacy-v1.zip")
print("  -> Da copy thanh cong thanh: runtimes/runtime-cuda-legacy-v1.zip tren R2!")

# 2. Rename local file if exists
print("\n[2/3] Doi ten file cuc bo trong dist_r2/...")
local_modern = DIST_DIR / "runtime-cuda-modern-v1.zip"
local_legacy = DIST_DIR / "runtime-cuda-legacy-v1.zip"
if local_modern.exists() and not local_legacy.exists():
    local_modern.rename(local_legacy)
    print("  -> Da doi ten local: runtime-cuda-legacy-v1.zip")

# 3. Read manifest.json and update
print("\n[3/3] Cap nhat manifest.json voi ten va URL legacy chuan xac...")
manifest_path = DIST_DIR / "manifest.json"
data = json.loads(manifest_path.read_text("utf-8"))

legacy_sha = data["runtimes"]["cuda-legacy"]["sha256"]
legacy_size = data["runtimes"]["cuda-legacy"]["size_bytes"]

data["runtimes"]["cuda-legacy"] = {
    "name": "PyTorch CUDA 12.6 Runtime (Maxwell/Pascal/Volta/Turing/Ampere CC 5.2 - 8.6)",
    "filename": "runtime-cuda-legacy-v1.zip",
    "cuda_version": "cu126",
    "target_cc": "5.2 - 8.6",
    "url": f"{conf['public_base_url']}/runtimes/runtime-cuda-legacy-v1.zip",
    "sha256": legacy_sha,
    "size_bytes": legacy_size,
}

manifest_path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
upload_file(client, manifest_path, bucket, "manifest.json", "application/json")
print("  -> Da upload manifest.json da chinh sua thanh cong len Cloudflare R2!")

print("\n" + "=" * 60)
print("HOAN TAT DIEU CHINH!")
print(f"Legacy URL : {conf['public_base_url']}/runtimes/runtime-cuda-legacy-v1.zip")
print(f"Manifest   : {conf['public_base_url']}/manifest.json")
print("=" * 60)
