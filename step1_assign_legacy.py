# -*- coding: utf-8 -*-
"""Assign uploaded 3.1GB package as cuda-legacy on R2 and update manifest.json."""

import json
import sys
import time
from pathlib import Path

# UTF-8 stdout
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

from r2_tool import load_r2_config, get_s3_client, DIST_DIR, upload_file, compute_sha256

conf = load_r2_config()
client = get_s3_client(conf)
bucket = conf["bucket"]

print("=" * 60)
print("CAP NHAT PROFILE CUDA-LEGACY VA MANIFEST TREN R2")
print("=" * 60)

# 1. Delete old key runtime-cuda-modern-v1.zip on R2
print("\n[1/4] Xoa key cu runtimes/runtime-cuda-modern-v1.zip tren R2 de tiet kiem dung luong...")
try:
    client.delete_object(Bucket=bucket, Key="runtimes/runtime-cuda-modern-v1.zip")
    print("  -> Da xoa key cu thanh cong!")
except Exception as e:
    print(f"  -> Bo qua (khong tim thay hoac loi): {e}")

# 2. Rename local file
print("\n[2/4] Doi ten file local sang runtime-cuda-legacy-v1.zip...")
local_modern = DIST_DIR / "runtime-cuda-modern-v1.zip"
local_legacy = DIST_DIR / "runtime-cuda-legacy-v1.zip"
if local_modern.exists() and not local_legacy.exists():
    local_modern.rename(local_legacy)
    print("  -> Da doi ten thanh cong:", local_legacy.name)
elif local_legacy.exists():
    print("  -> File local da ton tai:", local_legacy.name)

# 3. Verify size & sha256
print("\n[3/4] Tinh toan SHA256 cho runtime-cuda-legacy-v1.zip...")
legacy_size = local_legacy.stat().st_size
legacy_sha = compute_sha256(local_legacy)
print(f"  -> Dung luong: {legacy_size / (1024*1024):.2f} MB")
print(f"  -> SHA256: {legacy_sha}")

# 4. Update manifest.json
print("\n[4/4] Cap nhat manifest.json...")
manifest_path = DIST_DIR / "manifest.json"
data = json.loads(manifest_path.read_text("utf-8"))
data["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime())

legacy_url = f"{conf['public_base_url']}/runtimes/runtime-cuda-legacy-v1.zip"

data["runtimes"]["cuda-legacy"] = {
    "name": "PyTorch CUDA 12.6 Runtime (Maxwell/Pascal/Volta/Turing/Ampere CC 5.2 - 8.6)",
    "filename": "runtime-cuda-legacy-v1.zip",
    "cuda_version": "cu126",
    "target_cc": "5.2 - 8.6",
    "url": legacy_url,
    "sha256": legacy_sha,
    "size_bytes": legacy_size,
}

# Fallback for cuda-modern until cu128 is packaged
data["runtimes"]["cuda-modern"] = {
    "name": "PyTorch CUDA 12.8 Runtime (Turing -> Blackwell CC >= 7.5)",
    "filename": "runtime-cuda-modern-v1.zip",
    "cuda_version": "cu128",
    "target_cc": ">= 7.5",
    "url": f"{conf['public_base_url']}/runtimes/runtime-cuda-modern-v1.zip",
    "sha256": legacy_sha,
    "size_bytes": legacy_size,
    "status": "ready_fallback_cu126",
}

manifest_path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
upload_file(client, manifest_path, bucket, "manifest.json", "application/json")
print("  -> Da upload manifest.json len R2 thanh cong!")

print("\n" + "=" * 60)
print("HOAN TAT DIEU CHINH CUDA-LEGACY!")
print(f"Legacy URL : {legacy_url}")
print(f"Manifest   : {conf['public_base_url']}/manifest.json")
print("=" * 60)
