# -*- coding: utf-8 -*-
"""Builder and uploader for CPU-Universal Runtime (~320MB).

Steps:
1. Create lightweight venv_cpu/
2. Install PyTorch CPU + torchaudio CPU (from https://download.pytorch.org/whl/cpu)
3. Install application dependencies (PySide6, transformers, vieneu, onnxruntime, etc.)
4. Package venv_cpu into runtime-cpu-universal-v1.zip
5. Upload to Cloudflare R2
6. Update manifest.json on R2 with accurate profiles:
   - cuda-legacy (cu126)
   - cpu-universal (cpu)
   - cuda-modern (cu128)
"""

import json
import os
import shutil
import subprocess
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
    BASE_DIR,
    DIST_DIR,
    APP_VERSION,
    load_r2_config,
    get_s3_client,
    compute_sha256,
    upload_file,
)

VENV_CPU_DIR = BASE_DIR / "venv_cpu"
ZIP_CPU_PATH = DIST_DIR / "runtime-cpu-universal-v1.zip"


def create_cpu_runtime():
    print("=" * 60)
    print("BAT DAU XAY DUNG GÓI RUNTIME CPU-UNIVERSAL (SIÊU NHẸ)")
    print("=" * 60)

    # 1. Create venv_cpu if not exists
    python_exe = VENV_CPU_DIR / "Scripts" / "python.exe"
    if not python_exe.exists():
        print(f"[1/4] Dang tao venv_cpu tai {VENV_CPU_DIR}...")
        subprocess.run([sys.executable, "-m", "venv", str(VENV_CPU_DIR)], check=True)

    # Upgrade pip in venv_cpu
    subprocess.run([str(python_exe), "-m", "pip", "install", "--upgrade", "pip", "setuptools", "wheel"], check=True)

    # 2. Install PyTorch CPU
    print("\n[2/4] Dang cai dat PyTorch CPU (sieu nhe ~180MB)...")
    subprocess.run(
        [str(python_exe), "-m", "pip", "install", "torch", "torchaudio", "--index-url", "https://download.pytorch.org/whl/cpu"],
        check=True,
    )

    # 3. Install core dependencies
    print("\n[3/4] Dang cai dat cac thu vien phan mem (PySide6, vieneu, omnivoice, onnxruntime...)...")
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
    subprocess.run([str(python_exe), "-m", "pip", "install"] + deps, check=True)

    # 4. Pack venv_cpu into zip
    print(f"\n[4/4] Dang dong goi venv_cpu -> {ZIP_CPU_PATH.name}...")
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    t0 = time.time()
    
    with zipfile.ZipFile(ZIP_CPU_PATH, "w", zipfile.ZIP_DEFLATED, compresslevel=1) as zf:
        file_count = 0
        for root, _, files in os.walk(VENV_CPU_DIR):
            if "__pycache__" in root or ".git" in root:
                continue
            for f in files:
                fp = Path(root) / f
                rel = fp.relative_to(VENV_CPU_DIR)
                zf.write(fp, arcname=f"runtime/{rel.as_posix()}")
                file_count += 1
                if file_count % 500 == 0:
                    sys.stdout.write(f"\r  Đã xử lý {file_count} tệp...")
                    sys.stdout.flush()

    elapsed = max(time.time() - t0, 0.001)
    size_mb = ZIP_CPU_PATH.stat().st_size / (1024 * 1024)
    print(f"\n[Pack] Hoan tat! Dung luong: {size_mb:.1f} MB ({file_count} tệp) trong {elapsed:.1f}s")

    # 5. Upload to Cloudflare R2
    conf = load_r2_config()
    client = get_s3_client(conf)
    sha256_cpu = compute_sha256(ZIP_CPU_PATH)
    upload_file(client, ZIP_CPU_PATH, conf["bucket"], "runtimes/runtime-cpu-universal-v1.zip", "application/zip")
    print(f"\n[R2] Da upload runtime-cpu-universal-v1.zip thanh cong! SHA256: {sha256_cpu}")

    # 6. Update manifest.json with updated 3-profile mapping
    print("\n[Manifest] Dang cap nhat manifest.json tren Cloudflare R2...")
    
    # Read existing assets info
    app_zip = DIST_DIR / f"app-v{APP_VERSION}.zip"
    app_sha = compute_sha256(app_zip) if app_zip.exists() else ""
    app_size = app_zip.stat().st_size if app_zip.exists() else 0

    omni_zip = DIST_DIR / "omnivoice_model.zip"
    omni_sha = compute_sha256(omni_zip) if omni_zip.exists() else ""
    omni_size = omni_zip.stat().st_size if omni_zip.exists() else 0

    vieneu_zip = DIST_DIR / "vieneu_model.zip"
    vieneu_sha = compute_sha256(vieneu_zip) if vieneu_zip.exists() else ""
    vieneu_size = vieneu_zip.stat().st_size if vieneu_zip.exists() else 0

    cuda_legacy_zip = DIST_DIR / "runtime-cuda-modern-v1.zip"  # This is cu126
    cuda_legacy_sha = compute_sha256(cuda_legacy_zip) if cuda_legacy_zip.exists() else ""
    cuda_legacy_size = cuda_legacy_zip.stat().st_size if cuda_legacy_zip.exists() else 0

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
            "cuda-legacy": {
                "name": "PyTorch CUDA 12.6 Runtime (Maxwell/Pascal/Volta/Turing/Ampere CC 5.2 - 8.6)",
                "filename": "runtime-cuda-modern-v1.zip",
                "cuda_version": "cu126",
                "target_cc": "5.2 - 8.6",
                "url": f"{conf['public_base_url']}/runtimes/runtime-cuda-modern-v1.zip",
                "sha256": cuda_legacy_sha,
                "size_bytes": cuda_legacy_size,
            },
            "cpu-universal": {
                "name": "PyTorch CPU Universal Runtime (Intel/AMD/Office PC)",
                "filename": "runtime-cpu-universal-v1.zip",
                "cuda_version": "CPU",
                "target_cc": "None",
                "url": f"{conf['public_base_url']}/runtimes/runtime-cpu-universal-v1.zip",
                "sha256": sha256_cpu,
                "size_bytes": ZIP_CPU_PATH.stat().st_size,
            },
            "cuda-modern": {
                "name": "PyTorch CUDA 12.8 Runtime (Turing -> Blackwell CC >= 7.5)",
                "filename": "runtime-cuda-modern-v1.zip",
                "cuda_version": "cu128",
                "target_cc": ">= 7.5",
                "url": f"{conf['public_base_url']}/runtimes/runtime-cuda-modern-v1.zip",
                "sha256": cuda_legacy_sha,
                "size_bytes": cuda_legacy_size,
            }
        }
    }

    manifest_path = DIST_DIR / "manifest.json"
    manifest_path.write_text(json.dumps(manifest_data, indent=2, ensure_ascii=False), encoding="utf-8")
    upload_file(client, manifest_path, conf["bucket"], "manifest.json", "application/json")
    print(f"\n[Manifest] Da cap nhat manifest.json thanh cong len R2!")


if __name__ == "__main__":
    create_cpu_runtime()
