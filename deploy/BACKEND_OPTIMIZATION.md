<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# So sánh tối ưu backend cấp chứng thư và ký PDF

Ngày kiểm chứng: **25/10/2026**. Mốc trước thay đổi: commit `50ddd2133ddd6d31f6f20a696d9579f3dee7e330`.

Giữ nguyên API public và provider OCS: route/controller, input/output, mã lỗi nghiệp vụ, phân trang, proof, header chống lặp và callback. Không sửa OpenAPI, TypeScript API types, Postman hoặc tài liệu contract. Kiểm tra `python3 deploy/export-public-api.py --check` đạt đủ **24 thao tác public**.

## Kết quả so sánh

Các lớp service ban đầu được lấy từ `git archive` của commit trên, nạp vào namespace hiện có bằng tên lớp riêng chỉ trong kiểm thử. Hai bản dùng cùng dữ liệu SQLite, cùng index sau migration, cùng runtime và mã hóa thật. Thời gian là trung vị **5 lượt** ở lớp service, chưa bao gồm HTTP/xác thực. Đây là phép đo cục bộ, không phải tải đồng thời hoặc latency production.

| Thao tác | Truy vấn trước | Truy vấn sau | Service trước → sau |
|---|---:|---:|---:|
| Danh sách 20 chứng thư | 42 | **3** | 26,67 → 10,81 ms |
| Danh sách 100 chứng thư | 202 | **3** | 131,52 → 51,47 ms |
| Chi tiết một chứng thư public | 3 | **2** | 1,45 → 0,80 ms |
| Tra phiên cấp đã hoàn tất | 6 | **3** | 3,38 → 1,47 ms |
| Tìm ACTIVE trong 221 bản ghi: 100 thu hồi, 120 hết hạn, 1 ACTIVE | 224 | **2** | 147,34 → 1,06 ms |

Các response trước/sau ở các bài so sánh này được kiểm tra bằng `assertSame`, bao gồm danh sách, thứ tự, tổng số và metadata. Kiểm thử bổ sung xác nhận trạng thái thu hồi mới được phản ánh ngay, tenant khác không đọc được chứng thư và thiếu CRL vẫn bị từ chối.

| Thao tác I/O tại lớp ứng dụng | Trước | Sau |
|---|---:|---:|
| Lấy một kết quả trong lô 100 file | Đọc 100 artifact | **Đọc 1 artifact** |
| Hoàn tất 100 file không có callback, gồm đọc input và dựng response | Đọc 300 artifact | **Đọc 200 artifact** |
| Tổng JSON truyền vào các lần lưu checkpoint khi ký 20 file, mỗi file dùng ảnh 256 KiB | 300.953.098 byte | **500.058 byte** |

Đo I/O bằng store/signer giả lập để đếm lời gọi và kích thước payload chính xác. Số byte checkpoint giảm **99,83%**, nhưng không đồng nghĩa giảm cùng tỷ lệ tổng thời gian ký PDF. Đây không phải số byte ghi đĩa vật lý: chưa tính lớp mã hóa/database/filesystem, và ảnh được lưu riêng trong artifact mã hóa. Số checkpoint chống ký lặp được giữ nguyên. Response kết quả và byte ảnh chuyển vào signer được kiểm tra tương thích.

Dữ liệu đo: [provider-backend-20261025.json](benchmarks/provider-backend-20261025.json).

## Riêng luồng cấp chứng thư

Bổ sung đo các **POST trong luồng cấp**, không dùng số truy vấn danh sách để thay cho số đo cấp chứng thư. `ProviderIssuanceOptimizationTest` chạy cả backend gốc và backend mới trên CA/SQLite/datadir riêng, dùng **OpenSSL/PFX thật**, OTP thật qua Mailpit và RSA assertion thật. Có đủ bốn nhánh OTP/assertion, có/không có callback. Kiểm tra sai OTP, giới hạn gửi lại, chain/PFX, confirm lặp không cấp thêm và nội dung outbox callback.

Các số dưới đếm SELECT/INSERT/UPDATE trên ba bảng nghiệp vụ `libresign_provider_reg`, `libresign_provider_state`, `libresign_crl` trong toàn bộ lời gọi service tương ứng endpoint. Chưa tính truy vấn xác thực HTTP, filecache và cấu hình Nextcloud. Lần mở phiên đầu chưa có lịch sử chứng thư; bảng không có callback:

| Bước / endpoint | SQL OTP trước → sau | SQL assertion trước → sau | Giải mã hồ sơ OTP trước → sau |
|---|---:|---:|---:|
| `POST /certificates/registration`: mở phiên mới | 6 → 6 | 6 → 6 | 0 → 0 |
| `POST /registration-sessions/{id}/identity/info` | 9 → **8** | 7 → **5** | 5 → **2** |
| `POST /registration-sessions/{id}/otp/resend` | 6 → 6 | — | 4 → **3** |
| `POST /registration-sessions/{id}/otp` | 6 → 6 | — | 4 → **3** |
| `POST /registration-sessions/{id}/confirm`: cấp thật | 21 → **13** | 24 → **16** | 12 → **3** |
| Gọi lại confirm của phiên đã cấp | 9 → **3** | 9 → **3** | 7 → **2** |
| `POST /certificates/registration`: tái dùng hồ sơ sau thu hồi | 20 → **14** | 18 → **11** | 12 → **4** |

Với callback, confirm cấp thật giảm **22 → 14** truy vấn ở OTP và **25 → 17** ở assertion; confirm lặp giảm **11 → 5**, vẫn chỉ giữ một event theo khóa nghiệp vụ. Ở nhánh assertion, bước confirm giảm giải mã hồ sơ **14 → 4**.

Thay đổi bổ sung:

- Dùng lại state đã giải mã khi kiểm tra PENDING, dựng challenge và dựng kết quả của cùng thao tác; không giải mã lại ngay sau khi vừa ghi state đó.
- Dùng kết quả native vừa tạo/gửi OTP/cấp xong để dựng response public, thay vì đọc lại phiên native. Cùng kết quả cấp được dùng để dựng callback.
- Tái dùng identity đã được kiểm tra PFX khi trả chứng thư ACTIVE; đọc hồ sơ cũ một lần trong thao tác có lock. Kiểm tra hạn CCCD vẫn chạy ở các điểm chấp nhận hồ sơ.

Sinh khóa, OpenSSL cấp chứng thư, ghi registry/CRL, lưu PFX mã hóa và đọc lại kiểm tra đều giữ nguyên. Mỗi lần cấp thành công gọi engine đúng một lần; confirm lặp gọi engine **0 lần**. Số SQL của mở phiên mới và gửi/xác minh OTP **không giảm**; ở OTP chỉ loại bỏ một lần giải mã state. Vì gửi thư, băm OTP và sinh khóa còn chiếm thời gian, không suy ra toàn bộ thời gian API giảm theo tỷ lệ truy vấn. Raw timing có tách thời gian engine nhưng chỉ là một mẫu mỗi nhánh, không dùng để khẳng định tốc độ OpenSSL được cải thiện.

Response được đối chiếu theo các trường nghiệp vụ và kiểu dữ liệu; UUID, alias, nonce/challenge, serial, fingerprint và thời điểm phát hành khác nhau giữa hai lần cấp thật được chuẩn hóa khi so sánh. Bước sinh chứng thư vẫn được kiểm tra trực tiếp bằng chứng thư/PFX/chain thực tế của từng lượt. Nhánh `card-reader/info` dùng cùng service nhận hồ sơ và được hồi quy trong bộ HTTP integration.

## Các thay đổi bên trong

- Lấy CRL theo lô; giải mã một hồ sơ một lần và dùng lại identity để dựng response. Không cache trạng thái thu hồi qua request hoặc qua các file đang ký.
- Tìm chứng thư bằng tenant/subject, trạng thái registry và metadata CRL; kiểm tra lại chứng thư đã giải mã. Duyệt bằng ID và dừng khi đủ hai chứng thư ACTIVE để báo xung đột. Giữ bước kiểm tra thời hạn, fingerprint và PFX trước khi sử dụng khóa.
- Chọn document trước khi đọc artifact ở endpoint kết quả riêng. GET trạng thái phiên vẫn trả đủ `signedData` theo contract hiện tại.
- Chỉ dựng payload callback khi cần. Đọc PDF trước transaction; trạng thái kết thúc và outbox callback vẫn được ghi trong cùng transaction.
- Snapshot mới giữ tham chiếu/hash ảnh trong kho artifact mã hóa thay vì lặp Base64 trong mỗi checkpoint. Ảnh giống nhau trong một lô dùng cùng artifact. Snapshot cũ chứa ảnh inline vẫn ký được, không đổi challenge/manifest đã được xác nhận.
- Kiểm tra manifest/authorization một lần mỗi lượt xử lý có lock; kiểm tra lại khi worker tiếp tục. Vẫn kiểm tra quyền integration, thời hạn, trạng thái chứng thư và digest input theo từng file. Snapshot không chắc kết quả `SIGNING` vẫn thành `RECOVERY_REQUIRED`, không tự ký lại.
- Worker lấy danh sách ID trước, chỉ đọc/giải mã payload sau khi có lock. Callback lọc thời điểm retry trong SQL trước `LIMIT`, rồi kiểm tra lại sau lock để tránh gửi theo lịch cũ.

## Migration và runtime

Migration `19002Date20261025140000` bổ sung:

- Index danh sách chứng thư `(client_uid, id)`.
- Index danh sách phiên `(client_uid, kind, created_at, record_id)`.
- Cột `next_attempt_at`, index `(kind, status, next_attempt_at, updated_at)` và backfill lịch retry của sự kiện PENDING hiện có. Backfill đọc một sự kiện mỗi lượt, giữ nguyên ciphertext, version và trạng thái nghiệp vụ.

Kiểm tra query plan trên SQLite xác nhận hai truy vấn danh sách dùng index sắp xếp, không còn `TEMP B-TREE`. Lookup alias, idempotency key và hàng đợi ký tiếp tục dùng index hiện có.

Bộ cài `deploy/setup.py` tự phát hiện migration mới qua `Version19002Date*.php`. Khi cập nhật source bằng quy trình riêng, cần áp dụng migration trước khi mở lại request/worker với code mới:

```bash
php occ migrations:execute libresign 19002Date20261025140000 --no-interaction
```

Chạy bằng user ứng dụng, sau khi sao lưu và trong bước cập nhật có kiểm soát. Không chạy lại `migrations:execute` nếu migration đã được ghi nhận; bộ cài kiểm tra các migration còn thiếu trước khi thực thi.

Runtime source hiện tại đã áp dụng migration sau khi sao lưu SQLite/cấu hình riêng. Bản sao nằm tại `data/provider-backend-backups/20261025T134800Z/` trong runtime; chứa dữ liệu nhạy cảm và được tạo với quyền private. Đối chiếu hash trước/sau xác nhận giữ nguyên **12 registration, 36 workflow và 28 CRL**. Migration không thay CA, PFX, chính sách integration hoặc credential. Kiểm tra GET qua nginx với App Password hiện có đạt cho danh mục, danh sách/chi tiết chứng thư, PEM, trạng thái ký và kết quả riêng; anonymous trả 401.

## Kiểm thử và tái hiện

Chuẩn bị **datadir/SQLite/CA riêng** bằng `tests/php/Integration/Provider/prepare-isolated.php --use-runtime-tools`. Tách fixture unit/SQL và fixture HTTP; không chạy PHPUnit bằng cấu hình runtime đang phục vụ người dùng.

- Bộ unit tập trung mở rộng bao gồm provider/controller và các lớp liên quan: **852 tests / 2.314 assertions đạt**.
- SQL/migration/performance integration: **6 tests / 43 assertions đạt**; kiểm tra tenant, thu hồi, thiếu CRL, phân trang, migration chạy lại, query plan, CAS cho lịch retry và tương đương response với bản đầu.
- So sánh luồng cấp thật: **4 tests / 144 assertions đạt** trên fixture riêng; OTP/assertion, có/không callback và cấp lại từ hồ sơ cũ.
- HTTP integration public + OCS: **5 tests / 1.613 assertions đạt** với OpenSSL/JSignPdf thật, OTP email/mock, assertion, tái dùng hồ sơ/chứng thư, tenant, QR, PDF đã ký và lô có file lỗi.
- Chạy thêm luồng email thật với **100 PDF: 1 test / 690 assertions đạt**, kết quả 100 SUCCESS; kiểm tra độc lập chữ ký/fingerprint đầu và cuối lô.
- PHP syntax, coding style và `git diff --check` đạt. OpenAPI/controller/Postman vẫn đồng bộ; các tệp contract không có diff.

Để bật so sánh bản đầu, xuất thư mục `lib/` của commit baseline bằng `git archive`, đặt thư mục đó trong container thử và khai báo:

```bash
export LIBRESIGN_PROVIDER_BASELINE_DIR=/tmp/provider-backend-baseline
export LIBRESIGN_PROVIDER_BENCHMARK_OUTPUT=/tmp/provider-backend-comparison.json
```

Sau đó chạy `ProviderBackendOptimizationTest` bằng PHPUnit trong fixture SQL. Chạy `PublicSigningServiceTest` với đường output khác để lấy số đếm I/O/checkpoint. Đặt `LIBRESIGN_PROVIDER_TEST_LARGE_BATCH=1` khi chạy dataset `real email OTP` của `PublicProviderApiIntegrationTest` để kiểm chứng lô 100 PDF. Các bài HTTP cần base URL/host/Mailpit của server thử dùng chính cấu hình fixture đó.

Để đo riêng luồng cấp, dùng fixture có đường dẫn `/tmp/provider-integration-issuance-*`, khai báo thêm `LIBRESIGN_PROVIDER_TEST_MAILPIT` và `LIBRESIGN_PROVIDER_ISSUANCE_OUTPUT`, rồi chạy `ProviderIssuanceOptimizationTest`. Bộ thử tạo chứng thư/bằng chứng thật chỉ trong fixture, không gửi callback ra adapter ngoài.

Chưa benchmark tải đồng thời, p95/p99, MariaDB/PostgreSQL hoặc mọi loại PDF của bên tích hợp. Giới hạn và response công khai giữ nguyên.
