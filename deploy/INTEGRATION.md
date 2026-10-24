<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Bàn giao tích hợp cấp chứng thư và ký PDF

Tài liệu dành cho backend của bệnh viện/doanh nghiệp/đối tác kết nối bản LibreSign custom. Hợp đồng ở đây là **Public JSON API** của fork, được đối chiếu với controller, service và collection ngày 24/10/2026. Người được cấp chứng thư/ký không cần tài khoản Nextcloud; mỗi hệ thống tích hợp dùng một tài khoản máy riêng.

Bộ bàn giao: [tham chiếu đủ 24 endpoint, trường dữ liệu và lỗi](API_REFERENCE.md), [OpenAPI public để import](examples/libresign-public.openapi.json), [collection Postman](examples/libresign-public.postman_collection.json) và [environment mẫu](examples/libresign-public.postman_environment.json). Gửi các tệp này cùng cấu trúc thư mục `examples/` cho bên tích hợp; credential bàn giao riêng. `openapi-administration.json` không chứa các API public này.

## 1. Hai bên cần chuẩn bị gì?

| Bên vận hành LibreSign cung cấp | Bên tích hợp chuẩn bị |
|---|---|
| Base URL theo môi trường, HTTPS và quy tắc network | Backend có thể gọi HTTPS, lưu secret và truy cập DNS/cổng đã thống nhất |
| UID tài khoản máy + Nextcloud App Password | Kho secret phía server; không nhúng credential vào JS/mobile app/trang QR |
| Scope được cấp, tên nguồn xác minh được phép | Định danh `verification.source` đúng `trustedSources` |
| Kênh OTP `email`/`sms`/`email_sms` đã cấu hình, hoặc đăng ký public key assertion | UI nhập mã đúng phiên; email/số điện thoại đã xác minh, hoặc adapter xác minh sự đồng ý và ký challenge |
| `packageCode`, profile, capabilities thực tế | PDF, thông tin chủ thể đã xác minh, cấu hình ký và lưu kết quả |
| Callback allowlist/secret nếu dùng callback | Endpoint HTTPS nhận callback, xác minh HMAC, chống trùng và cơ chế polling |
| Chính sách tái dùng hồ sơ/face-match, đầu mối hỗ trợ | Thực hiện xác minh CCCD/NFC/VNeID/face-match và lưu bằng chứng bên mình |

LibreSign không điều khiển đầu đọc, không tự kiểm tra chip CCCD/VNeID và không tính face-match. Adapter chịu trách nhiệm xác minh các dữ liệu đó trước khi gửi `isPass`, điểm và biên nhận. Không gửi hồ sơ giả/mẫu vào môi trường dữ liệu thật.

Hồ sơ bàn giao nên ghi rõ: tên môi trường; base URL; UID; scope; trusted source; phương thức xác nhận; email người nhận thử; callback hostname; policy tái dùng; giới hạn file; người liên hệ vận hành. App Password/callback secret truyền riêng qua kênh bảo mật. Chỉ assertion public key được gửi về LibreSign; private key của adapter ở lại hệ thống tích hợp.

### Phần tối thiểu phía tích hợp phải có

Phía cung cấp vận hành LibreSign/Nextcloud, CA, công cụ ký và cron. Phía tích hợp xây dựng **backend gọi HTTP API**, có thể dùng Java, .NET, Node.js, Python hoặc ngôn ngữ hỗ trợ HTTPS/JSON. Bộ Postman dùng để thử hợp đồng trước khi ghép vào ứng dụng.

1. **Kết nối:** backend truy cập được base URL qua DNS/cổng đã thống nhất, kiểm chứng TLS. Chuẩn bị IP egress cố định chỉ khi bên vận hành yêu cầu allowlist. Với UAT qua SSH tunnel, giữ tunnel và giải quyết hostname trên chính máy chạy client; `.localhost` trên máy đối tác trỏ về máy đó.
2. **Credential:** nhận UID và App Password riêng từ vận hành, lưu trong secret store phía backend và gửi HTTP Basic. Thống nhất scope/tên `verification.source`; đây là các cấu hình trên LibreSign, không tự cấp quyền bằng body request.
3. **Dữ liệu:** hồ sơ chủ thể đã xác minh và PDF thật. Với cấp chứng thư, cần CCCD dạng chuỗi 12 số, họ tên, ngày/thông tin hồ sơ và biên nhận xác minh; email/số điện thoại theo kênh OTP. Với ký, cần `certAlias` hợp lệ hoặc luồng cấp trước, PDF Base64 và cấu hình vị trí/ảnh nếu dùng.
4. **UI/adapter:** giao diện cho người dùng xem/chấp thuận hành động và nhập OTP, hoặc adapter giữ private key để xác nhận bằng assertion. Khi đọc CCCD/NFC/VNeID/face-match, chuẩn bị SDK/thiết bị/dịch vụ và xử lý xác minh ở phía adapter.
5. **Lưu trạng thái:** liên kết mã nghiệp vụ nội bộ với `Idempotency-Key`, body đã chốt, `sessionId`, `certAlias`, `signSessionId`/`transactionId`, document ID, trạng thái và hash file. Có cơ chế polling/retry và lưu byte PDF đã ký sau khi decode Base64; không tạo yêu cầu mới chỉ vì một lần gọi timeout.

Scope tối thiểu cho cấp và ký: `certificates:read`, `certificates:write`, `signing:read`, `signing:write`; thêm `certificates:revoke` khi được giao quyền thu hồi. Nếu chỉ tra cứu hoặc chỉ ký bằng chứng thư đã có, xin đúng nhóm quyền phục vụ chức năng đó.

### Những phần chỉ cần khi chọn chức năng tương ứng

| Chức năng | Phía tích hợp chuẩn bị | Phía vận hành cấu hình |
|---|---|---|
| Email OTP | Email người nhận và UI nhập mã | SMTP thật hoặc Mailpit cho UAT |
| SMS OTP — làm sau khi cần | Số điện thoại được xác minh và UI nhập mã khi kích hoạt SMS | Tùy chọn cài sẵn app gateway; cấu hình provider và `otpGateway` khi bắt đầu dùng SMS thật |
| Email + SMS | Cả email và số điện thoại | Cả hai dịch vụ; cùng một mã gửi hai nơi, một lần nhập |
| Assertion | RSA ≥2048 bit, private key ở backend/HSM của adapter, ký challenge sau khi có sự đồng ý | Đăng ký public key PEM của adapter cho đúng UID |
| Callback | Endpoint HTTPS, đọc raw body, xác minh HMAC và chống xử lý trùng event ID | Allowlist hostname và callback secret chia sẻ riêng |
| Trang QR thật | Trang HTTPS và backend adapter quản lý phiên | `registrationBaseUrl`; tắt QR mock khi dùng trang thật |
| Tái dùng hồ sơ/face-match | Dịch vụ xác minh ảnh, thang điểm và biên nhận đúng policy | Cho phép tái dùng hồ sơ, ngưỡng/thang điểm và quy tắc yêu cầu face-match |

Có thể bắt đầu bằng **email OTP + polling**, chưa cần callback, RSA assertion hoặc cấu hình SMS. Phía vận hành có thể cài sẵn app SMS gateway mà vẫn giữ email; chi tiết dịch vụ SMS làm sau. Phía tích hợp nhận chứng thư công khai/chain qua API khi cần kiểm tra kết quả; CA và private key ký do LibreSign quản lý trong mô hình hiện tại.

### Thông tin cần chốt khi bàn giao một môi trường

```text
Môi trường: UAT / môi trường thật
Base URL: https://sign.example.com/index.php/apps/libresign/api/v1/public
UID tích hợp: <UID được cấp>
App Password: <bàn giao riêng qua kênh secret>
Scopes: <danh sách đã được vận hành bật>
verification.source / trustedSources: <tên adapter được phép>
confirmationMethod: service_otp hoặc external_assertion
otpChannel: email / sms / email_sms
mockOtpEnabled: false cho xác minh mã thật; true chỉ khi hai bên chủ động thử mock
Địa chỉ email / số điện thoại nhận mã UAT: <liên hệ có thể nhận thật>
packageCode / signingProfileCode: lấy từ API danh mục
Callback URL + cách chia sẻ secret: <nếu dùng>
Assertion public key + nơi giữ private key: <nếu dùng>
Chính sách tái dùng hồ sơ, face-match: <nếu dùng>
Giới hạn payload / timeout / đầu mối vận hành: <hai bên thống nhất>
```

Các dòng mô tả policy trong bảng bàn giao là cấu hình vận hành; không đưa `mockOtpEnabled`, `otpChannel` hay `otpGateway` vào request API để bật tính năng.

## 2. URL, xác thực và request chung

```text
https://sign.example.com/index.php/apps/libresign/api/v1/public
```

Mọi endpoint trong tài liệu được ghép sau base URL. `public` là namespace; API vẫn kiểm tra xác thực, scope và quyền sở hữu tài nguyên.

```http
Authorization: Basic base64(UID:APP_PASSWORD)
Accept: application/json
Content-Type: application/json
Idempotency-Key: partnerA-registration-create-000001
```

HTTP Basic phải đi qua HTTPS được kiểm chứng trên môi trường thật. Tài khoản máy không cần admin. `certAlias`, phiên đăng ký và phiên ký thuộc UID đã tạo; đổi sang UID khác sẽ không đọc được cùng tài nguyên.

Các scope:

| Scope | Quyền |
|---|---|
| `certificates:read` | Danh mục gói, trạng thái đăng ký, tra cứu chứng thư/hồ sơ/PEM |
| `certificates:write` | Tạo/nộp hồ sơ/xác nhận/hủy đăng ký, gửi và kiểm tra OTP cấp |
| `certificates:revoke` | Thu hồi chứng thư |
| `signing:read` | Capabilities/profile, trạng thái ký và PDF kết quả |
| `signing:write` | Tạo/xác nhận/hủy ký, gửi lại OTP ký |

POST tạo phiên và xác nhận cấp/ký cần `Idempotency-Key`: 8–128 ký tự chữ/số hoặc `_ - . :`. Mỗi thao tác có khóa riêng. Retry cùng thao tác phải giữ khóa và toàn bộ body; không tự tạo timestamp mới trong body khi retry. **Khi tạo phiên**, tái sử dụng khóa với dữ liệu khác gây xung đột 409; giữ khóa cũ có thể trả lại phiên cũ đã hết hạn. Một yêu cầu nghiệp vụ mới cần khóa mới. **Khi xác nhận**, backend kiểm tra định dạng header và dùng trạng thái phiên để chống cấp/ký lặp, chưa gắn khóa với body/proof; proof sai khi retry có thể tốn thêm lượt thử. Xem [quy tắc retry](API_REFERENCE.md#trạng-thái-retry-và-kết-quả).

## 3. Kiểm tra kết nối trước

Gọi ba API bằng tài khoản máy:

```text
GET /capabilities
GET /certificates/packages
GET /signing-profiles?pageIndex=1&pageSize=20
```

Ví dụ curl sẽ hỏi App Password kín trên terminal:

```bash
curl --fail-with-body --user provider-client \
  -H 'Accept: application/json' \
  'https://sign.example.com/index.php/apps/libresign/api/v1/public/capabilities'
```

Hiện hỗ trợ PDF, tối đa 100 file/lô, 5 MiB/file và 25 MiB tổng byte đã decode của PDF cùng ảnh trong lô. Đọc capabilities từ hệ thống đích trước khi gửi; đặt giới hạn payload ở HTTP gateway đủ cho Base64 (lớn hơn byte nhị phân khoảng một phần ba) và JSON. Profile hiện là `DEFAULT_PDF`, gói kỹ thuật `internal-default`; đây không phải gói thương mại.

`GET /capabilities` trả `otpChannel` của UID đang gọi và `supportedOtpChannels=["email","sms","email_sms"]`. Kênh đang dùng do vận hành chọn; danh sách hỗ trợ không xác nhận gateway đã giao SMS/email thành công. Chốt môi trường có mock hay không qua vận hành vì API response không đưa cờ mock ra client.

## 4. Luồng cấp chứng thư qua OTP

Môi trường tích hợp có thể bật **mock OTP ở backend** bằng policy `mockOtpEnabled=true` của tài khoản máy. Khi bật, mã bất kỳ gồm đúng 6 chữ số ASCII đều hợp lệ, ví dụ `000000` hoặc `123456`; không cần khớp mã thực tế đã gửi. Luồng giao mã theo kênh được cấu hình và toàn bộ API contract giữ nguyên: vẫn nộp hồ sơ, gửi OTP, gọi `/otp` với `{otpCode}` rồi `/confirm`; ký tài liệu tiếp tục dùng `{signSessionId, otpValue}`. Backend không đọc cờ mock từ request và không thêm trường mock vào response.

Chế độ mock vẫn yêu cầu lượt gửi OTP thành công, OTP/phiên còn hạn và đúng bước xác nhận. Mã thiếu/thừa số, có chữ hoặc khoảng trắng vẫn bị từ chối. SMS là tùy chọn; với `otpChannel=email` không cần cài gateway SMS. `mockOtpEnabled` mặc định tắt khi không cấu hình; khi tắt, backend kiểm tra đúng mã đã gửi. Đây là cấu hình phục vụ thử tích hợp.

Ví dụ dưới dùng email. Khi vận hành chọn `sms`, hồ sơ cần số điện thoại và người dùng nhận mã qua SMS; chọn `email_sms` cần cả hai liên hệ, cùng một mã được gửi qua hai kênh và nhập một lần. Nhận mã qua một trong hai kênh không xác minh độc lập quyền sở hữu cả hai liên hệ. Mock vẫn yêu cầu gateway/dịch vụ tương ứng hoạt động; nó không thay thế dịch vụ gửi OTP. Nếu một kênh lỗi trong chế độ hai kênh, API trả `delivery_failed`; xử lý lỗi dịch vụ rồi gửi lại thay vì dùng mã từ lượt gửi thất bại.

**Bước A — mở phiên:** `POST /certificates/registration`, khóa tạo phiên mới.

```json
{
  "personalIdNumber": "001234567890",
  "fullName": "Nguyễn Văn Test",
  "phone": "0912345678",
  "email": "signer@example.com",
  "registrationMethod": "CARD_READER",
  "packageCode": "internal-default",
  "confirmationMethod": "service_otp",
  "reuseActiveCertificate": true,
  "reusePreviousDossier": false,
  "requireReuseFaceMatch": false
}
```

Lưu `data.sessionId`, `data.status`, `data.step`, `data.nextAction`. Nếu API trả `ACTIVE` và `reusedCertificate=true`, dùng `data.certificate.certAlias`; bỏ nhánh nộp hồ sơ/OTP cấp mới.

**Bước B — adapter nộp hồ sơ đã xác minh:** `POST /registration-sessions/{sessionId}/identity/info`:

```json
{
  "identifyNumber": "001234567890",
  "name": "Nguyễn Văn Test",
  "address": "Hà Nội",
  "dateOfBirth": "01/01/1990",
  "issueDate": "01/01/2021",
  "expiredDate": "01/01/2030",
  "sex": "Nam",
  "isPass": true,
  "verification": {
    "method": "adapter",
    "source": "provider-client",
    "reference": "partnerA-identity-receipt-000001",
    "verifiedAt": "2026-10-24T00:00:00Z"
  }
}
```

Thay hồ sơ và thời điểm bằng dữ liệu đúng của lần xác minh thực tế. `source` phải được cho phép; `reference` là mã biên nhận để đối soát. Không lấy timestamp cố định trong ví dụ cho các lần xác minh sau. Có thể dùng `/card-reader/info` thay `/identity/info` nếu gửi bộ dữ liệu đầu đọc; các field ảnh/SOD theo collection và OpenAPI.

Response bước này nằm trong `data.session`, kèm `data.faceMatch` và `data.certificate`. Khi hồ sơ được chấp nhận theo nhánh OTP, hệ thống gửi mã theo `otpChannel` tới liên hệ được lưu trong phiên. Theo dõi `nextAction`, `otpChannel` và `otpExpiresAt`.

**Bước C — kiểm tra OTP cấp:** `POST /registration-sessions/{sessionId}/otp`:

```json
{"otpCode": "123456"}
```

Kết quả hợp lệ chuyển sang `step=VERIFIED`; chứng thư chưa được cấp ở bước này.

**Bước D — xác nhận cấp:** `POST /registration-sessions/{sessionId}/confirm`, body `{}`, với một `Idempotency-Key` riêng cho thao tác xác nhận. Đọc `ACTIVE`, `step=COMPLETE` và lưu `certificate.certAlias`, serial, fingerprint, thời hạn.

Tra cứu khi chờ/mất kết nối: `GET /certificates/registration/{sessionId}`. Gửi lại OTP cấp: `POST /registration-sessions/{sessionId}/otp/resend`, body `{}`. OTP gồm 6 số, tối đa 5 phút và không vượt hạn phiên; gửi lại cách tối thiểu 60 giây, giới hạn số lần gửi/thử. Đọc lỗi 410/429 và trường hạn trả về thay vì tự giả định phiên vẫn còn hiệu lực.

## 5. Luồng ký một PDF

**Bước A — gửi file:** `POST /signing-requests/direct-sign`, khóa tạo phiên ký mới:

```json
{
  "certAlias": "ALIAS_DA_CAP",
  "personalIdNumber": "001234567890",
  "type": "PDF",
  "signingProfileCode": "DEFAULT_PDF",
  "documentName": "contract.pdf",
  "fileBase64": "BASE64_CUA_PDF_THAT",
  "confirmationMethod": "service_otp"
}
```

Lưu `data.signSessionId`/`transactionId`. Hệ thống gửi OTP ký riêng. Không dùng lại OTP cấp chứng thư.

**Bước B — xác nhận ký:** gọi lại `POST /signing-requests/direct-sign`, khóa riêng cho xác nhận:

```json
{"signSessionId": "UUID_PHIEN_KY", "otpValue": "123456"}
```

Sau khi tạo phiên, chỉ gửi proof và định danh tương ứng khi xác nhận; không gửi lại file hoặc thay vị trí/ảnh/chủ thể của phiên đã chốt. Thay đổi nội dung cần tạo yêu cầu nghiệp vụ mới và xác nhận lại.

**Bước C — lấy kết quả:** `GET /signing-requests/{transactionId}`. Nếu còn `PROCESSING`, polling có giãn cách, ví dụ 2–5 giây và tăng dần khi chờ lâu. Chỉ chốt thành công khi trạng thái nghiệp vụ là `SUCCESS` hoặc đã xử lý `PARTIAL_SUCCESS` theo từng file.

PDF kết quả ở `signedData` Base64. Có thể lấy riêng bằng `GET /signing-requests/{transactionId}/documents/{documentId}/result`; ký đơn mặc định `documentId=0`. Decode thành byte PDF, lưu file và đối soát chữ ký/fingerprint theo yêu cầu nghiệp vụ.

Ký lô dùng `POST /signing-requests` với `files[]`, mỗi file có `id` riêng, sau đó xác nhận qua `direct-sign` bằng ID phiên lô. HTTP 202 chỉ thể hiện tiếp nhận. Đọc `results[].status`, `errorCode`, hash, `successCount` và `failedCount`; không coi toàn bộ lô thành công khi một file thành công. Không tự gửi lại một lô đã xác nhận bằng khóa mới chỉ vì timeout; polling phiên cũ trước.

Tọa độ chữ ký là point PDF, gốc dưới trái; `page` bắt đầu từ 1 (`0`/`-1` là trang cuối). Hỗ trợ vị trí theo tọa độ hoặc keyword duy nhất, không OCR. Chi tiết ảnh chữ ký/con dấu/logo, giới hạn và DocMDP xem [tham chiếu input ký](API_REFERENCE.md#tạo-và-xác-nhận-ký).

## 6. Assertion, QR và tái dùng hồ sơ

**Assertion:** bên tích hợp tạo RSA keypair ≥2048 bit và gửi public key PEM để cấu hình `assertionPublicKeyFile`. Chọn `confirmationMethod=external_assertion`; decode `challengeBase64`, ký **đúng byte nhận được** bằng RSA-SHA256, PKCS#1 v1.5, rồi Base64 chữ ký vào `assertion`. Không parse/serialize lại challenge, không ký chuỗi Base64. Challenge cấp nằm trong `data.session.challengeBase64` của bước nộp hồ sơ hoặc response trạng thái; challenge ký nằm ở phiên ký. Hai proof tách mục đích, không dùng chéo. Backend adapter chỉ ký sau khi xác minh sự đồng ý cho đúng hành động/tài liệu; private key không ra trình duyệt hoặc gửi về LibreSign.

**QR:** mở phiên bằng `registrationMethod=QR_SELF_UPLOAD`, nhận `registrationUrl`/`qrCode`. Mở nguyên URL được trả, bao gồm token. Khi dùng trang adapter thật, cấu hình `registrationBaseUrl` HTTPS; backend adapter gọi API bằng credential máy, trình duyệt không giữ credential đó. `mockRegistrationEnabled` chỉ là trang thử nhập OTP/đồng ý, không thực hiện eKYC thật.

**Hồ sơ cũ:** gọi `GET /certificates/dossier-reuse?personalIdNumber=...`; đọc `reusable`, hạn CCCD và policy yêu cầu face-match. Chứng thư ACTIVE phù hợp được tái dùng khi cho phép; chứng thư hết hạn/thu hồi mở phiên cấp lại. Tái dùng hồ sơ cần `edgeDeployment` được vận hành cho phép; khi yêu cầu face-match, adapter gửi ảnh mới và kết quả đã xác minh vào `/registration-sessions/{sessionId}/reuse-face-match`. Ngưỡng/thang điểm phải khớp policy; điểm mẫu trong Postman không chứng minh định danh thực tế.

## 7. Callback và đối soát

Callback là tùy chọn; polling vẫn dùng được khi không có callback. Đăng ký hostname HTTPS trong `callbackHosts` và chia sẻ `callbackSecret` ≥32 ký tự. Request tạo phiên gửi `callbackUrl` đúng allowlist. Endpoint nhận callback cần:

1. Đọc nguyên byte body UTF-8 trước khi parse JSON.
2. Tính `HMAC-SHA256(callbackSecret, rawBody)`, biểu diễn hex và so sánh constant-time với `X-Event-Signature`.
3. Đối chiếu `X-Event-Id`/`eventId`, lưu chống xử lý trùng và liên kết tới request/transaction.
4. Chỉ phản hồi HTTP 2xx sau khi tiếp nhận bền vững. Hệ thống không theo redirect; lỗi được thử lại tối đa 8 lần với thời gian chờ tăng dần.

Ví dụ logic Python ở backend nhận callback:

```python
expected = hmac.new(callback_secret.encode("utf-8"), raw_body, hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, received_signature):
    reject_request()
```

Callback ký có thể chứa PDF Base64/kết quả từng file; cấu hình giới hạn body phù hợp. Đừng tin chỉ IP nguồn; xác minh HMAC, event ID và đối soát phiên bằng API khi cần. Không ghi raw hồ sơ/PDF/OTP/credential vào access log hay error log phía tích hợp.

Callback gửi JSON phẳng, không bọc `data` và không có `eventType`. Xem [payload cấp/ký, các trường hợp phát sự kiện và lịch retry](API_REFERENCE.md#callback-gửi-tới-hệ-thống-tích-hợp); hủy, hết hạn, thu hồi hoặc dùng lại chứng thư ACTIVE không có callback riêng.

## 8. Đọc kết quả và xử lý lỗi

Envelope public:

```json
{"status": true, "timestamp": "2026-10-24T00:00:00Z", "transId": "correlation-id", "data": {}}
```

`status` ngoài cùng là kết quả lời gọi; `data.status` và từng file mới là kết quả nghiệp vụ. Danh sách có `data: []`, `totalItem` ngoài cùng, `pageIndex` bắt đầu từ **1**. Lỗi có `status:false`, `data:null`, `error.code` và `error.message`. Lưu `transId` để hỗ trợ đối soát, cùng ID nghiệp vụ/Idempotency-Key và hash file.

| HTTP | Cách xử lý |
|---|---|
| 400 | Sửa input theo `error.code`; không retry nguyên dữ liệu sai vô hạn |
| 401 | Kiểm tra UID/App Password, tài khoản bị disable/token bị revoke |
| 403 | Kiểm tra scope, trusted source, proof/OTP, allowlist hoặc policy |
| 404 | Kiểm tra ID và UID sở hữu; không chuyển credential tenant khác để dò tài nguyên |
| 409 | Đối soát idempotency/body/trạng thái; polling yêu cầu cũ trước khi tạo mới |
| 410 | Phiên hết hạn; tạo luồng mới và thu thập xác nhận mới |
| 413 | Giảm tổng dung lượng/lô hoặc điều chỉnh gateway nếu giới hạn nằm ở gateway |
| 429 | Chờ theo hạn/rate limit; không tạo phiên hàng loạt để vượt giới hạn OTP |
| 500/503 hoặc timeout | Tra trạng thái bằng ID cũ; retry thao tác an toàn với cùng khóa/body, báo vận hành khi trạng thái chưa chắc chắn |

CCCD phải là chuỗi 12 chữ số; điện thoại nhận dạng VN hoặc E.164; ngày CCCD `dd/MM/yyyy` hoặc `yyyy-MM-dd`; timestamp RFC3339 không có phần mili giây. Base64 thuần không có prefix `data:...`; API nhận nội dung file, không nhận đường dẫn file trên server. API không xuất private key/PFX/mật khẩu chứng thư.

## 9. Bộ kiểm thử bàn giao

Import [collection public](examples/libresign-public.postman_collection.json) và mẫu environment cùng thư mục. Bộ cài xuất environment private đã điền credential tại runtime; nó giữ đầy đủ biến và PDF mẫu của mẫu public. Chọn đúng environment, sửa `signerEmail`, thông tin hồ sơ thử và URL theo môi trường. Không gửi environment private lên Git.

Các luồng chính trong collection:

| Luồng | Thứ tự |
|---|---|
| Kết nối và danh mục | 01 → 02 → 03 |
| Cấp qua OTP của kênh đã cấu hình | 04 → 05 hoặc 05B → lấy OTP → 06 → 07 → 08 |
| Tra cứu/tái dùng chứng thư ACTIVE | 09 → 10 → 11 → 12 → 13 → 14 |
| Ký một PDF | 15 → lấy OTP ký mới → 16 → 17 → 18 |
| Lô có file thành công/lỗi | 19 → lấy OTP lô → 20 → 21 |
| Cấp lại, QR, assertion | Các nhánh R, Q, E, A; theo trạng thái và điều kiện thực tế |

Không chạy thẳng toàn collection bằng Runner: có bước nhập OTP/assertion và các nhánh có điều kiện/thu hồi. Mã cấp, mã ký đơn và mã ký lô là các biến khác nhau. Khi vận hành đã bật mock OTP, có thể điền `123456` vào các biến OTP đó, giữ nguyên tên trường/body và thứ tự gọi. Khi mock tắt, email trong Mailpit của bản thử có thể mở qua shared proxy `https://TEN_PROJECT-mailpit.localhost`, hoặc cùng cổng local 8443 nếu dùng tunnel. Với SMTP thật, lấy mã tại hộp thư người nhận; với SMS, lấy mã từ điện thoại. Đổi biến Postman không đổi kênh OTP trên server.

Tiêu chí nghiệm thu: credential đúng/sai; thiếu scope; tenant khác; tạo/cấp/tra cứu thật; retry cùng khóa không cấp/ký trùng; OTP sai/hết hạn/gửi lại; ký PDF hợp lệ và kiểm tra chữ ký; lô lỗi từng phần; polling/callback trùng; thu hồi; quyền sở hữu; khôi phục sau gián đoạn. Đồng bộ giờ máy qua NTP để timestamp/OTP/challenge không lệch.

Các API OCS cũ ở `/ocs/v2.php/apps/libresign/api/v1/provider` có envelope `ocs.data`, phân trang từ 0 và nghĩa `/otp` khác. Hãy dùng collection public và hợp đồng trong tài liệu này cho tích hợp mới. Phần chưa có: ký XML/HASH, điều khiển tablet/máy trạm, eKYC/face-match nội bộ, CA ngoài/HSM và quản lý gói thương mại.

Nguồn contract: `lib/Controller/PublicProviderController.php`, `lib/Service/Provider/`, OpenAPI sinh từ source và phần bổ sung contract trong `deploy/export-public-api.py`. Bên tích hợp dùng [bản OpenAPI public](examples/libresign-public.openapi.json) của chính revision được triển khai. Khi hai bên cập nhật phiên bản, đối chiếu lại OpenAPI/capabilities và nghiệm thu thay đổi trước khi chuyển môi trường.
