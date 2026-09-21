# -*- coding: utf-8 -*-
"""Upload secured app-v1.0.0.zip and manifest.json to Cloudflare R2."""

from pathlib import Path
from r2_tool import load_r2_config, get_s3_client, upload_file

def main():
    repo = Path("D:/CodeApp/omnivoice-vieneu")
    dist_r2 = repo / "dist_r2"
    
    app_zip = dist_r2 / "app-v1.0.0.zip"
    manifest = dist_r2 / "manifest.json"
    
    if not app_zip.exists():
        raise FileNotFoundError(f"Missing {app_zip}")
    if not manifest.exists():
        raise FileNotFoundError(f"Missing {manifest}")
        
    config = load_r2_config()
    s3 = get_s3_client(config)
    bucket = config["bucket"]
    
    print(f"Target bucket: {bucket}")
    
    # 1. Upload app-v1.0.0.zip
    upload_file(s3, app_zip, bucket, "app/app-v1.0.0.zip", content_type="application/zip")
    
    # 2. Upload manifest.json
    upload_file(s3, manifest, bucket, "manifest.json", content_type="application/json")
    
    print("\nAll secured release assets uploaded successfully to R2!")

if __name__ == "__main__":
    main()
