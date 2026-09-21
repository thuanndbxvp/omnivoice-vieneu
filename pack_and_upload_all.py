# -*- coding: utf-8 -*-
"""Comprehensive Pack and Upload Script to Cloudflare R2.

Steps:
1. Pack VieNeu Model (HuggingFace cache of VieNeu + MOSS Audio Tokenizer)
2. Pack OmniVoice Model (omnivoice_model/ directory)
3. Pack Runtime venv (PyTorch CUDA 12.6 + dependencies)
4. Upload all to Cloudflare R2 with progress
5. Update manifest.json on R2 with accurate SHA256 and size
"""

from __future__ import annotations

import hashlib
import json
import os
import sys
import time
import zipfile
from pathlib import Path

# UTF-8 stdout
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

from r2_tool import (
    load_r2_config,
    get_s3_client,
    compute_sha256,
    upload_file,
    BASE_DIR,
    DIST_DIR,
    APP_VERSION,
)

DIST_DIR.mkdir(parents=True, exist_ok=True)


def pack_directory(source_dir: Path, zip_path: Path, arc_prefix: str = "", ignore_pycache: bool = True) -> Path:
    """Pack an entire directory into a zip file with fast compression."""
    print(f"\n[Nén tệp] Đang nén {source_dir.name} -> {zip_path.name}...")
    t0 = time.time()
    
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED, compresslevel=1) as zf:
        file_count = 0
        for root, _, files in os.walk(source_dir):
            if ignore_pycache and ("__pycache__" in root or ".git" in root):
                continue
            for f in files:
                fp = Path(root) / f
                rel = fp.relative_to(source_dir)
                arcname = f"{arc_prefix}/{rel.as_posix()}" if arc_prefix else rel.as_posix()
                zf.write(fp, arcname=arcname)
                file_count += 1
                if file_count % 500 == 0:
                    sys.stdout.write(f"\r  Đã xử lý {file_count} tệp...")
                    sys.stdout.flush()

    elapsed = max(time.time() - t0, 0.001)
    size_mb = zip_path.stat().st_size / (1024 * 1024)
    print(f"\r[Nén tệp] Hoàn tất {zip_path.name}: {size_mb:.1f} MB ({file_count} tệp) trong {elapsed:.1f}s")
    return zip_path


def pack_vieneu_model() -> Path:
    """Pack VieNeu and MOSS tokenizer cache into vieneu_model.zip."""
    zip_path = DIST_DIR / "vieneu_model.zip"
    hf_hub = Path.home() / ".cache" / "huggingface" / "hub"
    vieneu_dir = hf_hub / "models--pnnbao-ump--VieNeu-TTS-v3-Turbo"
    moss_dir = hf_hub / "models--OpenMOSS-Team--MOSS-Audio-Tokenizer-Nano-ONNX"

    if not vieneu_dir.exists():
        raise FileNotFoundError(f"VieNeu cache not found at {vieneu_dir}")

    print(f"\n[Nén VieNeu] Đang đóng gói VieNeu + Tokenizer -> {zip_path.name}...")
    t0 = time.time()
    
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED, compresslevel=1) as zf:
        # Pack VieNeu
        for root, _, files in os.walk(vieneu_dir):
            for f in files:
                fp = Path(root) / f
                rel = fp.relative_to(hf_hub)
                zf.write(fp, arcname=f"hf_cache/{rel.as_posix()}")
                
        # Pack MOSS Tokenizer if present
        if moss_dir.exists():
            for root, _, files in os.walk(moss_dir):
                for f in files:
                    fp = Path(root) / f
                    rel = fp.relative_to(hf_hub)
                    zf.write(fp, arcname=f"hf_cache/{rel.as_posix()}")

    elapsed = max(time.time() - t0, 0.001)
    size_mb = zip_path.stat().st_size / (1024 * 1024)
    print(f"[Nén VieNeu] Hoàn tất {zip_path.name}: {size_mb:.1f} MB trong {elapsed:.1f}s")
    return zip_path


def pack_omnivoice_model() -> Path:
    """Pack local omnivoice_model directory into omnivoice_model.zip."""
    zip_path = DIST_DIR / "omnivoice_model.zip"
    model_dir = BASE_DIR / "omnivoice_model"
    return pack_directory(model_dir, zip_path, arc_prefix="omnivoice_model")


def pack_runtime_venv() -> Path:
    """Pack local venv directory into runtime-cuda-modern-v1.zip."""
    zip_path = DIST_DIR / "runtime-cuda-modern-v1.zip"
    venv_dir = BASE_DIR / "venv"
    return pack_directory(venv_dir, zip_path, arc_prefix="runtime", ignore_pycache=True)


def run_pipeline():
    conf = load_r2_config()
    client = get_s3_client(conf)
    
    print("=" * 65)
    print("QUY TRÌNH NÉN VÀ TẢI TOÀN BỘ MODEL & RUNTIME LÊN CLOUDFLARE R2")
    print("=" * 65)
    
    # 1. Pack VieNeu Model
    vieneu_zip = pack_vieneu_model()
    vieneu_sha = compute_sha256(vieneu_zip)
    vieneu_size = vieneu_zip.stat().st_size
    upload_file(client, vieneu_zip, conf["bucket"], "models/vieneu_model.zip", "application/zip")
    
    # 2. Pack OmniVoice Model
    omni_zip = pack_omnivoice_model()
    omni_sha = compute_sha256(omni_zip)
    omni_size = omni_zip.stat().st_size
    upload_file(client, omni_zip, conf["bucket"], "models/omnivoice_model.zip", "application/zip")
    
    # 3. Pack Runtime venv
    runtime_zip = pack_runtime_venv()
    runtime_sha = compute_sha256(runtime_zip)
    runtime_size = runtime_zip.stat().st_size
    upload_file(client, runtime_zip, conf["bucket"], "runtimes/runtime-cuda-modern-v1.zip", "application/zip")
    
    # 4. Update manifest.json with all hashes and sizes
    print("\n[Cập nhật Manifest] Đang cập nhật manifest.json với đầy đủ mã SHA256...")
    app_zip = DIST_DIR / f"app-v{APP_VERSION}.zip"
    app_sha = compute_sha256(app_zip) if app_zip.exists() else ""
    app_size = app_zip.stat().st_size if app_zip.exists() else 0
    
    manifest_data = {
        "version": APP_VERSION,
        "updated_at": time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime()),
        "base_url": conf["public_base_url"],
        "app_package": {
            "version": APP_VERSION,
            "filename": f"app-v{APP_VERSION}.zip",
            "url": f"{conf['public_base_url']}/app/app-v{APP_VERSION}.zip",
            "sha256": app_sha,
            "size_bytes": app_size,
        },
        "models": {
            "omnivoice": {
                "name": "OmniVoice Zero-Shot Model",
                "folder": "omnivoice_model",
                "filename": "omnivoice_model.zip",
                "url": f"{conf['public_base_url']}/models/omnivoice_model.zip",
                "sha256": omni_sha,
                "size_bytes": omni_size,
                "fallback_hf": "k2-fsa/OmniVoice",
            },
            "vieneu": {
                "name": "VieNeu-TTS-v3-Turbo Vietnamese Model",
                "folder": "vieneu_model",
                "filename": "vieneu_model.zip",
                "url": f"{conf['public_base_url']}/models/vieneu_model.zip",
                "sha256": vieneu_sha,
                "size_bytes": vieneu_size,
                "fallback_hf": "pnnbao-ump/VieNeu-TTS-v3-Turbo",
            }
        },
        "runtimes": {
            "cuda-modern": {
                "name": "PyTorch CUDA 12.6 Runtime (NVIDIA RTX/GTX)",
                "filename": "runtime-cuda-modern-v1.zip",
                "url": f"{conf['public_base_url']}/runtimes/runtime-cuda-modern-v1.zip",
                "sha256": runtime_sha,
                "size_bytes": runtime_size,
            }
        }
    }
    
    manifest_path = DIST_DIR / "manifest.json"
    manifest_path.write_text(json.dumps(manifest_data, indent=2, ensure_ascii=False), encoding="utf-8")
    upload_file(client, manifest_path, conf["bucket"], "manifest.json", "application/json")
    
    print("\n" + "=" * 65)
    print("HOÀN TẤT TẤT CẢ TỆP LÊN CLOUDFLARE R2 THÀNH CÔNG!")
    print(f"1. Manifest URL   : {conf['public_base_url']}/manifest.json")
    print(f"2. App Code       : {conf['public_base_url']}/app/app-v{APP_VERSION}.zip ({app_size / (1024*1024):.1f} MB)")
    print(f"3. VieNeu Model   : {conf['public_base_url']}/models/vieneu_model.zip ({vieneu_size / (1024*1024):.1f} MB)")
    print(f"4. OmniVoice Model: {conf['public_base_url']}/models/omnivoice_model.zip ({omni_size / (1024*1024):.1f} MB)")
    print(f"5. Runtime CUDA   : {conf['public_base_url']}/runtimes/runtime-cuda-modern-v1.zip ({runtime_size / (1024*1024):.1f} MB)")
    print("=" * 65)


if __name__ == "__main__":
    run_pipeline()
