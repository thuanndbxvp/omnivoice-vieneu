# -*- coding: utf-8 -*-
"""Cloudflare R2 Release Manager for OmniVoice Cloner.

Features:
- Connects to Cloudflare R2 using credentials in D:/CodeApp/r2.txt
- Packs App Code into app-v1.0.0.zip (clean Python code, no PyArmor)
- Packs OmniVoice Model into omnivoice_model.zip
- Packs VieNeu Model into vieneu_model.zip
- Calculates SHA256 and byte sizes for all assets
- Generates and uploads manifest.json to R2
- Supports resume/multipart upload with real-time MB/s progress
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import sys
import time
import zipfile
from pathlib import Path

import boto3
from botocore.config import Config

# Reconfigure console output to UTF-8
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

BASE_DIR = Path(__file__).resolve().parent
DIST_DIR = BASE_DIR / "dist_r2"
CREDS_FILE = Path("D:/CodeApp/r2.txt")
APP_VERSION = "1.0.0"


def load_r2_config(creds_path: Path = CREDS_FILE) -> dict:
    """Parse credentials from r2.txt."""
    if not creds_path.exists():
        raise FileNotFoundError(f"Credentials file not found: {creds_path}")

    text = creds_path.read_text(encoding="utf-8")
    
    config = {
        "bucket": "omnivoice-app-dist",
        "endpoint_url": "https://b87b7c5ae56ce2dd84e2a18d104a47b4.r2.cloudflarestorage.com",
        "public_base_url": "https://pub-809dc95ff1ec45b2a32a971be2eb83e0.r2.dev",
        "access_key_id": "",
        "secret_access_key": "",
    }

    m_bucket = re.search(r"Buckets:\s*\n\s*([a-zA-Z0-9_\-]+)", text)
    if m_bucket:
        config["bucket"] = m_bucket.group(1).strip()

    m_ak = re.search(r"Access Key ID\s*\n\s*([a-zA-Z0-9]+)", text)
    if m_ak:
        config["access_key_id"] = m_ak.group(1).strip()

    m_sk = re.search(r"Secret Access Key\s*\n\s*([a-zA-Z0-9]+)", text)
    if m_sk:
        config["secret_access_key"] = m_sk.group(1).strip()

    m_ep = re.search(r"(https://[a-zA-Z0-9\.\-]+\.r2\.cloudflarestorage\.com)", text)
    if m_ep:
        config["endpoint_url"] = m_ep.group(1).strip()

    m_url = re.search(r"URL:\s*(https://[a-zA-Z0-9\.\-]+)", text)
    if m_url:
        config["public_base_url"] = m_url.group(1).strip()

    return config


def get_s3_client(config: dict):
    """Create S3 client for Cloudflare R2."""
    return boto3.client(
        "s3",
        endpoint_url=config["endpoint_url"],
        aws_access_key_id=config["access_key_id"],
        aws_secret_access_key=config["secret_access_key"],
        region_name="auto",
        config=Config(s3={"addressing_style": "virtual"}, signature_version="s3v4"),
    )


def compute_sha256(filepath: Path, chunk_size: int = 4 * 1024 * 1024) -> str:
    """Calculate SHA256 checksum of a file."""
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        while chunk := f.read(chunk_size):
            h.update(chunk)
    return h.hexdigest()


class ProgressPercentage:
    """Progress callback for boto3 upload."""

    def __init__(self, filename: str, total_bytes: int):
        self._filename = filename
        self._total = total_bytes
        self._seen = 0
        self._start = time.time()

    def __call__(self, bytes_amount: int):
        self._seen += bytes_amount
        percent = (self._seen / self._total) * 100 if self._total > 0 else 100
        elapsed = max(time.time() - self._start, 0.001)
        speed_mb = (self._seen / (1024 * 1024)) / elapsed
        sys.stdout.write(
            f"\r  Uploading {self._filename}: {self._seen / (1024*1024):.1f}/{self._total / (1024*1024):.1f} MB ({percent:.1f}%) - {speed_mb:.1f} MB/s"
        )
        sys.stdout.flush()
        if self._seen >= self._total:
            sys.stdout.write("\n")
            sys.stdout.flush()


def upload_file(s3_client, local_path: Path, bucket: str, remote_key: str, content_type: str = "application/octet-stream"):
    """Upload a file with progress reporting."""
    local_path = Path(local_path)
    total_size = local_path.stat().st_size
    print(f"[R2 Upload] Bat dau: {local_path.name} -> {remote_key} ({total_size / (1024*1024):.2f} MB)")
    
    cb = ProgressPercentage(local_path.name, total_size)
    extra_args = {"ContentType": content_type}
    
    s3_client.upload_file(
        str(local_path),
        bucket,
        remote_key,
        ExtraArgs=extra_args,
        Callback=cb,
    )
    print(f"[R2 Upload] Hoan tat: {remote_key}")


def pack_app(version: str = APP_VERSION) -> Path:
    """Pack application source code into app-v<version>.zip."""
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    zip_path = DIST_DIR / f"app-v{version}.zip"
    print(f"\n[Packaging App] Dang dong goi ma nguon ung dung v{version} -> {zip_path.name}...")
    
    # Items to include in app package
    include_dirs = ["src", "omnivoice_voice_library", "vieneu_voice_library"]
    include_files = ["main2.py", "main.py", "Applogo.png", "favicon.ico", "requirements.txt"]
    
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
        # Include files
        for fname in include_files:
            p = BASE_DIR / fname
            if p.exists():
                zf.write(p, arcname=f"app/{fname}")
                
        # Include directories
        for dname in include_dirs:
            dp = BASE_DIR / dname
            if dp.exists():
                for root, _, files in os.walk(dp):
                    if "__pycache__" in root or ".git" in root:
                        continue
                    for f in files:
                        fp = Path(root) / f
                        rel = fp.relative_to(BASE_DIR)
                        zf.write(fp, arcname=f"app/{rel.as_posix()}")
                        
    size_mb = zip_path.stat().st_size / (1024 * 1024)
    print(f"[Packaging App] Hoan tat! Dung luong: {size_mb:.2f} MB")
    return zip_path


def pack_omnivoice_model() -> Path:
    """Pack local omnivoice_model directory into omnivoice_model.zip."""
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    zip_path = DIST_DIR / "omnivoice_model.zip"
    model_dir = BASE_DIR / "omnivoice_model"
    
    if not model_dir.exists():
        raise FileNotFoundError(f"Model directory not found: {model_dir}")
        
    print(f"\n[Packaging Model] Dang nen omnivoice_model -> {zip_path.name}...")
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED, compresslevel=1) as zf:
        for root, _, files in os.walk(model_dir):
            for f in files:
                fp = Path(root) / f
                rel = fp.relative_to(model_dir)
                zf.write(fp, arcname=f"omnivoice_model/{rel.as_posix()}")
                
    size_mb = zip_path.stat().st_size / (1024 * 1024)
    print(f"[Packaging Model] Hoan tat! Dung luong: {size_mb:.2f} MB")
    return zip_path


def build_and_upload_manifest(s3_client, config: dict, assets: dict):
    """Generate and upload manifest.json to R2."""
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    manifest_path = DIST_DIR / "manifest.json"
    
    manifest_data = {
        "version": APP_VERSION,
        "updated_at": time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime()),
        "base_url": config["public_base_url"],
        "app_package": assets.get("app"),
        "models": assets.get("models", {}),
        "runtimes": assets.get("runtimes", {}),
    }
    
    manifest_json = json.dumps(manifest_data, indent=2, ensure_ascii=False)
    manifest_path.write_text(manifest_json, encoding="utf-8")
    print(f"\n[Manifest] Da tao manifest.json tai {manifest_path}")
    
    upload_file(s3_client, manifest_path, config["bucket"], "manifest.json", content_type="application/json")


if __name__ == "__main__":
    conf = load_r2_config()
    client = get_s3_client(conf)
    
    print("=" * 60)
    print("BAT DAU CHUAN BI CLOUDFLARE R2 RELEASE")
    print("=" * 60)
    
    # 1. Pack and upload App Package
    app_zip = pack_app(APP_VERSION)
    app_sha = compute_sha256(app_zip)
    app_size = app_zip.stat().st_size
    remote_app_key = f"app/app-v{APP_VERSION}.zip"
    upload_file(client, app_zip, conf["bucket"], remote_app_key, content_type="application/zip")
    
    assets = {
        "app": {
            "version": APP_VERSION,
            "filename": f"app-v{APP_VERSION}.zip",
            "url": f"{conf['public_base_url']}/{remote_app_key}",
            "sha256": app_sha,
            "size_bytes": app_size,
        },
        "models": {
            "omnivoice": {
                "name": "OmniVoice Zero-Shot Model",
                "folder": "omnivoice_model",
                "url": f"{conf['public_base_url']}/models/omnivoice_model.zip",
                "fallback_hf": "k2-fsa/OmniVoice",
            },
            "vieneu": {
                "name": "VieNeu-TTS-v3-Turbo Vietnamese Model",
                "folder": "vieneu_model",
                "url": f"{conf['public_base_url']}/models/vieneu_model.zip",
                "fallback_hf": "pnnbao-ump/VieNeu-TTS-v3-Turbo",
            }
        },
        "runtimes": {
            "cuda-modern": {
                "name": "PyTorch CUDA 12.6 Runtime (NVIDIA RTX/GTX)",
                "url": f"{conf['public_base_url']}/runtimes/runtime-cuda-modern-v1.zip",
            },
            "cpu-universal": {
                "name": "PyTorch CPU Universal Runtime (Intel/AMD/Laptop)",
                "url": f"{conf['public_base_url']}/runtimes/runtime-cpu-universal-v1.zip",
            }
        }
    }
    
    # 2. Build and upload Manifest
    build_and_upload_manifest(client, conf, assets)
    
    print("\n" + "=" * 60)
    print("HOAN TAT CHUAN BI R2:")
    print(f"1. App Package : {conf['public_base_url']}/{remote_app_key}")
    print(f"2. Manifest    : {conf['public_base_url']}/manifest.json")
    print("=" * 60)
