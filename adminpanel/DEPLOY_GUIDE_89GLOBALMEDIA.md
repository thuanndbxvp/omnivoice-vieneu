# HƯỚNG DẪN TRIỂN KHAI HỆ THỐNG ADMIN PANEL & LICENSE SERVER (89GLOBALMEDIA.ONLINE)

Tài liệu này hướng dẫn chi tiết cách triển khai toàn bộ hệ thống quản lý bản quyền (Admin Panel) và cơ sở dữ liệu mới sạch 100% cho thương hiệu **89 Global Media**.

---

## 1. Cơ sở Dữ liệu (Database) Đã Xóa Trắng Sạch 100%

- **Tệp SQL khởi tạo sạch**: `db/clean_init_89globalmedia.sql` (và `db/gomhuong1_license.sql`).
- **Tình trạng**: Đã xóa sạch toàn bộ licenses cũ, thiết bị cũ, logs, bộ đếm của khách hàng cũ.
- **Dữ liệu danh mục ứng dụng đã nạp sẵn**:
  - `89tts`: **89TTS — 89 Global Media** (Ứng dụng chính duy nhất)

### Cách nạp Database lên Server:
1. Tạo một Database MySQL mới (ví dụ: `license_89globalmedia` với charset `utf8mb4_unicode_ci`).
2. Mở **phpMyAdmin** (hoặc dòng lệnh MySQL) và Import tệp:
   `adminpanel/db/clean_init_89globalmedia.sql`

---

## 2. Cấu hình Tệp `.env` trên Server Mới

Mở tệp `adminpanel/.env` và cập nhật thông tin kết nối MySQL của máy chủ mới:

```env
# ── Database ─────────────────────────────────────────────────────────────────
DB_HOST=localhost
DB_NAME=license_89globalmedia
DB_USER=tên_user_mysql_mới
DB_PASS=mật_khẩu_mysql_mới
DB_CHARSET=utf8mb4

# ── Admin Panel — Layer 2 login (Đăng nhập Quản trị viên) ────────────────────
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=$argon2id$v=19$m=65536,t=4,p=1$D+Nzx68A+RobhULjccOYPw$lAuCOwzFurmvERQObXbVfmO2Bxvmr2rR/GXkWBuxJ9E
ROOT_USERNAME=Root
ROOT_PASSWORD_HASH=$argon2id$v=19$m=65536,t=4,p=1$fDdSD2P3Rlw8F/r40VxKIg$IOxENdRUwf3BiYQWUfLcj50XSS90Z/kUsFniq/kHPDI

# ── Admin Panel — Layer 1 gate (Lớp bảo vệ vòng ngoài) ──────────────────────
LAYER1_USER=Admin@2026
LAYER1_PASS=Khong!biet2026

# ── Khóa ký Ed25519 (ĐÃ ĐỒNG BỘ 100% VỚI DESKTOP APP) ───────────────────────
# TUYỆT ĐỐI GIỮ NGUYÊN để Desktop App xác thực được token bản quyền
PRIVATE_KEY=In61R39R6QY7YGC1uGL3MN3JG4VyWLeoR1cpSS/aDeh2pglksq3PZmrTbXYv8VRoL+MyHAVy/JQ9FhciEaFaHg==
ED25519_PUBLIC_KEY=dqYJZLKtz2Zq0212L/FUaC/jMhwFcvyUPRYXIhGhWh4=
```

---

## 3. Thông tin Đăng nhập Admin Panel Mặc định

Khi truy cập vào đường dẫn quản trị: `https://89globalmedia.online/admin`

1. **Lớp 1 (HTTP Security Gate - Bảo vệ vòng ngoài)**:
   - Tên đăng nhập: `Admin@2026`
   - Mật khẩu: `Khong!biet2026`

2. **Lớp 2 (Admin Dashboard Login - Đăng nhập trang quản trị)**:
   Hệ thống hỗ trợ 2 tài khoản quản trị song song:
   - **Tài khoản Root (Admin quyền cao nhất)**:
     - Tên đăng nhập: `Root`
     - Mật khẩu: `89globalmedia2026@`
   - **Tài khoản Admin tiêu chuẩn**:
     - Tên đăng nhập: `admin`
     - Mật khẩu: `Khong!biet2026`

---

## 4. Kiểm tra Endpoints API từ Desktop App

Desktop App (OmniVoice) sẽ tự động gọi tới các endpoints sau:
- Kích hoạt bản quyền: `POST https://89globalmedia.online/api/license/activate`
- Kiểm tra âm thầm định kỳ: `POST https://89globalmedia.online/api/license/verify`
- Web reset thiết bị cho khách: `https://89globalmedia.online/reset-device`

Tất cả các route trên đã được cấu hình và định tuyến chuẩn xác trong mã nguồn.
