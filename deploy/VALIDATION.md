<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Ghi nhận kiểm chứng bộ cài

Ngày kiểm tra: **24/10/2026**. Phạm vi là bộ cài của fork LibreSign 16.0.0-dev.2 / Nextcloud 36 dev, không phải đánh giá production hoặc benchmark.

## Các kiểm tra đã thực hiện

| Kiểm tra | Kết quả |
|---|---|
| Bash syntax của entrypoint, runtime helper, host dependencies và wrapper ngoài repo | Đạt |
| Python orchestration regression | **30 tests đạt**: các trường hợp cài mới/chạy lại, secret/identity, OTP email/SMS/hai kênh, mock, app SMS tùy chọn, dung lượng nơi lưu runtime và biến host trùng cấu hình Compose |
| PHP syntax và tương thích với Nextcloud đang dùng | Đạt |
| PHP provisioning trên SQLite snapshot/CA/datadir riêng | **54 assertions đạt**, không ghi vào database hoặc CA đang phục vụ người dùng |
| Compose render bằng Docker Compose thật | Đạt cho HTTPS local và HTTP overlay; kiểm tra mounts, UID cron và literal `$`/quote/space trong cấu hình thử |
| Cài sạch đầy đủ ở lượt bộ cài ban đầu, source/runtime/project/hostname riêng | **Đạt**: chuẩn bị Git submodule/runtime, Nextcloud mới, Composer/npm/Vite, enable/migration, Java/JSignPdf/PDFtk, CA, tài khoản máy, App Password, policy, cron và doctor |
| Chạy lại installer với `--skip-build` trên runtime thử | **Đạt**; hash file admin credential, integration credential và CA certificate giữ nguyên |
| Chuyển runtime thử sang HTTP loopback với cổng ngẫu nhiên | **Đạt**; gọi API thật qua cổng host được 200 khi có App Password và 401 khi anonymous |
| Postman environment private | Đạt: đủ biến/PDF mẫu, credential khớp file private, quyền `0600` |
| Cron thực thi provider workflow | Đạt: kiểm tra `last_run` trong bảng job của runtime mới cho thấy job đã chạy |

Kiểm thử PHP tách riêng bao gồm: tạo account thường; xác minh mật khẩu; tạo đúng một App Password; chạy lại giữ token và policy; giữ client khác; cập nhật riêng một tùy chọn; từ chối admin/mật khẩu sai/public key không hợp lệ; SMTP chứa ký tự đặc biệt; migration chỉ chạy bước còn thiếu; giữ CA đã có; tạo CA mới; từ chối tự thay CA khi còn file mà mất metadata.

Doctor đã thực hiện request qua nginx/PHP-FPM với App Password: `/capabilities`, `/certificates/packages`, `/signing-profiles` thành công và anonymous bị từ chối. Việc kiểm tra nội bộ này không bỏ qua TLS: request đi HTTP trong mạng Compose. Chứng thư TLS và network công khai cần kiểm tra riêng từ phía client.

## Lượt cập nhật OTP và SMS tùy chọn

- Đối chiếu backend mới hỗ trợ `email`, `sms`, `email_sms`, `otpGateway` và `mockOtpEnabled`; giữ mặc định email + đối chiếu mã thật trong cấu hình mới. Cấu hình cũ không có cờ mock vẫn dùng được và không tự bị ghi lại khi chạy cài đặt.
- 28 tests Python kiểm tra validate/forward policy, normalize tên gateway, bỏ qua yêu cầu cấu hình provider trong setup, chỉ cài app SMS khi cần, giữ app đã bật, tiếp tục setup khi chưa cài được app tương thích và doctor báo riêng SMS còn chờ cấu hình.
- 54 assertions PHP trên fixture riêng kiểm tra bật/tắt mock, giữ token/client khác, lưu account/policy SMS ngay cả khi provider chưa cấu hình, ghi rõ SMS chưa ready và từ chối tên gateway sai định dạng. Các kiểm tra CA/migration/SMTP trước đó tiếp tục đạt.
- Gọi GET chỉ đọc bằng credential hiện có qua nginx/PHP-FPM: ba API danh mục, metadata `otpChannel`/`supportedOtpChannels` và anonymous rejection đều đạt. Runtime thử hiện có trả kênh email, mock đang bật theo cấu hình của nó; lượt kiểm tra không đổi policy đó.
- Không gửi SMS và không cài app vào runtime đang dùng trong lượt này. Nhánh cài app/tương thích bị lỗi được kiểm thử bằng mock; provider SMS cụ thể được để cấu hình sau theo yêu cầu. Trước khi dùng SMS thật vẫn cần thử giao mã.

## Lượt rà soát lại bộ cài

- Tái hiện và sửa hai trường hợp: nơi lưu runtime thiếu dung lượng dù source/Docker còn chỗ; biến đã export trên host ghi đè thông số trong runtime `.env`. Thêm hai regression tests, tổng **30 tests đạt**. Kiểm tra Bash syntax và `git diff --check` đạt.
- Pin thêm image nginx và Redis bằng digest của image đã có trên máy, giữ phiên bản đang dùng. Render bằng Docker Compose thật đạt cho HTTPS local và HTTP IP:port, kể cả khi host export sai đường dẫn source/runtime, UID, cổng, hostname và mật khẩu bootstrap. Đối chiếu mount, credential, cron user, cổng publish và image digest; không tạo container hoặc in secret trong bước render.
- PHP helper qua kiểm tra cú pháp. Helper kiểm tra CA/tools, bảng/job và ba API danh mục qua nginx nội bộ bằng credential hiện có đều đạt; anonymous bị từ chối. Policy đang dùng vẫn là email + mock OTP; không thay CA, tài khoản hoặc policy của máy hiện tại. OpenAPI public vẫn khớp controller/Postman, đủ 24 thao tác.
- Lượt rà soát này không chạy lại toàn bộ cài sạch hoặc cài package host. Kết quả cài sạch/54 assertions PHP ở trên thuộc các lượt kiểm chứng trước; giới hạn Ubuntu trắng và đường mạng/HTTPS công khai vẫn giữ nguyên.

## Giới hạn

- Cài sạch được thử trên Docker host Linux đã có Docker/Git/Python/ACL; nhánh cài package Ubuntu được kiểm tra cú pháp và đối chiếu tài liệu Docker, chưa chạy trên một hệ điều hành Ubuntu trắng.
- Không coi build thành công là nghiệm thu mọi chức năng nghiệp vụ. Bộ cài không gửi OTP tới email khách hàng, không gửi callback tới đối tác thật, không mở firewall/DNS/NAT và không tự kiểm thử eKYC/face-match.
- Không nghiệm thu MariaDB/PostgreSQL, cặp phiên bản stable, public HTTPS hoặc tải production trong lượt này. Các kiểm thử API nghiệp vụ của fork có tài liệu riêng; không tự chạy bộ test ghi dữ liệu trên database đang dùng.
- npm/Vite có cảnh báo upstream về peer dependency/engine, import và chunk size; build hoàn tất, không sửa dependency/lockfile để che các cảnh báo đó.

Các môi trường thử dùng source/runtime/datadir riêng và được dọn sau khi kiểm tra; bộ cài không được áp vào runtime hiện có của `scripts/source-dev.sh`.
