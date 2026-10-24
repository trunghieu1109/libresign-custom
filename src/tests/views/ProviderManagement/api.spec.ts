/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { managementUrl, pdfFileBase64, recordId, safeRegistrationUrl } from '../../../views/ProviderManagement/api'

describe('provider console boundaries', () => {
	it('encodes tenant and document identifiers as query values', () => {
		const url = new URL(managementUrl('pdf', { clientUid: 'a&clientUid=b', documentId: '../other?id=1', download: true }), 'https://cloud.test')
		expect(url.searchParams.get('clientUid')).toBe('a&clientUid=b')
		expect(url.searchParams.get('documentId')).toBe('../other?id=1')
	})

	it.each(['javascript:alert(1)', 'data:text/html,hi', 'https://user:pass@example.test', '/relative', 'broken-url'])('rejects unsafe registration link %s', value => {
		expect(safeRegistrationUrl(value)).toBeNull()
	})

	it('preserves signed capability query parameters', () => {
		expect(safeRegistrationUrl('https://cloud.test/register?token=abc.123')).toBe('https://cloud.test/register?token=abc.123')
	})

	it('selects aliases and workflow identifiers according to record kind', () => {
		expect(recordId({ certAlias: 'cert', id: 'session', status: 'ACTIVE' }, 'certificates')).toBe('cert')
		expect(recordId({ sessionId: 'enroll', status: 'PENDING' }, 'enrollment')).toBe('enroll')
	})

	it('validates PDF bytes before base64 conversion', async () => {
		expect(await pdfFileBase64(new File(['%PDF-1.7\nfixture'], 'test.pdf'))).toBe(btoa('%PDF-1.7\nfixture'))
		await expect(pdfFileBase64(new File(['<html>'], 'fake.pdf', { type: 'application/pdf' }))).rejects.toThrow('định dạng PDF')
		await expect(pdfFileBase64(new File([], 'empty.pdf'))).rejects.toThrow('5 MiB')
	})
})
