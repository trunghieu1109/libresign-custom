<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Tham chiếu API cho bên tích hợp

Đối chiếu source ngày **24/10/2026**. Đọc [hướng dẫn bắt đầu và các luồng](INTEGRATION.md) trước; tài liệu này bổ sung hợp đồng trường dữ liệu, trạng thái và lỗi. Dùng cùng revision với bản LibreSign được triển khai.

## Bộ tài liệu bàn giao

| Tệp | Cách dùng |
|---|---|
| [INTEGRATION.md](INTEGRATION.md) | Chuẩn bị kết nối, credential, cấp chứng thư và ký PDF từng bước |
| [OpenAPI public](examples/libresign-public.openapi.json) | Import Swagger Editor/UI, Postman hoặc công cụ sinh client; chỉ có 24 thao tác dành cho tích hợp |
| [Collection Postman](examples/libresign-public.postman_collection.json) + [environment mẫu](examples/libresign-public.postman_environment.json) | Gọi thử từng nhánh; điền credential riêng và thay dữ liệu mẫu |
| [API_REFERENCE.md](API_REFERENCE.md) | Bảng endpoint, input/output, trạng thái, callback và giới hạn |

Có thể gửi nguyên các tệp trên cho đối tác, giữ cấu trúc thư mục `examples/`. Chúng không chứa credential thực tế. Base URL trong environment là địa chỉ thử, phải thay bằng môi trường được bàn giao. Environment **private** do bộ cài xuất chứa App Password, chỉ bàn giao qua kênh secret cho đúng bên tích hợp.

`openapi-administration.json` phục vụ quản trị, **không có 24 API public**. `openapi.json`/`openapi-full.json` là đầu ra chung của Nextcloud: extractor không suy ra xác thực trong service, header chống lặp và toàn bộ trường đọc từ raw JSON. Bản public riêng bổ sung các phần đó và schema ký/callback. Không dùng lựa chọn anonymous của OpenAPI chung để gọi API này.

Người duy trì sinh lại bản public sau `composer openapi` bằng `python3 deploy/export-public-api.py`; kiểm tra bằng `python3 deploy/export-public-api.py --check`. Script đối chiếu route/controller, scope, OpenAPI gốc và độ phủ Postman; các ràng buộc trong service vẫn cần rà soát khi thay đổi nghiệp vụ.

## Endpoint và quyền

Ghép đường dẫn dưới đây sau base URL, ví dụ `https://sign.example.com/index.php/apps/libresign/api/v1/public`. Mọi thao tác cần **HTTP Basic UID + App Password**, scope và quyền sở hữu tài nguyên. `sessionId` là phiên cấp; `signSessionId`, `transactionId` và `id` của phiên ký có cùng giá trị. `transId` ngoài envelope là mã đối soát **riêng cho từng lần gọi HTTP**.

Quy ước scope: `C:R` = `certificates:read`; `C:W` = `certificates:write`; `C:X` = `certificates:revoke`; `S:R` = `signing:read`; `S:W` = `signing:write`. Cột khóa là header `Idempotency-Key` bắt buộc, không phải trường JSON.

| Method | Đường dẫn | Scope | Khóa | Input chính → `data` |
|---|---|---|---|---|
| GET | `/capabilities` | S:R | — | Không body → giới hạn, kênh OTP, loại tài liệu |
| GET | `/certificates/packages` | C:R | — | Không body → danh sách gói, `confirmationMethods` được bật |
| GET | `/signing-profiles` | S:R | — | Query phân trang → danh sách profile |
| POST | `/certificates/registration` | C:W | Có | Chủ thể, phương thức/cờ tái dùng → phiên cấp hoặc chứng thư ACTIVE |
| POST | `/registration-sessions/{sessionId}/identity/info` | C:W | — | Hồ sơ đã xác minh → `{session, faceMatch, certificate:null}` |
| POST | `/registration-sessions/{sessionId}/card-reader/info` | C:W | — | Hồ sơ + bằng chứng đầu đọc → cùng cấu trúc `/identity/info` |
| POST | `/registration-sessions/{sessionId}/otp` | C:W | — | `{otpCode}` → phiên có `step=VERIFIED` |
| POST | `/registration-sessions/{sessionId}/otp/resend` | C:W | — | `{}` → phiên và hạn OTP mới |
| POST | `/registration-sessions/{sessionId}/confirm` | C:W | Có | `{}` sau OTP, hoặc `{assertion}` → phiên và chứng thư |
| POST | `/registration-sessions/{sessionId}/cancel` | C:W | — | `{}` → phiên CANCELLED |
| GET | `/certificates/registration/{sessionId}` | C:R | — | Không body → phiên cấp hiện tại |
| GET | `/certificates/dossier-reuse` | C:R | — | Query `personalIdNumber` bắt buộc → điều kiện tái dùng hồ sơ |
| POST | `/registration-sessions/{sessionId}/reuse-face-match` | C:W | — | Ảnh mới + kết quả adapter → phiên cấp |
| GET | `/certificates` | C:R | — | Query CCCD tùy chọn + phân trang → danh sách chứng thư của UID |
| GET | `/certificates/mine` | C:R | — | Query phân trang → toàn bộ chứng thư của UID |
| GET | `/certificates/{certAlias}` | C:R | — | Không body → metadata chứng thư |
| GET | `/certificates/{certAlias}/public` | C:R | — | Không body → `{certAlias, format:"PEM", chain:[...]}` |
| POST | `/certificates/{certAlias}/revoke` | C:X | — | `{reasonCode, reasonText}` → metadata REVOKED |
| POST | `/signing-requests` | S:W | Có | PDF hoặc `files[]` → phiên PENDING, HTTP 202 |
| POST | `/signing-requests/direct-sign` | S:W | Có | Tạo phiên hoặc xác nhận bằng `signSessionId` + proof → phiên ký |
| POST | `/signing-requests/direct-sign/resend-otp` | S:W | — | `{signSessionId}` → phiên ký |
| GET | `/signing-requests/{transactionId}` | S:R | — | Không body → trạng thái và kết quả từng file |
| POST | `/signing-requests/{transactionId}/cancel` | S:W | — | `{}` → phiên CANCELLED, chỉ khi còn PENDING/chưa hết hạn |
| GET | `/signing-requests/{transactionId}/documents/{documentId}/result` | S:R | — | Không body → một phần tử kết quả, vẫn là JSON/Base64 |

Danh sách gói/profile/chứng thư có `data:[]` và `totalItem` **ngoài cùng**. Query phân trang dùng `pageIndex=1`, `pageSize=20` mặc định, `pageSize` từ 1–100. Không có cursor; không giả định thứ tự ổn định khi dữ liệu đang thay đổi. Trang profile từ 2 trở đi hiện trả danh sách rỗng vì chỉ có một profile.

## Quy tắc dữ liệu và input bắt buộc

Gửi JSON object với `Content-Type: application/json`; không dùng multipart. CCCD/OTP/ID/alias là chuỗi, boolean phải là `true`/`false`. Độ dài text phía backend tính theo **byte UTF-8**, nên chuỗi tiếng Việt có thể chạm giới hạn sớm hơn số ký tự hiển thị. Timestamp xác minh là RFC3339 không có phần lẻ giây, ví dụ `2026-10-24T10:00:00+07:00`; response thời gian public dùng UTC với `Z`.

### Mở phiên cấp

| Trường | Yêu cầu |
|---|---|
| `personalIdNumber`, `fullName`, `registrationMethod` | Bắt buộc; CCCD đúng 12 số, họ tên tối đa 128 byte, phương thức `CARD_READER` hoặc `QR_SELF_UPLOAD` |
| `email`, `phone` | Theo kênh OTP: email cần email; sms cần điện thoại; email_sms cần cả hai. Điện thoại VN `0xxxxxxxxx` được chuẩn hóa thành `+84...`; nhận E.164 |
| `packageCode`, `confirmationMethod` | Mặc định `internal-default`, `service_otp`; assertion chỉ dùng khi public key đã được đăng ký |
| `reuseActiveCertificate` | Mặc định `true`; lấy lại ACTIVE phù hợp với đúng chủ thể/liên hệ; `false` khi đã có ACTIVE trả `SYS-0030` |
| `reusePreviousDossier`, `requireReuseFaceMatch` | Mặc định `false`; policy server có thể bắt buộc face-match. ACTIVE phù hợp được ưu tiên dùng lại, kể cả khi yêu cầu dùng hồ sơ cũ |
| `callbackUrl` | Tùy chọn; HTTPS thuộc allowlist của UID |

Nếu có phiên PENDING chưa hết hạn với cùng dữ liệu, backend có thể trả phiên đó cùng `reusedSession=true`, dù dùng khóa tạo mới. Nếu dữ liệu khác, trả `identity_conflict`. Có nhiều chứng thư ACTIVE cho một CCCD thì không tự chọn; dùng `certAlias` rõ ràng khi ký và nhờ vận hành đối soát khi cấp.

### Nộp hồ sơ và đầu đọc

`identifyNumber`, `name`, `address`, `dateOfBirth`, `issueDate`, `expiredDate`, `sex`, `isPass=true` bắt buộc. CCCD và tên phải khớp phiên đã mở. Ngày nhận `dd/MM/yyyy` hoặc `yyyy-MM-dd`; ngày sinh ≤ ngày cấp ≤ hôm nay, ngày cấp < ngày hết hạn và CCCD chưa hết hạn.

`verification` là object gồm `method`, `source`, `reference`, `verifiedAt`. Các trường thiếu được backend điền theo phiên/UID/thời điểm nhận; adapter nên gửi biên nhận thực tế để đối soát. `source` phải thuộc `trustedSources` nếu có cấu hình. `verifiedAt` không được ở tương lai quá 60 giây.

`phone`, `email` có thể cập nhật khi nộp hồ sơ đã xác minh **trước lần chấp nhận đầu tiên**. Sau đó hồ sơ bị đóng băng: gửi lại cùng body có thể lấy kết quả, body khác trả `dossier_frozen`. Nếu gửi mã lỗi sau khi đã lưu hồ sơ, tra phiên và dùng API gửi lại OTP; đừng sửa hồ sơ chỉ để retry.

| Trường bằng chứng | Quy tắc |
|---|---|
| `imageCap`, `imageChip`, `sodData`, `score` | Bắt buộc với `/card-reader/info`; `score` là số nguyên 0–100. Với `/identity/info`, các bằng chứng này là tùy chọn |
| `dg1DataBase64`, `dg2DataBase64`, `dg13DataBase64`, `dg14DataBase64` | Bằng chứng chip tùy chọn, byte Base64; LibreSign không tự xác minh tính đúng của chip/SOD |
| `documentFrontBase64`, `documentBackBase64` | Ảnh giấy tờ tùy chọn |
| `nationality`, `nation`, `religion`, `hometown`, `character`, `fatherName`, `motherName`, `partnerName` | Text tùy chọn, tối đa 1024 byte mỗi trường |
| `mrz` | Tối đa 256 byte, chỉ chữ hoa ASCII, số, `<`, khoảng trắng, xuống dòng |

Mỗi bằng chứng tối đa **5 MiB** đã decode; tổng một hồ sơ tối đa **20 MiB**. Ảnh phải là PNG/JPEG hợp lệ, tối đa 16 triệu pixel. Ảnh không hợp lệ hoặc Base64/file vượt giới hạn riêng có thể trả `SYS-0003`/400; vượt tổng bằng chứng trả `evidence_too_large`/413.

Tái dùng hồ sơ cần `reusable=true`. `reasonCode` có thể là `ELIGIBLE`, `REUSE_NOT_ENABLED`, `NO_INITIAL_DOSSIER`, `IDENTITY_DOCUMENT_EXPIRED`. Nếu `nextAction=SUBMIT_FACE_MATCH`, gửi `portraitBase64` và `verification` có `score`, `isPass=true`, **`sessionId` đúng phiên**, `verifiedAt` mới (không trước lúc mở phiên quá 60 giây), cùng biên nhận adapter. Điểm phải ≥ ngưỡng server và ≤ `reuseFaceScale`. `threshold` request chỉ có thể nâng ngưỡng, không hạ policy. Ảnh mới PNG/JPEG tối đa 5 MiB/16 triệu pixel. Dùng lại hồ sơ không bỏ bước đồng ý/OTP/assertion cấp mới.

### Tạo và xác nhận ký

Khi tạo, gửi **`certAlias` hoặc `personalIdNumber`** và **`fileBase64` hoặc `files[]`**. Nếu gửi cả alias và CCCD thì phải cùng chủ thể. Khi chỉ gửi CCCD, phải tìm được đúng một chứng thư ACTIVE và có khóa được quản lý. Mảng `files[]` có 1–100 phần tử; mỗi phần tử có PDF riêng và `id` duy nhất (mặc định chuỗi index từ `"0"`). Lưu ID trả về, URL-encode ID khi ghép path.

| Trường ký | Quy tắc |
|---|---|
| `type`, `signingProfileCode` | Chỉ `PDF`, `DEFAULT_PDF`, cũng là mặc định |
| `documentName`, `signerName`, `reason` | Mặc định `document.pdf`, tên trên chứng thư, lý do rỗng; tối đa lần lượt 255/128/512 byte. `signerName` chỉ đổi tên hiển thị, không thay chủ thể chứng thư |
| `confirmationMethod`, `callbackUrl` | Chọn phương thức khi tạo và callback tùy chọn |
| `page`, `llx`, `lly`, `urx`, `ury` | Tọa độ point PDF, gốc dưới trái, đủ 4 giá trị và hình chữ nhật trong trang. `page` từ 1; `0`/`-1` là trang cuối; mặc định trang 1 khi có rectangle |
| `signatureSearchText` | Thay cho 4 tọa độ; phải khớp đúng một đoạn text trên toàn PDF, không OCR. Không gửi đồng thời keyword và tọa độ |
| `signatureImageBase64`, `stampBase64`, `logoBase64` | PNG đơn khung, mỗi ảnh ≤1 MiB/4 triệu pixel, cần vị trí hiển thị hợp lệ. Tổng PDF và ảnh ≤25 MiB đã decode |

PDF tối đa **5 MiB/file**, tổng lô gồm PDF và ảnh **25 MiB**. Không gửi cả `files[]` và PDF ở cấp ngoài. Cấu hình hiển thị cấp ngoài được từng file kế thừa; trường ở file ghi đè trường cùng tên. Thiếu vị trí/keyword thì ký không có hình hiển thị. `signatureFontSize` và `signatureFontColor` hiện bị từ chối. PDF đã ký phải cho phép thêm chữ ký theo DocMDP; cần nghiệm thu bằng mẫu PDF thực tế của bên tích hợp.

Các tên tương đương được nhận: `pdfBase64` → `fileBase64`; `signaturePage` → `page`; `signatureLlx/Lly/Urx/Ury` → `llx/lly/urx/ury`; `sealImageBase64` → `stampBase64`. Không gửi hai tên tương đương có giá trị khác nhau.

Xác nhận chỉ gửi `{signSessionId, otpValue}` hoặc `{signSessionId, assertion}`, có thể kèm `certAlias`/`personalIdNumber` khớp phiên. Không gửi file, ảnh hoặc cấu hình ký ở lần này (`snapshot_frozen`/409). Ký lô cũng xác nhận qua `/direct-sign`.

### Thu hồi và lấy chứng thư công khai

`POST /certificates/{certAlias}/revoke` nhận `reasonCode` nguyên trong `0,1,2,3,4,5,6,8,9,10` (mặc định `0`) và `reasonText` tối đa 512 byte (mặc định rỗng). Không có thao tác kích hoạt lại qua API này. Gọi lại với chứng thư đã thu hồi trả trạng thái hiện tại. Lấy chain PEM bằng endpoint `/public`, từ chứng thư chủ thể qua CA trung gian đến root; không xuất private key/PFX. CA nội bộ phải được bên kiểm tra cấu hình tin cậy theo yêu cầu của hệ thống họ.

## Trạng thái, retry và kết quả

Phiên cấp/ký hiện có hạn **15 phút** từ lúc tạo. OTP có hạn tối đa **5 phút**, không vượt hạn phiên. Tối đa **5 lượt gửi**, tối thiểu **60 giây** giữa hai lượt; lượt gửi lỗi cũng chiếm suất và làm mất hiệu lực mã/proof cũ. Tối đa 5 lượt kiểm tra proof; không thử mã hàng loạt. Phiên ký không có `otpExpiresAt` riêng; xử lý proof hết hạn bằng gửi lại nếu phiên còn PENDING.

| Phiên cấp: `status` / `step` / `nextAction` | Hành động |
|---|---|
| `PENDING` / `AWAITING_DOSSIER` / `SUBMIT_IDENTITY` | Nộp hồ sơ đã xác minh |
| `PENDING` / `FACE_MATCH_REQUIRED` / `SUBMIT_FACE_MATCH` | Gửi face-match mới cho tái dùng hồ sơ |
| `PENDING` / `AWAITING_CONFIRMATION` / `VERIFY_OTP` hoặc `CONFIRM_ASSERTION` | OTP → `/otp`, hoặc assertion → `/confirm` |
| `PENDING` / `VERIFIED` / `CONFIRM` | Gọi `/confirm` để cấp; verify OTP chưa cấp chứng thư |
| `ACTIVE` / `COMPLETE` / `USE_CERTIFICATE` | Lưu `certificate.certAlias`; kiểm tra trạng thái chứng thư riêng trước lần ký sau |
| `EXPIRED`, `CANCELLED`, `LOCKED` / `CREATE_NEW_SESSION` | Tạo luồng mới bằng khóa mới sau khi đối soát |
| `ISSUING`, `RECOVERY_REQUIRED` / `CONTACT_ADMIN` | Chờ/nhờ vận hành kiểm tra; không tự cấp một chứng thư khác để thay kết quả chưa rõ |

`ACTIVE` của phiên cấp mô tả lần cấp đã hoàn tất; chứng thư bên trong có thể đã `REVOKED`/`EXPIRED` sau đó. Đọc `certificate.status`/metadata chứng thư cho tính khả dụng hiện tại.

| Phiên ký | Ý nghĩa |
|---|---|
| `PREPARING` | Đang chốt dữ liệu; chưa gửi xác nhận |
| `PENDING` | Chờ OTP/assertion; xem `results[]` vì có file đã FAILED ở bước chuẩn bị |
| `PROCESSING` | Đã nhận xác nhận, đang xử lý; polling giãn cách 2–5 giây rồi tăng dần |
| `SUCCESS`, `PARTIAL_SUCCESS`, `FAILED` | Trạng thái cuối; đọc từng file, số thành công/lỗi và tải kết quả |
| `EXPIRED`, `CANCELLED`, `LOCKED` | Phiên đóng; đối soát trước khi mở yêu cầu mới |

`results[].status` gồm `PENDING`, `SIGNING`, `SUCCESS`, `FAILED`, `RECOVERY_REQUIRED`. Một file không chắc kết quả có thể là `RECOVERY_REQUIRED` trong khi trạng thái cả phiên là `FAILED`/`PARTIAL_SUCCESS`; cần vận hành đối soát file đó trước khi thử ký lại. GET chỉ đọc trạng thái; tiến trình nền tiếp tục các lô `PROCESSING`. Nếu trạng thái không tiến triển, kiểm tra cron/ProviderWorkflowJob.

Kết quả thành công có `signedData` Base64 và `outputHash` SHA-256 của **byte PDF sau decode**. Ký đơn còn có `data.signedData`; ký lô đọc `data.results[]`. `signedData=null` khi chưa có kết quả. Endpoint kết quả riêng cũng trả JSON object, kể cả khi file FAILED/chưa hoàn tất; không phải đường dẫn tải PDF nhị phân. Đối chiếu hash, chữ ký và fingerprint chứng thư rồi mới chốt giao dịch nội bộ.

Header chống lặp dùng 8–128 ký tự `[A-Za-z0-9_.:-]`. Phân biệt:

- **Tạo phiên:** khóa được gắn với dữ liệu; gửi lại cùng khóa/dữ liệu lấy phiên cũ, dữ liệu khác trả `idempotency_conflict`. Giữ khóa cũ có thể lấy lại cả phiên đã hết hạn/hủy. Chọn khóa mới cho một yêu cầu nghiệp vụ mới.
- **Xác nhận:** backend yêu cầu đúng định dạng header nhưng hiện xử lý chống lặp bằng trạng thái **phiên**, chưa lưu quan hệ khóa–body của thao tác xác nhận. Không giả định đổi proof cùng khóa sẽ luôn trả 409. Giữ khóa và body ổn định khi retry; proof sai có thể tốn thêm lượt thử.
- **Timeout/mất response:** nếu đã có ID, GET đối soát trước. Nếu mất response tạo, gửi lại đúng khóa/body để lấy ID. Không retry với khóa mới chỉ vì chưa biết kết quả cũ. Callback không thay cơ chế này.

## Callback gửi tới hệ thống tích hợp

Callback là POST HTTPS **JSON phẳng**, không có envelope `status/timestamp/transId/data` của API. Không có trường `eventType`; có thể cấu hình URL riêng cho cấp và ký. HMAC và event ID nằm ở header:

```http
Content-Type: application/json
X-Event-Id: <UUID sự kiện>
X-Event-Signature: <64 ký tự hex HMAC-SHA256, không có prefix sha256=>
```

Ví dụ callback **cấp thành công**, dữ liệu minh họa:

```json
{
  "subjectName": "Nguyễn Văn Test",
  "validFrom": "2026-10-24T03:00:00Z",
  "validTo": "2027-10-24T03:00:00Z",
  "status": "ACTIVE",
  "certAlias": "ALIAS_DA_CAP",
  "personalIdNumber": "001234567890",
  "sessionId": "UUID_PHIEN_CAP",
  "eventId": "UUID_SU_KIEN"
}
```

Callback ký là cấu trúc phiên ký (`transactionId`, `signSessionId`, `status`, `results[]`, `successCount`, `failedCount`, v.v.), bỏ `email`, `phone`, `challengeBase64`; thêm **`requestId` bằng ID phiên ký** và `eventId`. Ký đơn còn có `signedData` ở cấp ngoài. Schema đầy đủ nằm trong `SigningEvent` của OpenAPI public.

Chỉ cấp **mới** thành công và ký tới trạng thái cuối qua worker mới phát sự kiện; dùng lại chứng thư ACTIVE, hủy, hết hạn, khóa và thu hồi không có callback riêng. Không dùng callback để phát hiện tất cả thay đổi; duy trì polling/đối soát.

Xác minh HMAC trên **nguyên byte body nhận được**, so sánh constant-time, kiểm tra header/body cùng event ID và lưu chống trùng theo UID/môi trường + event ID. Retry có cùng event ID, không bảo đảm thời gian/thứ tự đến. Trả 2xx sau khi lưu bền vững, kể cả sự kiện đã xử lý. Không redirect; timeout gửi 15 giây, tối đa 8 lần thất bại. Các khoảng chờ tối thiểu lần lượt 120, 240, 480, 960, 1920, 3600, 3600 giây; cron có thể khiến thời gian thực tế dài hơn. Hết lượt thì polling là đường đối soát; hiện chưa có API để đối tác replay outbox.

## Lỗi cần xử lý theo mã

Lỗi từ controller có `status:false`, `data:null`, `error:{code,message}` và `transId`. Lỗi proxy/TLS/Nextcloud trước controller có thể trả HTML hoặc body khác; client kiểm tra HTTP và Content-Type trước khi parse.

| HTTP / `error.code` tiêu biểu | Xử lý |
|---|---|
| 400 / `SYS-0003`, `invalid_input`, `missing_contact`, `idempotency_key_required` | Sửa dữ liệu/header; không lặp vô hạn |
| 401 / `unauthenticated` | Kiểm tra App Password/UID; credential sai cũng có thể bị Nextcloud chặn trước controller |
| 403 / `forbidden`, `method_not_enabled`, `untrusted_adapter`, `callback_not_allowed` | Kiểm tra quyền/policy với vận hành |
| 403 / `invalid_proof` (cấp), `TOTP-0001` (ký), `SYS-0025` (face-match) | Xác nhận đúng phiên/phương thức/hạn; nhập proof mới khi cần |
| 404 / `SYS-0002`, `not_found` | Sai ID hoặc tài nguyên không thuộc UID |
| 409 / `idempotency_conflict`, `identity_conflict`, `dossier_frozen`, `snapshot_frozen`, `ambiguous_certificate` | Đối soát input và phiên đã tạo trước khi chọn luồng tiếp |
| 409 / `operation_busy`, `concurrent_update` | GET trạng thái, retry có giãn cách với cùng dữ liệu |
| 409 / `invalid_state`, `SYS-0031`, `SYS-0030`, `dossier_unavailable` | Kiểm tra phiên, chứng thư và điều kiện tái dùng; phiên ký đóng/hết hạn cũng dùng 409 |
| 400 hoặc 409 / `SYS-0058` | CCCD hết hạn: thu thập hồ sơ mới; không tái dùng |
| 410 / `SYS-0027`, `session_expired` | Phiên cấp hết hạn; tạo phiên mới sau đối soát |
| 413 / `evidence_too_large`, `batch_too_large` | Giảm tổng dung lượng; giới hạn riêng của file có thể hiện thành 400/lỗi từng file |
| 429 / `code_rate_limited` | Chờ đủ 60 giây và kiểm tra số lượt gửi; hiện không có cam kết header `Retry-After` |
| 503 / `delivery_failed`, `gateway_not_configured`, `ca_not_ready`, `invalid_assertion_key` | Vận hành khắc phục dịch vụ/cấu hình; nếu gửi OTP lỗi, không dùng mã từ lượt đó |
| 503 / `issuance_requires_recovery`, `credential_unavailable` | Vận hành đối soát trước khi cấp/ký lại |
| 500 / `PROVIDER_INTERNAL_ERROR` | Lưu `transId`, ID nghiệp vụ, khóa và hash; GET đối soát, gửi thông tin cho đầu mối vận hành |

Lỗi tài liệu như `keyword_not_unique`, `unsupported_signing_profile`, `signature_validation_failed` có thể nằm trong **`results[].errorCode` khi HTTP vẫn 200/202 và envelope `status=true`**. Không chỉ kiểm tra HTTP hoặc `error` ngoài cùng. Danh sách mã trên không đóng; client cần nhánh dự phòng cho mã chưa biết và giữ nguyên mã/thông tin đối soát.

Trước khi dùng dữ liệu thật, hai bên nghiệm thu credential/scope/tenant, cấp và ký PDF thực tế, OTP hoặc assertion, timeout/retry, lỗi từng file, callback trùng (nếu bật), thu hồi và khả năng phục hồi. Ghi rõ revision, base URL, mock OTP, thời gian chờ và đầu mối hỗ trợ trong biên bản bàn giao; không coi việc import OpenAPI/Postman thành công là nghiệm thu môi trường.
