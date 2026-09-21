# -*- coding: utf-8 -*-
"""Generate high-quality Applogo.png and multi-resolution favicon.ico from app.jpg."""

import sys
from pathlib import Path
from PIL import Image

if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

BASE_DIR = Path(__file__).resolve().parent
SRC_JPG = BASE_DIR / "app.jpg"
DST_PNG = BASE_DIR / "Applogo.png"
DST_ICO = BASE_DIR / "favicon.ico"

print("=" * 60)
print("CHUYỂN ĐỔI APP.JPG THÀNH APPLOGO.PNG VÀ FAVICON.ICO")
print("=" * 60)

if not SRC_JPG.exists():
    raise FileNotFoundError(f"Không tìm thấy tệp: {SRC_JPG}")

img = Image.open(SRC_JPG)
print(f"Ảnh gốc: {SRC_JPG.name} | Kích thước: {img.size} | Định dạng: {img.format} | Mode: {img.mode}")

# Ensure RGB
if img.mode != "RGB":
    img = img.convert("RGB")

# 1. Save Applogo.png (High quality, 1000x1000)
img.save(DST_PNG, format="PNG", optimize=True)
print(f"[1/3] Đã tạo Applogo.png thành công! Kích thước: {DST_PNG.stat().st_size / 1024:.2f} KB")

# Also copy to dist_launcher if exists
dist_launcher_dir = BASE_DIR / "dist_launcher" / "OmniVoiceLauncher"
if dist_launcher_dir.exists():
    img.save(dist_launcher_dir / "Applogo.png", format="PNG", optimize=True)
    print(f"  -> Đã cập nhật Applogo.png vào thư mục launcher bundle!")

# 2. Save favicon.ico with full multi-resolution icon sizes for Windows
# Windows standard icon sizes: 16, 24, 32, 48, 64, 128, 256
ico_sizes = [(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)]
img.save(DST_ICO, format="ICO", sizes=ico_sizes)
print(f"[2/3] Đã tạo favicon.ico thành công! Kích thước: {DST_ICO.stat().st_size / 1024:.2f} KB ({len(ico_sizes)} phân giải)")

if dist_launcher_dir.exists():
    img.save(dist_launcher_dir / "favicon.ico", format="ICO", sizes=ico_sizes)
    print(f"  -> Đã cập nhật favicon.ico vào thư mục launcher bundle!")

print("\n[3/3] Hoàn tất tạo logo và icon mới cho toàn bộ ứng dụng!")
print("=" * 60)
