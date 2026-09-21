# -*- coding: utf-8 -*-
"""Compile runner_direct.py into a lightweight standalone 89TTS_Studio.exe."""

import subprocess
import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
ICON_ICO = BASE_DIR / "favicon.ico"
RUNNER_SRC = BASE_DIR / "runner_direct.py"
OUTPUT_DIR = BASE_DIR / "dist_direct"


def build_studio_exe():
    print("=" * 60)
    print("DONG GOI 89TTS_Studio.exe (KHOI DONG TRUC TIEP)")
    print("=" * 60)

    py_exe = BASE_DIR / "venv" / "Scripts" / "python.exe"
    if not py_exe.exists():
        py_exe = Path(sys.executable)

    cmd = [
        str(py_exe),
        "-m",
        "PyInstaller",
        "--noconfirm",
        "--onefile",
        "--windowed",
        "--name",
        "89TTS_Studio",
        "--distpath",
        str(OUTPUT_DIR),
        "--workpath",
        str(BASE_DIR / "build_studio_temp"),
        "--specpath",
        str(BASE_DIR / "build_studio_temp"),
    ]

    if ICON_ICO.exists():
        cmd.extend(["--icon", str(ICON_ICO)])

    cmd.append(str(RUNNER_SRC))

    print(f"[RUN] {' '.join(cmd)}")
    subprocess.run(cmd, check=True)

    exe_out = OUTPUT_DIR / "89TTS_Studio.exe"
    print("\n" + "=" * 60)
    print("HOAN TAT DONG GOI 89TTS_Studio.exe!")
    print(f"File: {exe_out} ({exe_out.stat().st_size / (1024*1024):.1f} MB)")
    print("=" * 60)


if __name__ == "__main__":
    build_studio_exe()
