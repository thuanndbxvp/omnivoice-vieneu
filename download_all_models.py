# -*- coding: utf-8 -*-
"""Download and verify all AI models for OmniVoice & VieNeu-TTS."""

import os
import sys
import time

# Set utf-8 stdout
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

# Ensure imageio_ffmpeg sets PATH for pydub
try:
    import imageio_ffmpeg
    ffmpeg_dir = os.path.dirname(imageio_ffmpeg.get_ffmpeg_exe())
    if ffmpeg_dir not in os.environ.get("PATH", ""):
        os.environ["PATH"] = ffmpeg_dir + os.pathsep + os.environ.get("PATH", "")
except Exception as e:
    print(f"[WARN] ffmpeg setup: {e}")

print("=" * 60)
print("BAT DAU KIEM TRA VA TAI TOAN BO MODEL CHO OMNIVOICE & VIENEU")
print("=" * 60)

# -------------------------------------------------------------
# 1. Model OmniVoice (Cuc bo tai omnivoice_model)
# -------------------------------------------------------------
print("\n[1/3] Kiem tra OmniVoice Model...")
from src.core.engine import _resolve_model_source, DEFAULT_MODEL_ID
resolved = _resolve_model_source(DEFAULT_MODEL_ID)
print(f"  -> Thu muc mo hinh: {resolved}")
model_file = os.path.join(resolved, "model.safetensors")
if os.path.exists(model_file):
    size_mb = os.path.getsize(model_file) / (1024 * 1024)
    print(f"  -> model.safetensors ton tai ({size_mb:.1f} MB)")
else:
    print("  -> CHUA CO model.safetensors! Tien hanh tai tu HuggingFace Hub (k2-fsa/OmniVoice)...")
    from huggingface_hub import snapshot_download
    snapshot_download(repo_id="k2-fsa/OmniVoice", local_dir=resolved)
    print("  -> Da tai xong OmniVoice model!")

# -------------------------------------------------------------
# 2. Model VieNeu-TTS (pnnbao-ump/VieNeu-TTS-v3-Turbo)
# -------------------------------------------------------------
print("\n[2/3] Kiem tra va tai mo hinh VieNeu-TTS (mode='v3turbo')...")
try:
    from vieneu import Vieneu
    t0 = time.time()
    m_vieneu = Vieneu(mode="v3turbo")
    print(f"  -> VieNeu tai thanh cong trong {time.time() - t0:.2f}s!")
except Exception as e:
    print(f"  -> [LOI] VieNeu load: {e}")
    from huggingface_hub import snapshot_download
    print("  -> Thu tai thu cong snapshot_download pnnbao-ump/VieNeu-TTS-v3-Turbo...")
    snapshot_download(repo_id="pnnbao-ump/VieNeu-TTS-v3-Turbo")
    from vieneu import Vieneu
    m_vieneu = Vieneu(mode="v3turbo")
    print("  -> VieNeu tai thanh cong sau khi snapshot!")

# -------------------------------------------------------------
# 3. Model Whisper ASR (openai/whisper-large-v3-turbo)
# -------------------------------------------------------------
print("\n[3/3] Kiem tra va tai mo hinh Whisper ASR (cho tinh nang clone giong)...")
try:
    from transformers import pipeline
    t0 = time.time()
    pipe = pipeline("automatic-speech-recognition", model="openai/whisper-large-v3-turbo")
    print(f"  -> Whisper ASR tai thanh cong trong {time.time() - t0:.2f}s!")
except Exception as e:
    print(f"  -> [CANH BAO] Tai Whisper: {e}")

# -------------------------------------------------------------
# 4. Kiem tra tai thu OmniVoice engine
# -------------------------------------------------------------
print("\n[4/4] Kiem tra tai thu VoiceEngine...")
try:
    from src.core.engine import VoiceEngine
    engine = VoiceEngine()
    print("  -> Khoi tao VoiceEngine OK, thu load_model (load_asr=False cho nhanh)...")
    t0 = time.time()
    engine.load_model(device="cpu", load_asr=False)
    print(f"  -> OmniVoice load_model thanh cong tren CPU trong {time.time() - t0:.2f}s!")
except Exception as e:
    print(f"  -> [LOI] VoiceEngine load: {e}")

print("\n" + "=" * 60)
print("HOAN TAT TAT CA CAC BUOC KIEM TRA VA TAI MODEL!")
print("=" * 60)
