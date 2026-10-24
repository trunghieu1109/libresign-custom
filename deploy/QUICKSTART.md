<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Hướng dẫn tích hợp thử LibreSign: cấp chứng thư và ký PDF

Tài liệu dành cho lập trình viên tích hợp hệ thống với LibreSign. Làm lần lượt các bước dưới đây để **gọi API, cấp một chứng thư, ký một PDF và lưu file đã ký**. Có thể thử bằng Postman trước khi viết backend.

Phía tích hợp gọi API từ **backend của mình** bằng tài khoản máy được cấp. Người được cấp chứng thư/ký tài liệu không cần tài khoản Nextcloud. Bên vận hành LibreSign phụ trách cài đặt server, CA, công cụ ký và tác vụ nền.

Bên vận hành có thể theo dõi dữ liệu qua [màn quản lý](MANAGEMENT.md) bằng admin. Tài khoản tích hợp vẫn dùng Postman/backend theo hướng dẫn này; không cần cấp quyền admin hoặc chuyển sang API `/management/*`.

## 1. Thông tin máy thử hiện tại

Thông tin bàn giao ngày **24/10/2026**, dành cho dữ liệu thử. Khi chuyển sang máy/môi trường khác, nhận lại cấu hình tương ứng từ bên vận hành.

Địa chỉ dưới đây thuộc máy thử đang hoạt động. Bộ cài mới dùng host HTTP `18085`, HTTPS local `18443` và Mailpit local `18025`; việc đổi mặc định bộ cài không đổi địa chỉ `19841 → 8085` của máy thử hiện tại. Xem [bảng cổng bộ cài](README.md#cổng-của-bộ-cài-mới) khi dựng server mới.

| Thông tin | Giá trị |
|---|---|
| Địa chỉ server | `http://76.71.171.67:19841` |
| API base URL, dùng cho biến `baseUrl` | `http://76.71.171.67:19841/index.php/apps/libresign/api/v1/public` |
| UID tài khoản thử, dùng cho `apiUser` | `provider-demo` |
| App Password, dùng cho `apiAppPassword` | Bên vận hành bàn giao riêng; không nằm trong tài liệu này |
| Xác thực | HTTP Basic: UID + **Nextcloud App Password** |
| Quyền tài khoản thử | `certificates:read`, `certificates:write`, `certificates:revoke`, `signing:read`, `signing:write` |
| Luồng xác nhận bắt đầu | `confirmationMethod=service_otp`, kênh `email` |
| OTP thử | Máy này đã bật mock OTP cho `provider-demo`: có thể dùng `123456` sau khi gửi OTP thành công |
| Nguồn xác minh hồ sơ thử | `verification.source=provider-demo` |
| Gói chứng thư / profile ký | `internal-default` / `DEFAULT_PDF`; kiểm tra lại bằng API danh mục |

Không cần domain để thử bằng IP và port trên. Địa chỉ hiện dùng HTTP nên Basic Auth chưa được mã hóa trên đường truyền; nên dùng đường kết nối được bảo vệ như VPN/tunnel khi bàn giao credential. Khi dùng dữ liệu thật, hai bên cấu hình HTTPS và credential riêng. Nếu vận hành bàn giao địa chỉ truy cập qua tunnel/proxy, điền địa chỉ đó vào `baseUrl`.

`public` là tên nhóm API; **các API vẫn bắt buộc xác thực**. Không dùng mật khẩu admin hoặc mật khẩu đăng nhập thông thường thay cho App Password. Phiên và chứng thư thuộc UID tạo ra chúng; đổi tài khoản có thể không đọc được tài nguyên cũ.

## 2. Phía tích hợp cần chuẩn bị gì?

| Cần chuẩn bị | Dùng để làm gì? |
|---|---|
| Máy chạy Postman Desktop, truy cập được IP/cổng đã bàn giao | Thử API trực tiếp từ mạng của bên tích hợp |
| UID và App Password nhận riêng | Gọi API; giữ credential ở máy thử/kho secret của backend |
| Thông tin người thử | CCCD dạng **chuỗi 12 số**, họ tên, email, điện thoại và hồ sơ tại mục 5 |
| PDF | Có thể dùng PDF mẫu đã nằm trong environment; sau đó thay bằng PDF nghiệp vụ |
| Backend lưu được trạng thái và file | Liên kết mã nghiệp vụ với phiên cấp, chứng thư, phiên ký và PDF kết quả |

Chưa cần cấu hình SMS, callback, QR, đầu đọc CCCD hoặc khóa RSA để thử luồng trong tài liệu này. Giữ callback trống và dùng polling lấy kết quả.

Khi tích hợp định danh thật, phía tích hợp chuẩn bị thiết bị/SDK/dịch vụ xác minh CCCD, NFC, VNeID hoặc face-match theo nghiệp vụ của mình. LibreSign nhận hồ sơ và biên nhận **đã được xác minh**; không tự điều khiển đầu đọc hay tính điểm face-match. Dữ liệu mẫu và `isPass=true` trong Postman chỉ phục vụ môi trường thử.

## 3. Nhận tệp và cấu hình Postman

Nhận các tệp sau cùng phiên bản với server:

- [Collection Postman](examples/libresign-public.postman_collection.json).
- [Environment mẫu](examples/libresign-public.postman_environment.json), hoặc environment private do vận hành bàn giao qua kênh riêng.
- [OpenAPI public](examples/libresign-public.openapi.json) nếu cần xem schema hoặc sinh client.

Import collection và **một** environment vào Postman, rồi chọn environment đó cho các request. Trong Authorization, các request kế thừa Basic Auth từ collection, dùng `{{apiUser}}` và `{{apiAppPassword}}`.

Điền các biến sau trong environment:

| Biến | Cách điền khi thử máy hiện tại |
|---|---|
| `baseUrl` | API base URL ở mục 1, không thêm dấu `/` cuối |
| `apiUser` | `provider-demo`; mẫu chung đang để `provider-client`, cần thay lại |
| `apiAppPassword` | App Password được cấp riêng |
| `personalIdNumber` | Ví dụ `001234567890`; giữ số 0 ở đầu, dùng dữ liệu thử đã thống nhất |
| `fullName` | Ví dụ `Nguyễn Văn Test`; dùng thống nhất giữa tạo phiên và nộp hồ sơ |
| `phone` | Ví dụ `0912345678` |
| `signerEmail` | Email thử đã thống nhất; khi dùng OTP thật phải lấy được mã gửi đến email này |
| `registrationOtp`, `signingOtp` | Máy thử đang bật mock: điền chuỗi `123456` cho từng biến |
| `certificateCallbackUrl`, `signingCallbackUrl` | Để trống |
| `samplePdfBase64` | Giữ PDF mẫu có sẵn cho lượt thử đầu |
| `documentId` | Giữ chuỗi `0` khi ký một PDF |

Nếu nhận environment private đã điền credential, vẫn kiểm tra URL/UID và thay thông tin người thử. Giữ giá trị secret riêng trên máy; không commit hoặc chia sẻ environment có App Password vào workspace chung.

Collection tự tạo `runId`, `verifiedAt` khi chúng còn trống, rồi lưu `sessionId`, `certAlias`, `signSessionId` từ response. Không tự điền ID giả. `verifiedAt` dùng giờ máy, vì vậy cần đồng bộ giờ hệ thống.

**OTP mock vẫn tuân theo luồng:** phải có lượt gửi mã thành công, phiên/mã còn hạn và đúng bước. Không thêm trường `mock` vào body để bật chế độ này. Khi vận hành tắt mock, điền mã thật của đúng phiên vào từng biến OTP; email có thể nằm trong Mailpit trên máy thử, chưa chắc được gửi ra hộp thư ngoài. Khi đó nhận cách lấy mã từ vận hành.

## 4. Kiểm tra kết nối và quyền trước

Gửi lần lượt ba request trong thư mục **1. Danh mục và giới hạn**:

| Request trong collection | API | Kết quả cần thấy |
|---|---|---|
| `01 - Capabilities` | `GET /capabilities` | HTTP 200, `status=true`, giới hạn và định dạng PDF |
| `02 - Gói chứng thư` | `GET /certificates/packages` | Có gói `internal-default` và phương thức `service_otp` |
| `03 - Hồ sơ cấu hình ký` | `GET /signing-profiles?pageIndex=1&pageSize=20` | Có profile `DEFAULT_PDF` |

Đường dẫn API trong tài liệu đều ghép sau `baseUrl`. Ví dụ request đầu gọi `.../api/v1/public/capabilities`.

Nếu ba request chưa thành công, xử lý kết nối/credential/quyền trước khi tạo dữ liệu. Không gọi được IP/cổng là vấn đề kết nối; nhận HTTP 401 thường cần kiểm tra UID/App Password. Xem thêm mục 9.

## 5. Cấp chứng thư lần đầu

Mở thư mục **2. Đăng ký bằng OTP email/SMS**, chạy từng request sau:

| Bước | Request / API | Cách dùng và kết quả |
|---|---|---|
| 1 | `04 - Tạo hoặc lấy lại phiên` — `POST /certificates/registration` | Giữ `registrationMethod=CARD_READER`, `confirmationMethod=service_otp`, `reusePreviousDossier=false`. Lưu `data.sessionId`; nhánh mới có `PENDING`, `AWAITING_DOSSIER`, `nextAction=SUBMIT_IDENTITY` |
| 2 | `05 - Adapter nộp hồ sơ đã xác minh` — `POST /registration-sessions/{sessionId}/identity/info` | Gửi hồ sơ ở bảng dưới. Kết quả ở **`data.session`**, thường có `step=AWAITING_CONFIRMATION`, `nextAction=VERIFY_OTP`; hệ thống thực hiện gửi OTP |
| 3 | `06 - Kiểm tra OTP cấp chứng thư` — `POST /registration-sessions/{sessionId}/otp` | Body `{"otpCode":"{{registrationOtp}}"}`. Thành công có `data.step=VERIFIED`, `nextAction=CONFIRM`; **chưa cấp chứng thư** |
| 4 | `07 - Xác nhận cấp` — `POST /registration-sessions/{sessionId}/confirm` | Body `{}`. Thành công có `data.status=ACTIVE`, `step=COMPLETE`, `data.certificate.certAlias` |
| 5 | `08 - Trạng thái đăng ký` — `GET /certificates/registration/{sessionId}` | Đọc lại kết quả, lưu alias chứng thư để ký |

Request 04 và 07 đã có `Idempotency-Key` riêng trong collection. Request 05 dùng hồ sơ sau; mẫu đã điền sẵn và lấy CCCD/tên từ environment:

| Trường trong body request 05 | Ý nghĩa / ví dụ |
|---|---|
| `identifyNumber`, `name` | Phải khớp `personalIdNumber`, `fullName` của phiên |
| `address` | Địa chỉ, ví dụ `Hà Nội` |
| `dateOfBirth`, `issueDate`, `expiredDate` | Ví dụ `01/01/1990`, `01/01/2021`, `01/01/2030`; nhận cả dạng `yyyy-MM-dd`. Hồ sơ chưa hết hạn, ngày sinh ≤ ngày cấp ≤ hôm nay |
| `sex`, `isPass` | Ví dụ `Nam`, boolean `true`; dùng kết quả xác minh thực tế khi tích hợp thật |
| `verification.method`, `.source` | Mẫu dùng `adapter`, `{{apiUser}}`; nguồn phải được vận hành cho phép |
| `verification.reference`, `.verifiedAt` | Mã biên nhận và thời điểm xác minh; mẫu dùng `{{runId}}-identity`, `{{verifiedAt}}`. Timestamp RFC3339 không có phần lẻ giây |

Để bắt đầu bằng dữ liệu mẫu, dùng **05**; chỉ dùng **05B** thay cho 05 khi đã có bộ bằng chứng đầu đọc theo contract. Không chạy cả hai cho cùng một hồ sơ.

**Nếu request 04 trả ngay `ACTIVE`:** chứng thư phù hợp đã tồn tại. Collection lưu `certificate.certAlias`; bỏ qua 05–07 và chuyển sang ký. Backend phải dựa vào `status`/`step`/`nextAction`, không cố chạy mọi bước. Nếu gặp `ISSUING` hoặc `RECOVERY_REQUIRED`, nhờ vận hành đối soát trước khi mở lượt cấp khác.

Kết quả cần giữ sau luồng này là **`certAlias`**, gắn với người dùng/nghiệp vụ nội bộ và UID tích hợp. Không dùng `sessionId` cấp chứng thư làm ID phiên ký.

## 6. Ký một PDF và lấy file kết quả

Mở thư mục **4. Ký một PDF bằng OTP email**, chạy:

| Bước | Request / API | Kết quả |
|---|---|---|
| 1 | `15 - Gửi PDF, nhận OTP ký` — `POST /signing-requests/direct-sign` | Chốt PDF và cấu hình ký, nhận `data.signSessionId`, thường có trạng thái `PENDING`; hệ thống gửi OTP ký |
| 2 | `16 - OTP xác nhận và ký PDF` — cùng endpoint `/direct-sign` | Gửi ID phiên và OTP, nhận `SUCCESS` hoặc `PROCESSING` |
| 3 | `17 - Tra kết quả ký` — `GET /signing-requests/{signSessionId}` | Nếu còn `PROCESSING`, polling cách 2–5 giây rồi giãn dần. Khi xong, kiểm tra trạng thái và từng phần tử `data.results[]` |
| 4 | `18 - Kết quả file riêng` — `GET /signing-requests/{signSessionId}/documents/0/result` | JSON kết quả file: `data.status=SUCCESS`, `data.signedData` là Base64 của PDF đã ký |

Request 15 đã có PDF mẫu và vị trí chữ ký. Khi tự viết backend, body tối thiểu cho ký một PDF có thể dùng:

```json
{
  "certAlias": "{{certAlias}}",
  "personalIdNumber": "{{personalIdNumber}}",
  "type": "PDF",
  "signingProfileCode": "DEFAULT_PDF",
  "documentName": "contract-demo.pdf",
  "fileBase64": "{{samplePdfBase64}}",
  "confirmationMethod": "service_otp"
}
```

Các chuỗi `{{...}}` là biến Postman; backend thay bằng giá trị thực. Bỏ tọa độ thì chữ ký không có vùng hiển thị như mẫu request 15. Khi cần vị trí/ảnh chữ ký, xem [quy tắc input ký](API_REFERENCE.md#tạo-và-xác-nhận-ký).

Request 16 gửi **body khác** tới cùng endpoint, với một `Idempotency-Key` riêng cho xác nhận:

```json
{
  "signSessionId": "{{signSessionId}}",
  "otpValue": "{{signingOtp}}"
}
```

Ở bước xác nhận, không gửi lại/sửa PDF, tọa độ hoặc ảnh chữ ký. Chúng đã được chốt lúc tạo phiên. OTP cấp chứng thư dùng trường **`otpCode`**; OTP ký dùng **`otpValue`**.

`status=true` ở ngoài response chỉ cho biết lần gọi API thành công; HTTP 202 hoặc `data.status=PENDING/PROCESSING` chưa có nghĩa PDF đã ký xong. Chỉ lấy `signedData` của file có `status=SUCCESS`.

### Lưu Base64 thành PDF

Endpoint kết quả riêng vẫn trả JSON, không trả trực tiếp file nhị phân. Lưu **toàn bộ response request 18** thành `result.json`, đặt cạnh tệp Python chứa đoạn sau rồi chạy bằng Python 3:

```python
import base64
import hashlib
import json
from pathlib import Path

response = json.loads(Path("result.json").read_text(encoding="utf-8"))
document = response.get("data") or {}
if response.get("status") is not True or document.get("status") != "SUCCESS":
    raise SystemExit("File chưa ký thành công; kiểm tra trạng thái và mã lỗi.")
pdf = base64.b64decode(document["signedData"], validate=True)
if hashlib.sha256(pdf).hexdigest() != document["outputHash"]:
    raise SystemExit("Hash PDF không khớp kết quả API.")
Path("signed.pdf").write_bytes(pdf)
print("Đã lưu signed.pdf")
```

Backend thực hiện tương tự: decode Base64 thành byte, kiểm tra `outputHash` SHA-256, lưu PDF và liên kết với hồ sơ nội bộ. Kiểm chứng chữ ký và chain/CA theo thỏa thuận triển khai; mở được PDF chưa đủ để xác minh chữ ký.

## 7. Thử thêm ký lô

Sau khi ký đơn thành công, chạy **19 → 20 → 21** trong thư mục **5. Ký lô bằng OTP - có file lỗi**. Điền `batchOtp` từ đúng phiên; máy mock có thể dùng `123456`.

Mẫu chủ động gửi hai file `GOOD` và `BROKEN`, trong đó file thứ hai không phải PDF hợp lệ. Kết quả cuối mong đợi là `PARTIAL_SUCCESS`: lưu PDF của file thành công và xử lý lỗi từng file. Không đánh dấu toàn bộ lô thành công chỉ vì HTTP 200/202. Muốn thử lô toàn bộ hợp lệ, thay file `BROKEN` bằng một PDF thật, đặt ID duy nhất và dùng khóa tạo mới.

Giới hạn hiện tại: **PDF, tối đa 100 file/lô, 5 MiB/file, 25 MiB tổng byte PDF và ảnh sau decode**. Base64 thuần, không có tiền tố `data:application/pdf;base64,`. Đọc lại `/capabilities` khi chuyển môi trường.

## 8. Khi ghép vào backend

1. **Gọi từ server của bên tích hợp.** Lưu App Password trong kho secret/cấu hình riêng của backend. Frontend/mobile chỉ trao đổi với backend của mình; không nhúng App Password vào ứng dụng client.
2. **Lưu quan hệ nghiệp vụ.** Giữ mã hồ sơ nội bộ, UID, `sessionId` cấp, `certAlias`, `signSessionId`, document ID, trạng thái, khóa chống lặp và bản dữ liệu đã gửi. `transId` ngoài response là mã đối soát của từng lần gọi HTTP, không phải ID phiên.
3. **Tách thao tác tạo và xác nhận.** Gửi JSON với `Accept: application/json`, `Content-Type: application/json`; các POST tạo phiên/xác nhận cấp hoặc ký cần `Idempotency-Key` riêng, dài 8–128 ký tự `[A-Za-z0-9_.:-]`.
4. **Xử lý timeout bằng đối soát.** Đã có ID thì GET trạng thái trước. Mất response tạo thì gửi lại đúng khóa/body để lấy phiên cũ; không đổi khóa chỉ vì timeout. Khi tạo, dùng lại khóa với body khác trả 409. Khi xác nhận, backend hiện chống lặp theo trạng thái phiên; không giả định mọi thay đổi proof với cùng khóa đều trả 409. Giữ khóa/body ổn định; proof sai có thể tốn lượt thử.
5. **Theo dõi trạng thái và kết quả từng file.** Bắt đầu bằng polling; callback là tùy chọn có thể cấu hình sau. Phiên `PROCESSING` kéo dài cần vận hành kiểm tra tác vụ nền; không tạo hàng loạt yêu cầu thay thế.

Phiên cấp/ký hiện có hạn 15 phút; OTP tối đa 5 phút và không vượt hạn phiên. Gửi lại OTP cách tối thiểu 60 giây, tối đa 5 lượt gửi; kiểm tra proof tối đa 5 lượt. Tra hạn/trạng thái response thay vì giữ một OTP để dùng cho nhiều phiên. Ký có API gửi lại mã ở request **X2**, cấp có **X1**.

Khi thử **một lượt nghiệp vụ mới**, dùng environment mới từ mẫu hoặc xóa `runId`, `verifiedAt` và các biến kết quả phiên cũ để collection sinh lại. Khi retry cùng lượt, giữ nguyên chúng. Cùng CCCD/hồ sơ có thể lấy lại phiên PENDING hoặc chứng thư ACTIVE; muốn thử toàn bộ luồng cấp mới, dùng chủ thể thử khác đã thống nhất. Không chạy thu hồi chỉ để làm sạch dữ liệu đang được dùng chung.

## 9. Các lỗi thường gặp

| Hiện tượng | Cách xử lý |
|---|---|
| Timeout / connection refused trước khi nhận HTTP | Kiểm tra đúng IP/cổng, NAT/firewall và đường mạng của máy gọi |
| HTTP 401 | Kiểm tra environment đang chọn, UID và **App Password**; thử lại ba API danh mục |
| HTTP 403 | Đọc `error.code`: có thể thiếu scope, nguồn xác minh chưa được phép hoặc proof không hợp lệ; gửi mã lỗi cho vận hành |
| HTTP 404 | Kiểm tra ID và đúng UID sở hữu; tránh trộn phiên giữa các environment |
| HTTP 409 | Kiểm tra `error.code`, body đã chốt và trạng thái phiên; thường do khóa/dữ liệu khác, hồ sơ đã đóng băng hoặc phiên không ở đúng bước |
| Phiên hết hạn | GET đối soát rồi mở lượt mới bằng khóa mới; phiên cấp có thể trả 410, phiên ký đóng/hết hạn thường trả 409 |
| HTTP 429 | Chờ đủ khoảng gửi lại và kiểm tra số lượt; không gửi OTP liên tục |
| HTTP 503 khi gửi OTP / lỗi CA | Nhờ vận hành kiểm tra dịch vụ; **mock OTP cũng không dùng được nếu lượt gửi mã thất bại** |
| HTTP 200/202 nhưng file lỗi | Đọc `data.status`, `data.results[].status`, `.errorCode`, `.errorMessage` |

Lỗi API thường có `status=false`, `data=null`, `error.code`, `error.message`, `transId`. Lỗi proxy/Nextcloud có thể trả HTML, vì vậy kiểm tra HTTP và Content-Type trước khi parse JSON. Khi cần hỗ trợ, gửi thời điểm, method/path, HTTP status, `error.code`, `transId` và ID phiên; không gửi Authorization/App Password hoặc dữ liệu cá nhân không cần thiết.

## 10. Hoàn thành lượt thử và tra cứu thêm

Một lượt thử đầu hoàn thành khi ba API danh mục chạy được, lấy được `certAlias`, ký đơn đạt `SUCCESS` và lưu/kiểm chứng được PDF kết quả. Sau đó hai bên có thể thử ký lô, timeout/retry và các nhánh OTP thật trước khi chuyển sang dữ liệu thật.

**Chạy từng request theo hướng dẫn, không chạy toàn bộ collection bằng Runner.** Collection có các nhánh cấp lại/QR/assertion và các request hủy/thu hồi ở thư mục 9; chúng chỉ chạy khi chủ động thử chức năng tương ứng.

- [INTEGRATION.md](INTEGRATION.md): chuẩn bị môi trường, các luồng nâng cao, assertion, QR, face-match và callback.
- [API_REFERENCE.md](API_REFERENCE.md): đủ 24 thao tác HTTP, input/output, trạng thái, lỗi và hợp đồng callback.
- [OpenAPI public](examples/libresign-public.openapi.json): schema để import/sinh client. `openapi-administration.json` không chứa nhóm API public này.
