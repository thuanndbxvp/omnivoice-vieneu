# -*- coding: utf-8 -*-
"""Download, hash verification, and extraction module for OmniVoice Launcher."""

import hashlib
import json
import os
import shutil
import socket
import sys
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path
from typing import Callable, Optional

# Set default socket timeout for OS level (prevents infinite hanging / socket freeze)
socket.setdefaulttimeout(20.0)

DEFAULT_USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) OmniVoiceLauncher/1.0"
MANIFEST_URL = "https://pub-809dc95ff1ec45b2a32a971be2eb83e0.r2.dev/manifest.json"


def fetch_manifest(manifest_url: str = MANIFEST_URL, timeout: int = 8) -> Optional[dict]:
    """Fetch and parse manifest.json from Cloudflare R2."""
    try:
        req = urllib.request.Request(manifest_url, headers={"User-Agent": DEFAULT_USER_AGENT})
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            if resp.status == 200:
                data = json.loads(resp.read().decode("utf-8"))
                return data
    except Exception as e:
        print(f"[Launcher Downloader] Failed to fetch manifest: {e}")
    return None


def compute_sha256(filepath: Path, chunk_size: int = 4 * 1024 * 1024) -> str:
    """Compute SHA256 checksum of a local file."""
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        while chunk := f.read(chunk_size):
            h.update(chunk)
    return h.hexdigest()


def _atomic_replace_with_retry(src: Path, dst: Path, max_attempts: int = 20, delay: float = 0.5) -> bool:
    """Safely replace dst with src on Windows, handling antivirus/indexer locks."""
    for attempt in range(max_attempts):
        try:
            if dst.exists():
                try:
                    dst.unlink()
                except Exception:
                    pass
            os.replace(src, dst)
            return True
        except (PermissionError, OSError):
            time.sleep(delay)
    # Fallback: copy then unlink
    try:
        shutil.copy2(src, dst)
        try:
            src.unlink()
        except Exception:
            pass
        return True
    except Exception as e:
        print(f"[Downloader] Failed to move {src} -> {dst}: {e}")
        return False


def download_file(
    url: str,
    dest_path: Path,
    expected_sha256: Optional[str] = None,
    progress_callback: Optional[Callable[[int, int, float], None]] = None,
    cancel_check: Optional[Callable[[], bool]] = None,
    chunk_size: int = 1024 * 1024,
    max_retries: int = 8,
) -> bool:
    """Download a file with HTTP Range resume, socket timeout, auto-retry, and checksum verification.

    Args:
        url: Remote URL to download.
        dest_path: Target local path.
        expected_sha256: Expected SHA256 checksum (optional).
        progress_callback: callback(downloaded_bytes, total_bytes, speed_mb_s).
        cancel_check: callback returning True if cancellation is requested.
        chunk_size: read buffer size.
        max_retries: Maximum number of reconnection attempts before failing.

    Returns:
        bool: True if downloaded and verified successfully, False otherwise.
    """
    dest_path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = dest_path.with_suffix(dest_path.suffix + ".part")

    # 1. Quick check if dest_path already exists and matches checksum
    if dest_path.exists() and expected_sha256:
        try:
            actual_sha = compute_sha256(dest_path)
            if actual_sha.lower() == expected_sha256.lower():
                print(f"[Downloader] {dest_path.name} already downloaded and verified. Skipping download.")
                return True
        except Exception as e:
            print(f"[Downloader] Error checking existing {dest_path.name}: {e}")

    # 2. Quick check if temp_path is already completely downloaded and matches checksum
    if temp_path.exists() and expected_sha256:
        try:
            actual_sha = compute_sha256(temp_path)
            if actual_sha.lower() == expected_sha256.lower():
                print(f"[Downloader] Found completed part file for {dest_path.name}. Finalizing...")
                if _atomic_replace_with_retry(temp_path, dest_path):
                    return True
        except Exception as e:
            print(f"[Downloader] Error checking part file: {e}")

    # Query total content length via HEAD request
    total_size = 0
    try:
        head_req = urllib.request.Request(url, headers={"User-Agent": DEFAULT_USER_AGENT}, method="HEAD")
        with urllib.request.urlopen(head_req, timeout=12) as head_resp:
            total_size = int(head_resp.headers.get("Content-Length", 0))
    except Exception as e:
        print(f"[Downloader] Could not determine total size via HEAD: {e}")

    for attempt in range(1, max_retries + 1):
        if cancel_check and cancel_check():
            return False

        try:
            downloaded = 0
            open_mode = "wb"
            headers = {"User-Agent": DEFAULT_USER_AGENT}

            # Resume partial download if .part file already exists
            if temp_path.exists():
                existing_bytes = temp_path.stat().st_size
                if total_size > 0 and existing_bytes > total_size:
                    temp_path.unlink()
                elif existing_bytes > 0:
                    headers["Range"] = f"bytes={existing_bytes}-"
                    downloaded = existing_bytes
                    open_mode = "ab"
                    print(f"[Downloader] Resuming download at byte {existing_bytes}/{total_size} (attempt {attempt}/{max_retries})...")

            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=20) as resp:
                status = getattr(resp, "status", 200)

                if status == 206:
                    content_range = resp.headers.get("Content-Range", "")
                    if content_range and "/" in content_range:
                        try:
                            total_size = int(content_range.split("/")[-1])
                        except Exception:
                            pass
                elif status == 200:
                    if open_mode == "ab":
                        open_mode = "wb"
                        downloaded = 0
                    total_size = int(resp.headers.get("Content-Length", total_size))

                start_time = time.time()
                last_calc_time = start_time
                last_calc_bytes = downloaded
                speed_mb = 0.0

                with open(temp_path, open_mode) as f:
                    while True:
                        if cancel_check and cancel_check():
                            return False

                        chunk = resp.read(chunk_size)
                        if not chunk:
                            break

                        f.write(chunk)
                        downloaded += len(chunk)

                        now = time.time()
                        time_diff = now - last_calc_time
                        if time_diff >= 0.5:
                            bytes_diff = downloaded - last_calc_bytes
                            speed_mb = (bytes_diff / (1024 * 1024)) / time_diff
                            last_calc_time = now
                            last_calc_bytes = downloaded

                        if progress_callback:
                            progress_callback(downloaded, total_size, speed_mb)

            time.sleep(0.2)

            # Check if download is truly complete
            if total_size > 0 and temp_path.exists() and temp_path.stat().st_size < total_size:
                print(f"[Downloader] Incomplete download ({temp_path.stat().st_size}/{total_size}), resuming next chunk...")
                continue

            # Verify SHA256 if provided
            if expected_sha256 and temp_path.exists():
                actual_sha = compute_sha256(temp_path)
                if actual_sha.lower() != expected_sha256.lower():
                    print(f"[Downloader] Checksum mismatch! Expected {expected_sha256}, got {actual_sha}")
                    try:
                        temp_path.unlink()
                    except Exception:
                        pass
                    if attempt < max_retries:
                        time.sleep(1)
                        continue
                    return False

            # Atomic rename with retry for Windows Defender file locking
            if not _atomic_replace_with_retry(temp_path, dest_path):
                print(f"[Downloader] Could not replace {temp_path} -> {dest_path} due to file lock.")
                return False

            return True

        except Exception as e:
            print(f"[Downloader] Network error on attempt {attempt}/{max_retries} for {url}: {e}")
            if cancel_check and cancel_check():
                return False
            if attempt < max_retries:
                backoff = min(attempt * 1.5, 6.0)
                print(f"[Downloader] Auto-retrying with resume in {backoff:.1f}s...")
                time.sleep(backoff)
            else:
                print(f"[Downloader] Failed after {max_retries} attempts.")
                return False

    return False


def extract_zip(
    zip_path: Path,
    target_dir: Path,
    progress_callback: Optional[Callable[[int, int], None]] = None,
) -> bool:
    """Safely extract a zip archive to the target directory with progress reporting."""
    target_dir.mkdir(parents=True, exist_ok=True)
    try:
        with zipfile.ZipFile(zip_path, "r") as zf:
            members = zf.infolist()
            total = len(members)
            for i, member in enumerate(members):
                zf.extract(member, target_dir)
                if progress_callback and (i % 250 == 0 or i == total - 1):
                    progress_callback(i + 1, total)
        return True
    except Exception as e:
        print(f"[Downloader] Extract error {zip_path}: {e}")
        return False

