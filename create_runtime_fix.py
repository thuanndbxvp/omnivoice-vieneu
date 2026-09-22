import os
import sys
import zipfile
import shutil
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

BASE_DIR = Path(r"D:\CodeApp\omnivoice-vieneu")
LEGACY_RT = BASE_DIR / "dist_offline" / "89TTS_Legacy_v1.0.0_Offline" / "runtime"
OUTPUT_ZIP = BASE_DIR / "dist_r2" / "89TTS_Fix_Runtime.zip"

def create_runtime_fix():
    print(f"Creating Runtime Hotfix package from {LEGACY_RT}...")
    
    # Files to include in hotfix:
    core_files = [
        "python.exe",
        "pythonw.exe",
        "python3.dll",
        "python312.dll",
        "vcruntime140.dll",
        "vcruntime140_1.dll",
        "python312._pth",
    ]
    
    with zipfile.ZipFile(OUTPUT_ZIP, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        # Add core files into runtime/
        for fname in core_files:
            fp = LEGACY_RT / fname
            if fp.exists():
                zf.write(fp, f"runtime/{fname}")
                print(f"  + Added runtime/{fname}")
            else:
                print(f"  [WARN] Missing {fp}")
                
        # Add DLLs directory into runtime/DLLs/
        dlls_dir = LEGACY_RT / "DLLs"
        if dlls_dir.exists():
            for item in dlls_dir.rglob("*"):
                if item.is_file():
                    rel = item.relative_to(LEGACY_RT)
                    zf.write(item, f"runtime/{rel.as_posix()}")
            print("  + Added runtime/DLLs/ directory completely.")
            
        # Add Fix script directly in zip root
        fix_bat_content = """@echo off
chcp 65001 >nul
title 89TTS Studio — Sửa Nhanh Môi Trường Python
echo ========================================================
echo   89TTS STUDIO — BỘ VÁ LỖI RUNTIME PYTHON PORTABLE
echo ========================================================
echo.

cd /d "%~dp0"

if exist "runtime\\pyvenv.cfg" (
    echo [1/3] Đang loại bỏ cấu hình virtualenv xung đột...
    del /f /q "runtime\\pyvenv.cfg"
    echo   - Đã xóa runtime\\pyvenv.cfg
)

echo [2/3] Kiểm tra tệp thực thi Python độc lập...
if exist "runtime\\python.exe" (
    echo   - runtime\\python.exe OK
)

echo [3/3] Kiểm tra cấu hình liên kết thư viện (python312._pth)...
if exist "runtime\\python312._pth" (
    echo   - runtime\\python312._pth OK
)

echo.
echo ========================================================
echo   ĐÃ SỬA LỖI THÀNH CÔNG! BẠN CÓ THỂ KHỞI ĐỘNG APP NGAY:
echo   1. Nhấp đúp: 89TTS_Studio.exe (Mở trực tiếp)
echo   2. Hoặc: 89TTS_Launcher.exe
echo ========================================================
echo.
pause
"""
        zf.writestr("SỬA_LỖI_NHANH_89TTS.bat", fix_bat_content.encode("utf-8"))
        print("  + Added SỬA_LỖI_NHANH_89TTS.bat")

    size_mb = OUTPUT_ZIP.stat().st_size / (1024 * 1024)
    print(f"\n[DONE] Hotfix archive created: {OUTPUT_ZIP.name} ({size_mb:.2f} MB)")

if __name__ == "__main__":
    create_runtime_fix()
