/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export type RecordKind = 'certificates' | 'enrollment' | 'signing'
export interface ProviderClient {
	uid: string
	enabled: boolean
	scopes: string[]
	otpChannel: string | null
	mockOtpEnabled: boolean
	mockRegistrationEnabled: boolean
	qrEnabled: boolean
}
export interface DocumentResult {
	id: string
	documentName: string
	status: string
	errorCode?: string
	errorMessage?: string
	inputHash?: string
	outputHash?: string
	hasSource: boolean
	hasResult: boolean
}
export interface ManagedRecord {
	id?: string
	sessionId?: string
	signSessionId?: string
	certAlias?: string
	status: string
	fullName?: string
	subjectName?: string
	personalIdNumber?: string
	serialNumber?: string
	fingerprintSha256?: string
	validFrom?: string
	validTo?: string
	createdAt?: string
	expiresAt?: string
	step?: string
	nextAction?: string
	message?: string
	registrationMethod?: string
	confirmationMethod?: string
	registrationUrl?: string | null
	registrationMode?: string | null
	qrCode?: string | null
	email?: string
	phone?: string
	otpChannel?: string | null
	challengeBase64?: string
	manualDossierAllowed?: boolean
	dossierOrigin?: 'manual_test' | 'adapter' | null
	dossierFields?: { address?: string, dateOfBirth?: string, issueDate?: string, expiredDate?: string, sex?: string } | null
	documentCount?: number
	successCount?: number
	failedCount?: number
	certificate?: ManagedRecord
	results?: DocumentResult[]
}
export interface RecordList { items: ManagedRecord[], totalItem: number }

export function managementUrl(path: string, params: Record<string, string | number | boolean> = {}): string {
	const query = new URLSearchParams(Object.entries(params).map(([key, value]) => [key, String(value)]))
	return generateUrl(`/apps/libresign/api/v1/management/${path}`) + (query.size ? `?${query}` : '')
}

export async function getManagement<T>(path: string, params: Record<string, string | number> = {}): Promise<T> {
	return (await axios.get<{ data: T }>(managementUrl(path, params))).data.data
}

export async function performAction(clientUid: string, kind: RecordKind, action: string, id = '', payload: Record<string, unknown> = {}, requestId = ''): Promise<ManagedRecord> {
	return (await axios.post<{ data: ManagedRecord }>(managementUrl('action'), { clientUid, kind, action, id, payload, requestId })).data.data
}

export function errorMessage(error: unknown): string {
	const failure = error as { response?: { data?: { error?: { code?: string, message?: string } } } }
	const detail = failure.response?.data?.error
	return detail?.message ? `${detail.message}${detail.code ? ` (${detail.code})` : ''}` : 'Không thể kết nối LibreSign. Hãy tải lại để đối soát trạng thái trước khi thử lại.'
}

export function recordId(record: ManagedRecord, kind: RecordKind): string {
	return (kind === 'certificates' ? record.certAlias : record.sessionId ?? record.signSessionId ?? record.id) ?? ''
}

export function safeRegistrationUrl(value?: string | null): string | null {
	if (!value) return null
	try {
		const url = new URL(value)
		return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password ? url.href : null
	} catch {
		return null
	}
}

export async function pdfFileBase64(file: File): Promise<string> {
	if (!file.size || file.size > 5242880) throw new Error('Mỗi PDF phải lớn hơn 0 byte và không quá 5 MiB.')
	const bytes = new Uint8Array(await file.arrayBuffer())
	if (new TextDecoder().decode(bytes.slice(0, 5)) !== '%PDF-') throw new Error(`${file.name}: tệp không có định dạng PDF.`)
	let binary = ''
	for (let start = 0; start < bytes.length; start += 32768) binary += String.fromCharCode(...bytes.subarray(start, start + 32768))
	return btoa(binary)
}
