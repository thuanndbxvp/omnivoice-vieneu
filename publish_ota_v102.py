# -*- coding: utf-8 -*-
"""Bump version to 1.0.2 and publish app-v1.0.2.zip with 89gm.id.vn license domain to Cloudflare R2."""

import hashlib
import json
import shutil
import time
from pathlib import Path
from r2_tool import load_r2_config, get_s3_client, upload_file, DIST_DIR

def bump_to_102():
    conf = load_r2_config()
    client = get_s3_client(conf)
    bucket = conf["bucket"]
    base_url = conf["public_base_url"]
    
    src_zip = DIST_DIR / "app-v1.0.0.zip"
    dst_zip = DIST_DIR / "app-v1.0.2.zip"
    
    print(f"Creating {dst_zip.name} from {src_zip.name}...")
    shutil.copy2(src_zip, dst_zip)
    
    sha256 = hashlib.sha256(dst_zip.read_bytes()).hexdigest()
    size_bytes = dst_zip.stat().st_size
    print(f"SHA256: {sha256}")
    print(f"Size: {size_bytes} bytes ({size_bytes / (1024*1024):.2f} MB)")
    
    # 1. Upload app-v1.0.2.zip to R2
    print(f"\n[1/2] Uploading app-v1.0.2.zip to R2...")
    upload_file(client, dst_zip, bucket, "app/app-v1.0.2.zip", "application/zip")
    
    # 2. Update manifest.json
    print(f"\n[2/2] Updating manifest.json to v1.0.2...")
    manifest_path = DIST_DIR / "manifest.json"
    manifest = json.loads(manifest_path.read_text("utf-8"))
    
    manifest["version"] = "1.0.2"
    manifest["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime())
    manifest["app_package"] = {
        "version": "1.0.2",
        "filename": "app-v1.0.2.zip",
        "url": f"{base_url}/app/app-v1.0.2.zip",
        "sha256": sha256,
        "size_bytes": size_bytes
    }
    
    manifest_path.write_text(json.dumps(manifest, indent=4, ensure_ascii=False), encoding="utf-8")
    upload_file(client, manifest_path, bucket, "manifest.json", "application/json")
    
    print("\n[SUCCESS] Đã kích hoạt cơ chế tự động cập nhật OTA v1.0.2 thành công!")
    print("Mọi Launcher của khách hàng khi mở lên sẽ TỰ ĐỘNG nhận diện domain check license 89gm.id.vn!")

if __name__ == "__main__":
    bump_to_102()
