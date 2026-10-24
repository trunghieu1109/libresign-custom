<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppContent>
		<main class="provider-management" :aria-busy="loading || busy">
			<header class="workspace-header">
				<div>
					<p class="eyebrow">LIBRESIGN · QUẢN TRỊ</p>
					<h1>Chứng thư &amp; ký số</h1>
					<p>Theo dõi cấp chứng thư, chia sẻ QR và quản lý kết quả ký PDF.</p>
				</div>
				<div class="button-row">
					<button :disabled="loading || busy || !clientUid" @click="refresh">Làm mới</button>
					<button :disabled="!canWrite('certificates:write')" @click="openCreate('enrollment')">Cấp chứng thư</button>
					<button class="primary" :disabled="!canWrite('signing:write')" @click="openCreate('signing')">+ Ký PDF</button>
				</div>
			</header>

			<div v-if="error" class="notice error" role="alert">{{ error }}</div>
			<div v-if="notice" class="notice success" role="status">{{ notice }}</div>
			<div class="integration-bar">
				<label>Bên tích hợp
					<select v-model="clientUid" :disabled="busy || clientsLoading">
						<option v-if="!clients.length" value="">{{ clientsLoading ? 'Đang tải…' : 'Chưa có bên tích hợp' }}</option>
						<option v-for="client in clients" :key="client.uid" :value="client.uid">{{ client.uid }}{{ client.enabled ? '' : ' · Đã tắt' }}</option>
					</select>
				</label>
				<span v-if="currentClient" class="integration-meta">OTP: {{ currentClient.otpChannel || 'Chưa cấu hình' }}</span>
				<span v-if="currentClient?.mockOtpEnabled || currentClient?.mockRegistrationEnabled" class="status warning">Môi trường có chế độ mô phỏng</span>
				<span v-if="currentClient && !currentClient.enabled" class="status warning">Đã tắt · Chỉ xem</span>
			</div>

			<nav class="workspace-tabs" aria-label="Nhóm quản lý">
				<button v-for="tab in tabs" :key="tab.kind" :class="{ active: kind === tab.kind }"
					:aria-current="kind === tab.kind ? 'page' : undefined" :disabled="busy" @click="kind = tab.kind">{{ tab.label }}</button>
			</nav>
			<div v-if="!clientsLoading && !clients.length" class="empty-state">
				<h2>Chưa cấu hình bên tích hợp</h2>
				<p>Cấu hình tài khoản provider bằng bộ cài, sau đó làm mới trang này. Màn hình dùng dữ liệu API của từng bên tích hợp.</p>
				<button @click="loadClients">Thử tải lại</button>
			</div>

			<div v-else class="workspace-grid" :class="{ 'has-detail': detail || detailLoading }">
				<section class="records-panel" aria-label="Danh sách">
					<div class="panel-heading">
						<div><h2>{{ activeTab.label }}</h2><p>{{ total }} bản ghi · Trang {{ page }} / {{ pageCount }}</p></div>
						<span v-if="loading" role="status">Đang tải…</span>
					</div>
					<label class="search-field">Lọc trong trang hiện tại
						<input v-model="search" type="search" placeholder="Tên, CCCD, mã hoặc trạng thái…">
					</label>
					<form v-if="kind === 'certificates'" class="lookup-row" @submit.prevent="applyPersonalId">
						<label>Tra toàn bộ theo CCCD<input v-model="personalIdInput" inputmode="numeric" pattern="[0-9]{12}" maxlength="12" placeholder="12 chữ số"></label>
						<button :disabled="busy || loading">Tra cứu</button>
						<button v-if="personalId" type="button" @click="clearPersonalId">Bỏ lọc</button>
					</form>
					<div v-if="!loading && !rows.length" class="empty-state">
						<h3>{{ search || personalId ? 'Không tìm thấy bản ghi phù hợp' : 'Chưa có dữ liệu' }}</h3>
						<p>{{ kind === 'certificates' ? 'Chứng thư đã cấp qua API sẽ xuất hiện tại đây.' : 'Các phiên tạo qua API hoặc màn hình này sẽ xuất hiện tại đây.' }}</p>
					</div>
					<div v-else class="table-scroll">
						<table>
							<thead><tr><th>Chủ thể / Mã</th><th>Trạng thái</th><th>{{ kind === 'signing' ? 'Tài liệu' : 'Thời hạn' }}</th><th><span class="sr-only">Chi tiết</span></th></tr></thead>
							<tbody>
								<tr v-for="row in rows" :key="recordId(row, kind)" :class="{ selected: selectedId === recordId(row, kind) }">
									<td><strong>{{ row.subjectName || row.fullName || 'Chưa có tên' }}</strong><span class="secondary">{{ row.personalIdNumber }}</span><code :title="recordId(row, kind)">{{ recordId(row, kind) }}</code></td>
									<td><span class="status" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span><span v-if="row.step" class="secondary">{{ stepLabel(row.step) }}</span></td>
									<td>{{ kind === 'signing' ? `${row.successCount ?? 0}/${row.documentCount ?? 0} đã ký` : formatDate(row.validTo || row.expiresAt) }}</td>
									<td><button :disabled="busy" :aria-label="`Xem ${recordId(row, kind)}`" @click="selectRecord(recordId(row, kind))">Xem</button></td>
								</tr>
							</tbody>
						</table>
					</div>
					<div class="pagination">
						<button :disabled="page <= 1 || loading || busy" @click="page--">← Trước</button>
						<span>{{ total }} bản ghi</span>
						<button :disabled="page >= pageCount || loading || busy" @click="page++">Sau →</button>
					</div>
				</section>

				<aside v-if="detail || detailLoading" ref="detailPanel" class="detail-panel" aria-label="Chi tiết bản ghi">
					<div class="panel-heading"><h2>Chi tiết</h2><button :disabled="busy" @click="closeDetail">Đóng</button></div>
					<p v-if="detailLoading" role="status">Đang tải chi tiết…</p>
					<template v-else-if="detail">
						<span class="status" :class="statusClass(detail.status)">{{ statusLabel(detail.status) }}</span>
						<h3>{{ detail.subjectName || detail.fullName || 'Phiên xử lý' }}</h3>
						<dl><template v-for="field in detailFields" :key="field.label"><dt>{{ field.label }}</dt><dd>{{ field.value }}</dd></template></dl>
						<div v-if="detail.nextAction" class="notice">
							<template v-if="detail.manualDossierAllowed">Bổ sung hồ sơ bằng form thử nghiệm bên dưới, hoặc gửi hồ sơ từ đầu đọc qua API.</template>
							<template v-else>{{ stepLabel(detail.nextAction) }}</template>
						</div>
						<details v-if="detail.challengeBase64"><summary>Challenge cho adapter ký assertion</summary><label>Challenge Base64<textarea :value="detail.challengeBase64" readonly rows="4" /></label></details>
						<div v-if="kind === 'enrollment' && registrationUrl" class="qr-panel">
							<h3>QR đăng ký chứng thư</h3>
							<img v-if="qrImage" :src="qrImage" alt="Mã QR mở trang đăng ký chứng thư" width="240" height="240">
							<p v-if="detail.registrationMode === 'mock'" class="notice warning">Trang mô phỏng để thử nghiệm, không thực hiện eKYC thật.</p>
							<p v-if="detail.status !== 'PENDING'">Phiên đã đóng. Không gửi lại QR này để đăng ký mới.</p>
							<label>Đường dẫn đăng ký<input :value="registrationUrl" readonly @focus="($event.target as HTMLInputElement).select()"></label>
							<div class="button-row"><button @click="copyLink">{{ copied ? 'Đã sao chép' : 'Sao chép link' }}</button><a v-if="qrImage" class="button" :href="qrImage" :download="`registration-${selectedId}.png`">Tải QR</a><a class="button" :href="registrationUrl" target="_blank" rel="noopener noreferrer">Mở trang</a></div>
							<small>Hạn phiên: {{ formatDate(detail.expiresAt) }}</small>
						</div>
						<p v-else-if="kind === 'enrollment' && detail.registrationMethod === 'CARD_READER' && !detail.manualDossierAllowed" class="notice">Phiên đầu đọc CCCD không có QR đăng ký. Trong thực tế, đầu đọc gửi hồ sơ qua API.</p>
						<p v-if="detail.dossierOrigin === 'manual_test'" class="notice warning">Hồ sơ đã nhận: nhập tay thử nghiệm, không phải dữ liệu xác minh từ đầu đọc.</p>
						<ManualDossierForm v-if="kind === 'enrollment' && detail.manualDossierAllowed"
							:session="detail" :disabled="busy || !canWrite('certificates:write')" :error="dossierError" @submit="submitManualDossier" />
						<div v-if="kind === 'certificates'" class="button-row section-actions">
							<button :disabled="busy" @click="downloadPem">Tải chứng thư PEM</button>
							<button v-if="detail.status === 'ACTIVE'" :disabled="!canWrite('signing:write')" @click="openCreate('signing', selectedId)">Ký PDF bằng chứng thư này</button>
							<button v-if="detail.status === 'ACTIVE'" class="danger" :disabled="!canWrite('certificates:revoke')" @click="confirmation = 'revoke'">Thu hồi…</button>
						</div>
						<div v-if="detail.certificate?.certAlias" class="notice success">Chứng thư đã cấp: <code>{{ detail.certificate.certAlias }}</code><button :disabled="busy" @click="openCertificate(detail.certificate.certAlias)">Xem chứng thư</button></div>
						<div v-if="kind === 'signing'" class="document-list">
							<h3>PDF trong phiên · {{ detail.successCount }}/{{ detail.documentCount }} thành công</h3>
							<article v-for="doc in detail.results" :key="doc.id" class="document-card">
								<strong>{{ doc.documentName }}</strong><span class="status" :class="statusClass(doc.status)">{{ statusLabel(doc.status) }}</span>
								<p v-if="doc.errorCode" class="document-error">{{ doc.errorCode }} · {{ doc.errorMessage }}</p>
								<div class="button-row">
									<button v-if="doc.hasSource" @click="preview(doc, 'source')">Xem bản gốc</button>
									<button v-if="doc.hasResult" @click="preview(doc, 'result')">Xem bản đã ký</button>
									<a v-if="doc.hasResult" class="button" :href="pdfUrl(doc.id, 'result', true)">Tải PDF đã ký</a>
								</div>
								<details v-if="doc.outputHash"><summary>SHA-256 kết quả</summary><code>{{ doc.outputHash }}</code></details>
							</article>
							<p v-if="detail.status === 'PROCESSING'" class="notice">Hệ thống đang xử lý. Bấm Làm mới để cập nhật kết quả từng PDF.</p>
						</div>
						<form v-if="kind !== 'certificates' && detail.status === 'PENDING'" class="workflow-actions" @submit.prevent="confirmProof">
							<h3>Xử lý phiên</h3>
							<p v-if="kind === 'enrollment' && ['SUBMIT_IDENTITY', 'SUBMIT_FACE_MATCH'].includes(detail.nextAction || '') && !detail.manualDossierAllowed">Cần adapter gửi hồ sơ đã xác minh hoặc kết quả đối chiếu khuôn mặt trước khi xác nhận cấp.</p>
							<template v-if="kind === 'signing' || !['SUBMIT_IDENTITY', 'SUBMIT_FACE_MATCH'].includes(detail.nextAction || '')">
								<label v-if="detail.nextAction !== 'CONFIRM'">{{ detail.confirmationMethod === 'external_assertion' ? 'Assertion từ adapter' : 'Mã OTP của người ký' }}<textarea v-if="detail.confirmationMethod === 'external_assertion'" v-model="proof" required rows="3" autocomplete="off" /><input v-else v-model="proof" required autocomplete="one-time-code" maxlength="12"></label>
								<label class="checkbox-label"><input v-model="consent" type="checkbox" required> Tôi xác nhận người ký đã đồng ý với thao tác này.</label>
								<button class="primary" :disabled="!canWrite(kind === 'signing' ? 'signing:write' : 'certificates:write')">{{ detail.nextAction === 'CONFIRM' ? 'Xác nhận cấp chứng thư' : kind === 'signing' ? 'Xác nhận ký PDF' : 'Kiểm tra OTP / xác nhận' }}</button>
								<button v-if="detail.confirmationMethod !== 'external_assertion'" type="button" :disabled="busy || !canWrite(kind === 'signing' ? 'signing:write' : 'certificates:write')" @click="runAction('resend')">Gửi lại OTP</button>
							</template>
							<button type="button" class="danger" :disabled="!canWrite(kind === 'signing' ? 'signing:write' : 'certificates:write')" @click="confirmation = 'cancel'">Hủy phiên…</button>
						</form>
					</template>
				</aside>
			</div>

			<NcDialog v-if="createKind" :name="createKind === 'enrollment' ? 'Đăng ký chứng thư' : 'Tạo phiên ký PDF'" :no-close="busy" @update:open="open => { if (!open) closeCreate() }">
				<form class="provider-management create-form" @submit.prevent="createRecord">
					<p>Bên tích hợp: <strong>{{ clientUid }}</strong></p>
					<div v-if="createError" class="notice error" role="alert">{{ createError }}</div>
					<template v-if="createKind === 'enrollment'">
						<label>CCCD<input v-model="registration.personalIdNumber" required pattern="[0-9]{12}" maxlength="12" inputmode="numeric" :disabled="busy"></label>
						<label>Họ tên<input v-model="registration.fullName" required maxlength="128" :disabled="busy"></label>
						<label>Email<input v-model="registration.email" type="email" :required="currentClient?.otpChannel !== 'sms'" :disabled="busy"></label>
						<label>Điện thoại<input v-model="registration.phone" type="tel" :required="['sms', 'email_sms'].includes(currentClient?.otpChannel || '')" :disabled="busy"></label>
						<label>Luồng đăng ký<select v-model="registration.registrationMethod" :disabled="busy"><option v-if="currentClient?.qrEnabled" value="QR_SELF_UPLOAD">QR · Tự gửi hồ sơ</option><option value="CARD_READER">Đầu đọc CCCD / adapter</option></select></label>
						<p class="notice">Chứng thư chỉ được cấp sau khi hồ sơ hợp lệ và người ký xác nhận. QR dùng trang đăng ký đã cấu hình của bên tích hợp.</p>
					</template>
					<template v-else>
						<label>Alias chứng thư ACTIVE<input v-model="signingAlias" required :disabled="busy" placeholder="Chọn từ chi tiết chứng thư hoặc dán alias"></label>
						<label>PDF cần ký · tối đa 5 MiB/tệp, 25 MiB/lô<input ref="fileInput" type="file" accept="application/pdf,.pdf" multiple required :disabled="busy" @change="chooseFiles"></label>
						<ul v-if="selectedFiles.length"><li v-for="file in selectedFiles" :key="file.name + file.size">{{ file.name }} · {{ (file.size / 1024).toFixed(0) }} KiB</li></ul>
						<label>Lý do ký<input v-model="signingReason" maxlength="512" :disabled="busy"></label>
						<p class="notice">Bước này chuẩn bị tài liệu và gửi OTP. Kiểm tra bản gốc trong chi tiết phiên rồi xác nhận để thực hiện ký.</p>
					</template>
					<div class="button-row"><button type="button" :disabled="busy" @click="closeCreate">Đóng</button><button class="primary" :disabled="busy">{{ busy ? 'Đang xử lý…' : 'Tạo phiên' }}</button></div>
				</form>
			</NcDialog>

			<NcDialog v-if="confirmation" :name="confirmation === 'revoke' ? 'Thu hồi chứng thư?' : 'Hủy phiên đang chờ?'" @update:open="open => { if (!open) confirmation = null }">
				<div class="provider-management dialog-content">
				<p>{{ confirmation === 'revoke' ? 'Chứng thư sẽ được đưa vào danh sách thu hồi và không thể dùng cho lần ký mới. Thao tác này không thể hoàn tác.' : 'Phiên sẽ đóng và không thể tiếp tục xác nhận.' }}</p>
				<p><code>{{ selectedId }}</code> · {{ clientUid }}</p>
				<label v-if="confirmation === 'revoke'">Lý do thu hồi<input v-model="revocationReason" maxlength="512"></label>
				<div class="button-row section-actions"><button :disabled="busy" @click="confirmation = null">Quay lại</button><button class="danger" :disabled="busy" @click="confirmDestructive">{{ busy ? 'Đang xử lý…' : 'Xác nhận' }}</button></div>
				</div>
			</NcDialog>

			<NcDialog v-if="pdfPreview" :name="pdfPreview.name" size="large" @update:open="open => { if (!open) pdfPreview = null }">
				<div class="provider-management pdf-preview"><iframe :src="pdfPreview.url" :title="pdfPreview.name" /><a class="button" :href="pdfPreview.url" target="_blank" rel="noopener noreferrer">Mở PDF trong tab mới</a><p>Nếu trình duyệt không hiển thị PDF, hãy mở trong tab mới hoặc tải tệp từ chi tiết phiên.</p></div>
			</NcDialog>
		</main>
	</NcAppContent>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import ManualDossierForm from './ManualDossierForm.vue'
import { getManagement, performAction, managementUrl, recordId, errorMessage, pdfFileBase64, safeRegistrationUrl } from './api'
import type { ProviderClient, ManagedRecord, RecordKind, RecordList, DocumentResult } from './api'

const tabs: { kind: RecordKind, label: string }[] = [
	{ kind: 'certificates', label: 'Chứng thư' }, { kind: 'enrollment', label: 'Phiên cấp chứng thư' }, { kind: 'signing', label: 'Phiên ký PDF' },
]
const clients = ref<ProviderClient[]>([])
const clientsLoading = ref(true)
const clientUid = ref('')
const kind = ref<RecordKind>('certificates')
const page = ref(1)
const total = ref(0)
const records = ref<ManagedRecord[]>([])
const search = ref('')
const personalIdInput = ref('')
const personalId = ref('')
const loading = ref(false)
const detailLoading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const selectedId = ref('')
const detail = ref<ManagedRecord | null>(null)
const detailPanel = ref<HTMLElement | null>(null)
const proof = ref('')
const consent = ref(false)
const copied = ref(false)
const confirmation = ref<'revoke' | 'cancel' | null>(null)
const revocationReason = ref('')
const pdfPreview = ref<{ name: string, url: string } | null>(null)
const createKind = ref<'enrollment' | 'signing' | null>(null)
const createError = ref('')
const dossierError = ref('')
const registration = ref({ personalIdNumber: '', fullName: '', email: '', phone: '', registrationMethod: 'QR_SELF_UPLOAD', confirmationMethod: 'service_otp' })
const signingAlias = ref('')
const signingReason = ref('')
const selectedFiles = ref<File[]>([])
// A retry retains the same key and normalized payload after an uncertain response.
let pendingCreate: { key: string, fingerprint: string, payload: Record<string, unknown> } | null = null
let listGeneration = 0
let detailGeneration = 0
const currentClient = computed(() => clients.value.find(client => client.uid === clientUid.value))
const activeTab = computed(() => tabs.find(tab => tab.kind === kind.value)!)
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / 20)))
const rows = computed(() => records.value.filter(row => !search.value || [row.fullName, row.subjectName, row.personalIdNumber, recordId(row, kind.value), row.status, statusLabel(row.status)].join(' ').toLocaleLowerCase().includes(search.value.toLocaleLowerCase())))
const registrationUrl = computed(() => safeRegistrationUrl(detail.value?.registrationUrl))
const qrImage = computed(() => detail.value?.qrCode && /^[A-Za-z0-9+/=\r\n]+$/.test(detail.value.qrCode) ? `data:image/png;base64,${detail.value.qrCode}` : null)
const detailFields = computed(() => {
	const item = detail.value
	if (!item) return []
	return [
		['Mã', selectedId.value], ['CCCD', item.personalIdNumber], ['Serial', item.serialNumber], ['Alias chứng thư', kind.value !== 'certificates' ? item.certAlias : null],
		['Hiệu lực từ', item.validFrom ? formatDate(item.validFrom) : null], ['Hết hạn', item.validTo || item.expiresAt ? formatDate(item.validTo || item.expiresAt) : null],
		['Ngày tạo', item.createdAt ? formatDate(item.createdAt) : null], ['Email', item.email], ['Điện thoại', item.phone], ['Kênh OTP', item.otpChannel],
		['Phương thức xác nhận', item.confirmationMethod], ['SHA-256 chứng thư', item.fingerprintSha256],
		['Ngày sinh', item.dossierFields?.dateOfBirth], ['Giới tính', item.dossierFields?.sex], ['Địa chỉ', item.dossierFields?.address],
		['Ngày cấp CCCD', item.dossierFields?.issueDate], ['Ngày hết hạn CCCD', item.dossierFields?.expiredDate],
	].filter(([, value]) => value).map(([label, value]) => ({ label: label!, value: value! }))
})

function canWrite(scope: string): boolean { return !busy.value && currentClient.value?.enabled === true && currentClient.value.scopes.includes(scope) }
function formatDate(value?: string): string {
	if (!value) return '—'
	const date = new Date(value)
	return Number.isNaN(date.valueOf()) ? value : date.toLocaleString('vi-VN')
}
function statusLabel(status: string): string {
	return ({ ACTIVE: 'Đang hiệu lực', REVOKED: 'Đã thu hồi', EXPIRED: 'Hết hạn', PENDING: 'Đang chờ', PREPARING: 'Đang chuẩn bị', PROCESSING: 'Đang ký', SUCCESS: 'Thành công', PARTIAL_SUCCESS: 'Thành công một phần', FAILED: 'Thất bại', CANCELLED: 'Đã hủy', LOCKED: 'Đã khóa', ISSUING: 'Đang cấp', RECOVERY_REQUIRED: 'Cần đối soát' } as Record<string, string>)[status] || status
}
function statusClass(status: string): string {
	return ['ACTIVE', 'SUCCESS'].includes(status) ? 'success' : ['FAILED', 'REVOKED', 'RECOVERY_REQUIRED', 'LOCKED'].includes(status) ? 'error' : ['PENDING', 'PREPARING', 'PROCESSING', 'PARTIAL_SUCCESS', 'ISSUING'].includes(status) ? 'warning' : 'neutral'
}
function stepLabel(step: string): string {
	return ({ AWAITING_DOSSIER: 'Chờ hồ sơ', AWAITING_CONFIRMATION: 'Chờ xác nhận', FACE_MATCH_REQUIRED: 'Cần đối chiếu khuôn mặt', VERIFIED: 'OTP đã hợp lệ', COMPLETE: 'Hoàn tất', SUBMIT_IDENTITY: 'Cần nộp hồ sơ đã xác minh qua adapter.', SUBMIT_FACE_MATCH: 'Cần gửi kết quả đối chiếu khuôn mặt qua adapter.', VERIFY_OTP: 'Kiểm tra OTP rồi xác nhận cấp chứng thư.', CONFIRM: 'OTP hợp lệ. Có thể xác nhận cấp chứng thư.', CONFIRM_ASSERTION: 'Cần assertion của adapter để xác nhận cấp.', USE_CERTIFICATE: 'Chứng thư đã sẵn sàng để ký.', CREATE_NEW_SESSION: 'Phiên đã đóng. Hãy tạo phiên mới.', CONTACT_ADMIN: 'Cần đối soát trạng thái với nhật ký vận hành.' } as Record<string, string>)[step] || step
}
async function loadClients() {
	clientsLoading.value = true
	error.value = ''
	try {
		clients.value = (await getManagement<{ items: ProviderClient[] }>('clients')).items
		if (!clients.value.some(client => client.uid === clientUid.value)) clientUid.value = clients.value[0]?.uid || ''
	} catch (failure) { error.value = errorMessage(failure) } finally { clientsLoading.value = false }
}
async function loadRecords() {
	const generation = ++listGeneration
	if (!clientUid.value) { records.value = []; total.value = 0; loading.value = false; return }
	loading.value = true
	try {
		const data = await getManagement<RecordList>('records', { clientUid: clientUid.value, kind: kind.value, pageIndex: page.value, pageSize: 20, personalIdNumber: kind.value === 'certificates' ? personalId.value : '' })
		if (generation !== listGeneration) return
		records.value = data.items
		total.value = data.totalItem
	} catch (failure) { if (generation === listGeneration) { records.value = []; total.value = 0; error.value = errorMessage(failure) } } finally { if (generation === listGeneration) loading.value = false }
}
function closeDetail() { detailGeneration++; detail.value = null; selectedId.value = ''; detailLoading.value = false; proof.value = ''; consent.value = false; copied.value = false; pdfPreview.value = null; dossierError.value = '' }
async function selectRecord(id: string) {
	const generation = ++detailGeneration
	selectedId.value = id
	detail.value = null
	detailLoading.value = true
	proof.value = ''; consent.value = false; copied.value = false; dossierError.value = ''
	try {
		const data = await getManagement<ManagedRecord>('record', { clientUid: clientUid.value, kind: kind.value, id })
		if (generation === detailGeneration) {
			detail.value = data
			await nextTick()
			if (generation === detailGeneration && window.innerWidth <= 1200) detailPanel.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
		}
	} catch (failure) { if (generation === detailGeneration) error.value = errorMessage(failure) } finally { if (generation === detailGeneration) detailLoading.value = false }
}
async function refresh() { error.value = ''; await Promise.all([loadRecords(), selectedId.value ? selectRecord(selectedId.value) : Promise.resolve()]) }
function applyPersonalId() { personalId.value = personalIdInput.value.trim(); if (page.value !== 1) page.value = 1; else void loadRecords() }
function clearPersonalId() { personalIdInput.value = ''; applyPersonalId() }
function pdfUrl(documentId: string, version: string, download = false) { return managementUrl('pdf', { clientUid: clientUid.value, sessionId: selectedId.value, documentId, version, download }) }
function preview(doc: DocumentResult, version: string) { pdfPreview.value = { name: `${version === 'source' ? 'Bản gốc' : 'Đã ký'} · ${doc.documentName}`, url: pdfUrl(doc.id, version) } }
async function copyLink() {
	if (!registrationUrl.value) return
	try { await navigator.clipboard.writeText(registrationUrl.value); copied.value = true } catch { error.value = 'Trình duyệt chưa cho phép sao chép. Bạn có thể chọn và sao chép đường dẫn trong ô phía trên.' }
}
async function downloadPem() {
	try {
		const data = await getManagement<{ chain: string[] }>('public-certificate', { clientUid: clientUid.value, id: selectedId.value })
		const url = URL.createObjectURL(new Blob([data.chain.join('\n')], { type: 'application/x-pem-file' }))
		const anchor = document.createElement('a'); anchor.href = url; anchor.download = `certificate-${selectedId.value}.pem`; anchor.click()
		setTimeout(() => URL.revokeObjectURL(url), 1000)
	} catch (failure) { error.value = errorMessage(failure) }
}
async function runAction(action: string, payload: Record<string, unknown> = {}) {
	if (busy.value) return
	busy.value = true; error.value = ''; notice.value = ''
	try {
		await performAction(clientUid.value, kind.value, action, selectedId.value, payload)
		notice.value = action === 'resend' ? 'Đã yêu cầu gửi lại OTP. Kiểm tra kênh nhận mã của phiên.' : 'Thao tác đã hoàn tất. Trạng thái mới được hiển thị bên dưới.'
	} catch (failure) { error.value = errorMessage(failure) } finally {
		busy.value = false; proof.value = ''; consent.value = false
		await refreshAfterAction()
	}
}
async function refreshAfterAction() { await Promise.all([loadRecords(), selectedId.value ? selectRecord(selectedId.value) : Promise.resolve()]) }
async function submitManualDossier(payload: Record<string, unknown>) {
	if (busy.value || !detail.value?.manualDossierAllowed || !canWrite('certificates:write')) return
	const uid = clientUid.value
	const id = selectedId.value
	busy.value = true; dossierError.value = ''; error.value = ''; notice.value = ''
	try {
		await performAction(uid, 'enrollment', 'manual-identity', id, payload)
		notice.value = 'Đã nhận hồ sơ. Tiếp tục bước OTP và xác nhận cấp trong chi tiết phiên.'
		await refreshAfterAction()
	} catch (failure) {
		dossierError.value = errorMessage(failure)
		// Delivery may fail after the dossier was frozen. Reconcile without
		// unmounting the form and discarding fields on ordinary validation errors.
		try {
			const latest = await getManagement<ManagedRecord>('record', { clientUid: uid, kind: 'enrollment', id })
			if (clientUid.value === uid && selectedId.value === id) {
				detail.value = latest
				if (!latest.manualDossierAllowed) error.value = dossierError.value
			}
		} catch { error.value = dossierError.value }
	} finally { busy.value = false }
}
async function confirmProof() {
	if (!consent.value || !detail.value) return
	const action = kind.value === 'enrollment' && detail.value.nextAction === 'VERIFY_OTP' ? 'otp' : 'confirm'
	const payload = kind.value === 'enrollment' ? { proof: proof.value.trim() } : detail.value.confirmationMethod === 'external_assertion' ? { assertion: proof.value.trim() } : { otpValue: proof.value.trim() }
	await runAction(action, payload)
}
async function confirmDestructive() { const action = confirmation.value; confirmation.value = null; if (action) await runAction(action, action === 'revoke' ? { reasonCode: 0, reasonText: revocationReason.value } : {}) }
function openCreate(value: 'enrollment' | 'signing', alias = '') {
	createKind.value = value; createError.value = ''; signingAlias.value = alias; selectedFiles.value = []; pendingCreate = null
	registration.value.registrationMethod = currentClient.value?.qrEnabled ? 'QR_SELF_UPLOAD' : 'CARD_READER'
}
function closeCreate() { if (!busy.value) createKind.value = null }
function chooseFiles(event: Event) { selectedFiles.value = Array.from((event.target as HTMLInputElement).files || []); pendingCreate = null }
async function createRecord() {
	if (busy.value || !createKind.value) return
	busy.value = true; createError.value = ''; error.value = ''; notice.value = ''
	const targetKind = createKind.value
	try {
		let payload: Record<string, unknown>
		if (targetKind === 'enrollment') payload = { ...registration.value }
		else {
			if (!selectedFiles.value.length || selectedFiles.value.length > 100 || selectedFiles.value.reduce((size, file) => size + file.size, 0) > 26214400) throw new Error('Chọn từ 1–100 PDF, tổng dung lượng không quá 25 MiB.')
			const files = []
			for (const [index, file] of selectedFiles.value.entries()) files.push({ id: String(index), documentName: file.name, fileBase64: await pdfFileBase64(file) })
			payload = { certAlias: signingAlias.value.trim(), confirmationMethod: 'service_otp', reason: signingReason.value, files }
		}
		const fingerprint = JSON.stringify(payload)
		if (!pendingCreate || pendingCreate.fingerprint !== fingerprint) pendingCreate = { key: `console-${Date.now()}-${Math.random().toString(36).slice(2)}`, fingerprint, payload }
		const result = await performAction(clientUid.value, targetKind, 'create', '', pendingCreate.payload, pendingCreate.key)
		createKind.value = null; pendingCreate = null
		kind.value = targetKind; page.value = 1
		await loadRecords()
		await selectRecord(recordId(result, targetKind))
		notice.value = 'Đã tiếp nhận yêu cầu. Kiểm tra trạng thái và bước tiếp theo trong chi tiết phiên.'
	} catch (failure) { createError.value = failure instanceof Error && !('response' in failure) ? failure.message : errorMessage(failure) } finally { busy.value = false }
}
async function openCertificate(alias: string) { kind.value = 'certificates'; await loadRecords(); await selectRecord(alias) }
watch([clientUid, kind], () => { closeDetail(); records.value = []; total.value = 0; search.value = ''; error.value = ''; notice.value = ''; personalId.value = ''; personalIdInput.value = ''; if (page.value !== 1) page.value = 1; else void loadRecords() }, { flush: 'sync' })
watch(page, () => { void loadRecords() })
onMounted(loadClients)
</script>

<style scoped lang="scss">
.provider-management {
	--panel-border: var(--color-border, #dedede);
	padding: 32px;
	max-width: 1800px;
	margin: 0 auto;
	color: var(--color-main-text);
	button, .button { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 8px 14px; border: 1px solid var(--panel-border); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); font: inherit; cursor: pointer; text-decoration: none; }
	button:hover, .button:hover { background: var(--color-background-hover); }
	button:disabled { opacity: .5; cursor: default; }
	button.primary { background: var(--color-primary-element); color: var(--color-primary-element-text); border-color: var(--color-primary-element); }
	button.danger { color: var(--color-error-text, #a42222); }
	button:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
	label { display: flex; flex-direction: column; gap: 6px; font-weight: 600; }
	input:not([type='checkbox']), select, textarea { width: 100%; min-height: 42px; margin: 0; border: 1px solid var(--panel-border); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); padding: 9px 12px; font: inherit; font-weight: 400; box-sizing: border-box; }
	h1 { font-size: 30px; font-weight: 700; line-height: 1.25; margin: 4px 0 10px; }
	h2 { font-size: 20px; font-weight: 600; margin: 0; }
	h3 { font-size: 17px; font-weight: 600; margin: 16px 0 10px; }
	p { line-height: 1.6; margin: 0; }
	code { overflow-wrap: anywhere; font-size: 12px; }
}
.workspace-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 24px; p { color: var(--color-text-maxcontrast); } }
.eyebrow { font-size: 11px; letter-spacing: .12em; font-weight: 700; }
.button-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.integration-bar { display: flex; flex-wrap: wrap; gap: 18px; align-items: center; padding: 18px 20px; background: var(--color-background-dark); border-radius: 12px; label { min-width: 220px; } }
.integration-meta { color: var(--color-text-maxcontrast); }
.workspace-tabs { display: flex; gap: 8px; margin: 22px 0; border-bottom: 1px solid var(--panel-border); padding-bottom: 12px; flex-wrap: wrap; button.active { color: var(--color-primary-element); border-color: var(--color-primary-element); background: var(--color-primary-element-light); font-weight: 700; } }
.workspace-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; align-items: start; &.has-detail { grid-template-columns: minmax(0, 1.4fr) minmax(330px, 1fr); } }
.records-panel, .detail-panel { border: 1px solid var(--panel-border); border-radius: 12px; min-width: 0; overflow: hidden; }
.panel-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 20px; p { color: var(--color-text-maxcontrast); font-size: 13px; margin-top: 4px; } }
.search-field { margin: 0 20px 16px; font-size: 12px; }
.lookup-row { display: flex; align-items: end; gap: 8px; padding: 0 20px 16px; label { flex: 1; font-size: 12px; } }
.table-scroll { overflow-x: auto; table { width: 100%; border-collapse: collapse; text-align: start; } th { font-weight: 600; color: var(--color-text-maxcontrast); background: var(--color-background-dark); font-size: 12px; } th, td { padding: 14px 16px; border-bottom: 1px solid var(--panel-border); vertical-align: middle; } td { font-size: 13px; } td:first-child { min-width: 170px; max-width: 290px; } td code { display: block; color: var(--color-text-maxcontrast); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } tr.selected { background: var(--color-primary-element-light); } }
.secondary { display: block; font-size: 12px; margin: 4px 0; color: var(--color-text-maxcontrast); }
.status { display: inline-block; padding: 4px 9px; border-radius: 6px; font-size: 12px; font-weight: 600; line-height: 1.5; white-space: nowrap; }
.success { background: var(--color-success-light, #eaf5ed); color: var(--color-success-text, #246538); }
.warning { background: var(--color-warning-light, #fff4d7); color: var(--color-warning-text, #805a06); }
.error { background: var(--color-error-light, #fcecec); color: var(--color-error-text, #a42222); }
.neutral { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.notice { padding: 12px 14px; border-radius: 8px; margin: 12px 0; border: 1px solid var(--panel-border); line-height: 1.6; overflow-wrap: anywhere; }
.pagination { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; gap: 8px; font-size: 12px; }
.empty-state { padding: 48px 28px; text-align: center; p { color: var(--color-text-maxcontrast); max-width: 580px; margin: 10px auto 18px; } }
.detail-panel { padding: 20px; .panel-heading { padding: 0 0 16px; } dl { margin: 16px 0; display: grid; grid-template-columns: minmax(90px, .65fr) minmax(0, 1fr); gap: 9px 16px; font-size: 13px; } dt { color: var(--color-text-maxcontrast); } dd { margin: 0; overflow-wrap: anywhere; } }
.qr-panel { border-top: 1px solid var(--panel-border); margin-top: 20px; img { display: block; padding: 12px; margin: 12px auto; background: white; max-width: 100%; height: auto; } .button-row { margin: 12px 0; } small { color: var(--color-text-maxcontrast); } }
.document-card { border: 1px solid var(--panel-border); padding: 14px; margin-top: 12px; border-radius: 8px; strong { display: block; overflow-wrap: anywhere; margin-bottom: 8px; } .button-row { margin-top: 12px; } details { margin-top: 12px; } }
.document-error { color: var(--color-error-text); margin-top: 8px; font-size: 12px; }
.workflow-actions { border-top: 1px solid var(--panel-border); margin-top: 20px; display: flex; flex-direction: column; gap: 12px; }
.section-actions { margin-top: 16px; }
.checkbox-label { flex-direction: row !important; align-items: start; font-size: 13px; input { margin-top: 4px; } }
.create-form { display: flex; flex-direction: column; gap: 16px; padding: 6px; min-width: 280px; }
.dialog-content { padding: 6px; }
.pdf-preview { padding: 6px; iframe { width: 100%; height: 65vh; border: 1px solid var(--panel-border); margin-bottom: 12px; } p { margin-top: 12px; } }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
@media (max-width: 1200px) { .workspace-grid.has-detail { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 600px) { .provider-management { padding: 18px 12px; h1 { font-size: 25px; } } .workspace-header .eyebrow { padding-inline-start: 36px; } .integration-bar { padding: 14px; label { min-width: 100%; } } .lookup-row { flex-wrap: wrap; } .detail-panel { padding: 14px; } }
</style>
