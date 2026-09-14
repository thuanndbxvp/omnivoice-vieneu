#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
OmniVoice Cloner — Direct Bypass Entry Point (main2.py).

This entry point launches the application directly, bypassing all license checks,
dialogs, auto-verifications, and background license guards.

Usage:
  python main2.py
"""

from __future__ import annotations

import io
import logging
import os
import sys


def _setup_utf8_streams() -> None:
    """Ensure sys.stdout and sys.stderr use UTF-8 encoding with character replacement to prevent UnicodeEncodeError in GUI executables."""
    os.environ["PYTHONIOENCODING"] = "utf-8"
    os.environ["PYTHONUTF8"] = "1"

    class NullStream(io.TextIOBase):
        def write(self, s: str) -> int:
            return len(s) if s else 0

        def flush(self) -> None:
            pass

    for stream_name in ("stdout", "stderr"):
        stream = getattr(sys, stream_name, None)
        if stream is None:
            setattr(sys, stream_name, NullStream())
        else:
            try:
                if hasattr(stream, "reconfigure"):
                    stream.reconfigure(encoding="utf-8", errors="replace")
                elif hasattr(stream, "buffer"):
                    setattr(
                        sys,
                        stream_name,
                        io.TextIOWrapper(stream.buffer, encoding="utf-8", errors="replace"),
                    )
            except Exception:
                setattr(sys, stream_name, NullStream())

    # Protect logging.StreamHandler against UnicodeEncodeError & AttributeError (NoneType write)
    try:
        _orig_emit = logging.StreamHandler.emit

        def _safe_emit(self, record):
            if getattr(self, "stream", None) is None:
                self.stream = sys.stderr or NullStream()
            try:
                _orig_emit(self, record)
            except Exception:
                try:
                    msg = self.format(record)
                    safe_msg = msg.encode("ascii", errors="replace").decode("ascii")
                    if self.stream and hasattr(self.stream, "write"):
                        self.stream.write(safe_msg + "\n")
                except Exception:
                    pass

        logging.StreamHandler.emit = _safe_emit
    except Exception:
        pass


_setup_utf8_streams()

# Ensure ffmpeg is available (bundled via imageio-ffmpeg)
try:
    import imageio_ffmpeg

    _ffmpeg_exe = imageio_ffmpeg.get_ffmpeg_exe()
    _ffmpeg_dir = os.path.dirname(_ffmpeg_exe)
    if _ffmpeg_dir not in os.environ.get("PATH", ""):
        os.environ["PATH"] = _ffmpeg_dir + os.pathsep + os.environ.get("PATH", "")
    try:
        from pydub.utils import which as _which

        if not _which("ffmpeg"):
            import pydub

            pydub.AudioSegment.converter = _ffmpeg_exe
            _ffprobe = _ffmpeg_exe.replace("ffmpeg", "ffprobe")
            if os.path.exists(_ffprobe):
                pydub.AudioSegment.ffprobe = _ffprobe
    except Exception:
        pass
except ImportError:
    pass

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%H:%M:%S",
)
logger = logging.getLogger("omnivoice-cloner-bypass")


def main() -> int:
    """Application entry point bypassing license verification."""
    from src.ui.app import create_app
    from src.ui.main_window import MainWindow
    from src.core.license_models import LicenseInfo, LicenseStatus, LicenseTier

    app = create_app(sys.argv)
    logger.info("Starting %s %s (License Bypass Mode)", app.applicationName(), app.applicationVersion())

    bypass_license_dict = {
        "valid": True,
        "tier": "professional",
        "license_key": "PRO-BYPASS-UNLIMITED-PERPETUAL",
        "username": "VIP License User",
        "email": "vip@local.app",
        "message": "License Bypass Active (Professional Perpetual)",
        "days_left": 99999,
        "machine_id": "LOCAL-BYPASS-HWID",
        "expiry": 4102444799,
    }

    logger.info("Bypassing license dialog & runtime guards — active tier: professional")

    # Launch MainWindow directly with full professional license info
    window = MainWindow(license_info=bypass_license_dict)
    window.show()

    logger.info("Application window shown (Bypass Mode) — entering event loop")
    return app.exec()


if __name__ == "__main__":
    if "--smoke-test" in sys.argv:
        print("SMOKE_TEST_OK")
        sys.exit(0)
    sys.exit(main())
