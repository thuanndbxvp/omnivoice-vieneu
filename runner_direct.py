# -*- coding: utf-8 -*-
"""89TTS Studio — Direct Application Loader.
Allows instant launch of 89TTS without running Launcher hardware checks.
"""

import ctypes
import os
import subprocess
import sys
import time
from pathlib import Path


def show_error(title: str, message: str):
    """Show native Windows message box on fatal error."""
    try:
        ctypes.windll.user32.MessageBoxW(0, message, title, 0x10 | 0x10000)
    except Exception:
        print(f"[{title}] {message}", file=sys.stderr)


def main():
    if getattr(sys, "frozen", False):
        base_dir = Path(sys.executable).resolve().parent
    else:
        base_dir = Path(__file__).resolve().parent

    app_dir = base_dir / "app"
    runtime_dir = base_dir / "runtime"

    # 1. Resolve Python executable
    py_candidates = [
        runtime_dir / "pythonw.exe",
        runtime_dir / "python.exe",
        runtime_dir / "Scripts" / "pythonw.exe",
        runtime_dir / "Scripts" / "python.exe",
        base_dir / "venv" / "Scripts" / "pythonw.exe",
        base_dir / "venv" / "Scripts" / "python.exe",
    ]
    python_exe = None
    for c in py_candidates:
        if c.exists():
            python_exe = c
            break

    if not python_exe:
        show_error(
            "89TTS Studio — Lỗi Môi Trường",
            f"Không tìm thấy môi trường Python trong thư mục:\n{runtime_dir}\n\n"
            "Vui lòng kiểm tra lại gói cài đặt hoặc khởi động bằng 89TTS_Launcher.exe để kiểm tra và khôi phục.",
        )
        sys.exit(1)

    # 2. Resolve App script entry
    script_candidates = [
        app_dir / "main_secure.pyc",
        app_dir / "main_secure.py",
        base_dir / "main_secure.pyc",
        base_dir / "main_secure.py",
    ]
    app_script = None
    for s in script_candidates:
        if s.exists():
            app_script = s
            break

    if not app_script:
        show_error(
            "89TTS Studio — Lỗi Ứng Dụng",
            f"Không tìm thấy mã nguồn ứng dụng tại:\n{app_dir}\n\n"
            "Vui lòng khởi động bằng 89TTS_Launcher.exe để đồng bộ hoặc sửa chữa ứng dụng.",
        )
        sys.exit(1)

    # 3. Environment configuration
    env = os.environ.copy()
    env["OMNIVOICE_ROOT"] = str(base_dir)
    env["PYTHONPATH"] = f"{str(app_dir)}{os.pathsep}{str(base_dir)}{os.pathsep}{env.get('PYTHONPATH', '')}"

    hf_cache = base_dir / "hf_cache"
    if hf_cache.exists():
        env["HF_HOME"] = str(hf_cache)
        env["HF_HUB_CACHE"] = str(hf_cache)

    # 4. Launch the application process
    try:
        proc = subprocess.Popen(
            [str(python_exe), str(app_script)],
            cwd=str(base_dir),
            env=env,
            creationflags=subprocess.DETACHED_PROCESS if sys.platform == "win32" else 0,
        )
        # Quick health check in first 1.5 seconds
        time.sleep(1.5)
        if proc.poll() is not None and proc.returncode != 0:
            show_error(
                "89TTS Studio — Lỗi Khởi Chạy",
                f"Ứng dụng gặp sự cố và đã thoát với mã lỗi: {proc.returncode}.\n\n"
                "Bạn có thể mở '89TTS_Launcher.exe' để kiểm tra tính toàn vẹn hoặc chạy 'Chay_Truc_Tiep_89TTS.bat' để xem chi tiết lỗi.",
            )
            sys.exit(proc.returncode)
    except Exception as e:
        show_error("89TTS Studio — Lỗi", f"Không thể khởi chạy ứng dụng:\n\n{e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
