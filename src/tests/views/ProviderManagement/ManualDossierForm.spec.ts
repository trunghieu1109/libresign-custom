/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ManualDossierForm from '../../../views/ProviderManagement/ManualDossierForm.vue'

const session = { sessionId: 'enrollment', status: 'PENDING', personalIdNumber: '000000000001', fullName: 'Test Signer', email: 'test@example.test', phone: '+84912345678', otpChannel: 'email' }

describe('manual test dossier form', () => {
	it('locks the identity, preserves contacts and requires test-data acknowledgement', async () => {
		const wrapper = mount(ManualDossierForm, { props: { session } })
		expect(wrapper.findAll('input[readonly]')).toHaveLength(2)
		expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe(session.email)
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('submit')).toBeUndefined()
		await wrapper.find('input[type="checkbox"]').setValue(true)
		await wrapper.find('form').trigger('submit')
		const payload = wrapper.emitted('submit')![0][0]
		expect(payload).toMatchObject({ email: session.email, phone: session.phone, testDataConsent: true })
		expect(payload).not.toHaveProperty('identifyNumber')
		expect(payload).not.toHaveProperty('isPass')
		expect(payload).not.toHaveProperty('verification')
	})

	it('does not submit while the parent is processing and displays field errors', async () => {
		const wrapper = mount(ManualDossierForm, { props: { session, disabled: true, error: 'CCCD hết hạn' } })
		await wrapper.find('input[type="checkbox"]').setValue(true)
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('submit')).toBeUndefined()
		expect(wrapper.find('[role="alert"]').text()).toBe('CCCD hết hạn')
	})

	it('retains typed data when a validation response refreshes the same session', async () => {
		const wrapper = mount(ManualDossierForm, { props: { session } })
		await wrapper.find('textarea').setValue('Địa chỉ đang nhập')
		await wrapper.setProps({ session: { ...session }, error: 'Ngày không hợp lệ' })
		expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('Địa chỉ đang nhập')
		await wrapper.setProps({ session: { ...session, sessionId: 'another' } })
		expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('')
	})
})
