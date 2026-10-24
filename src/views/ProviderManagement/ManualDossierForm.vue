<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section class="manual-dossier" aria-label="Nhập hồ sơ thử nghiệm">
		<h3>Nhập hồ sơ thử nghiệm</h3>
		<p class="explanation">Dùng form này để thử bước nộp hồ sơ khi chưa nối đầu đọc. Dữ liệu được ghi nhận là nhập tay thử nghiệm; không xác minh chip CCCD hoặc khuôn mặt.</p>
		<form @submit.prevent="submit">
			<fieldset :disabled="disabled">
				<div class="fields">
					<label>CCCD<input :value="session.personalIdNumber" readonly></label>
					<label>Họ tên<input :value="session.fullName" readonly></label>
					<label>Ngày sinh<input v-model="fields.dateOfBirth" type="date" required></label>
					<label>Giới tính<select v-model="fields.sex" required><option value="" disabled>Chọn giới tính</option><option>Nam</option><option>Nữ</option><option>Khác</option></select></label>
					<label class="full-width">Địa chỉ thường trú<textarea v-model="fields.address" required maxlength="1024" rows="2" /></label>
					<label>Ngày cấp CCCD<input v-model="fields.issueDate" type="date" required></label>
					<label>Ngày hết hạn CCCD<input v-model="fields.expiredDate" type="date" required></label>
					<label>Email nhận OTP<input v-model="fields.email" type="email" :required="['email', 'email_sms'].includes(session.otpChannel || '')" maxlength="254"></label>
					<label>Số điện thoại nhận OTP<input v-model="fields.phone" type="tel" :required="['sms', 'email_sms'].includes(session.otpChannel || '')" maxlength="20"></label>
				</div>
				<p class="hint">CCCD và họ tên được lấy từ phiên đã tạo. Có thể sửa liên hệ trước khi gửi hồ sơ; OTP sẽ gửi tới liên hệ này.</p>
				<label class="test-consent"><input v-model="testDataConsent" type="checkbox" required> Tôi xác nhận đây là dữ liệu thử nghiệm để kiểm tra luồng cấp chứng thư.</label>
				<p v-if="error" class="form-error" role="alert">{{ error }}</p>
				<button class="primary" :disabled="disabled || !testDataConsent">{{ disabled ? 'Đang gửi hồ sơ…' : 'Gửi hồ sơ' }}</button>
				<p class="hint">Hồ sơ hợp lệ sẽ chuyển sang bước OTP hoặc xác nhận cấp. Gửi hồ sơ chưa cấp chứng thư ngay.</p>
			</fieldset>
		</form>
	</section>
</template>

<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import type { ManagedRecord } from './api'

const props = withDefaults(defineProps<{ session: ManagedRecord, disabled?: boolean, error?: string }>(), { disabled: false, error: '' })
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const fields = reactive({ dateOfBirth: '', sex: '', address: '', issueDate: '', expiredDate: '', email: props.session.email || '', phone: props.session.phone || '' })
const testDataConsent = ref(false)
watch(() => props.session.sessionId, () => {
	Object.assign(fields, { dateOfBirth: '', sex: '', address: '', issueDate: '', expiredDate: '', email: props.session.email || '', phone: props.session.phone || '' })
	testDataConsent.value = false
})
function submit() {
	if (!props.disabled && testDataConsent.value) emit('submit', { ...fields, testDataConsent: true })
}
</script>

<style scoped lang="scss">
.manual-dossier { border-top: 1px solid var(--color-border); margin-top: 20px; padding-top: 4px; }
.explanation { padding: 12px; border-radius: 8px; background: var(--color-warning-light, #fff4d7); color: var(--color-warning-text, #805a06); line-height: 1.6; }
fieldset { border: 0; padding: 0; margin: 16px 0 0; min-width: 0; }
.fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.fields label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; }
.full-width { grid-column: 1 / -1; }
input:not([type='checkbox']), select, textarea { width: 100%; min-width: 0; min-height: 42px; border: 1px solid var(--color-border); border-radius: 8px; padding: 8px; margin: 0; box-sizing: border-box; font: inherit; font-weight: 400; background: var(--color-main-background); color: var(--color-main-text); }
input[readonly] { background: var(--color-background-dark); }
.test-consent { display: flex; flex-direction: row; align-items: start; gap: 8px; margin: 14px 0; font-size: 13px; line-height: 1.5; input { margin-top: 4px; flex-shrink: 0; } }
.hint { color: var(--color-text-maxcontrast); font-size: 12px; margin-top: 12px; line-height: 1.6; }
.form-error { padding: 12px; border-radius: 8px; margin-bottom: 12px; background: var(--color-error-light); color: var(--color-error-text); }
button { padding: 10px 16px; min-height: 42px; border: 0; border-radius: 8px; font: inherit; background: var(--color-primary-element); color: var(--color-primary-element-text); cursor: pointer; }
button:disabled { opacity: .5; cursor: default; }
input:focus-visible, select:focus-visible, textarea:focus-visible, button:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
@media (max-width: 600px) { .fields { grid-template-columns: minmax(0, 1fr); } }
</style>
