@echo off
title 89TTS Launcher - 89 Global Media
cd /d "%~dp0"
.\venv\Scripts\python.exe -m launcher.main %*
