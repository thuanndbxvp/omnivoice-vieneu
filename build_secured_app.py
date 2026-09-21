# -*- coding: utf-8 -*-
"""Isolated Secured Build Pipeline for 89TTS.

- Preserves original repository code 100% untouched.
- Staging directory: build_app_secured/app/
- Compiles core sensitive modules to native C-extensions (.pyd) using Cython & MSVC.
- Compiles all remaining modules to Python Bytecode (.pyc).
- Strips 100% of plain text source code (.py) from the distribution package.
- Packages into dist_r2/app-v1.0.0.zip and updates manifest.json.
- Synchronizes to dist_launcher/89TTS_Launcher/app.
"""

import compileall
import hashlib
import json
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path

REPO_ROOT = Path("D:/CodeApp/omnivoice-vieneu").resolve()
STAGING_DIR = REPO_ROOT / "build_app_secured"
APP_DIR = STAGING_DIR / "app"
PYTHON_EXE = REPO_ROOT / "venv" / "Scripts" / "python.exe"

# Modules to compile to native C-Extension (.pyd) via Cython
CYTHON_MODULES = [
    "src/core/license_client.py",
    "src/core/runtime_guard.py",
    "src/core/engine.py",
    "src/core/synthesis_pipeline.py",
    "src/ui/app.py",
]


def step1_prepare_staging():
    print("\n" + "=" * 60)
    print("STEP 1: Setting up isolated staging directory...")
    print("=" * 60)

    if STAGING_DIR.exists():
        shutil.rmtree(STAGING_DIR)
    APP_DIR.mkdir(parents=True, exist_ok=True)

    # 1. Copy src/
    shutil.copytree(
        REPO_ROOT / "src",
        APP_DIR / "src",
        ignore=shutil.ignore_patterns("__pycache__", "*.pyc", "*.c", "*.pyd", "*.obj"),
    )
    print("  -> Copied src/ (without pycache)")

    # 2. Copy voice libraries
    for vlib in ["vieneu_voice_library", "omnivoice_voice_library"]:
        src_vlib = REPO_ROOT / vlib
        if src_vlib.exists():
            shutil.copytree(
                src_vlib,
                APP_DIR / vlib,
                ignore=shutil.ignore_patterns("__pycache__", "*.pyc"),
            )
            print(f"  -> Copied {vlib}/")

    # 3. Copy top-level files (strictly exclude main.py, main2.py)
    top_files = ["main_secure.py", "version.json", "Applogo.png", "favicon.ico", "requirements.txt"]
    for fname in top_files:
        fpath = REPO_ROOT / fname
        if fpath.exists():
            shutil.copy2(fpath, APP_DIR / fname)
            print(f"  -> Copied {fname}")

    print("Staging directory initialized at:", APP_DIR)


def step2_activate_anti_debug():
    print("\n" + "=" * 60)
    print("STEP 2: Activating full Anti-Debug in staging...")
    print("=" * 60)

    # 1. Patch main_secure.py in staging
    ms_path = APP_DIR / "main_secure.py"
    ms_text = ms_path.read_text(encoding="utf-8")
    # Replace the skip condition in _native_anti_debug
    old_code = 'def _native_anti_debug() -> None:\n    """Check for debugger presence using Windows native APIs."""\n    if not getattr(sys, "frozen", False):\n        return  # Skip in dev mode'
    new_code = 'def _native_anti_debug() -> None:\n    """Check for debugger presence using Windows native APIs."""\n    # Full anti-debug protection active'
    if old_code in ms_text:
        ms_text = ms_text.replace(old_code, new_code)
        ms_path.write_text(ms_text, encoding="utf-8")
        print("  -> Activated anti-debug in staging main_secure.py")
    else:
        # Fallback patch
        ms_text = ms_text.replace('if not getattr(sys, "frozen", False):\n        return  # Skip in dev mode', '# Anti-debug active')
        ms_path.write_text(ms_text, encoding="utf-8")
        print("  -> Activated anti-debug in staging main_secure.py (fallback match)")

    # 2. Patch runtime_guard.py in staging
    rg_path = APP_DIR / "src" / "core" / "runtime_guard.py"
    rg_text = rg_path.read_text(encoding="utf-8")
    rg_old = 'def _check_debugger() -> None:\n    """Check for debugger presence using Windows native APIs. Exit if detected."""\n    if not getattr(sys, "frozen", False):\n        return'
    rg_new = 'def _check_debugger() -> None:\n    """Check for debugger presence using Windows native APIs. Exit if detected."""\n    # Background anti-debug active'
    if rg_old in rg_text:
        rg_text = rg_text.replace(rg_old, rg_new)
        rg_path.write_text(rg_text, encoding="utf-8")
        print("  -> Activated anti-debug in staging runtime_guard.py")
    else:
        rg_text = rg_text.replace('if not getattr(sys, "frozen", False):\n        return', '# Anti-debug active')
        rg_path.write_text(rg_text, encoding="utf-8")
        print("  -> Activated anti-debug in staging runtime_guard.py (fallback match)")


def step3_compile_cython():
    print("\n" + "=" * 60)
    print("STEP 3: Compiling Core modules to native C-Extension (.pyd)...")
    print("=" * 60)

    # Generate setup_cython.py in staging
    setup_content = f"""# -*- coding: utf-8 -*-
from setuptools import setup
from Cython.Build import cythonize

modules = {CYTHON_MODULES}

setup(
    name="89TTS_Core",
    ext_modules=cythonize(
        modules,
        language_level=3,
        compiler_directives={{
            'always_allow_keywords': True,
            'binding': True,
            'embedsignature': False,
        }}
    )
)
"""
    setup_file = APP_DIR / "setup_cython.py"
    setup_file.write_text(setup_content, encoding="utf-8")

    # Run build_ext --inplace
    cmd = [str(PYTHON_EXE), "setup_cython.py", "build_ext", "--inplace"]
    print(f"Running Cython compilation in {APP_DIR}...")
    t0 = time.time()
    res = subprocess.run(cmd, cwd=str(APP_DIR), capture_output=True, text=True)
    if res.returncode != 0:
        print("STDOUT:\n", res.stdout)
        print("STDERR:\n", res.stderr)
        raise RuntimeError(f"Cython compilation failed with code {res.returncode}")

    print(f"Cython compilation finished in {time.time() - t0:.2f}s")

    # Verify each .pyd was created and remove original .py + generated .c
    for mod_rel in CYTHON_MODULES:
        py_file = APP_DIR / mod_rel
        mod_dir = py_file.parent
        stem = py_file.stem
        # Search for stem*.pyd
        pyds = list(mod_dir.glob(f"{stem}*.pyd"))
        if not pyds:
            raise RuntimeError(f"Expected compiled .pyd for {mod_rel}, but none found in {mod_dir}")
        print(f"  [OK] Compiled: {mod_rel} -> {pyds[0].name} ({pyds[0].stat().st_size:,} bytes)")

        # Safely remove the .py source file in staging
        py_file.unlink()
        # Remove intermediate .c file
        c_file = py_file.with_suffix(".c")
        if c_file.exists():
            c_file.unlink()

    # Clean build directory and setup script
    build_dir = APP_DIR / "build"
    if build_dir.exists():
        shutil.rmtree(build_dir)
    if setup_file.exists():
        setup_file.unlink()


def step4_compile_bytecode():
    print("\n" + "=" * 60)
    print("STEP 4: Compiling all remaining modules to Bytecode (.pyc)...")
    print("=" * 60)

    # Compile all remaining .py files with legacy=True (creates .pyc directly alongside .py)
    # Using python -m compileall -b <APP_DIR>
    cmd = [str(PYTHON_EXE), "-m", "compileall", "-b", str(APP_DIR)]
    res = subprocess.run(cmd, capture_output=True, text=True)
    print("compileall returncode:", res.returncode)

    # Now remove ALL remaining .py files in staging
    py_files = list(APP_DIR.rglob("*.py"))
    print(f"Removing {len(py_files)} remaining .py source files from staging...")
    for py in py_files:
        py.unlink()

    # Clean all __pycache__ folders
    for pycache in list(APP_DIR.rglob("__pycache__")):
        try:
            shutil.rmtree(pycache)
        except Exception:
            pass

    # Verification: Ensure ZERO .py files exist in staging
    remaining_py = list(APP_DIR.rglob("*.py"))
    remaining_c = list(APP_DIR.rglob("*.c"))
    print(f"Verification: remaining .py files = {len(remaining_py)}, remaining .c files = {len(remaining_c)}")
    if remaining_py:
        raise RuntimeError(f"Security check failed! .py files still present in staging: {remaining_py}")

    # List all compiled binary artifacts
    all_pyd = list(APP_DIR.rglob("*.pyd"))
    all_pyc = list(APP_DIR.rglob("*.pyc"))
    print(f"  -> Total .pyd native C binaries: {len(all_pyd)}")
    print(f"  -> Total .pyc Bytecode binaries: {len(all_pyc)}")
    print("  -> Staging app/ is 100% binary and source-code free!")


def step5_package_and_manifest():
    print("\n" + "=" * 60)
    print("STEP 5: Packaging app-v1.0.0.zip & Updating manifest...")
    print("=" * 60)

    dist_r2 = REPO_ROOT / "dist_r2"
    dist_r2.mkdir(parents=True, exist_ok=True)
    zip_output = dist_r2 / "app-v1.0.0.zip"
    if zip_output.exists():
        zip_output.unlink()

    # Zip the contents with 'app/' root prefix
    import zipfile
    with zipfile.ZipFile(zip_output, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for file_path in APP_DIR.rglob("*"):
            if file_path.is_file():
                arcname = "app/" + str(file_path.relative_to(APP_DIR)).replace("\\", "/")
                zf.write(file_path, arcname)

    size_bytes = zip_output.stat().st_size
    print(f"Created {zip_output.name}: {size_bytes:,} bytes ({size_bytes / (1024*1024):.2f} MB)")

    # Compute SHA256
    h = hashlib.sha256()
    with open(zip_output, "rb") as f:
        while chunk := f.read(4 * 1024 * 1024):
            h.update(chunk)
    new_sha256 = h.hexdigest()
    print("New SHA256:", new_sha256)

    # Update manifest.json
    manifest_path = dist_r2 / "manifest.json"
    if manifest_path.exists():
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest["app_package"]["sha256"] = new_sha256
        manifest["app_package"]["size_bytes"] = size_bytes
        manifest["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime())
        manifest_path.write_text(json.dumps(manifest, indent=4), encoding="utf-8")
        print("Updated manifest.json with new app package hash and size.")

    # Also sync secured app to dist_launcher/89TTS_Launcher/app
    launcher_app = REPO_ROOT / "dist_launcher" / "89TTS_Launcher" / "app"
    if launcher_app.exists():
        shutil.rmtree(launcher_app)
    shutil.copytree(APP_DIR, launcher_app)
    print("Synchronized secured binary app to dist_launcher/89TTS_Launcher/app")

    # Also copy app-v1.0.0.zip into dist_launcher downloads cache
    dl_dir = REPO_ROOT / "dist_launcher" / "89TTS_Launcher" / "downloads"
    dl_dir.mkdir(parents=True, exist_ok=True)
    shutil.copy2(zip_output, dl_dir / "app-v1.0.0.zip")
    print("Updated downloads cache in dist_launcher.")

    return zip_output, manifest_path


def main():
    print("=" * 70)
    print("89TTS SECURED BUILD PIPELINE (Cython .pyd + Bytecode .pyc)")
    print("=" * 70)
    step1_prepare_staging()
    step2_activate_anti_debug()
    step3_compile_cython()
    step4_compile_bytecode()
    zip_out, man_out = step5_package_and_manifest()
    print("\n" + "=" * 70)
    print("SECURED BUILD PIPELINE COMPLETED SUCCESSFULLY!")
    print(f"Zip: {zip_out}")
    print(f"Manifest: {man_out}")
    print("=" * 70)


if __name__ == "__main__":
    main()
