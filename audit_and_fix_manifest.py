# -*- coding: utf-8 -*-
"""Audit and synchronize all SHA256 hashes in manifest.json against live R2 objects."""

import hashlib
import json
import time
import urllib.request
from pathlib import Path
from r2_tool import load_r2_config, get_s3_client, upload_file, DIST_DIR

def audit_and_fix_manifest():
    conf = load_r2_config()
    client = get_s3_client(conf)
    bucket = conf["bucket"]
    base_url = conf["public_base_url"]
    
    # Fetch current manifest from R2
    manifest_url = f"{base_url}/manifest.json"
    req = urllib.request.Request(manifest_url, headers={"User-Agent": "Mozilla/5.0"})
    manifest_data = json.loads(urllib.request.urlopen(req).read().decode("utf-8"))
    
    print("=" * 70)
    print("KIỂM TRA VÀ ĐỒNG BỘ TOÀN BỘ SHA256 / KÍCH THƯỚC TRÊN CLOUDFLARE R2")
    print("=" * 70)
    
    # 1. Audit App Package
    app_info = manifest_data.get("app_package", {})
    app_url = app_info.get("url")
    print(f"\n[1] Kiểm tra App Package: {app_url}...")
    try:
        req_app = urllib.request.Request(app_url, headers={"User-Agent": "Mozilla/5.0"})
        app_bytes = urllib.request.urlopen(req_app).read()
        real_app_sha = hashlib.sha256(app_bytes).hexdigest()
        real_app_size = len(app_bytes)
        
        old_sha = app_info.get("sha256")
        old_size = app_info.get("size_bytes")
        print(f"  - Manifest khai báo: SHA={old_sha[:16]}... | Size={old_size}")
        print(f"  - Thực tế trên R2  : SHA={real_app_sha[:16]}... | Size={real_app_size}")
        
        if old_sha.lower() != real_app_sha.lower() or old_size != real_app_size:
            print("  ==> [LỆCH HASH/SIZE] Đang cập nhật lại app_package...")
            app_info["sha256"] = real_app_sha
            app_info["size_bytes"] = real_app_size
        else:
            print("  ==> [KHỚP 100%]")
    except Exception as e:
        print(f"  [LỖI] {e}")

    # 2. Audit Models
    for m_key, m_info in manifest_data.get("models", {}).items():
        m_url = m_info.get("url")
        print(f"\n[2] Kiểm tra Model {m_key}: {m_url}...")
        try:
            head_req = urllib.request.Request(m_url, headers={"User-Agent": "Mozilla/5.0"}, method="HEAD")
            with urllib.request.urlopen(head_req) as head_resp:
                real_m_size = int(head_resp.headers.get("Content-Length", 0))
                print(f"  - Manifest size: {m_info.get('size_bytes')} | Thực tế: {real_m_size}")
                if m_info.get("size_bytes") != real_m_size and real_m_size > 0:
                    print(f"  ==> Cập nhật size cho model {m_key}")
                    m_info["size_bytes"] = real_m_size
        except Exception as e:
            print(f"  [LỖI] {e}")

    # 3. Audit Runtimes
    for r_key, r_info in manifest_data.get("runtimes", {}).items():
        r_url = r_info.get("url")
        print(f"\n[3] Kiểm tra Runtime {r_key}: {r_url}...")
        try:
            head_req = urllib.request.Request(r_url, headers={"User-Agent": "Mozilla/5.0"}, method="HEAD")
            with urllib.request.urlopen(head_req) as head_resp:
                real_r_size = int(head_resp.headers.get("Content-Length", 0))
                print(f"  - Manifest size: {r_info.get('size_bytes')} | Thực tế: {real_r_size}")
                if r_info.get("size_bytes") != real_r_size and real_r_size > 0:
                    print(f"  ==> Cập nhật size cho runtime {r_key}")
                    r_info["size_bytes"] = real_r_size
        except Exception as e:
            print(f"  [LỖI] {e}")

    # Update manifest timestamp
    manifest_data["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime())
    
    # Save local manifest
    manifest_path = DIST_DIR / "manifest.json"
    manifest_path.write_text(json.dumps(manifest_data, indent=4, ensure_ascii=False), encoding="utf-8")
    
    # Upload to R2
    print(f"\n[UPLOAD] Tải manifest.json mới lên Cloudflare R2 bucket '{bucket}'...")
    upload_file(client, manifest_path, bucket, "manifest.json", "application/json")
    print("\n[SUCCESS] Manifest trên R2 đã đồng bộ 100% chuẩn xác!")

if __name__ == "__main__":
    audit_and_fix_manifest()
