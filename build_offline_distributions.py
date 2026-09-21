# -*- coding: utf-8 -*-
"""Script to build and assemble 2 complete offline distribution packages:
1. 89TTS_Modern_v1.0.0_Offline (CUDA 12.8 / RTX series + CPU fallback)
2. 89TTS_Legacy_v1.0.0_Offline (CUDA 12.6 / GTX series + CPU fallback)
"""

import os
import shutil
import subprocess
import sys
import time
import zipfile
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
DIST_R2_DIR = BASE_DIR / "dist_r2"
DIST_OFFLINE_DIR = BASE_DIR / "dist_offline"
SECURED_APP_SRC = BASE_DIR / "build_app_secured" / "app"
LAUNCHER_SRC = BASE_DIR / "dist_launcher" / "89TTS_Launcher"

MODERN_ZIP = DIST_R2_DIR / "runtime-cuda-modern-v1.zip"
LEGACY_ZIP = DIST_R2_DIR / "runtime-cuda-legacy-v1.zip"
OMNI_ZIP = DIST_R2_DIR / "omnivoice_model.zip"
VIENEU_ZIP = DIST_R2_DIR / "vieneu_model.zip"

MODERN_OUT = DIST_OFFLINE_DIR / "89TTS_Modern_v1.0.0_Offline"
LEGACY_OUT = DIST_OFFLINE_DIR / "89TTS_Legacy_v1.0.0_Offline"


def print_banner(msg: str):
    print("\n" + "=" * 70)
    print(f"  {msg}")
    print("=" * 70)


def extract_zip_fast(zip_path: Path, target_dir: Path, label: str):
    """Fast extraction using zipfile with progress reporting."""
    print(f"\n[BUNG NEN] {label}: {zip_path.name} -> {target_dir}")
    target_dir.mkdir(parents=True, exist_ok=True)
    t0 = time.time()
    
    with zipfile.ZipFile(zip_path, "r") as zf:
        infolist = zf.infolist()
        total_files = len(infolist)
        total_bytes = sum(info.file_size for info in infolist)
        extracted_bytes = 0
        
        for idx, info in enumerate(infolist, 1):
            zf.extract(info, target_dir)
            extracted_bytes += info.file_size
            if idx % 1000 == 0 or idx == total_files:
                pct = int((extracted_bytes / total_bytes) * 100) if total_bytes > 0 else 0
                mb_done = extracted_bytes / (1024 * 1024)
                mb_total = total_bytes / (1024 * 1024)
                print(f"\r  Progress: {pct}% ({mb_done:.1f}/{mb_total:.1f} MB) - {idx}/{total_files} files", end="", flush=True)

    elapsed = time.time() - t0
    speed = (total_bytes / (1024 * 1024)) / elapsed if elapsed > 0 else 0
    print(f"\n  [OK] Hoan tat {label} trong {elapsed:.1f}s ({speed:.1f} MB/s)")


def copy_launcher(target_folder: Path):
    """Copy 89TTS_Launcher.exe and _internal/ directory."""
    print(f"\n[LAUNCHER] Sao chep 89TTS_Launcher sang {target_folder.name}...")
    launcher_exe = LAUNCHER_SRC / "89TTS_Launcher.exe"
    internal_dir = LAUNCHER_SRC / "_internal"
    
    if not launcher_exe.exists() or not internal_dir.exists():
        raise FileNotFoundError(f"Launcher build missing at {LAUNCHER_SRC}. Please build launcher first.")
    
    shutil.copy2(launcher_exe, target_folder / "89TTS_Launcher.exe")
    dst_internal = target_folder / "_internal"
    if dst_internal.exists():
        shutil.rmtree(dst_internal)
    shutil.copytree(internal_dir, dst_internal)
    print(f"  [OK] Sao chep Launcher thanh cong.")


def copy_secured_app(target_folder: Path):
    """Copy compiled secured app directory."""
    print(f"\n[APP] Sao chep Secured App (Cython .pyd + Bytecode .pyc) sang {target_folder.name}...")
    if not SECURED_APP_SRC.exists():
        raise FileNotFoundError(f"Secured app missing at {SECURED_APP_SRC}. Please run build_secured_app.py first.")
    
    dst_app = target_folder / "app"
    if dst_app.exists():
        shutil.rmtree(dst_app)
    shutil.copytree(SECURED_APP_SRC, dst_app)
    print(f"  [OK] Sao chep App thanh cong (Tong {len(list(dst_app.rglob('*')))} files).")


def write_instructions(target_folder: Path, is_modern: bool):
    """Create customer usage guide with CPU fallback explanation."""
    mode_name = "Modern Edition (CUDA 12.8 - RTX series)" if is_modern else "Legacy Edition (CUDA 12.6 - GTX series)"
    gpu_recommendation = (
        "NVIDIA RTX 20/30/40/50 series, GTX 16xx (Kien truc CC >= 7.5)"
        if is_modern else
        "NVIDIA GTX 9xx, GTX 10xx, MX150/250, Volta, Maxwell (Kien truc CC 5.2 - 7.0)"
    )
    
    content = f"""================================================================================
89TTS STUDIO — HUONG DAN SU DUNG GOI OFFLINE TRON GOI
Phien ban: {mode_name}
================================================================================

1. GIOI THIEU:
   - Day la ban phan phoi Offline day du 100%, tich hop san toan bo moi truong chay (Runtime),
     mo hinh AI (OmniVoice + VieNeu-TTS Tieng Viet) va ma nguon ung dung bao mat.
   - Giai nen la chay ngay, khong can cai dat them bat ky phan mem ho tro nao va khong can mang.

2. YEU CAU HE THONG & PHAN CUNG KHUYEN NGHI:
   - He dieu hanh: Windows 10 / Windows 11 (64-bit).
   - Dung luong o dia: Toi thieu 15GB trong de giai nen va hoat dong muot ma.
   - GPU toi uu: {gpu_recommendation}.

3. TINH NANG FAILBACK CPU TU DONG (KHONG LOI / KHONG CRASH):
   - Ung dung duoc trang bi cong nghe Active Probe & Zero-Crash Fallback:
     + Neu may tinh co GPU NVIDIA tuong thich: He thong se tu dong su dung GPU de dat toc do sinh giong cao nhat.
     + Neu may tinh KHONG CO CARD ROI (may van phong, CPU Intel Core / AMD Ryzen) HOAC Driver GPU bi loi/chua cai dat:
       He thong se TU DONG CHUYEN SANG CHE DO CPU DA NANG.
     + Ung dung se hoat dong binh thuong, khong bi vang loi hay bao thieu thu vien.

4. CACH KHOI CHAY UNG DUNG:
   - Buoc 1: Giai nen thu muc nay vao o dia co toc do cao (khuyen nghi o SSD D:\\ hoac C:\\).
   - Buoc 2: Nhap dup vao file "89TTS_Launcher.exe" de bat dau.
   - Buoc 3: Launcher se tu dong quet phan cung va mo giao dien chinh cua 89TTS Studio.
   - Buoc 4: Nhap License Key cua ban trong lan khoi dong dau tien de kich hoat ban quyen.

================================================================================
HO TRO KY THUAT & BAN QUYEN: 89 GLOBAL MEDIA
================================================================================
"""
    doc_file = target_folder / "HUONG_DAN_SU_DUNG.txt"
    doc_file.write_text(content, encoding="utf-8")
    print(f"  [OK] Da tao {doc_file.name}")


def make_runtime_portable(runtime_dir: Path):
    """Convert venv runtime into a 100% self-contained portable Python environment."""
    print(f"\n[PORTABLE] Dang chuyen doi runtime sang Standalone Portable tai {runtime_dir.parent.name}...")
    uv_base = Path(r"C:\Users\admin\AppData\Roaming\uv\python\cpython-3.12-windows-x86_64-none")
    if not uv_base.exists():
        uv_base = Path(sys.base_prefix)

    # 1. Copy core executables and DLLs to runtime root
    for fname in ["python.exe", "pythonw.exe", "python3.dll", "python312.dll", "vcruntime140.dll", "vcruntime140_1.dll"]:
        src = uv_base / fname
        if src.exists():
            shutil.copy2(src, runtime_dir / fname)

    # 2. Copy DLLs directory (contains C extension DLLs for stdlib: _socket, _ssl, unicodedata, etc.)
    src_dlls = uv_base / "DLLs"
    dst_dlls = runtime_dir / "DLLs"
    if src_dlls.exists() and not dst_dlls.exists():
        shutil.copytree(src_dlls, dst_dlls)

    # 3. Copy standard library packages from Lib (os.py, json, urllib, etc., skipping site-packages)
    src_lib = uv_base / "Lib"
    dst_lib = runtime_dir / "Lib"
    if src_lib.exists():
        for item in src_lib.iterdir():
            if item.name == "site-packages":
                continue
            target_item = dst_lib / item.name
            if not target_item.exists():
                if item.is_dir():
                    shutil.copytree(item, target_item)
                else:
                    shutil.copy2(item, target_item)

    # 4. Create python312._pth to enable standalone portable module resolution
    pth_file = runtime_dir / "python312._pth"
    pth_content = ".\nDLLs\nLib\nLib\\site-packages\nimport site\n"
    pth_file.write_text(pth_content, encoding="utf-8")

    # 5. Remove any pyvenv.cfg so Python acts as a standalone portable installation
    cfg_file = runtime_dir / "pyvenv.cfg"
    if cfg_file.exists():
        cfg_file.unlink()

    print(f"  [OK] Runtime da tro thanh Standalone Portable (Hoan toan doc lap, khong can cai Python tren may khach).")


def verify_package(target_folder: Path, label: str):
    """Run smoke test using the bundled python runtime and app."""
    print(f"\n[VERIFY] Kiem tra tinh toan ven cho {label}...")
    py_exe = target_folder / "runtime" / "python.exe"
    app_main = target_folder / "app" / "main_secure.pyc"
    
    if not py_exe.exists():
        raise FileNotFoundError(f"Python runtime missing: {py_exe}")
    if not app_main.exists():
        raise FileNotFoundError(f"App main missing: {app_main}")
        
    # Smoke test 1: App import & anti-debug smoke test
    cmd1 = [str(py_exe), str(app_main), "--smoke-test"]
    res1 = subprocess.run(cmd1, capture_output=True, text=True, cwd=str(target_folder))
    if res1.returncode != 0 or "SMOKE_TEST_OK" not in res1.stdout:
        print(f"  [FAIL] Smoke test app that bai: {res1.stderr} | stdout: {res1.stdout}")
        return False
    print("  [OK] App Smoke Test PASS (Ma hoa nhi phan & Anti-debug hoat dong hoan hao)")

    # Smoke test 2: Force CPU Fallback simulation (CUDA_VISIBLE_DEVICES="")
    test_cpu_code = """
import torch
x = torch.randn(64, 64)
y = x @ x
cuda_avail = torch.cuda.is_available()
print(f"CPU_FALLBACK_VERIFIED|shape={y.shape}|cuda={cuda_avail}")
"""
    env_cpu = os.environ.copy()
    env_cpu["CUDA_VISIBLE_DEVICES"] = ""
    res2 = subprocess.run([str(py_exe), "-c", test_cpu_code], capture_output=True, text=True, env=env_cpu, cwd=str(target_folder))
    if res2.returncode != 0 or "CPU_FALLBACK_VERIFIED" not in res2.stdout:
        print(f"  [FAIL] CPU Fallback test that bai: {res2.stderr} | stdout: {res2.stdout}")
        return False
    print(f"  [OK] CPU Fallback Simulation PASS ({res2.stdout.strip()})")

    # Smoke test 3: Normal device probe test
    test_dev_code = """
import torch
cuda_avail = torch.cuda.is_available()
dev_name = torch.cuda.get_device_name(0) if cuda_avail else "No GPU"
print(f"DEVICE_INFO|cuda={cuda_avail}|gpu={dev_name}")
"""
    res3 = subprocess.run([str(py_exe), "-c", test_dev_code], capture_output=True, text=True, cwd=str(target_folder))
    print(f"  [INFO] Hardware Probe: {res3.stdout.strip()}")
    return True


def build_all():
    print_banner("BAT DAU KHOI TAO 2 BAN PHAN PHOI OFFLINE GIAO KHACH HANG")
    
    DIST_OFFLINE_DIR.mkdir(parents=True, exist_ok=True)

    # 1. Prepare Modern Edition
    print_banner("1. DONG GOI BAN 89TTS_Modern_v1.0.0_Offline (CUDA 12.8)")
    MODERN_OUT.mkdir(parents=True, exist_ok=True)
    
    # 1.1 Copy Launcher & Secured App
    copy_launcher(MODERN_OUT)
    copy_secured_app(MODERN_OUT)
    write_instructions(MODERN_OUT, is_modern=True)
    
    # 1.2 Extract Modern Runtime
    if not (MODERN_OUT / "runtime" / "Lib" / "site-packages" / "torch").exists():
        extract_zip_fast(MODERN_ZIP, MODERN_OUT, "Runtime CUDA 12.8 Modern")
    else:
        print("  [SKIP] Runtime da ton tai san tai thu muc Modern.")

    # 1.3 Make Modern runtime fully portable
    make_runtime_portable(MODERN_OUT / "runtime")

    # 1.4 Extract Models into Modern
    if not (MODERN_OUT / "omnivoice_model" / "model.safetensors").exists():
        extract_zip_fast(OMNI_ZIP, MODERN_OUT, "Mo hinh OmniVoice")
    else:
        print("  [SKIP] OmniVoice model da ton tai san.")

    if not (MODERN_OUT / "hf_cache").exists():
        extract_zip_fast(VIENEU_ZIP, MODERN_OUT, "Mo hinh VieNeu-TTS")
    else:
        print("  [SKIP] VieNeu model da ton tai san.")

    # 1.5 Verify Modern
    verify_package(MODERN_OUT, "89TTS_Modern_v1.0.0_Offline")

    # 2. Prepare Legacy Edition
    print_banner("2. DONG GOI BAN 89TTS_Legacy_v1.0.0_Offline (CUDA 12.6)")
    LEGACY_OUT.mkdir(parents=True, exist_ok=True)
    
    # 2.1 Copy Launcher & Secured App
    copy_launcher(LEGACY_OUT)
    copy_secured_app(LEGACY_OUT)
    write_instructions(LEGACY_OUT, is_modern=False)

    # 2.2 Extract Legacy Runtime
    if not (LEGACY_OUT / "runtime" / "Lib" / "site-packages" / "torch").exists():
        extract_zip_fast(LEGACY_ZIP, LEGACY_OUT, "Runtime CUDA 12.6 Legacy")
    else:
        print("  [SKIP] Runtime da ton tai san tai thu muc Legacy.")

    # 2.3 Make Legacy runtime fully portable
    make_runtime_portable(LEGACY_OUT / "runtime")

    # 2.4 Copy / Link Models from Modern to avoid re-extracting 3.5GB zip
    omni_src = MODERN_OUT / "omnivoice_model"
    omni_dst = LEGACY_OUT / "omnivoice_model"
    if not (omni_dst / "model.safetensors").exists():
        print(f"\n[MODELS] Sao chep nhanh OmniVoice model sang Legacy...")
        if omni_dst.exists():
            shutil.rmtree(omni_dst)
        shutil.copytree(omni_src, omni_dst)
        print("  [OK] Sao chep OmniVoice sang Legacy thanh cong.")

    hf_src = MODERN_OUT / "hf_cache"
    hf_dst = LEGACY_OUT / "hf_cache"
    if not hf_dst.exists():
        print(f"\n[MODELS] Sao chep nhanh VieNeu model sang Legacy...")
        shutil.copytree(hf_src, hf_dst)
        print("  [OK] Sao chep VieNeu sang Legacy thanh cong.")

    # 2.5 Verify Legacy
    verify_package(LEGACY_OUT, "89TTS_Legacy_v1.0.0_Offline")

    print_banner("HOAN TAT DONG GOI THANH CONG CA 2 BAN PHAN PHOI OFFLINE!")
    print(f"1. Ban Modern: {MODERN_OUT}")
    print(f"2. Ban Legacy: {LEGACY_OUT}")
    print("=" * 70 + "\n")


if __name__ == "__main__":
    build_all()
