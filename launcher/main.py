# -*- coding: utf-8 -*-
"""OmniVoice Launcher — Main Entrypoint & Background Orchestration Worker.

Features:
- Compute Capability (CC) based hardware detection
- Runtime smoke test: torch matrix multiplication verification
- Decoupled VRAM execution mode
- Hot update via Cloudflare R2 manifest
- Detached process spawn of main2.py
"""

import os
import subprocess
import sys
import time
from pathlib import Path
from typing import Optional, Tuple

from PySide6.QtCore import QObject, QThread, Signal, Slot
from PySide6.QtWidgets import QApplication

from launcher.downloader import (
    MANIFEST_URL,
    download_file,
    extract_zip,
    fetch_manifest,
)
from launcher.hardware import detect_hardware
from launcher.ui import LauncherWindow

if getattr(sys, "frozen", False):
    ROOT_DIR = Path(sys.executable).resolve().parent
else:
    ROOT_DIR = Path(__file__).resolve().parent.parent
LOCAL_APP_DIR = ROOT_DIR / "app"
LOCAL_RUNTIME_DIR = ROOT_DIR / "runtime"
LOCAL_MODELS_DIR = ROOT_DIR / "models"


def verify_runtime_health(python_exe: Path) -> Tuple[bool, str]:
    """Execute smoke test on downloaded runtime using torch tensor multiplication with zero-crash CPU fallback."""
    test_code = """
import sys
try:
    import torch
    cuda_works = False
    if torch.cuda.is_available():
        try:
            x = torch.randn(64, 64, device="cuda")
            y = x @ x
            dev_name = torch.cuda.get_device_name(0)
            archs = torch.cuda.get_arch_list()
            print(f"CUDA_OK|{dev_name}|{archs}")
            cuda_works = True
        except Exception as ce:
            print(f"CUDA_WARN|{ce}", file=sys.stderr)
            cuda_works = False

    if not cuda_works:
        x = torch.randn(64, 64)
        y = x @ x
        print("CPU_OK")
except Exception as e:
    print(f"FAIL|{e}")
    sys.exit(1)
"""
    try:
        res = subprocess.run(
            [str(python_exe), "-c", test_code],
            capture_output=True,
            text=True,
            timeout=15,
        )
        out = res.stdout.strip()
        if res.returncode == 0 and ("CUDA_OK" in out or "CPU_OK" in out):
            return True, out
        return False, res.stderr.strip() or out
    except Exception as e:
        return False, str(e)


class SetupWorker(QThread):
    """Background worker thread that manages hardware detection, downloads, and updates."""

    status_changed = Signal(str)
    hw_detected = Signal(str)
    progress_changed = Signal(int, str)  # percent, details
    launch_ready = Signal(str, str)     # python_exe, app_dir
    error_occurred = Signal(str)

    def __init__(self, manifest_url: str = MANIFEST_URL, force_repair: bool = False):
        super().__init__()
        self.manifest_url = manifest_url
        self.force_repair = force_repair
        self._is_cancelled = False

    def cancel(self):
        self._is_cancelled = True

    def run(self):
        try:
            # 1. Hardware Detection via Compute Capability
            self.status_changed.emit("Đang quét cấu hình phần cứng (Compute Capability)...")
            hw_info = detect_hardware()
            profile = hw_info["recommended_profile"]
            
            if hw_info["has_nvidia_gpu"]:
                hw_desc = f"{hw_info['gpu_name']} (CC {hw_info['compute_capability']}) | {hw_info['cuda_version_target']} | VRAM: {hw_info['vram_gb']}GB"
            else:
                hw_desc = f"Đồ họa: {hw_info['gpu_name']} | Profile: CPU"
            self.hw_detected.emit(hw_desc)
            time.sleep(0.5)

            # 2. Check & Fetch Manifest
            self.status_changed.emit("Đang kiểm tra kết nối máy chủ phát hành R2...")
            manifest = fetch_manifest(self.manifest_url)
            
            # 3. Check App Code
            self._ensure_app_code(manifest)

            # 4. Check Runtime Environment
            self._ensure_runtime(manifest, profile)

            # 5. Runtime Smoke Test (Tensor math check)
            self.status_changed.emit("Đang kiểm tra sức khỏe môi trường tính toán (Tensor Smoke Test)...")
            python_exe = self._resolve_python_executable()
            if python_exe:
                ok, diag = verify_runtime_health(python_exe)
                if not ok:
                    print(f"[Smoke Test Warning] Runtime test issue: {diag}")
                    # If CUDA failed on modern profile, fallback to CPU
                    if profile != "cpu-universal" and manifest:
                        self.status_changed.emit("GPU runtime không phản hồi — Tự động fallback sang gói CPU đa năng...")
                        self._ensure_runtime(manifest, "cpu-universal")
                else:
                    if "CUDA_OK" in diag:
                        self.status_changed.emit("Môi trường tăng tốc GPU NVIDIA sẵn sàng.")
                    else:
                        self.status_changed.emit("Môi trường sẵn sàng ở chế độ CPU đa năng (Thích ứng tự động).")

            # 6. Check AI Models
            self._ensure_models(manifest)

            # 7. Ready to launch
            if "--ui-preview" in sys.argv or "--preview" in sys.argv:
                self.status_changed.emit("Giao diện Launcher 89TTS sẵn sàng! (Chế độ xem trước UI)")
                self.progress_changed.emit(100, "100% Hoàn tất")
                return

            self.status_changed.emit("Tất cả đã sẵn sàng! Đang khởi động ứng dụng...")
            self.progress_changed.emit(100, "Khởi chạy...")
            time.sleep(1.0)

            python_exe = self._resolve_python_executable()
            app_entry = LOCAL_APP_DIR if LOCAL_APP_DIR.exists() else ROOT_DIR
            self.launch_ready.emit(str(python_exe), str(app_entry))

        except Exception as e:
            self.error_occurred.emit(str(e))

    def _ensure_app_code(self, manifest: Optional[dict]):
        """Ensure app code is present and up to date."""
        app_main_script = LOCAL_APP_DIR / "main_secure.pyc"
        if not app_main_script.exists():
            app_main_script = LOCAL_APP_DIR / "main_secure.py"
        if not app_main_script.exists():
            app_main_script = LOCAL_APP_DIR / "main.py"
        
        # In dev or repo environment, if app/ doesn't exist yet, we can run from ROOT_DIR
        if ((ROOT_DIR / "main_secure.py").exists() or (ROOT_DIR / "main_secure.pyc").exists()) and not LOCAL_APP_DIR.exists() and not self.force_repair:
            return

        need_download = not app_main_script.exists() or self.force_repair

        # Check version if manifest is reachable
        if manifest and not need_download:
            server_ver = manifest.get("version", "1.0.0")
            local_ver_file = LOCAL_APP_DIR / "version.json"
            local_ver = ""
            if local_ver_file.exists():
                try:
                    import json
                    local_ver = json.loads(local_ver_file.read_text("utf-8")).get("version", "")
                except Exception:
                    pass
            if local_ver != server_ver:
                need_download = True

        if need_download and manifest and "app_package" in manifest:
            pkg = manifest["app_package"]
            self.status_changed.emit(f"Đang tải bản cập nhật mã nguồn (v{pkg.get('version', '1.0.0')})...")
            
            zip_target = ROOT_DIR / "downloads" / pkg["filename"]
            
            def dl_cb(d, t, speed):
                pct = int((d / t) * 100) if t > 0 else 0
                detail = f"{d / (1024*1024):.1f}/{t / (1024*1024):.1f} MB ({speed:.1f} MB/s)"
                self.progress_changed.emit(pct, detail)

            ok = download_file(
                pkg["url"],
                zip_target,
                expected_sha256=pkg.get("sha256"),
                progress_callback=dl_cb,
                cancel_check=lambda: self._is_cancelled,
            )
            if not ok:
                raise RuntimeError("Không thể tải bản cập nhật mã nguồn từ máy chủ.")

            def ext_cb_app(d, t):
                pct = int((d / t) * 100) if t > 0 else 0
                self.progress_changed.emit(pct, f"Giải nén mã nguồn: {pct}% ({d}/{t} tệp)")

            self.status_changed.emit("Đang giải nén mã nguồn ứng dụng...")
            ok_ext = extract_zip(zip_target, ROOT_DIR, progress_callback=ext_cb_app)
            if not ok_ext:
                raise RuntimeError("Không thể giải nén mã nguồn ứng dụng.")
            
            # Save local version
            (LOCAL_APP_DIR / "version.json").write_text(
                f'{{"version": "{pkg.get("version", "1.0.0")}"}}', encoding="utf-8"
            )

    def _ensure_runtime(self, manifest: Optional[dict], profile: str):
        """Ensure Python runtime is present."""
        python_exe = self._resolve_python_executable()
        if python_exe and not self.force_repair:
            return

        if not manifest:
            if python_exe:
                return
            raise RuntimeError("Không tìm thấy môi trường Python cục bộ và không kết nối được máy chủ R2.")

        runtime_info = manifest.get("runtimes", {}).get(profile) or manifest.get("runtimes", {}).get("cuda-modern")
        if not runtime_info:
            raise RuntimeError(f"Không tìm thấy cấu hình runtime cho profile: {profile}")

        self.status_changed.emit(f"Đang chuẩn bị môi trường tăng tốc AI ({runtime_info.get('name', profile)})...")
        zip_target = ROOT_DIR / "downloads" / runtime_info["filename"]

        def dl_cb(d, t, speed):
            pct = int((d / t) * 100) if t > 0 else 0
            detail = f"{d / (1024*1024):.1f}/{t / (1024*1024):.1f} MB ({speed:.1f} MB/s)"
            self.progress_changed.emit(pct, detail)

        ok = download_file(
            runtime_info["url"],
            zip_target,
            expected_sha256=runtime_info.get("sha256"),
            progress_callback=dl_cb,
            cancel_check=lambda: self._is_cancelled,
        )
        if not ok:
            raise RuntimeError("Không thể tải môi trường runtime từ máy chủ.")

        def ext_cb_rt(d, t):
            pct = int((d / t) * 100) if t > 0 else 0
            self.progress_changed.emit(pct, f"Giải nén runtime: {pct}% ({d}/{t} tệp)")

        self.status_changed.emit("Đang giải nén môi trường tăng tốc (quá trình này có thể mất 1-2 phút)...")
        ok_ext = extract_zip(zip_target, ROOT_DIR, progress_callback=ext_cb_rt)
        if not ok_ext:
            raise RuntimeError("Không thể giải nén môi trường runtime. Vui lòng kiểm tra dung lượng ổ đĩa.")

    def _ensure_models(self, manifest: Optional[dict]):
        """Ensure required model weights are present."""
        # 1. OmniVoice Model
        omni_local = ROOT_DIR / "omnivoice_model" / "model.safetensors"
        if not omni_local.exists() and manifest and "omnivoice" in manifest.get("models", {}):
            m_info = manifest["models"]["omnivoice"]
            self.status_changed.emit("Đang tải mô hình giọng nói OmniVoice Zero-Shot...")
            zip_target = ROOT_DIR / "downloads" / m_info["filename"]

            def dl_cb(d, t, speed):
                pct = int((d / t) * 100) if t > 0 else 0
                detail = f"{d / (1024*1024):.1f}/{t / (1024*1024):.1f} MB ({speed:.1f} MB/s)"
                self.progress_changed.emit(pct, detail)

            ok = download_file(
                m_info["url"],
                zip_target,
                expected_sha256=m_info.get("sha256"),
                progress_callback=dl_cb,
                cancel_check=lambda: self._is_cancelled,
            )
            if ok:
                def ext_cb_omni(d, t):
                    pct = int((d / t) * 100) if t > 0 else 0
                    self.progress_changed.emit(pct, f"Giải nén mô hình: {pct}% ({d}/{t} tệp)")

                self.status_changed.emit("Đang giải nén mô hình OmniVoice...")
                extract_zip(zip_target, ROOT_DIR, progress_callback=ext_cb_omni)
            else:
                # Fallback directly to HuggingFace Hub
                hf_repo = m_info.get("fallback_hf", "k2-fsa/OmniVoice")
                self.status_changed.emit(f"Máy chủ R2 bận — Tự động chuyển hướng tải từ HuggingFace Hub ({hf_repo})...")
                python_exe = self._resolve_python_executable()
                if python_exe:
                    target_dir = ROOT_DIR / "omnivoice_model"
                    target_dir.mkdir(parents=True, exist_ok=True)
                    hf_cmd = [
                        str(python_exe),
                        "-c",
                        f"from huggingface_hub import snapshot_download; snapshot_download(repo_id='{hf_repo}', local_dir=r'{str(target_dir)}')"
                    ]
                    res_hf = subprocess.run(hf_cmd, capture_output=True, text=True)
                    if res_hf.returncode != 0:
                        raise RuntimeError(f"Không thể tải mô hình OmniVoice từ cả R2 lẫn HuggingFace: {res_hf.stderr or res_hf.stdout}")
                else:
                    raise RuntimeError("Không thể tải mô hình OmniVoice.")

        # 2. VieNeu Model
        vieneu_hf = Path.home() / ".cache" / "huggingface" / "hub" / "models--pnnbao-ump--VieNeu-TTS-v3-Turbo"
        vieneu_local_cache = ROOT_DIR / "hf_cache" / "models--pnnbao-ump--VieNeu-TTS-v3-Turbo"
        if not vieneu_hf.exists() and not vieneu_local_cache.exists() and manifest and "vieneu" in manifest.get("models", {}):
            v_info = manifest["models"]["vieneu"]
            self.status_changed.emit("Đang tải mô hình tiếng Việt VieNeu-TTS v3 Turbo...")
            zip_target = ROOT_DIR / "downloads" / v_info["filename"]

            def dl_cb(d, t, speed):
                pct = int((d / t) * 100) if t > 0 else 0
                detail = f"{d / (1024*1024):.1f}/{t / (1024*1024):.1f} MB ({speed:.1f} MB/s)"
                self.progress_changed.emit(pct, detail)

            ok = download_file(
                v_info["url"],
                zip_target,
                expected_sha256=v_info.get("sha256"),
                progress_callback=dl_cb,
                cancel_check=lambda: self._is_cancelled,
            )
            if ok:
                def ext_cb_vieneu(d, t):
                    pct = int((d / t) * 100) if t > 0 else 0
                    self.progress_changed.emit(pct, f"Giải nén mô hình: {pct}% ({d}/{t} tệp)")

                self.status_changed.emit("Đang giải nén mô hình VieNeu...")
                extract_zip(zip_target, Path.home() / ".cache" / "huggingface" / "hub", progress_callback=ext_cb_vieneu)
            else:
                # Fallback directly to HuggingFace Hub
                hf_repo = v_info.get("fallback_hf", "pnnbao-ump/VieNeu-TTS-v3-Turbo")
                self.status_changed.emit(f"Máy chủ R2 bận — Tự động chuyển hướng tải từ HuggingFace Hub ({hf_repo})...")
                python_exe = self._resolve_python_executable()
                if python_exe:
                    hf_cmd = [
                        str(python_exe),
                        "-c",
                        f"from huggingface_hub import snapshot_download; snapshot_download(repo_id='{hf_repo}')"
                    ]
                    res_hf = subprocess.run(hf_cmd, capture_output=True, text=True)
                    if res_hf.returncode != 0:
                        raise RuntimeError(f"Không thể tải mô hình VieNeu từ cả R2 lẫn HuggingFace: {res_hf.stderr or res_hf.stdout}")
                else:
                    raise RuntimeError("Không thể tải mô hình VieNeu-TTS.")

    def _resolve_python_executable(self) -> Optional[Path]:
        """Find the python executable in runtime or venv."""
        candidates = [
            LOCAL_RUNTIME_DIR / "python.exe",
            LOCAL_RUNTIME_DIR / "Scripts" / "python.exe",
            ROOT_DIR / "venv" / "Scripts" / "python.exe",
            ROOT_DIR / "venv" / "python.exe",
        ]
        for c in candidates:
            if c.exists():
                return c
        return None


class LauncherApp:
    """Launcher Application Controller."""

    def __init__(self):
        self.app = QApplication.instance() or QApplication(sys.argv)
        
        # Resolve icon path robustly (prioritizing internal/assets so root folder stays clean)
        icon_path = None
        base_candidates = []
        if getattr(sys, "frozen", False):
            exe_dir = Path(sys.executable).parent
            meipass = Path(getattr(sys, "_MEIPASS", exe_dir))
            base_candidates.extend([
                meipass / "assets" / "Applogo.png",
                meipass / "Applogo.png",
                exe_dir / "_internal" / "assets" / "Applogo.png",
                exe_dir / "_internal" / "Applogo.png",
                exe_dir / "assets" / "Applogo.png",
                meipass / "assets" / "favicon.ico",
                exe_dir / "_internal" / "favicon.ico",
            ])
        base_candidates.extend([
            ROOT_DIR / "assets" / "Applogo.png",
            ROOT_DIR / "Applogo.png",
            ROOT_DIR / "app" / "Applogo.png",
            ROOT_DIR / "favicon.ico",
        ])
        for p in base_candidates:
            if p.exists():
                icon_path = p
                break

        if icon_path:
            from PySide6.QtGui import QIcon
            self.app.setWindowIcon(QIcon(str(icon_path)))

        self.window = LauncherWindow(app_icon_path=icon_path)
        self.worker = SetupWorker()

        # Connect Worker signals
        self.worker.status_changed.connect(self.window.set_status)
        self.worker.hw_detected.connect(self.window.set_hw_info)
        self.worker.progress_changed.connect(self.window.set_progress)
        self.worker.launch_ready.connect(self._on_launch_ready)
        self.worker.error_occurred.connect(self._on_error)

        # Connect Window signals
        self.window.cancel_requested.connect(self.worker.cancel)
        self.window.repair_requested.connect(self._start_repair)

    def run(self) -> int:
        self.window.show()
        self.worker.start()
        return self.app.exec()

    @Slot(str, str)
    def _on_launch_ready(self, python_exe: str, app_dir: str):
        """Spawn the main application process and close the launcher."""
        main_script = None
        for candidate in [
            Path(app_dir) / "main_secure.pyc",
            Path(app_dir) / "main_secure.py",
            ROOT_DIR / "main_secure.pyc",
            ROOT_DIR / "main_secure.py",
        ]:
            if candidate.exists():
                main_script = candidate
                break

        if not main_script:
            main_script = Path(app_dir) / "main_secure.pyc" if (Path(app_dir) / "main_secure.pyc").exists() else Path(app_dir) / "main_secure.py"

        # Setup runtime environment so app knows root folder and python modules
        spawn_env = os.environ.copy()
        spawn_env["PYTHONPATH"] = f"{str(app_dir)}{os.pathsep}{str(ROOT_DIR)}{os.pathsep}{spawn_env.get('PYTHONPATH', '')}"
        spawn_env["OMNIVOICE_ROOT"] = str(ROOT_DIR)

        # Offline HuggingFace cache config for VieNeu-TTS
        hf_cache_dir = ROOT_DIR / "hf_cache"
        if hf_cache_dir.exists():
            spawn_env["HF_HOME"] = str(hf_cache_dir)
            spawn_env["HF_HUB_CACHE"] = str(hf_cache_dir)

        subprocess.Popen(
            [python_exe, str(main_script)],
            cwd=str(ROOT_DIR),
            env=spawn_env,
            creationflags=subprocess.DETACHED_PROCESS if sys.platform == "win32" else 0,
        )
        self.window.close()
        self.app.quit()

    @Slot(str)
    def _on_error(self, message: str):
        self.window.set_status(f"Lỗi: {message}")
        self.window.lbl_status.setStyleSheet("font-size: 12px; color: #ef4444; font-weight: bold;")

    @Slot()
    def _start_repair(self):
        self.worker.force_repair = True
        self.worker.start()


def main():
    launcher = LauncherApp()
    sys.exit(launcher.run())


if __name__ == "__main__":
    main()
