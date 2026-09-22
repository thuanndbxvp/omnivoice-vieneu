# -*- coding: utf-8 -*-
"""Repack portable runtimes from dist_offline into zip files and upload to Cloudflare R2."""

import os
import sys
import time
import zipfile
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

from r2_tool import (
    load_r2_config,
    get_s3_client,
    compute_sha256,
    upload_file,
    BASE_DIR,
    DIST_DIR,
)

LEGACY_SRC = BASE_DIR / "dist_offline" / "89TTS_Legacy_v1.0.0_Offline" / "runtime"
MODERN_SRC = BASE_DIR / "dist_offline" / "89TTS_Modern_v1.0.0_Offline" / "runtime"

def pack_folder(src_dir: Path, out_zip: Path, arc_prefix: str = "runtime") -> Path:
    print(f"\n[NÉN] {src_dir.name} -> {out_zip.name}...")
    t0 = time.time()
    
    with zipfile.ZipFile(out_zip, "w", zipfile.ZIP_DEFLATED, compresslevel=1) as zf:
        file_count = 0
        for root, _, files in os.walk(src_dir):
            if "__pycache__" in root or ".git" in root:
                continue
            for f in files:
                # Do NOT pack pyvenv.cfg if somehow present
                if f == "pyvenv.cfg":
                    continue
                fp = Path(root) / f
                rel = fp.relative_to(src_dir)
                arcname = f"{arc_prefix}/{rel.as_posix()}" if arc_prefix else rel.as_posix()
                zf.write(fp, arcname=arcname)
                file_count += 1
                if file_count % 1000 == 0:
                    sys.stdout.write(f"\r  Đã xử lý {file_count} tệp...")
                    sys.stdout.flush()

    elapsed = max(time.time() - t0, 0.001)
    size_mb = out_zip.stat().st_size / (1024 * 1024)
    print(f"\r[NÉN] Hoàn tất {out_zip.name}: {size_mb:.1f} MB ({file_count} tệp) trong {elapsed:.1f}s")
    return out_zip

def main():
    conf = load_r2_config()
    client = get_s3_client(conf)
    bucket = conf["bucket"]
    
    print("=" * 65)
    print("ĐÓNG GÓI VÀ TẢI RUNTIME PORTABLE THẬT LÊN CLOUDFLARE R2")
    print("=" * 65)
    
    # 1. Pack Legacy Runtime (CUDA 12.6)
    legacy_zip = DIST_DIR / "runtime-cuda-legacy-v1.zip"
    pack_folder(LEGACY_SRC, legacy_zip)
    legacy_sha = compute_sha256(legacy_zip)
    legacy_size = legacy_zip.stat().st_size
    print(f"Legacy SHA256: {legacy_sha}")
    upload_file(client, legacy_zip, bucket, "runtimes/runtime-cuda-legacy-v1.zip", "application/zip")
    
    # 2. Pack Modern Runtime (CUDA 12.8)
    modern_zip = DIST_DIR / "runtime-cuda-modern-v1.zip"
    pack_folder(MODERN_SRC, modern_zip)
    modern_sha = compute_sha256(modern_zip)
    modern_size = modern_zip.stat().st_size
    print(f"Modern SHA256: {modern_sha}")
    upload_file(client, modern_zip, bucket, "runtimes/runtime-cuda-modern-v1.zip", "application/zip")
    
    # 3. Update manifest.json
    import json
    manifest_path = DIST_DIR / "manifest.json"
    manifest_data = json.loads(manifest_path.read_text("utf-8"))
    
    if "cuda-legacy" in manifest_data.get("runtimes", {}):
        manifest_data["runtimes"]["cuda-legacy"]["sha256"] = legacy_sha
        manifest_data["runtimes"]["cuda-legacy"]["size_bytes"] = legacy_size
        
    if "cuda-modern" in manifest_data.get("runtimes", {}):
        manifest_data["runtimes"]["cuda-modern"]["sha256"] = modern_sha
        manifest_data["runtimes"]["cuda-modern"]["size_bytes"] = modern_size
        
    manifest_data["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime())
    manifest_path.write_text(json.dumps(manifest_data, indent=4, ensure_ascii=False), encoding="utf-8")
    upload_file(client, manifest_path, bucket, "manifest.json", "application/json")
    
    print("\n[SUCCESS] Cả 2 runtime portable đã được cập nhật thành công lên Cloudflare R2!")

if __name__ == "__main__":
    main()
