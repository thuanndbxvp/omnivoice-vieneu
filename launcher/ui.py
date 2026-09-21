# -*- coding: utf-8 -*-
"""Splash and Onboarding Wizard UI for OmniVoice Launcher."""

import sys
from pathlib import Path
from PySide6.QtCore import Qt, QTimer, Signal, Slot, QPoint
from PySide6.QtGui import QColor, QFont, QIcon, QLinearGradient, QPainter, QPainterPath, QPixmap
from PySide6.QtWidgets import (
    QApplication,
    QFrame,
    QHBoxLayout,
    QLabel,
    QProgressBar,
    QPushButton,
    QVBoxLayout,
    QWidget,
)


class LauncherWindow(QWidget):
    """Modern frameless launcher splash screen with dark glassmorphism theme."""

    cancel_requested = Signal()
    repair_requested = Signal()

    def __init__(self, app_icon_path: Path = None):
        super().__init__()
        self.setWindowTitle("89TTS — 89 Global Media")
        self.setWindowFlags(Qt.WindowType.FramelessWindowHint | Qt.WindowType.Window)
        self.setAttribute(Qt.WidgetAttribute.WA_TranslucentBackground)
        self.setFixedSize(580, 380)

        self._drag_pos = QPoint()
        self._init_ui(app_icon_path)

    def _init_ui(self, app_icon_path: Path):
        # Background card frame
        card = QFrame(self)
        card.setGeometry(10, 10, 560, 360)
        card.setObjectName("main_card")
        card.setStyleSheet("""
            QFrame#main_card {
                background: qlineargradient(x1:0, y1:0, x2:1, y2:1, stop:0 #12141a, stop:1 #1c202a);
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 16px;
            }
            QLabel {
                color: #e2e8f0;
                font-family: 'Segoe UI', system-ui, sans-serif;
            }
            QProgressBar {
                background-color: #1a1e28;
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 7px;
                text-align: center;
                color: transparent;
                height: 14px;
            }
            QProgressBar::chunk {
                background: qlineargradient(x1:0, y1:0, x2:1, y2:0, stop:0 #3b82f6, stop:1 #06b6d4);
                border-radius: 6px;
            }
            QPushButton#btn_close {
                background: transparent;
                border: none;
                color: #64748b;
                font-size: 16px;
                font-weight: bold;
                border-radius: 12px;
            }
            QPushButton#btn_close:hover {
                color: #ef4444;
                background: rgba(239, 68, 68, 0.15);
            }
            QPushButton#btn_repair {
                background: rgba(255, 255, 255, 0.06);
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 8px;
                color: #94a3b8;
                padding: 4px 12px;
                font-size: 11px;
            }
            QPushButton#btn_repair:hover {
                background: rgba(255, 255, 255, 0.12);
                color: #f1f5f9;
            }
        """)

        layout = QVBoxLayout(card)
        layout.setContentsMargins(28, 20, 28, 24)
        layout.setSpacing(14)

        # Top Bar (Close button)
        top_bar = QHBoxLayout()
        top_bar.addStretch()
        self.btn_close = QPushButton("✕", card)
        self.btn_close.setObjectName("btn_close")
        self.btn_close.setFixedSize(28, 28)
        self.btn_close.clicked.connect(self.close)
        top_bar.addWidget(self.btn_close)
        layout.addLayout(top_bar)

        # Header with Logo & Title
        header_layout = QHBoxLayout()
        header_layout.setSpacing(16)

        # Logo
        self.lbl_logo = QLabel(card)
        self.lbl_logo.setFixedSize(64, 64)
        if app_icon_path and app_icon_path.exists():
            pix = QPixmap(str(app_icon_path)).scaled(
                64, 64, Qt.AspectRatioMode.KeepAspectRatio, Qt.TransformationMode.SmoothTransformation
            )
            self.lbl_logo.setPixmap(pix)
        else:
            self.lbl_logo.setStyleSheet("background: #2563eb; border-radius: 12px;")
        header_layout.addWidget(self.lbl_logo)

        # Title block
        title_box = QVBoxLayout()
        title_box.setSpacing(3)
        self.lbl_title = QLabel("89TTS — 89 Global Media", card)
        self.lbl_title.setStyleSheet("font-size: 20px; font-weight: 700; color: #f8fafc;")
        title_box.addWidget(self.lbl_title)

        self.lbl_subtitle = QLabel("Hệ thống khởi động & cập nhật thông minh", card)
        self.lbl_subtitle.setStyleSheet("font-size: 12px; color: #94a3b8;")
        title_box.addWidget(self.lbl_subtitle)
        header_layout.addLayout(title_box)
        header_layout.addStretch()
        layout.addLayout(header_layout)

        layout.addSpacing(6)

        # Status block
        self.lbl_status = QLabel("Đang quét cấu hình phần cứng...", card)
        self.lbl_status.setStyleSheet("font-size: 13px; font-weight: 600; color: #38bdf8;")
        layout.addWidget(self.lbl_status)

        # Progress bar
        self.progress_bar = QProgressBar(card)
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        layout.addWidget(self.progress_bar)

        # Details row (Download speed + remaining size)
        details_layout = QHBoxLayout()
        self.lbl_details = QLabel("", card)
        self.lbl_details.setStyleSheet("font-size: 11px; color: #64748b;")
        details_layout.addWidget(self.lbl_details)

        details_layout.addStretch()

        self.lbl_percent = QLabel("0%", card)
        self.lbl_percent.setStyleSheet("font-size: 12px; font-weight: 600; color: #94a3b8;")
        details_layout.addWidget(self.lbl_percent)
        layout.addLayout(details_layout)

        layout.addStretch()

        # Footer row (Hardware info + Repair button)
        footer_layout = QHBoxLayout()
        self.lbl_hw_info = QLabel("Khởi tạo hệ thống...", card)
        self.lbl_hw_info.setStyleSheet("font-size: 11px; color: #64748b;")
        footer_layout.addWidget(self.lbl_hw_info)

        footer_layout.addStretch()

        self.btn_repair = QPushButton("Sửa lỗi / Tải lại", card)
        self.btn_repair.setObjectName("btn_repair")
        self.btn_repair.clicked.connect(self.repair_requested.emit)
        footer_layout.addWidget(self.btn_repair)
        layout.addLayout(footer_layout)

    # -------------------------------------------------------------
    # Window Draggable Implementation
    # -------------------------------------------------------------
    def mousePressEvent(self, event):
        if event.button() == Qt.MouseButton.LeftButton:
            self._drag_pos = event.globalPosition().toPoint() - self.frameGeometry().topLeft()
            event.accept()

    def mouseMoveEvent(self, event):
        if event.buttons() == Qt.MouseButton.LeftButton:
            self.move(event.globalPosition().toPoint() - self.drag_offset() if hasattr(self, 'drag_offset') else event.globalPosition().toPoint() - self._drag_pos)
            event.accept()

    # -------------------------------------------------------------
    # UI Update Slots
    # -------------------------------------------------------------
    @Slot(str)
    def set_status(self, text: str):
        self.lbl_status.setText(text)

    @Slot(str)
    def set_hw_info(self, text: str):
        self.lbl_hw_info.setText(text)

    @Slot(int, str)
    def set_progress(self, percent: int, details: str = ""):
        self.progress_bar.setValue(percent)
        self.lbl_percent.setText(f"{percent}%")
        if details:
            self.lbl_details.setText(details)
