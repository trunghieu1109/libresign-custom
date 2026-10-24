/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ProviderManagement from '../../../views/ProviderManagement/ProviderManagement.vue'
import type { ManagedRecord } from '../../../views/ProviderManagement/api'

const { get, action } = vi.hoisted(() => ({ get: vi.fn(), action: vi.fn() }))
vi.mock('../../../views/ProviderManagement/api', async importOriginal => ({
	...await importOriginal<typeof import('../../../views/ProviderManagement/api')>(),
	getManagement: (...args: unknown[]) => get(...args),
	performAction: (...args: unknown[]) => action(...args),
}))
vi.mock('@nextcloud/vue/components/NcAppContent', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: { props: ['name', 'size', 'noClose'], emits: ['update:open'], template: '<div role="dialog"><button data-test="dialog-close" @click="$emit(\'update:open\', false)">X</button><slot /></div>' } }))

let rows: ManagedRecord[]
let detail: ManagedRecord
const client = { uid: 'partner', enabled: true, scopes: ['certificates:write', 'certificates:revoke', 'signing:write'], otpChannel: 'email', qrEnabled: true }

async function render() { const wrapper = mount(ProviderManagement); await flushPromises(); return wrapper }
function button(wrapper: ReturnType<typeof mount>, text: string) { return wrapper.findAll('button').find(node => node.text() === text)! }

beforeEach(() => {
	vi.clearAllMocks()
	rows = [{ certAlias: 'certificate-a', subjectName: 'Nguyễn An', status: 'ACTIVE' }]
	detail = { ...rows[0] }
	get.mockImplementation(async (path: string) => path === 'clients' ? { items: [client] } : path === 'records' ? { items: rows, totalItem: rows.length } : detail)
	action.mockResolvedValue({ status: 'SUCCESS' })
})

describe('provider management workflows', () => {
	it('loads integration records and selected certificate details', async () => {
		const wrapper = await render()
		expect(get).toHaveBeenCalledWith('records', expect.objectContaining({ clientUid: 'partner', kind: 'certificates', pageIndex: 1 }))
		await button(wrapper, 'Xem').trigger('click'); await flushPromises()
		expect(wrapper.find('aside').text()).toContain('Nguyễn An')
		expect(wrapper.find('aside').text()).toContain('Tải chứng thư PEM')
	})

	it('requires an explicit confirmation before revoking a certificate', async () => {
		const wrapper = await render()
		await button(wrapper, 'Xem').trigger('click'); await flushPromises()
		await button(wrapper, 'Thu hồi…').trigger('click')
		expect(action).not.toHaveBeenCalled()
		expect(wrapper.find('[role="dialog"]').text()).toContain('không thể hoàn tác')
		await button(wrapper, 'Xác nhận').trigger('click'); await flushPromises()
		expect(action).toHaveBeenCalledWith('partner', 'certificates', 'revoke', 'certificate-a', { reasonCode: 0, reasonText: '' })
	})

	it('renders the QR returned by the enrollment API and a downloadable image', async () => {
		const wrapper = await render()
		rows = [{ id: 'enrollment-a', fullName: 'Nguyễn An', status: 'PENDING' }]
		detail = { sessionId: 'enrollment-a', status: 'PENDING', registrationUrl: 'https://cloud.test/register?token=abc', qrCode: 'aGVsbG8=', nextAction: 'SUBMIT_IDENTITY' }
		await button(wrapper, 'Phiên cấp chứng thư').trigger('click'); await flushPromises()
		await button(wrapper, 'Xem').trigger('click'); await flushPromises()
		expect(wrapper.find('.qr-panel img').attributes('src')).toBe('data:image/png;base64,aGVsbG8=')
		expect(wrapper.find('.qr-panel a[download]').attributes('download')).toBe('registration-enrollment-a.png')
	})

	it('offers PDF previews only for artifacts actually available', async () => {
		const wrapper = await render()
		rows = [{ id: 'sign-a', fullName: 'Nguyễn An', status: 'PARTIAL_SUCCESS' }]
		detail = { id: 'sign-a', status: 'PARTIAL_SUCCESS', documentCount: 2, successCount: 1, results: [
			{ id: '0', documentName: 'signed.pdf', status: 'SUCCESS', hasSource: true, hasResult: true },
			{ id: '1', documentName: 'failed.pdf', status: 'FAILED', errorCode: 'invalid_pdf', hasSource: false, hasResult: false },
		] }
		await button(wrapper, 'Phiên ký PDF').trigger('click'); await flushPromises()
		await button(wrapper, 'Xem').trigger('click'); await flushPromises()
		expect(wrapper.findAll('.document-card a')).toHaveLength(1)
		await button(wrapper, 'Xem bản đã ký').trigger('click')
		expect(wrapper.find('iframe').attributes('src')).toContain('documentId=0')
		expect(wrapper.find('iframe').attributes('src')).toContain('sessionId=sign-a')
	})

	it('does not show stale records when integration requests finish out of order', async () => {
		let finishFirst: (value: unknown) => void = () => {}
		get.mockImplementation((path: string, params?: { clientUid: string }) => {
			if (path === 'clients') return Promise.resolve({ items: [client, { ...client, uid: 'partner-b' }] })
			if (params?.clientUid === 'partner') return new Promise(resolve => { finishFirst = resolve })
			return Promise.resolve({ items: [{ certAlias: 'b-cert', subjectName: 'Partner B only', status: 'ACTIVE' }], totalItem: 1 })
		})
		const wrapper = await render()
		await wrapper.find('select').setValue('partner-b'); await flushPromises()
		finishFirst({ items: rows, totalItem: 1 }); await flushPromises()
		expect(wrapper.text()).toContain('Partner B only')
		expect(wrapper.text()).not.toContain('Nguyễn An')
	})

	it('retains the create request ID when retrying unchanged enrollment data', async () => {
		const wrapper = await render()
		await button(wrapper, 'Cấp chứng thư').trigger('click')
		const form = wrapper.find('.create-form')
		const inputs = form.findAll('input')
		await inputs[0].setValue('000000000001')
		await inputs[1].setValue('Nguyễn An')
		await inputs[2].setValue('test@example.test')
		action.mockRejectedValueOnce({ response: { data: { error: { code: 'timeout', message: 'Unknown outcome' } } } })
		await form.trigger('submit'); await flushPromises()
		expect(form.text()).toContain('Unknown outcome')
		action.mockResolvedValueOnce({ sessionId: 'enrollment-new', status: 'PENDING' })
		await form.trigger('submit'); await flushPromises()
		expect(action.mock.calls[1][5]).toBe(action.mock.calls[0][5])
		expect(action.mock.calls[1][4]).toEqual(action.mock.calls[0][4])
	})

	it('requires consent before forwarding a signing OTP', async () => {
		const wrapper = await render()
		rows = [{ id: 'sign-a', status: 'PENDING' }]
		detail = { id: 'sign-a', status: 'PENDING', confirmationMethod: 'service_otp', documentCount: 1, results: [] }
		await button(wrapper, 'Phiên ký PDF').trigger('click'); await flushPromises()
		await button(wrapper, 'Xem').trigger('click'); await flushPromises()
		const form = wrapper.find('.workflow-actions')
		await form.find('input:not([type="checkbox"])').setValue('654321')
		await form.trigger('submit'); await flushPromises()
		expect(action).not.toHaveBeenCalled()
		await form.find('input[type="checkbox"]').setValue(true)
		await form.trigger('submit'); await flushPromises()
		expect(action).toHaveBeenCalledWith('partner', 'signing', 'confirm', 'sign-a', { otpValue: '654321' })
	})

	it('disables create operations for a disabled integration', async () => {
		get.mockImplementation(async (path: string) => path === 'clients' ? { items: [{ ...client, enabled: false }] } : { items: [], totalItem: 0 })
		const wrapper = await render()
		expect(button(wrapper, 'Cấp chứng thư').attributes('disabled')).toBeDefined()
		expect(button(wrapper, '+ Ký PDF').attributes('disabled')).toBeDefined()
	})

	it('can reopen a form after closing it with the dialog close control', async () => {
		const wrapper = await render()
		await button(wrapper, 'Cấp chứng thư').trigger('click')
		await wrapper.find('[data-test="dialog-close"]').trigger('click')
		expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
		await button(wrapper, 'Cấp chứng thư').trigger('click')
		expect(wrapper.find('.create-form').exists()).toBe(true)
	})

	it('submits a manual test dossier and advances to the OTP step', async () => {
		const wrapper = await render()
		rows = [{ id: 'enrollment-a', status: 'PENDING' }]
		detail = { sessionId: 'enrollment-a', status: 'PENDING', fullName: 'Test Signer', personalIdNumber: '000000000001', email: 'test@example.test', phone: '+84912345678', manualDossierAllowed: true, nextAction: 'SUBMIT_IDENTITY' }
		await button(wrapper, 'Phiên cấp chứng thư').trigger('click'); await flushPromises()
		await button(wrapper, 'Xem').trigger('click'); await flushPromises()
		const form = wrapper.find('.manual-dossier form')
		expect(form.exists()).toBe(true)
		await form.find('textarea').setValue('Địa chỉ thử')
		await form.find('input[type="checkbox"]').setValue(true)
		detail = { ...detail, manualDossierAllowed: false, nextAction: 'VERIFY_OTP', confirmationMethod: 'service_otp', dossierOrigin: 'manual_test' }
		await form.trigger('submit'); await flushPromises()
		expect(action).toHaveBeenCalledWith('partner', 'enrollment', 'manual-identity', 'enrollment-a', expect.objectContaining({ address: 'Địa chỉ thử', testDataConsent: true }))
		expect(wrapper.find('.manual-dossier').exists()).toBe(false)
		expect(wrapper.find('.workflow-actions').text()).toContain('Kiểm tra OTP / xác nhận')
	})

	it('keeps the manual form and its values after invalid dossier input', async () => {
		const wrapper = await render()
		rows = [{ id: 'enrollment-a', status: 'PENDING' }]
		detail = { sessionId: 'enrollment-a', status: 'PENDING', manualDossierAllowed: true, nextAction: 'SUBMIT_IDENTITY' }
		await button(wrapper, 'Phiên cấp chứng thư').trigger('click'); await flushPromises()
		await button(wrapper, 'Xem').trigger('click'); await flushPromises()
		const form = wrapper.find('.manual-dossier form')
		await form.find('textarea').setValue('Giữ lại địa chỉ')
		await form.find('input[type="checkbox"]').setValue(true)
		action.mockRejectedValueOnce({ response: { data: { error: { code: 'SYS-0058', message: 'CCCD hết hạn' } } } })
		await form.trigger('submit'); await flushPromises()
		expect(wrapper.find('.manual-dossier [role="alert"]').text()).toContain('CCCD hết hạn')
		expect((wrapper.find('.manual-dossier textarea').element as HTMLTextAreaElement).value).toBe('Giữ lại địa chỉ')
	})
})
