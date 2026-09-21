# -*- coding: utf-8 -*-
"""Pack the new 89TTS_Launcher build into 89TTS_Setup_v1.0.0.zip and upload to Cloudflare R2."""

import zipfile
from pathlib import Path
from r2_tool import load_r2_config, get_s3_client, upload_file

def main():
    repo = Path("D:/CodeApp/omnivoice-vieneu")
    launcher_dir = repo / "dist_launcher" / "89TTS_Launcher"
    dist_r2 = repo / "dist_r2"
    dist_r2.mkdir(parents=True, exist_ok=True)
    
    setup_zip = dist_r2 / "89TTS_Setup_v1.0.0.zip"
    if setup_zip.exists():
        setup_zip.unlink()
        
    print(f"Packing fresh Launcher from {launcher_dir} -> {setup_zip.name}...")
    
    with zipfile.ZipFile(setup_zip, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        # 1. Add 89TTS_Launcher.exe
        exe_path = launcher_dir / "89TTS_Launcher.exe"
        if not exe_path.exists():
            raise FileNotFoundError(f"Missing {exe_path}")
        zf.write(exe_path, "89TTS_Launcher/89TTS_Launcher.exe")
        
        # 2. Add _internal/
        internal_dir = launcher_dir / "_internal"
        for item in internal_dir.rglob("*"):
            if item.is_file():
                rel = item.relative_to(launcher_dir)
                zf.write(item, f"89TTS_Launcher/{rel}".replace("\\", "/"))
                
    size_mb = setup_zip.stat().st_size / (1024 * 1024)
    print(f"Setup archive created successfully: {size_mb:.2f} MB")
    
    # Upload to Cloudflare R2
    config = load_r2_config()
    s3 = get_s3_client(config)
    bucket = config["bucket"]
    
    print(f"\nUploading {setup_zip.name} to R2 bucket '{bucket}'...")
    upload_file(s3, setup_zip, bucket, "89TTS_Setup_v1.0.0.zip", content_type="application/zip")
    
    print("\n[SUCCESS] 89TTS_Setup_v1.0.0.zip is now updated and live on R2!")
    print("URL: https://pub-809dc95ff1ec45b2a32a971be2eb83e0.r2.dev/89TTS_Setup_v1.0.0.zip")

if __name__ == "__main__":
    main()
