# -*- coding: utf-8 -*-
"""Hardware detection and runtime selection for OmniVoice Launcher.

Implements the 3-profile architecture based on NVIDIA Compute Capability (CC):
1. cuda-modern: Compute Capability >= 7.5 (Turing -> Blackwell: GTX 16xx, RTX 20/30/40/50 series) + Driver >= 528.33 (cu128)
2. cuda-legacy: Compute Capability 5.2 -> 7.0 (Maxwell, Pascal, Volta: GTX 9xx, GTX 10xx, MX150/250) (cu126)
3. cpu-universal: Non-NVIDIA (Intel, AMD, VM, Office PCs) or failed GPU test

Note: VRAM is decoupled from CUDA version and used solely for runtime execution modes:
- Normal (>= 8GB)
- Low-VRAM (4GB - 8GB)
- Very-Low-VRAM (< 4GB / CPU fallback)
"""

import os
import platform
import re
import subprocess
import sys
from typing import Dict, Any, Tuple

# Ensure UTF-8 stdout/stderr
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")


def get_compute_capability(gpu_name: str) -> float:
    """Determine NVIDIA Compute Capability from GPU Name."""
    name = gpu_name.upper()

    # Blackwell (RTX 50xx) -> CC 10.0
    if re.search(r"\b(RTX\s*50\d0|B200|B100)\b", name):
        return 10.0

    # Ada Lovelace (RTX 40xx, RTX 6000 Ada...) -> CC 8.9
    if re.search(r"\b(RTX\s*40\d0|ADA|L40|L4)\b", name):
        return 8.9

    # Ampere (RTX 30xx, A100, A6000, A5000, A4000, A2000...) -> CC 8.6 / 8.0
    if re.search(r"\b(RTX\s*30\d0|A100|A800|A6000|A5000|A4000|A2000)\b", name):
        return 8.6

    # Turing (RTX 20xx, GTX 16xx, T4, Quadro RTX...) -> CC 7.5
    if re.search(r"\b(RTX\s*20\d0|GTX\s*16\d0|T4|TITAN\s*RTX)\b", name):
        return 7.5

    # Volta (Titan V, V100, Quadro GV100) -> CC 7.0
    if re.search(r"\b(V100|TITAN\s*V|GV100)\b", name):
        return 7.0

    # Pascal (GTX 10xx, MX150, MX250, MX330, P100, P40...) -> CC 6.1 / 6.0
    if re.search(r"\b(GTX\s*10\d0|MX150|MX250|MX330|P100|P40|P4)\b", name):
        return 6.1

    # Maxwell (GTX 9xx, GTX 750 Ti, Titan X Maxwell, M40...) -> CC 5.2 / 5.0
    if re.search(r"\b(GTX\s*9\d0|GTX\s*750|TITAN\s*X\b|M40)\b", name):
        return 5.2

    # Generic RTX fallback -> >= 7.5
    if "RTX" in name:
        return 7.5

    # Generic GTX fallback
    if "GTX" in name:
        return 6.1

    # Default unknown NVIDIA
    return 6.0


def determine_vram_mode(vram_gb: float) -> Tuple[str, str]:
    """Determine execution mode based on VRAM capacity (decoupled from CUDA runtime)."""
    if vram_gb >= 8.0:
        return "normal", "Đủ bộ nhớ VRAM cao (>= 8GB) — Chế độ tiêu chuẩn chất lượng cao nhất."
    elif vram_gb >= 4.0:
        return "low-vram", "Bộ nhớ VRAM trung bình (4GB - 8GB) — Kích hoạt tối ưu phân mảnh FP16."
    else:
        return "very-low-vram", "Bộ nhớ VRAM thấp (< 4GB) — Kích hoạt cơ chế chia nhỏ câu và offload CPU khi cần."


def detect_hardware() -> Dict[str, Any]:
    """Detect system hardware and select optimal runtime profile and execution mode."""
    info = {
        "os": platform.platform(),
        "has_nvidia_gpu": False,
        "gpu_name": "Không có GPU NVIDIA",
        "compute_capability": 0.0,
        "driver_version": 0.0,
        "driver_str": "N/A",
        "vram_gb": 0.0,
        "recommended_profile": "cpu-universal",
        "cuda_version_target": "CPU",
        "vram_mode": "cpu",
        "vram_mode_desc": "Chạy suy luận hoàn toàn trên CPU.",
        "reason": "Mặc định cấu hình CPU phổ thông.",
    }

    # 1. Query NVIDIA GPU via nvidia-smi
    try:
        cmd = [
            "nvidia-smi",
            "--query-gpu=name,memory.total,driver_version",
            "--format=csv,noheader,nounits",
        ]
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=3)
        if res.returncode == 0 and res.stdout.strip():
            lines = res.stdout.strip().split("\n")
            if lines:
                parts = [p.strip() for p in lines[0].split(",")]
                if len(parts) >= 3:
                    gpu_name = parts[0]
                    vram_mb = float(parts[1])
                    driver_str = parts[2]
                    vram_gb = round(vram_mb / 1024, 1)

                    try:
                        driver_ver = float(driver_str.split(".")[0] + "." + driver_str.split(".")[1])
                    except Exception:
                        driver_ver = 500.0

                    cc = get_compute_capability(gpu_name)
                    vram_mode, vram_desc = determine_vram_mode(vram_gb)

                    info["has_nvidia_gpu"] = True
                    info["gpu_name"] = gpu_name
                    info["driver_version"] = driver_ver
                    info["driver_str"] = driver_str
                    info["vram_gb"] = vram_gb
                    info["compute_capability"] = cc
                    info["vram_mode"] = vram_mode
                    info["vram_mode_desc"] = vram_desc

                    # Driver check: CUDA 12.x on Windows requires Driver >= 528.33
                    driver_supports_cuda12 = driver_ver >= 528.0

                    if cc >= 7.5:
                        if driver_supports_cuda12:
                            info["recommended_profile"] = "cuda-modern"
                            info["cuda_version_target"] = "cu128"
                            info["reason"] = f"Phát hiện {gpu_name} (CC {cc} >= 7.5, Driver {driver_str}). Khuyến nghị runtime hiện đại PyTorch cu128 (hỗ trợ Turing -> Blackwell RTX 50xx)."
                        else:
                            info["recommended_profile"] = "cpu-universal"
                            info["cuda_version_target"] = "CPU"
                            info["reason"] = f"Phát hiện {gpu_name} (CC {cc}) nhưng Driver quá cũ ({driver_str} < 528.33). Vui lòng cập nhật Driver NVIDIA để kích hoạt tăng tốc GPU."
                    elif cc >= 5.2:
                        if driver_supports_cuda12:
                            info["recommended_profile"] = "cuda-legacy"
                            info["cuda_version_target"] = "cu126"
                            info["reason"] = f"Phát hiện {gpu_name} (CC {cc} Maxwell/Pascal/Volta, Driver {driver_str}). Khuyến nghị runtime kế thừa PyTorch cu126."
                        else:
                            info["recommended_profile"] = "cpu-universal"
                            info["cuda_version_target"] = "CPU"
                            info["reason"] = f"Phát hiện {gpu_name} (CC {cc}) nhưng Driver cũ ({driver_str} < 528.33). Vui lòng cập nhật Driver NVIDIA."
                    else:
                        info["recommended_profile"] = "cpu-universal"
                        info["cuda_version_target"] = "CPU"
                        info["reason"] = f"Phát hiện {gpu_name} có kiến trúc quá cũ (CC {cc} < 5.2). Chuyển sang chế độ CPU đa năng."

                    return info
    except Exception:
        pass

    # 2. Query Windows WMI fallback (for non-NVIDIA or when nvidia-smi is unavailable)
    try:
        wmi_cmd = ["powershell", "-Command", "Get-CimInstance Win32_VideoController | Select-Object -ExpandProperty Name"]
        res = subprocess.run(wmi_cmd, capture_output=True, text=True, timeout=4)
        if res.returncode == 0 and res.stdout.strip():
            names = [n.strip() for n in res.stdout.strip().split("\n") if n.strip()]
            info["gpu_name"] = ", ".join(names)
            info["recommended_profile"] = "cpu-universal"
            info["cuda_version_target"] = "CPU"
            info["reason"] = f"Phần cứng đồ họa: {info['gpu_name']} (Intel/AMD hoặc máy văn phòng). Khuyến nghị CPU đa năng ổn định."
    except Exception:
        pass

    return info


if __name__ == "__main__":
    import pprint
    pprint.pprint(detect_hardware())
