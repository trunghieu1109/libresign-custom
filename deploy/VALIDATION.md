<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Ghi nhận kiểm chứng bộ cài

Ngày kiểm tra: **24/10/2026**. Phạm vi là bộ cài của fork LibreSign 16.0.0-dev.2 / Nextcloud 36 dev, không phải đánh giá production hoặc benchmark.

## Các kiểm tra đã thực hiện

| Kiểm tra | Kết quả |
|---|---|
| Bash syntax của entrypoint, runtime helper, host dependencies và wrapper ngoài repo | Đạt |
| Python orchestration regression | **49 tests đạt**: cài mới/chạy lại, secret/identity, OTP/SMS/mock, dung lượng/cổng, biên nhận build và policy nhập hồ sơ thử thực tế |
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

## Lượt tách cổng của bộ cài mới

- Chuyển mặc định host sang HTTP `18085` (khi bật), HTTPS `127.0.0.1:18443` và Mailpit UI `127.0.0.1:18025`. Bỏ dependency nginx vào shared proxy/coordinator; service SQL kế thừa bị tắt profile và xóa dải cổng publish vì fork dùng SQLite. Không sửa NCDD đã pin hoặc các container đang phục vụ máy thử.
- **40 tests Python đạt**, gồm kiểm tra cổng trùng trong JSON, mapping Docker của project khác, listener ngoài Docker, mapping của chính runtime khi chạy lại, địa chỉ bind không chồng lấn, parse TCP/UDP và cấu hình cũ. Bash syntax và OpenAPI public check đạt.
- Render bằng Docker Compose thật cho HTTPS local và HTTP IP:port đạt: chỉ publish các cổng mong đợi, dependency nginx chỉ còn Nextcloud, không có dải cổng SQL, biến host không ghi đè cấu hình. Bộ cài kiểm tra Compose ≥2.24.4 để dùng `!override`.
- Khởi động project fixture riêng với các image đã pin: PHP-FPM phục vụ `status.php` tổng hợp, nginx trả HTTP qua `18085`, HTTPS qua `18443` kiểm chứng certificate/SAN `localhost`, Mailpit UI trả 200 qua `18025`. Tất cả cổng fixture bind loopback. Kiểm tra thật xác nhận chạy lại cho phép mapping của chính project; project khác và TCP listener ngoài Docker bị từ chối.
- Fixture không chạy Nextcloud bootstrap hay dùng database/CA thật. Các container/network fixture đã được dọn. Không thay mapping `19841 → 8085`, credential hoặc policy của runtime hiện tại. Đây là kiểm chứng topology/cổng, không phải lượt cài sạch end-to-end mới.

## Cập nhật theo màn quản lý và nhập hồ sơ thử

- Đối chiếu controller/service/router Vue mới: không thêm migration, package host hay cổng; vẫn dùng hai migration provider đã có và 24 API public giữ nguyên contract. Màn quản lý yêu cầu admin/session/CSRF; tài khoản máy không được nâng quyền.
- Bộ cài in URL màn quản lý khi hoàn tất, `plan` nêu rõ URL/bước build. Thêm `build.json` với hash đầu vào source/lockfile; `--skip-build` bị từ chối trước khi khởi động lại container nếu đầu vào đã đổi, asset chính thiếu hoặc runtime chưa có biên nhận. Build thất bại/thay đổi đầu vào trong khi build không giữ biên nhận hợp lệ. `doctor` kiểm tra lại các điều kiện này.
- **49 tests Python đạt**, gồm 9 kiểm tra bổ sung cho build/frontend/lockfile, PHP-only edit, build lỗi/đồng thời và hiển thị policy nhập tay đang áp dụng. Bash/PHP syntax, `git diff --check`, liên kết tài liệu và các JSON snippet đầy đủ đạt. Smoke `init/plan` không gọi Docker/network, trả đúng URL quản lý và kế hoạch build.
- Helper trên runtime hiện tại: CA/tools/bảng/job đạt; ba API danh mục public đạt; anonymous public bị từ chối. API quản lý `/api/v1/management/clients` trả **403** với App Password tài khoản máy và **401** khi anonymous. Policy đang dùng vẫn email + mock OTP/registration; không đổi các giá trị đó.
- Chạy lại provisioning trên **SQLite snapshot, datadir và CA riêng: 54 assertions đạt**, bao gồm tạo account/token, chạy lại giữ credential/policy/CA, cập nhật policy rõ ràng, SMTP và migration. Fixture đã được dọn; không ghi vào database/CA hoặc phiên nghiệp vụ đang phục vụ.
- README/MANAGEMENT có hướng dẫn cài mới, build đầy đủ khi cập nhật UI, bật/tắt form nhập tay và phân biệt mock registration với mock OTP. INTEGRATION/QUICKSTART làm rõ đối tác tiếp tục dùng API public. Lượt này không build lại UI, không chạy nghiệm thu trình duyệt hay một lượt cài sạch đầy đủ mới; các kết quả UI trong MANAGEMENT thuộc các lượt trước.

## Tối ưu backend ngày 25/10/2026

Đã tối ưu truy vấn chứng thư, tải kết quả riêng, checkpoint ảnh và hàng đợi callback, giữ nguyên contract API. So sánh trực tiếp với commit ban đầu và ghi nhận runtime/migration/kiểm thử tại [BACKEND_OPTIMIZATION.md](BACKEND_OPTIMIZATION.md); dữ liệu đo nằm trong [benchmarks/provider-backend-20261025.json](benchmarks/provider-backend-20261025.json).

Bổ sung đo riêng các POST cấp chứng thư bằng CA/PFX thật, OTP thật qua Mailpit và RSA assertion: **4 tests / 144 assertions đạt**, gồm callback và retry không cấp lặp. Confirm OTP giảm **21 → 13**, confirm assertion **24 → 16** truy vấn nghiệp vụ; gửi/xác minh OTP giữ số SQL và giảm một lượt giải mã. Bộ HTTP public/OCS chạy lại **5 tests / 1.613 assertions đạt**; không có migration bổ sung.

## Giới hạn

- Cài sạch được thử trên Docker host Linux đã có Docker/Git/Python/ACL; nhánh cài package Ubuntu được kiểm tra cú pháp và đối chiếu tài liệu Docker, chưa chạy trên một hệ điều hành Ubuntu trắng.
- Không coi build thành công là nghiệm thu mọi chức năng nghiệp vụ. Bộ cài không gửi OTP tới email khách hàng, không gửi callback tới đối tác thật, không mở firewall/DNS/NAT và không tự kiểm thử eKYC/face-match.
- Không nghiệm thu MariaDB/PostgreSQL, cặp phiên bản stable, public HTTPS hoặc tải production trong lượt này. Các kiểm thử API nghiệp vụ của fork có tài liệu riêng; không tự chạy bộ test ghi dữ liệu trên database đang dùng.
- npm/Vite có cảnh báo upstream về peer dependency/engine, import và chunk size; build hoàn tất, không sửa dependency/lockfile để che các cảnh báo đó.

Các môi trường thử dùng source/runtime/datadir riêng và được dọn sau khi kiểm tra; bộ cài không được áp vào runtime hiện có của `scripts/source-dev.sh`.
