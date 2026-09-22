@echo off
chcp 65001 >nul
title 89TTS Studio — Khởi Động Trực Tiếp

echo ========================================================
echo   89TTS STUDIO — KHỞI ĐỘNG TRỰC TIẾP
echo ========================================================
echo.

cd /d "%~dp0"
set "OMNIVOICE_ROOT=%~dp0"
set "PYTHONPATH=%~dp0app;%~dp0;%PYTHONPATH%"
set "PYTORCH_CUDA_ALLOC_CONF=expandable_segments:True"

:: Tự động loại bỏ pyvenv.cfg nếu có để chuyển runtime sang portable độc lập
if exist "%~dp0runtime\pyvenv.cfg" (
    del /f /q "%~dp0runtime\pyvenv.cfg" >nul 2>&1
)

if exist "%~dp0hf_cache" (
    set "HF_HOME=%~dp0hf_cache"
    set "HF_HUB_CACHE=%~dp0hf_cache"
)

if exist "%~dp0runtime\pythonw.exe" (
    start "" "%~dp0runtime\pythonw.exe" "%~dp0app\main_secure.pyc"
    exit /b 0
)

if exist "%~dp0runtime\python.exe" (
    "%~dp0runtime\python.exe" "%~dp0app\main_secure.pyc"
    if %errorlevel% neq 0 (
        echo.
        echo [LỖI] Ứng dụng thoát với mã lỗi: %errorlevel%
        pause
    )
    exit /b 0
)

echo [LỖI] Không tìm thấy môi trường Python tại runtime\python.exe.
echo Vui lòng chạy file 89TTS_Launcher.exe để kiểm tra và khôi phục!
echo.
pause
