# -*- coding: utf-8 -*-
"""Script to package Launcher into a standalone lightweight Launcher.exe."""

import os
import shutil
import subprocess
import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
ICON_ICO = BASE_DIR / "favicon.ico"
ICON_PNG = BASE_DIR / "Applogo.png"
LAUNCHER_MAIN = BASE_DIR / "launcher" / "main.py"
OUTPUT_DIR = BASE_DIR / "dist_launcher"


def build_launcher():
    print("=" * 60)
    print("BAT DAU DONG GOI LAUNCHER.EXE (SIEU NHE)")
    print("=" * 60)

    if not LAUNCHER_MAIN.exists():
        raise FileNotFoundError(f"Cannot find launcher entrypoint: {LAUNCHER_MAIN}")

    LAUNCHER_NAME = "89TTS_Launcher"
    cmd = [
        sys.executable,
        "-m",
        "PyInstaller",
        "--noconfirm",
        "--onedir",
        "--windowed",
        "--name",
        LAUNCHER_NAME,
        "--distpath",
        str(OUTPUT_DIR),
        "--workpath",
        str(BASE_DIR / "build_launcher_temp"),
        "--specpath",
        str(BASE_DIR / "build_launcher_temp"),
        "--icon",
        str(ICON_ICO),
        "--add-data",
        f"{ICON_PNG.as_posix()};assets",
        "--add-data",
        f"{ICON_ICO.as_posix()};assets",
        # Hidden imports needed for launcher only
        "--hidden-import",
        "PySide6.QtCore",
        "--hidden-import",
        "PySide6.QtGui",
        "--hidden-import",
        "PySide6.QtWidgets",
        "--hidden-import",
        "launcher.hardware",
        "--hidden-import",
        "launcher.downloader",
        "--hidden-import",
        "launcher.ui",
        # Exclude all heavy AI libraries from Launcher.exe
        "--exclude-module",
        "torch",
        "--exclude-module",
        "torchaudio",
        "--exclude-module",
        "transformers",
        "--exclude-module",
        "omnivoice",
        "--exclude-module",
        "vieneu",
        "--exclude-module",
        "scipy",
        "--exclude-module",
        "pandas",
        "--exclude-module",
        "matplotlib",
        "--exclude-module",
        "onnxruntime",
        str(LAUNCHER_MAIN),
    ]

    if ICON_ICO.exists():
        cmd.extend(["--icon", str(ICON_ICO)])

    print(f"[RUN] {' '.join(cmd)}")
    subprocess.run(cmd, check=True)

    out_folder = OUTPUT_DIR / LAUNCHER_NAME
    # Place assets inside _internal/assets to keep launcher root clean
    internal_assets = out_folder / "_internal" / "assets"
    internal_assets.mkdir(parents=True, exist_ok=True)
    if ICON_PNG.exists():
        shutil.copy2(ICON_PNG, internal_assets / "Applogo.png")
    if ICON_ICO.exists():
        shutil.copy2(ICON_ICO, internal_assets / "favicon.ico")

    # Clean any stale icons from root of launcher folder
    for stale in [out_folder / "Applogo.png", out_folder / "favicon.ico"]:
        if stale.exists():
            stale.unlink()

    # Create zip package
    zip_output = OUTPUT_DIR / f"{LAUNCHER_NAME}_v1.0.0"
    print(f"Creating zip distribution: {zip_output}.zip ...")
    shutil.make_archive(str(zip_output), "zip", OUTPUT_DIR, LAUNCHER_NAME)

    # Also sync to dist_r2 if exists
    dist_r2 = BASE_DIR / "dist_r2"
    dist_r2.mkdir(parents=True, exist_ok=True)
    shutil.copy2(f"{zip_output}.zip", dist_r2 / "89TTS_Setup_v1.0.0.zip")

    print("\n" + "=" * 60)
    print("HOAN TAT DONG GOI 89TTS LAUNCHER!")
    print(f"Executable: {out_folder / f'{LAUNCHER_NAME}.exe'}")
    print(f"Zip Setup:  {dist_r2 / '89TTS_Setup_v1.0.0.zip'}")
    print("=" * 60)


if __name__ == "__main__":
    build_launcher()
