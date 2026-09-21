# -*- coding: utf-8 -*-
"""Builder and Uploader for PyTorch CUDA 12.8 Runtime (cuda-modern) for RTX 50xx (Blackwell) & CC >= 7.5.

Workflow:
1. Create isolated venv_cu128/
2. Install PyTorch cu128 (torch, torchaudio from https://download.pytorch.org/whl/cu128)
3. Install core application dependencies (PySide6, vieneu, omnivoice, transformers, etc.)
4. Execute Smoke Test with main2.py --smoke-test
5. Package venv_cu128 into dist_r2/runtime-cuda-modern-v1.zip
6. Calculate SHA-256 and size
7. Upload to Cloudflare R2: runtimes/runtime-cuda-modern-v1.zip
8. Update manifest.json on Cloudflare R2
"""

import json
import os
import subprocess
import sys
import time
import zipfile
from pathlib import Path

# UTF-8 stdout configuration
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

VENV_DIR = BASE_DIR / "venv_cu128"
ZIP_PATH = DIST_DIR / "runtime-cuda-modern-v1.zip"
PYTHON_EXE = VENV_DIR / "Scripts" / "python.exe"
PIP_EXE = VENV_DIR / "Scripts" / "pip.exe"


def run_cmd(cmd, desc=""):
    print(f"\n--- {desc} ---")
    print(f"Executing: {' '.join(cmd) if isinstance(cmd, list) else cmd}")
    res = subprocess.run(cmd, check=True)
    return res


def build_cu128_runtime():
    print("=" * 70)
    print("XÂY DỰNG & ĐÓNG GÓI RUNTIME CUDA 12.8 CHO RTX 50XX (BLACKWELL) & CC >= 7.5")
    print("=" * 70)

    # 1. Create venv if not exists
    if not PYTHON_EXE.exists():
        print(f"\n[1/7] Tạo môi trường venv_cu128 tại: {VENV_DIR}...")
        subprocess.run([sys.executable, "-m", "venv", str(VENV_DIR)], check=True)
    else:
        print(f"\n[1/7] Môi trường venv_cu128 đã tồn tại: {VENV_DIR}")

    # Upgrade pip
    run_cmd([str(PYTHON_EXE), "-m", "pip", "install", "--upgrade", "pip", "setuptools", "wheel"], "Upgrade pip & wheel")

    # 2. Install PyTorch cu128
    print("\n[2/7] Cài đặt PyTorch CUDA 12.8 từ kho chính thức pytorch.org...")
    run_cmd([
        str(PYTHON_EXE), "-m", "pip", "install",
        "torch==2.11.0+cu128", "torchaudio==2.11.0+cu128",
        "--index-url", "https://download.pytorch.org/whl/cu128"
    ], "Cài đặt torch & torchaudio cu128")

    # 3. Install core dependencies
    print("\n[3/7] Cài đặt các thư viện ứng dụng (PySide6, transformers, vieneu, omnivoice...)...")
    deps = [
        "PySide6>=6.7",
        "transformers>=5.3.0",
        "omnivoice",
        "vieneu",
        "sea-g2p",
        "onnxruntime",
        "soxr",
        "kaldi-native-fbank",
        "pydub",
        "soundfile",
        "numpy",
        "scipy",
        "imageio-ffmpeg",
        "cryptography",
        "pynacl",
        "huggingface_hub",
        "safetensors",
        "accelerate",
    ]
    run_cmd([str(PYTHON_EXE), "-m", "pip", "install"] + deps, "Cài đặt dependencies")

    # 4. Smoke Test
    print("\n[4/7] Kiểm tra Smoke Test với main2.py...")
    test_code = (
        "import torch, torchaudio; "
        "print('PyTorch Version:', torch.__version__); "
        "print('CUDA Available :', torch.cuda.is_available()); "
        "print('Arch List      :', getattr(torch.cuda, 'get_arch_list', lambda: [])())"
    )
    run_cmd([str(PYTHON_EXE), "-c", test_code], "Kiểm tra CUDA & Arch List trong venv_cu128")
    run_cmd([str(PYTHON_EXE), str(BASE_DIR / "main2.py"), "--smoke-test"], "Chạy app smoke-test")
    print("  -> Smoke Test PASS hoàn hảo!")

    # 5. Pack venv_cu128 into zip
    print(f"\n[5/7] Đang nén venv_cu128 -> {ZIP_PATH.name}...")
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    t0 = time.time()
    
    file_count = 0
    with zipfile.ZipFile(ZIP_PATH, "w", zipfile.ZIP_DEFLATED, compresslevel=1) as zf:
        for root, _, files in os.walk(VENV_DIR):
            if any(skip in root for skip in ["__pycache__", ".git"]):
                continue
            for f in files:
                # Exclude unnecessary massive designer/webengine dlls to optimize size
                if any(skip in f for skip in ["Qt6WebEngineCore.dll", "Qt6Designer.dll"]):
                    continue
                fp = Path(root) / f
                rel = fp.relative_to(VENV_DIR)
                zf.write(fp, arcname=f"runtime/{rel.as_posix()}")
                file_count += 1
                if file_count % 1000 == 0:
                    sys.stdout.write(f"\r  Đã xử lý {file_count} tệp...")
                    sys.stdout.flush()

    elapsed = max(time.time() - t0, 0.001)
    size_mb = ZIP_PATH.stat().st_size / (1024 * 1024)
    print(f"\n[Pack] Hoàn tất nén! Dung lượng: {size_mb:.2f} MB ({file_count} tệp) trong {elapsed:.1f}s")

    # 6. Calculate SHA-256
    print(f"\n[6/7] Tính toán SHA-256 cho {ZIP_PATH.name}...")
    sha256_val = compute_sha256(ZIP_PATH)
    size_bytes = ZIP_PATH.stat().st_size
    print(f"  -> SHA256: {sha256_val}")
    print(f"  -> Kích thước: {size_bytes} bytes ({size_mb:.2f} MB)")

    # 7. Upload to R2 and update manifest
    print("\n[7/7] Tải tệp lên Cloudflare R2 & cập nhật manifest.json...")
    conf = load_r2_config()
    client = get_s3_client(conf)
    bucket = conf["bucket"]

    upload_file(client, ZIP_PATH, bucket, "runtimes/runtime-cuda-modern-v1.zip", "application/zip")
    print("  -> Upload runtime-cuda-modern-v1.zip thành công!")

    manifest_path = DIST_DIR / "manifest.json"
    manifest_data = json.loads(manifest_path.read_text("utf-8"))
    manifest_data["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime())

    manifest_data["runtimes"]["cuda-modern"] = {
        "name": "PyTorch CUDA 12.8 Runtime (Turing -> Blackwell CC >= 7.5)",
        "filename": "runtime-cuda-modern-v1.zip",
        "cuda_version": "cu128",
        "target_cc": ">= 7.5",
        "url": f"{conf['public_base_url']}/runtimes/runtime-cuda-modern-v1.zip",
        "sha256": sha256_val,
        "size_bytes": size_bytes,
        "status": "ready_production",
    }

    manifest_path.write_text(json.dumps(manifest_data, indent=2, ensure_ascii=False), encoding="utf-8")
    upload_file(client, manifest_path, bucket, "manifest.json", "application/json")
    print("  -> Đã upload manifest.json mới lên R2 thành công!")

    print("\n" + "=" * 70)
    print("HOÀN THÀNH XÂY DỰNG & TRIỂN KHAI GÓI CUDA-MODERN (CU128)!")
    print(f"URL       : {conf['public_base_url']}/runtimes/runtime-cuda-modern-v1.zip")
    print(f"SHA-256   : {sha256_val}")
    print(f"Dung lượng: {size_mb:.2f} MB")
    print(f"Manifest  : {conf['public_base_url']}/manifest.json")
    print("=" * 70)


if __name__ == "__main__":
    build_cu128_runtime()
