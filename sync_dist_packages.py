import os
import shutil
import sys

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

BASE_DIR = r"D:\CodeApp\omnivoice-vieneu"
DIST_OFFLINE = os.path.join(BASE_DIR, "dist_offline")
MODERN_DIR = os.path.join(DIST_OFFLINE, "89TTS_Modern_v1.0.0_Offline")
LEGACY_DIR = os.path.join(DIST_OFFLINE, "89TTS_Legacy_v1.0.0_Offline")
BUILD_APP = os.path.join(BASE_DIR, "build_app_secured", "app")
STUDIO_EXE = os.path.join(BASE_DIR, "dist_direct", "89TTS_Studio.exe")
BAT_FILE = os.path.join(BASE_DIR, "Khởi_Động_89TTS.bat")
LAUNCHER_SRC = os.path.join(BASE_DIR, "dist_launcher", "89TTS_Launcher")

def update_pth(runtime_dir):
    pth_file = os.path.join(runtime_dir, "python312._pth")
    if not os.path.exists(pth_file):
        print(f"[PTH] {pth_file} does not exist, skipping.")
        return
    
    with open(pth_file, "r", encoding="utf-8") as f:
        content = f.read()
    
    lines = [line.strip() for line in content.splitlines()]
    changed = False
    for item in ["..\\app", "app", ".."]:
        if item not in lines:
            lines.append(item)
            changed = True
    
    if changed:
        with open(pth_file, "w", encoding="utf-8") as f:
            f.write("\n".join(lines) + "\n")
        print(f"[PTH] Updated {pth_file}")
    else:
        print(f"[PTH] Already up-to-date in {pth_file}")

def sync_distribution(target_dir, name):
    print(f"\n--- Syncing {name} at {target_dir} ---")
    if not os.path.exists(target_dir):
        print(f"[WARN] Target dir not found: {target_dir}")
        return

    # 1. Copy 89TTS_Studio.exe
    if os.path.exists(STUDIO_EXE):
        shutil.copy2(STUDIO_EXE, os.path.join(target_dir, "89TTS_Studio.exe"))
        print(f"[OK] Copied 89TTS_Studio.exe -> {target_dir}")
    else:
        print(f"[ERR] 89TTS_Studio.exe not found at {STUDIO_EXE}")

    # 2. Copy Khởi_Động_89TTS.bat
    if os.path.exists(BAT_FILE):
        shutil.copy2(BAT_FILE, os.path.join(target_dir, "Khởi_Động_89TTS.bat"))
        print(f"[OK] Copied Khởi_Động_89TTS.bat -> {target_dir}")

    # 3. Update app directory with secured code
    target_app = os.path.join(target_dir, "app")
    if os.path.exists(BUILD_APP):
        print(f"[OK] Updating secured app in {target_app}...")
        shutil.copytree(BUILD_APP, target_app, dirs_exist_ok=True)
        print(f"[OK] App code updated successfully.")
    else:
        print(f"[ERR] Secured app build not found at {BUILD_APP}")

    # 4. Update launcher
    if os.path.exists(LAUNCHER_SRC):
        launcher_exe = os.path.join(LAUNCHER_SRC, "89TTS_Launcher.exe")
        internal_dir = os.path.join(LAUNCHER_SRC, "_internal")
        if os.path.exists(launcher_exe):
            shutil.copy2(launcher_exe, os.path.join(target_dir, "89TTS_Launcher.exe"))
            print(f"[OK] Updated 89TTS_Launcher.exe in {target_dir}")
        if os.path.exists(internal_dir):
            shutil.copytree(internal_dir, os.path.join(target_dir, "_internal"), dirs_exist_ok=True)
            print(f"[OK] Updated launcher _internal in {target_dir}")

    # 5. Update python312._pth in runtime
    update_pth(os.path.join(target_dir, "runtime"))

if __name__ == "__main__":
    print("Starting synchronization...")
    sync_distribution(MODERN_DIR, "Modern Offline")
    sync_distribution(LEGACY_DIR, "Legacy Offline")
    
    # Also sync 89TTS_Studio.exe into dist_launcher\89TTS_Launcher
    if os.path.exists(LAUNCHER_SRC) and os.path.exists(STUDIO_EXE):
        shutil.copy2(STUDIO_EXE, os.path.join(LAUNCHER_SRC, "89TTS_Studio.exe"))
        print(f"[OK] Copied 89TTS_Studio.exe -> {LAUNCHER_SRC}")
    if os.path.exists(LAUNCHER_SRC) and os.path.exists(BAT_FILE):
        shutil.copy2(BAT_FILE, os.path.join(LAUNCHER_SRC, "Khởi_Động_89TTS.bat"))
        print(f"[OK] Copied Khởi_Động_89TTS.bat -> {LAUNCHER_SRC}")

    print("\nALL SYNCS COMPLETED SUCCESSFULLY!")
