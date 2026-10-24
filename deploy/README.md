<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Bộ cài bản LibreSign custom

Bộ cài nằm trọn trong repo source. Khi push `libresign-custom`, hãy commit cả `deploy/`, `.gitmodules`, lockfile, các thay đổi API và migration. Không cần repo Docker bên ngoài để dùng bộ cài này.

Sau khi dựng server, gửi [hướng dẫn dành cho bên tích hợp](QUICKSTART.md) cùng collection/environment trong `examples/` và bàn giao credential riêng. Hướng dẫn có thông tin máy thử hiện tại; khi bàn giao máy khác, cập nhật URL và tài khoản tương ứng.

Source hiện có [giao diện quản lý chứng thư và ký PDF](MANAGEMENT.md) cho admin, gồm form nhập hồ sơ thử khi được bật. Cài mới tự build giao diện; cập nhật từ bản chưa có màn quản lý phải chạy `install` đầy đủ, bỏ `--skip-build`. Không có dependency host, cổng hoặc migration mới cho màn quản lý này.

Phạm vi: dựng mới môi trường **phát triển/UAT** trên Ubuntu Linux hoặc WSL2, dùng LibreSign 16-dev / Nextcloud 36 dev và SQLite. Bộ cài tái sử dụng NCDD pin theo checkout, không biến bản dev thành bản production. PHP/Composer/Node/npm chạy trong container. Linux cần filesystem hỗ trợ POSIX ACL; với WSL, clone trong filesystem Linux, ví dụ `/home/...`, thay vì `/mnt/c/...`.

## 1. Cài nhanh trên máy mới

Nếu chưa có Git, cài Git để lấy bộ cài:

```bash
sudo apt-get update
sudo apt-get install -y git
git clone --recurse-submodules --branch TEN_NHANH_CUSTOM URL_REPO_CUA_BAN libresign-custom
cd libresign-custom
```

Thay URL và nhánh bằng repo của bạn. Lệnh sau cài dependencies host, Docker Engine/Compose nếu thiếu, rồi dựng đầy đủ bản thử local:

```bash
sudo bash deploy/install.sh install --install-deps
```

Script tự chọn tên Compose project theo đường dẫn source. Cuối quá trình, nó in URL và **đường dẫn** lấy tài khoản, không in mật khẩu. Runtime mặc định là thư mục `.libresign-runtime-<mã>` bên cạnh source; đây là thư mục ngoài application/web root.

Trên máy đã có dependencies và quyền Docker, bỏ `--install-deps` và có thể chạy bằng user thường. Cài package tự động chỉ hỗ trợ Ubuntu. Các hệ khác cần cài Docker Compose **2.24.4+**, Git, Python 3.10+, `acl` và curl trước. Bộ cài cần cú pháp thay thế dependency/cổng trong Compose để bỏ proxy dùng chung của NCDD, xem [quy tắc `!override`](https://docs.docker.com/reference/compose-file/merge/#replace-value).

Nếu phát hiện package Docker xung đột, script dừng để bạn xử lý theo tài liệu Docker; nó không tự gỡ engine, xóa container hay prune volume. Nếu Docker daemon chưa chạy trong WSL/Docker Desktop, bật Docker rồi chạy lại.

## 2. Cài với cấu hình riêng — nên dùng trên VPS

Ví dụ chọn runtime cố định `/opt/libresign-runtime`, nằm ngoài source:

```bash
sudo bash deploy/install.sh init --install-deps --runtime-dir /opt/libresign-runtime
sudo nano /opt/libresign-runtime/install.json
sudo bash deploy/install.sh plan --runtime-dir /opt/libresign-runtime
sudo bash deploy/install.sh install --runtime-dir /opt/libresign-runtime
```

`init` giữ file đã tồn tại. `plan` chỉ đọc cấu hình, không cài package, không tải image và không in secret. `config.example.json` mô tả đầy đủ các key chính; file do `init` tạo có project name riêng để tránh trùng hệ thống.

Chọn URL **trước khi tạo CA/cấp chứng thư**. Ba cách truy cập:

| Cách dùng | Giá trị cấu hình | Truy cập |
|---|---|---|
| Local | Giữ `publicUrl=https://localhost:18443`, `publishHttp=false` | HTTPS trực tiếp của nginx, bind loopback 18443 |
| VPS qua SSH tunnel | Giữ `publicUrl=https://localhost:18443`, `publishHttp=false` | Tunnel local 18443 tới loopback 18443 của VPS |
| HTTP/NAT cho dữ liệu thử | `publicUrl=http://IP_CONG_KHAI:CONG_CONG_KHAI`, `publishHttp=true`, đặt `httpBind`/`httpPort` | Provider chuyển cổng công khai tới `httpPort` trên host |

Với tunnel, thay user/IP/cổng SSH của máy mới:

```bash
ssh -N \
  -L 127.0.0.1:18443:127.0.0.1:18443 \
  -L 127.0.0.1:18025:127.0.0.1:18025 USER@IP_VPS
```

Mở `https://localhost:18443` cho LibreSign và `http://127.0.0.1:18025` cho Mailpit. HTTPS local/tunnel dùng chứng thư tự ký của nginx với SAN `localhost`; cần tin cậy chứng thư này để kiểm tra TLS đầy đủ. CA HTTPS và CA ký PDF là hai CA riêng. Domain/HTTPS công khai được cấu hình ở reverse proxy riêng khi triển khai sau này.

Ví dụ NAT trên máy mới: nhà cung cấp ánh xạ `IP:19841 → host:18085`:

```json
"publicUrl": "http://IP_CUA_MAY_MOI:19841",
"publishHttp": true,
"httpBind": "0.0.0.0",
"httpPort": 18085
```

Sửa các field tương ứng trong file JSON đầy đủ, không thay cả file bằng đoạn rời trên. Với VPS truy cập trực tiếp, dùng `publicUrl=http://IP_CUA_MAY_MOI:18085`, không cần NAT. Chỉ public HTTP khi dùng dữ liệu thử. Script không tự sửa DNS, firewall hoặc bảng NAT.

### Cổng của bộ cài mới

| Dịch vụ | Host mặc định | Cổng trong container |
|---|---|---|
| LibreSign HTTP | `127.0.0.1:18085`, chỉ khi `publishHttp=true`; dùng `httpBind=0.0.0.0` khi cần truy cập ngoài | nginx `80` |
| LibreSign HTTPS local | `127.0.0.1:18443`; chỉnh `httpsBind`/`httpsPort` khi cần | nginx `443` |
| Giao diện Mailpit | `127.0.0.1:18025`; chỉnh `mailpitPort` khi cần | `8025` |
| SMTP Mailpit, Redis, PHP-FPM | Chỉ trong mạng Docker, không publish ra host | `1025`, `6379`, `9000` |
| Database | SQLite trong runtime; không chạy service SQL của NCDD | Không có cổng database |

Bộ cài mới không khởi động proxy/coordinator dùng chung, không chiếm host 80/443 và không publish Redis/SMTP/PHP-FPM. Cổng nội bộ container giữ nguyên vì các Compose project có mạng riêng. Nếu chạy nhiều bản trên một máy, đặt các cổng host khác nhau cho từng runtime.

Trước khi khởi động, bộ cài kiểm tra mapping Docker và thử bind các cổng cần dùng. Cổng bị chiếm hoặc địa chỉ bind không tồn tại sẽ có lỗi chỉ rõ field cần sửa trong `install.json`; script không tự chọn cổng khác. Chạy lại cho phép mapping thuộc đúng project/service hiện có. Kiểm tra cổng là trạng thái tại thời điểm kiểm tra; Docker vẫn có thể báo xung đột nếu dịch vụ khác chiếm cổng ngay sau đó.

Với `install.json` cũ, `httpPort` đã khai báo vẫn được giữ, kể cả `8085`; các key mới thiếu được bổ sung mặc định trong bộ nhớ. Muốn chuyển sang dải mới, sửa `httpPort=18085`, URL và NAT tương ứng rồi chạy lại `install`. Nếu trước đây mở HTTPS qua shared proxy, đổi `publicUrl`/tunnel sang `https://localhost:18443`. Script không dừng proxy dùng chung đang phục vụ hệ thống khác.

## 3. Bộ cài thực hiện những gì?

1. Kiểm tra Docker/Compose 2.24.4+, source đúng nhánh Nextcloud 36, Git submodule, cổng publish và dung lượng trống ít nhất 6 GiB tại source, nơi lưu runtime và Docker data root đọc được. Khi runtime chưa tồn tại, kiểm tra thư mục cha gần nhất đã có. Nên chuẩn bị nhiều dung lượng hơn cho image, npm và PDF; 6 GiB là ngưỡng chặn, không phải sizing production.
2. Lấy NCDD và Nextcloud đúng revision đã pin, dùng các image PHP/nginx/Redis đã pin digest; khởi tạo Nextcloud trước khi mount source để startup không đổi ownership file code.
3. Tạo mật khẩu admin ngẫu nhiên; cấp ACL cho UID 33 ở thư mục build, không recursively chown source.
4. Cài PHP runtime dependencies từ `composer.lock`; `npm ci` từ lockfile rồi build frontend. Không cài bộ test PHP trong bước này.
5. Bật LibreSign, chỉ chạy các migration provider còn thiếu, cài Java/JSignPdf/PDFtk, tạo CA nếu chưa có metadata/file CA.
6. Tạo tài khoản máy thường cho bên tích hợp, tạo Nextcloud App Password và policy scope. Người được cấp chứng thư/ký không cần tài khoản Nextcloud riêng.
7. Áp dụng SMTP nếu cấu hình; tùy chọn cài/bật app Two-Factor Gateway khi cần SMS, để cấu hình provider làm sau; tạo cron service gọi `cron.php` mỗi 60 giây, bằng `www-data`.
8. Kiểm tra CA/tools, bảng provider, đăng ký background job, cron đang chạy; gọi ba API danh mục qua nginx/PHP-FPM bằng App Password, đối chiếu kênh OTP/các kênh được backend hỗ trợ và xác nhận anonymous bị từ chối. In kênh OTP/chế độ mock đang thực sự áp dụng.

Redis và Mailpit thuộc topology NCDD. Mailpit mặc định giữ thư OTP để thử, không chuyển thư ra hộp thư thật; đọc thư tại `http://127.0.0.1:18025` trên máy Docker hoặc qua tunnel ở trên.

## 4. Tài khoản và quyền API

Mặc định: admin UID `admin`, integration UID `provider-client`, năm scope cấp/đọc/thu hồi chứng thư và đọc/ký. Đổi `integration.uid` và `integration.policy.trustedSources` đồng bộ khi dùng UID khác. Giới hạn `scopes` theo quyền thực tế của từng đối tác.

Máy cài mới không sao chép `provider-demo`, App Password hoặc chế độ mock từ máy đang thử. Mặc định bộ cài tạo `provider-client` với `mockOtpEnabled=false`; nếu muốn thử bằng mã `123456` như máy hiện tại, chủ động bật mock theo mục 5 và bàn giao environment private của máy mới.

Các file private trong runtime, quyền `0600`:

| File | Nội dung |
|---|---|
| `admin.json` | UID/mật khẩu admin được tạo lần đầu |
| `.env` | Cấu hình Compose và mật khẩu bootstrap admin |
| `build.json` | Hash đầu vào frontend/lockfile của lần build thành công để kiểm tra khi dùng `--skip-build` |
| `credentials/UID.json` | Mật khẩu tài khoản máy, App Password, callback secret nếu có |
| `integration.postman_environment.private.json` | Mẫu Postman đủ các biến, đã điền base URL/UID/App Password |
| `client-settings.json` | Policy không chứa callback secret/public key assertion |
| `sms-app.json` | Trạng thái cài app gateway khi được yêu cầu; chưa chứa cấu hình provider |

Không đưa runtime/credential vào Git hoặc gửi tài khoản admin cho bên tích hợp. Chỉ bàn giao UID, App Password và các tham số tích hợp cần thiết qua kênh riêng. Credential cũ trong `admin.json` là thông tin bootstrap: nếu đổi mật khẩu admin qua UI, cập nhật nơi quản lý secret của bạn; script không reset mật khẩu trong database.

Nếu tài khoản máy đã có trong cùng runtime nhưng credential do đơn vị quản lý riêng, cung cấp `integration.passwordFile` hoặc `integration.appPasswordFile`: đường dẫn file một dòng, quyền `0600`. Đường dẫn tương đối tính từ thư mục chứa `install.json`. Script giữ mật khẩu tài khoản hiện có; không dùng lại account admin hoặc account bị disable.

Khi chạy lại, script giữ policy đã tồn tại, giữ các client khác, App Password và CA. Muốn áp dụng các key policy đã sửa trong JSON:

```bash
sudo bash deploy/install.sh install --runtime-dir /opt/libresign-runtime --skip-build --update-client
```

`--update-client` merge những key được khai báo, giữ các key không khai báo. `--skip-build` yêu cầu các asset chính, Composer autoload và `build.json` còn khớp đầu vào source/lockfile. Source frontend đổi hoặc runtime cũ chưa có biên nhận build sẽ bị từ chối trước khi khởi động lại container; chạy một lần không có `--skip-build`. Biên nhận không thay thế kiểm thử trình duyệt. Nếu App Password bị revoke, doctor báo lỗi; cung cấp App Password mới qua `appPasswordFile`, hoặc cấp lại có chủ đích. Script không tự xoay token trên mỗi lần chạy.

### Đăng nhập màn quản lý sau cài

Đăng nhập bằng `adminUser` và mật khẩu bootstrap trong `admin.json`, mở LibreSign → **Chứng thư & ký số**, hoặc truy cập:

```text
PUBLIC_URL/index.php/apps/libresign/f/provider-management
```

Chọn UID `provider-client` mặc định của máy mới. Giao diện dùng phiên đăng nhập admin và CSRF token, không nhập App Password. Tài khoản máy thường chỉ dùng API public; không cấp admin cho tài khoản đó để mở giao diện. Màn hình quản lý dữ liệu theo UID, chưa có chức năng tạo account hay sửa policy; tiếp tục dùng bộ cài/CLI cho việc đó.

Để thử form **Nhập hồ sơ thử nghiệm**, thêm `mockRegistrationEnabled: true` vào `integration.policy` trong `install.json`, giữ scope `certificates:write` và `trustedSources` chứa đúng UID. Sau đó chạy `install --update-client`; chỉ thêm `--skip-build` nếu source frontend/lockfile vẫn khớp lần build trước. `mockRegistrationEnabled` cho phép cả form nhập tay và QR mock, không tự bật OTP mock. Nếu muốn nhập `123456`, đặt riêng `mockOtpEnabled: true`; mặc định máy mới vẫn đối chiếu mã OTP thật.

Không cần bật nhập tay để dùng API `identity/info` của adapter. Thông tin nhập tay phải là dữ liệu thử; khi chuyển sang vận hành thật, đặt rõ hai cờ mock về `false` và áp dụng `--update-client`. Bỏ key khỏi JSON giữ policy cũ, không tắt mock. Hướng dẫn đầy đủ tại [MANAGEMENT.md](MANAGEMENT.md).

## 5. SMTP thật và tùy chọn adapter

Thay `smtp: null` bằng object đầy đủ:

```json
"smtp": {
  "host": "smtp.example.com",
  "port": 587,
  "secure": "tls",
  "auth": true,
  "username": "smtp-account",
  "passwordFile": "/opt/libresign-secrets/smtp.password",
  "fromAddress": "sign",
  "domain": "example.com"
}
```

Tạo file secret bằng editor, đặt quyền `0600`; không đưa mật khẩu vào command line. `fromAddress` chỉ là phần trước `@`. `smtp: null` giữ cấu hình hiện có; trên runtime mới nó giữ Mailpit. Xác nhận gửi/nhận OTP ở email thật trước khi nghiệm thu; doctor không tự gửi thư cho khách hàng.

### SMS tùy chọn — chỉ cài app nếu cần

Bộ cài mặc định dùng email; **không cần cấu hình SMS để hoàn tất setup**. Nếu muốn cài sẵn app gateway cho việc dùng SMS sau này, thêm cờ sau, vẫn giữ `otpChannel=email`:

```bash
sudo bash deploy/install.sh install --runtime-dir /opt/libresign-runtime --skip-build --install-sms-app
```

Script thử cài/bật app `twofactor_gateway` tương thích bằng cơ chế App Store của Nextcloud. Nó không yêu cầu thông tin provider, không gửi SMS và không ép cài bản không tương thích. Nếu chưa tải/cài/bật được app, phần email/API vẫn tiếp tục; trạng thái được lưu ở `sms-app.json`. Có thể thử lại sau bằng lệnh trên.

Bộ cài hỗ trợ khai báo đủ `email`, `sms`, `email_sms` trong `integration.policy`; không truyền kênh/cờ mock vào API request:

| `otpChannel` | Cần chuẩn bị | Nhận mã |
|---|---|---|
| `email` — mặc định | Mailpit khi thử hoặc SMTP thật; hồ sơ có email | Mã qua email |
| `sms` | App gateway và dịch vụ SMS sẵn sàng khi bắt đầu gửi; hồ sơ có số điện thoại | Mã qua SMS |
| `email_sms` | Email và SMS sẵn sàng khi bắt đầu gửi; hồ sơ có cả hai liên hệ | **Cùng một mã** qua hai kênh, chỉ nhập một lần |

Ví dụ thay/merge các key trong policy đầy đủ:

```json
"otpChannel": "email_sms",
"otpGateway": "sms",
"mockOtpEnabled": false
```

`otpGateway` là tên gateway, có thể để trống hoặc chưa khai báo trong lúc setup. Khi chọn `sms`/`email_sms`, bộ cài tự thử cài/bật app gateway và ghi trạng thái SMS còn chờ cấu hình; việc này không chặn tạo tài khoản hoặc kiểm tra API danh mục. Chi tiết provider để làm sau. **Chỉ chuyển luồng cấp/ký sang SMS khi đã cấu hình và thử gửi được**; chọn SMS trong policy không tự làm dịch vụ giao mã hoạt động.

Khi cần dùng SMS thật, điền tên gateway tương ứng rồi áp dụng policy hoặc CLI:

```bash
sudo bash deploy/runtime.sh --runtime-dir /opt/libresign-runtime occ \
  libresign:provider:configure provider-client --otp-channel=email_sms --otp-gateway=sms
sudo bash deploy/install.sh doctor --runtime-dir /opt/libresign-runtime
```

Đồng bộ thay đổi đó vào `install.json` nếu tiếp tục quản lý bằng bộ cài. `GET /capabilities` trả `otpChannel` thực tế và `supportedOtpChannels`; danh sách hỗ trợ không chứng minh SMS/email đã giao được tới người nhận. Doctor in riêng trạng thái `SMS ... pending` khi chưa sẵn sàng và không gửi OTP. Với `email_sms`, lỗi một kênh gây `delivery_failed`; cần gửi lại sau khi khắc phục dịch vụ.

Policy hỗ trợ thêm: `assertionPublicKeyFile` (PEM RSA ≥2048 bit), `callbackHosts` (hostname chữ thường), `callbackSecretFile` (secret ≥32 ký tự), `registrationBaseUrl` (trang HTTPS của adapter), `edgeDeployment`, `mockRegistrationEnabled`, `mockOtpEnabled`, `requireReuseFaceMatch`, `reuseFaceThreshold`/`reuseFaceScale`. Chỉ bật những gì hai bên đã thống nhất. Nếu khai báo `callbackHosts` mà chưa có secret, bộ cài tạo secret và lưu trong credential private. QR mock, mock OTP và tái dùng hồ sơ mặc định không bật. Ngưỡng face-match phải do đơn vị tích hợp hiệu chỉnh; bộ cài không chọn ngưỡng thay bạn.

Để thử tích hợp với email và **OTP bất kỳ đúng 6 chữ số**, đặt `integration.policy.mockOtpEnabled=true` trong `install.json`, giữ `otpChannel=email`, rồi áp dụng bằng `install --skip-build --update-client`. Hoặc cập nhật trực tiếp tài khoản máy hiện có:

```bash
sudo bash deploy/runtime.sh --runtime-dir /opt/libresign-runtime occ \
  libresign:provider:configure provider-client --otp-channel=email --mock-otp=true
```

Mock chỉ bỏ so khớp với mã thực tế; vẫn giao OTP theo kênh đã cấu hình, kiểm tra đúng 6 chữ số, lượt gửi thành công, thời hạn và trạng thái phiên. Áp dụng cho cấp chứng thư, ký đơn/lô và QR, không thay request/response hay assertion. Không cần gateway SMS khi dùng email. Tắt bằng cùng lệnh với `--mock-otp=false` để kiểm tra mã thật trở lại; nếu tiếp tục quản lý bằng `install.json`, đổi policy đó về `false` và áp dụng đồng bộ. Cờ này chỉ dùng cho môi trường thử tích hợp, khác với `mockRegistrationEnabled` điều khiển trang QR mock.

`init` mới ghi rõ `mockOtpEnabled=false`. Với cấu hình cũ, thiếu key này không tự sửa policy trong database: policy đã tồn tại được giữ khi chạy lại. Tương tự, bỏ `--mock-otp` khỏi CLI là **giữ nguyên** cài đặt hiện có; muốn tắt cần truyền `false` hoặc khai báo `false` trong JSON rồi chạy `--update-client`.

## 6. Vận hành và chạy lại

Ví dụ runtime cố định ở trên:

```bash
sudo bash deploy/install.sh doctor --runtime-dir /opt/libresign-runtime
sudo bash deploy/runtime.sh --runtime-dir /opt/libresign-runtime ps
sudo bash deploy/runtime.sh --runtime-dir /opt/libresign-runtime logs --tail=100 nextcloud nginx cron
sudo bash deploy/runtime.sh --runtime-dir /opt/libresign-runtime occ status --output=json
sudo bash deploy/runtime.sh --runtime-dir /opt/libresign-runtime stop
sudo bash deploy/runtime.sh --runtime-dir /opt/libresign-runtime up
```

`stop/up` giữ dữ liệu. Chạy lại đúng lệnh `install` để tiếp tục một lần cài lỗi. Source/runtime path, project name và admin UID phải giữ ổn định; script từ chối đổi identity hoặc tự nhận dữ liệu Nextcloud không thuộc bộ cài. Script không cập nhật nhánh source bằng `git pull` và không dùng các lệnh xóa volume.

Các biến Compose do bộ cài ghi vào runtime `.env` được dùng theo file đó; biến cùng tên đã export trong shell không ghi đè chúng. Muốn đổi URL, cổng hoặc thông tin triển khai, sửa `install.json` rồi chạy lại bộ cài. Quy tắc này tránh dùng nhầm cấu hình của một project khác trên cùng máy; kết nối Docker và các biến host không thuộc cấu hình runtime vẫn được giữ. Cơ chế gốc của Compose ưu tiên biến shell hơn `--env-file`, xem [tài liệu Docker](https://docs.docker.com/compose/how-tos/environment-variables/variable-interpolation/#ways-to-set-variables-with-interpolation).

Một bước cài thất bại có thể để cron dừng hoặc ứng dụng chưa cấu hình xong. Sau khi xử lý lỗi, chạy lại bộ cài và doctor; không coi chỉ có container running là cài thành công. Không chạy song song hai lần cài trên cùng runtime.

### Sau khi cập nhật code của fork

Giữ nguyên thư mục source, runtime, `projectName`, `adminUser` và credential đã có. Bản thêm màn quản lý/form nhập tay có thay đổi frontend; từ thư mục source, chạy đầy đủ:

```bash
sudo bash deploy/install.sh plan --runtime-dir /opt/libresign-runtime
sudo bash deploy/install.sh install --runtime-dir /opt/libresign-runtime
sudo bash deploy/install.sh doctor --runtime-dir /opt/libresign-runtime
```

Luồng này cài dependencies từ lockfile, build UI và giữ policy/CA/token hiện có. Không thêm migration cho màn quản lý; hai migration provider đã có chỉ chạy nếu còn thiếu. Sau khi cài, đăng nhập admin và tải lại trang bằng Ctrl + F5. Nếu muốn áp dụng policy đã sửa trong `install.json`, thêm `--update-client`; xem lại toàn bộ key policy trước khi áp dụng.

Khi chỉ thay PHP/policy và runtime đã có `build.json` khớp, các lệnh dưới bỏ bước build:

```bash
sudo bash deploy/install.sh plan --runtime-dir /opt/libresign-runtime --update-client
sudo bash deploy/install.sh install --runtime-dir /opt/libresign-runtime --skip-build --update-client
sudo bash deploy/install.sh doctor --runtime-dir /opt/libresign-runtime
```

Sửa `install.json` trước, ví dụ chọn `otpChannel=email`, `mockOtpEnabled=false` để dùng mã thật. `--update-client` áp dụng mọi key policy có khai báo trong file; xem lại cả scopes và các tùy chọn khác, không chỉ riêng OTP. Khi giữ policy hiện có, bỏ `--update-client`. Nếu thay frontend/lockfile, bỏ `--skip-build` để build lại. Bộ cài không tự `git pull`; checkout đúng code đã review trước khi chạy.

Nếu môi trường cũ được tạo bằng **`scripts/source-dev.sh`**, tiếp tục dùng wrapper đó từ thư mục Docker bên ngoài source; bộ cài mới không tiếp quản database cũ. Với bản có giao diện quản lý, build bằng user ứng dụng rồi khởi động lại PHP-FPM:

```bash
bash scripts/source-dev.sh nat build
bash scripts/source-dev.sh nat restart nextcloud
bash scripts/source-dev.sh nat occ libresign:provider:configure provider-demo
bash scripts/source-dev.sh nat occ libresign:configure:check
```

Các lệnh này không đổi chế độ OTP hoặc bật nhập hồ sơ thử. Muốn bật/tắt mock có chủ đích, xem [cấu hình màn quản lý](MANAGEMENT.md#bật-form-nhập-hồ-sơ-thử). Với môi trường local/tunnel của wrapper cũ, bỏ `nat`. Không chạy lại tạo CA để áp dụng giao diện/OTP.

Sao lưu khi đã dừng các tiến trình ghi: cả runtime (SQLite/config/data/appdata/CA/khóa/private credentials), source commit và submodule revision tương ứng. Mã nguồn Git không chứa các dữ liệu đó. Restore cần giữ đồng bộ database với file CA/private appdata và `secret` của Nextcloud. Bộ cài này không tự restore hay tạo lại CA khi thiếu dữ liệu.

Để vận hành production cần một kế hoạch triển khai riêng: cặp phiên bản stable tương thích, kiểm thử database production, HTTPS được tin cậy, SMTP thật, backup/restore đã diễn tập và giám sát cron/callback. Bộ NCDD dùng cấu hình phát triển, gồm debug và chính sách dành cho phát triển; không public nguyên bộ này như một cấu hình production đã nghiệm thu.

## 7. Kiểm thử bộ cài

Các kiểm tra không cần Docker/network:

```bash
bash -n deploy/install.sh deploy/runtime.sh deploy/host-deps.sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s deploy/tests
```

Kiểm thử PHP provisioning là opt-in và chỉ chạy trên snapshot SQLite/datadir có `instanceid` bắt đầu `providertest`; dùng `tests/php/Integration/Provider/prepare-isolated.php` rồi chạy `deploy/tests/provision-integration.php` với `NEXTCLOUD_CONFIG_DIR` của fixture. Không chạy bộ test ghi dữ liệu với config/database đang dùng.

Bằng chứng kiểm tra khi viết bộ cài, 24/10/2026: xem [ghi nhận kiểm chứng](VALIDATION.md). Tài liệu bàn giao cho bên tích hợp nằm tại [INTEGRATION.md](INTEGRATION.md).

Bàn giao kèm [tham chiếu API](API_REFERENCE.md), [OpenAPI public](examples/libresign-public.openapi.json), collection và environment mẫu trong `examples/`. Dùng `python3 deploy/export-public-api.py --check` tại thư mục source để kiểm tra bản OpenAPI public khớp route và độ phủ Postman. Sau khi cập nhật source contract, chạy `composer openapi` rồi `python3 deploy/export-public-api.py`.

Nguồn: [Docker Engine Ubuntu](https://docs.docker.com/engine/install/ubuntu/), [môi trường phát triển LibreSign](https://docs.libresign.coop/developer_manual/getting-started/development-environment/setup.html), [Nextcloud background jobs](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html), [Nextcloud backup](https://docs.nextcloud.com/server/latest/admin_manual/maintenance/backup.html). Các phiên bản pin và contract custom được đối chiếu trực tiếp với source của fork này.
