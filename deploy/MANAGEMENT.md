<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Giao diện quản lý chứng thư và ký PDF

Đăng nhập Nextcloud bằng **tài khoản quản trị**, mở LibreSign và chọn **Chứng thư & ký số** ở thanh bên.
Đường dẫn trực tiếp: `/index.php/apps/libresign/f/provider-management` trên cùng máy chủ Nextcloud.

Chọn **Bên tích hợp** để xem dữ liệu đúng UID đã cấu hình trong `provider_clients`. Giao diện không yêu cầu nhập App Password. Tài khoản tích hợp bị tắt chỉ được xem; các thao tác ghi kiểm tra lại trạng thái tài khoản và scope tại server.

## Chứng thư

- Xem danh sách có phân trang, trạng thái, chủ thể, CCCD, alias, serial, thời hạn và fingerprint SHA-256.
- Tra toàn bộ chứng thư theo CCCD đủ 12 số; ô lọc tên/mã/trạng thái chỉ lọc trang đang xem.
- Tải chuỗi chứng thư PEM công khai; không xuất khóa riêng hoặc PFX.
- Chọn **Ký PDF bằng chứng thư này** để điền sẵn alias đang hiệu lực.
- Thu hồi có cửa sổ xác nhận và lý do; dùng lại dịch vụ CRL hiện có.

## Phiên cấp chứng thư và QR

Chọn **Cấp chứng thư**, nhập chủ thể và liên hệ theo kênh OTP. Luồng QR chỉ xuất hiện khi bên tích hợp có `registrationBaseUrl` hoặc đã bật `mockRegistrationEnabled`.

Chi tiết phiên QR hiển thị chính ảnh PNG và link backend tạo ra. Có thể sao chép link, tải ảnh QR và mở trang đăng ký. Kiểm tra hạn phiên trước khi gửi. Trang mock được đánh dấu rõ; đây không phải luồng eKYC thật.

Luồng đầu đọc CCCD không có QR. Trong thực tế, hồ sơ và kết quả face-match do adapter tin cậy gửi qua API hiện có. Để thử khi chưa nối đầu đọc, có thể dùng form nhập tay dưới đây nếu bên tích hợp đã bật chế độ mô phỏng. Giao diện quản trị không tự xác minh căn cước. Sau khi hồ sơ hợp lệ, có thể kiểm tra OTP, xác nhận cấp, gửi lại mã hoặc hủy phiên đang chờ. Việc kiểm tra OTP và cấp chứng thư là hai bước riêng.

### Nhập hồ sơ trực tiếp để thử luồng cấp

1. Chọn tab **Phiên cấp chứng thư**, bấm **Xem** phiên ở trạng thái **Đang chờ / Chờ hồ sơ**.
2. Trong khung **Chi tiết**, kéo xuống phần **Nhập hồ sơ thử nghiệm**. CCCD và họ tên lấy từ phiên, không sửa ở bước này.
3. Điền ngày sinh, giới tính, địa chỉ thường trú, ngày cấp và ngày hết hạn CCCD. Kiểm tra email/điện thoại nhận OTP; có thể cập nhật liên hệ trước khi chốt hồ sơ.
4. Tích xác nhận dữ liệu thử nghiệm, bấm **Gửi hồ sơ**. Nếu dữ liệu chưa hợp lệ, lỗi hiện ngay trong form và nội dung đã nhập được giữ lại.
5. Sau khi hồ sơ hợp lệ, nhập OTP và tích đồng ý để **Kiểm tra OTP / xác nhận**. Khi OTP đã hợp lệ, tích đồng ý và bấm **Xác nhận cấp chứng thư**. Gửi hồ sơ không tự cấp chứng thư.

Form chỉ hiện cho admin khi bên tích hợp có `mockRegistrationEnabled=true`, tài khoản đang bật, có scope `certificates:write`, nguồn xác minh cho phép UID của bên tích hợp và phiên còn hạn/chưa chốt hồ sơ. Nếu phiên đã hết hạn, tạo phiên mới; không kéo dài hoặc sửa hạn phiên cũ. Phiên tái sử dụng hồ sơ yêu cầu face-match tiếp tục qua adapter.

Hồ sơ nhập tay được gắn `verification.method=MANUAL_TEST` và hiển thị nhãn **nhập tay thử nghiệm** sau khi nhận. Không sinh ảnh chip, SOD hoặc kết quả face-match giả. Backend dùng bước nhận thông tin hồ sơ tổng quát tương đương `identity/info`, giữ kiểm tra ngày tháng, tính bất biến của hồ sơ và OTP. Hồ sơ từ adapter đã chốt không thể bị thay bằng dữ liệu nhập tay. Thử lại cùng dữ liệu giữ nguyên biên nhận xác minh; thay nội dung hồ sơ đã chốt bị từ chối.

Khi nối thiết bị thật, gửi bộ dữ liệu đã xác minh vào API public `POST /registration-sessions/{sessionId}/identity/info` hoặc `/card-reader/info` theo contract trong tài liệu API. Không cần dùng form nhập tay. API `card-reader/info` vẫn yêu cầu đủ dữ liệu ảnh/chip/SOD theo quy định hiện có.

## Phiên ký PDF

Chọn **Ký PDF**, điền alias hoặc bắt đầu từ một chứng thư ACTIVE, chọn một/nhiều PDF rồi tạo phiên. Mỗi tệp tối đa 5 MiB, tổng lô 25 MiB, tối đa 100 tệp; backend tiếp tục thực thi các giới hạn nghiệp vụ.

Tạo phiên chuẩn bị tài liệu và gửi OTP, chưa ký ngay. Mở chi tiết, xem PDF gốc, xác nhận đồng ý và nhập OTP để ký. Với phiên do API tạo bằng assertion, dùng proof từ adapter. Màn hình không sinh proof thay người ký. Có thể gửi lại OTP hoặc hủy khi phiên còn cho phép.

Sau khi xử lý, xem trạng thái từng tài liệu, mã lỗi nếu có, PDF đã ký và SHA-256 kết quả. **Thành công một phần** cần kiểm tra từng tệp. Bấm **Làm mới** để theo dõi phiên đang xử lý; màn hình không tự thực thi job nền thay cron. PDF gốc và PDF kết quả được xem/tải qua endpoint có kiểm tra quyền quản trị, UID, phiên và document ID.

Khi mất kết nối sau một thao tác, làm mới để đối soát trước khi thử lại. Thử lại tạo phiên trong cùng cửa sổ với cùng dữ liệu giữ nguyên request ID; đóng và mở cửa sổ mới bắt đầu yêu cầu mới.

## Áp dụng source

Build frontend bằng `npm run build` trong môi trường phát triển hiện có, với người dùng ứng dụng. Không cần migration mới. Với stack source ở repository cha, có thể chạy:

```bash
bash scripts/source-dev.sh exec sh -c 'cd /var/www/html/apps-extra/libresign && npm run build'
```

Sau đó tải lại trang LibreSign. Các API quản trị `/api/v1/management/*` là API nội bộ cho giao diện; bộ 24 API public cho bên tích hợp giữ nguyên contract.

## Kiểm chứng ngày 24/10/2026

- TypeScript và build Vite đạt; 23 test backend / 79 assertions và 43 test frontend liên quan đạt. Bao gồm chặn tài khoản thường, giới hạn UID khi đọc PDF, giữ kiểm tra OTP tại dịch vụ ký, bảo vệ CSRF của thao tác ghi, xác nhận thu hồi và giữ request ID khi thử lại.
- Đã đăng nhập quản trị qua HTTP nội bộ và đọc danh sách/chi tiết thật: 8 chứng thư, 19 phiên cấp, 4 phiên ký tại thời điểm kiểm tra. Ảnh QR là PNG hợp lệ; PDF gốc và PDF kết quả trả HTTP 200, hash bản kết quả khớp metadata.
- Trình duyệt kiểm tra được danh sách, chi tiết, cửa sổ tạo phiên ký và bố cục mobile; không ghi nhận lỗi JavaScript hoặc tràn ngang toàn trang.
- Lượt xác minh runtime chỉ đọc dữ liệu và mở form; không tạo thêm chứng thư, không ký hay thu hồi dữ liệu đang dùng. Các thao tác ghi của giao diện được kiểm thử bằng mock, không coi đây là nghiệm thu eKYC, gửi OTP thật hoặc toàn bộ luồng ký production.

### Sửa lỗi PDF bị chặn trong cửa sổ xem trước

Phản hồi PDF mặc định của Nextcloud có CSP `frame-ancestors 'none'`, nên HTTP 200 chưa đủ chứng minh PDF hiển thị trong iframe. Endpoint xem trước đã được sửa để chỉ cho phép nhúng từ cùng nguồn (`frame-ancestors 'self'`), giữ các chỉ thị hạn chế khác, xác thực quản trị và kiểm tra UID/phiên/tài liệu. Phản hồi tải xuống và lỗi quyền truy cập vẫn không cho nhúng.

Đã kiểm tra lại với Chrome đầy đủ có PDF viewer: **PDF gốc và PDF đã ký đều hiển thị nội dung trong hộp thoại**, không còn trang lỗi kết nối hoặc vi phạm `frame-ancestors`. Hash PDF kết quả vẫn khớp metadata; 24 test backend / 85 assertions đạt, gồm regression cho CSP và từ chối truy cập. Bản sửa chỉ ở backend, không cần build lại frontend. Đóng cửa sổ PDF đang lỗi và mở lại để nhận phản hồi mới.

### Bổ sung form nhập hồ sơ thử nghiệm

- TypeScript và build đạt; 41 test backend / 141 assertions và 23 test frontend cho màn quản lý đạt. Các nhánh phủ gồm điều kiện bật nhập tay, nguồn/định danh không được giả mạo từ form, phiên hết hạn, hồ sơ đã chốt, xác nhận dữ liệu thử nghiệm, giữ nội dung khi lỗi và chuyển sang OTP.
- Test tích hợp dùng bản SQLite, datadir và CA riêng: **1 test / 17 assertions đạt**, kiểm tra từ hồ sơ tới cấp chứng thư ACTIVE, từ chối CCCD hết hạn, giữ biên nhận khi thử lại, từ chối thay hồ sơ đã chốt và OTP sai định dạng.
- Trình duyệt chạy trọn luồng trên server thử nội bộ dùng bản sao này: tạo phiên → nhập hồ sơ → xử lý ngày hết hạn sai mà không mất nội dung → gửi hợp lệ → từ chối OTP sai định dạng → OTP hợp lệ → xác nhận cấp → chứng thư ACTIVE. Bố cục desktop/mobile đạt, không lỗi JavaScript hoặc tràn ngang toàn trang.
- Test tích hợp và trình duyệt dùng mock OTP được bật riêng trong bên tích hợp thử; email đi tới Mailpit. Không đổi OTP của tài khoản đang phục vụ, không nộp hồ sơ/cấp chứng thư vào phiên người dùng đang chờ. Giao diện mới đã build vào source đang mount; tải lại bằng **Ctrl + F5** nếu trình duyệt còn giữ bundle cũ.
