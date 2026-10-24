<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** Admin console adapter. Never expose encrypted workflow data or credential storage. */
class ProviderManagementService {
	public function __construct(
		private IAppConfig $config,
		private IUserSession $session,
		private IGroupManager $groups,
		private IUserManager $users,
		private ProviderStateStore $store,
		private ProviderArtifactStore $artifacts,
		private PublicRegistrationService $registrations,
		private ProviderCertificateService $certificates,
		private PublicSigningService $signing,
		private LoggerInterface $logger,
	) {
	}

	private function authorize(): void {
		$user = $this->session->getUser();
		if (!$user || !$user->isEnabled()) {
			throw new ProviderException('unauthenticated', 'Administrator authentication is required.', 401);
		}
		if (!$this->groups->isAdmin($user->getUID())) {
			throw new ProviderException('forbidden', 'Only administrators can manage provider workflows.', 403);
		}
	}

	private function configuration(): array {
		$this->authorize();
		$this->config->clearCache();
		$clients = json_decode($this->config->getValueString('libresign', 'provider_clients', '{}'), true, 64, JSON_THROW_ON_ERROR);
		return is_array($clients) ? $clients : [];
	}

	public function clients(): array {
		$items = [];
		foreach ($this->configuration() as $uid => $config) {
			if (!is_array($config)) {
				continue;
			}
			$items[] = ['uid' => (string)$uid, 'enabled' => ($config['enabled'] ?? false) === true && ($this->users->get((string)$uid)?->isEnabled() ?? false),
				'scopes' => array_values(array_intersect($config['scopes'] ?? [], ['certificates:read', 'certificates:write', 'certificates:revoke', 'signing:read', 'signing:write'])),
				'otpChannel' => $config['otpChannel'] ?? null, 'mockOtpEnabled' => ($config['mockOtpEnabled'] ?? false) === true,
				'mockRegistrationEnabled' => ($config['mockRegistrationEnabled'] ?? false) === true,
				'qrEnabled' => !empty($config['registrationBaseUrl']) || ($config['mockRegistrationEnabled'] ?? false) === true];
		}
		return ['items' => $items];
	}

	private function client(string $uid, ?string $writeScope = null): array {
		$config = $this->configuration()[$uid] ?? null;
		if (!is_array($config)) {
			throw new ProviderException('not_found', 'Integration not found.', 404);
		}
		// Administrators can inspect disabled integrations, but cannot act for them.
		if ($writeScope !== null && (($config['enabled'] ?? false) !== true || !$this->users->get($uid)?->isEnabled()
			|| !in_array($writeScope, $config['scopes'] ?? [], true))) {
			throw new ProviderException('forbidden', 'Integration is disabled or does not have the required scope.', 403);
		}
		return ['uid' => $uid, 'config' => $config];
	}

	public function listing(string $uid, string $kind, int $page, int $size, string $personalId): array {
		$client = $this->client($uid);
		if ($page < 1 || $size < 1 || $size > 100) {
			throw new ProviderException('invalid_pagination', 'pageIndex starts at one; pageSize must be 1-100.');
		}
		if ($kind === 'certificates') {
			return $this->registrations->listing($client, $personalId, $page, $size);
		}
		$list = $this->store->listing($uid, $kind, $page, $size);
		$list['items'] = array_map(function (array $record) use ($client, $kind): array {
			$item = $this->summary($record);
			if ($kind === 'enrollment' && !empty($record['data']['legacySession'])) {
				$native = $this->certificates->registration($client, $record['data']['legacySession']);
				if (in_array($native['status'], ['ISSUING', 'RECOVERY_REQUIRED', 'LOCKED'], true)) {
					$item['status'] = $native['status'];
				}
			}
			return $item;
		}, $list['items']);
		return $list;
	}

	private function summary(array $record): array {
		$data = $record['data'];
		$person = $data['input']['person'];
		$documents = $data['documents'] ?? [];
		return ['id' => $record['record_id'], 'status' => $record['status'] === 'PENDING' && $record['expires_at'] <= time() ? 'EXPIRED' : $record['status'],
			'fullName' => $person['fullName'], 'personalIdNumber' => $person['personalIdNumber'], 'step' => $data['step'] ?? null,
			'certAlias' => $data['alias'] ?? null, 'registrationMethod' => $data['input']['registrationMethod'] ?? null,
			'confirmationMethod' => $data['input']['confirmationMethod'], 'documentCount' => count($documents),
			'successCount' => count(array_filter($documents, fn ($doc) => $doc['status'] === 'SUCCESS')),
			'failedCount' => count(array_filter($documents, fn ($doc) => in_array($doc['status'], ['FAILED', 'RECOVERY_REQUIRED'], true))),
			'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $record['created_at']), 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $record['expires_at'])];
	}

	public function detail(string $uid, string $kind, string $id): array {
		$client = $this->client($uid);
		if ($kind === 'certificates') {
			return $this->registrations->certificate($client, $id);
		}
		if ($kind === 'enrollment') {
			$result = $this->registrations->registration($client, $id);
			$record = $this->store->get($uid, 'enrollment', $id);
			$dossier = $record['data']['dossier'] ?? null;
			$result['manualDossierAllowed'] = $this->manualIdentityEnabled($client)
				&& $result['status'] === 'PENDING' && $result['nextAction'] === 'SUBMIT_IDENTITY'
				&& $record['expires_at'] > time() && empty($dossier) && empty($record['data']['input']['reusePreviousDossier']);
			$result['dossierOrigin'] = $dossier ? (($dossier['verification']['method'] ?? '') === 'MANUAL_TEST' ? 'manual_test' : 'adapter') : null;
			$result['dossierFields'] = $dossier ? array_intersect_key($dossier['fields'], array_flip(['address', 'dateOfBirth', 'issueDate', 'expiredDate', 'sex'])) : null;
			return $result;
		}
		if ($kind !== 'signing') {
			throw new ProviderException('invalid_input', 'Unknown record kind.');
		}
		$record = $this->store->get($uid, 'signing', $id);
		$result = $this->summary($record);
		if ($result['status'] === 'PENDING' && $result['confirmationMethod'] === 'external_assertion') {
			$result['challengeBase64'] = $this->signing->get($client, $id)['challengeBase64'] ?? null;
		}
		$result['results'] = array_map(static function (array $doc): array {
			return array_intersect_key($doc, array_flip(['id', 'documentName', 'status', 'errorCode', 'errorMessage', 'inputHash', 'outputHash']))
				+ ['hasSource' => isset($doc['source']), 'hasResult' => isset($doc['result']) && $doc['status'] === 'SUCCESS'];
		}, $record['data']['documents']);
		return $result;
	}

	/** Resolve the artifact only after checking the tenant, session and document. */
	public function pdf(string $uid, string $id, string $documentId, string $version): array {
		$this->client($uid);
		if (!in_array($version, ['source', 'result'], true)) {
			throw new ProviderException('invalid_input', 'PDF version must be source or result.');
		}
		$record = $this->store->get($uid, 'signing', $id);
		foreach ($record['data']['documents'] as $doc) {
			if ($doc['id'] === $documentId && isset($doc[$version]) && ($version === 'source' || $doc['status'] === 'SUCCESS')) {
				$name = preg_replace('/[\x00-\x1f\x7f"\\\\\/]/', '_', $doc['documentName']);
				return ['content' => $this->artifacts->read($doc[$version]), 'name' => ($version === 'result' ? 'signed-' : '') . $name];
			}
		}
		throw new ProviderException('not_found', 'PDF is not available in this session.', 404);
	}

	public function publicCertificate(string $uid, string $id): array {
		return $this->certificates->publicCertificate($this->client($uid), $id);
	}

	private function manualIdentityEnabled(array $client): bool {
		$config = $client['config'];
		return ($config['mockRegistrationEnabled'] ?? false) === true
			&& ($config['enabled'] ?? false) === true && ($this->users->get($client['uid'])?->isEnabled() ?? false)
			&& in_array('certificates:write', $config['scopes'] ?? [], true)
			&& (empty($config['trustedSources']) || in_array($client['uid'], $config['trustedSources'], true));
	}

	/** Development-only identity/info input, explicitly distinct from chip-reader evidence. */
	private function receiveManualIdentity(array $client, string $id, array $payload): array {
		if (!$this->manualIdentityEnabled($client)) {
			throw new ProviderException('manual_identity_disabled', 'Nhập tay cần bật mockRegistrationEnabled và cho phép nguồn xác minh của bên tích hợp.', 403);
		}
		if (!ProviderContract::boolean($payload, 'testDataConsent', false)) {
			throw new ProviderException('test_data_consent_required', 'Vui lòng xác nhận đây là hồ sơ thử nghiệm.');
		}
		$fields = ['address', 'dateOfBirth', 'issueDate', 'expiredDate', 'sex', 'email', 'phone'];
		if (array_diff(array_keys($payload), array_merge($fields, ['testDataConsent']))) {
			throw new ProviderException('invalid_input', 'Không được thay định danh, nguồn xác minh hoặc bằng chứng đầu đọc trong form nhập tay.');
		}
		$record = $this->store->get($client['uid'], 'enrollment', $id);
		if ($record['status'] !== 'PENDING') {
			throw new ProviderException('invalid_state', 'Phiên đã đóng; không thể nhận thêm hồ sơ.', 409);
		}
		if ($record['expires_at'] <= time()) {
			throw new ProviderException('SYS-0027', 'Phiên đã hết hạn. Hãy tạo phiên cấp mới.', 410);
		}
		$data = $record['data'];
		if (!empty($data['input']['reusePreviousDossier']) || $data['step'] === 'FACE_MATCH_REQUIRED') {
			throw new ProviderException('invalid_state', 'Phiên tái sử dụng hồ sơ hoặc yêu cầu face-match phải tiếp tục qua adapter.', 409);
		}
		$existing = $data['dossier'] ?? null;
		if ($existing && ($existing['verification']['method'] ?? '') !== 'MANUAL_TEST') {
			throw new ProviderException('dossier_frozen', 'Hồ sơ từ adapter đã được chốt, không thể thay bằng dữ liệu nhập tay.', 409);
		}
		if (!$existing && $data['step'] !== 'AWAITING_DOSSIER') {
			throw new ProviderException('invalid_state', 'Phiên không còn ở bước chờ hồ sơ.', 409);
		}
		$person = $data['input']['person'];
		$body = array_intersect_key($payload, array_flip($fields)) + [
			'identifyNumber' => $person['personalIdNumber'], 'name' => $person['fullName'], 'isPass' => true,
			// Reuse the original receipt on retries so a timeout cannot change the frozen dossier.
			'verification' => $existing['verification'] ?? ['method' => 'MANUAL_TEST', 'source' => $client['uid'],
				'reference' => 'manual-test:' . substr(hash('sha256', $this->session->getUser()->getUID()), 0, 16) . ':' . $id,
				'verifiedAt' => gmdate('Y-m-d\TH:i:s\Z')],
		];
		// This is the generic identity/info contract, not a simulated NFC/chip read.
		// Existing validation, immutable dossier rules and OTP delivery remain authoritative.
		return $this->registrations->receive($client, $id, $body, false)['session'];
	}

	/** Use existing workflow services for all mutations, including proof and state checks. */
	public function action(string $uid, string $kind, string $id, string $action, array $payload, string $requestId): array {
		$scope = match ($kind) {
			'certificates' => 'certificates:revoke',
			'enrollment' => 'certificates:write',
			'signing' => 'signing:write',
			default => throw new ProviderException('invalid_input', 'Unknown record kind.'),
		};
		$client = $this->client($uid, $scope);
		if ($action === 'create') {
			if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $requestId)) {
				throw new ProviderException('idempotency_key_required', 'A valid requestId is required.');
			}
			$result = match ($kind) {
				'enrollment' => $this->registrations->register($client, $requestId, $payload),
				'signing' => $this->signing->start($client, $requestId, $payload),
				default => throw new ProviderException('invalid_action', 'Unsupported action.'),
			};
		} elseif ($kind === 'certificates' && $action === 'revoke') {
			$reason = $payload['reasonCode'] ?? 0;
			if (!is_int($reason)) {
				throw new ProviderException('invalid_input', 'reasonCode must be an integer.');
			}
			$result = $this->certificates->revoke($client, $id, $reason, ProviderContract::text($payload['reasonText'] ?? '', 'reasonText', 512, false));
		} elseif ($kind === 'enrollment' && $action === 'manual-identity') {
			$result = $this->receiveManualIdentity($client, $id, $payload);
		} elseif ($kind === 'enrollment' && in_array($action, ['cancel', 'resend', 'otp', 'confirm'], true)) {
			$result = $this->registrations->action($client, $id, $action, ProviderContract::text($payload['proof'] ?? '', 'proof', 16384, false));
		} elseif ($kind === 'signing') {
			$result = match ($action) {
				'cancel' => $this->signing->cancel($client, $id),
				'resend' => $this->signing->resend($client, $id),
				'confirm' => $this->signing->confirm($client, $id, $payload),
				default => throw new ProviderException('invalid_action', 'Unsupported action.'),
			};
		} else {
			throw new ProviderException('invalid_action', 'Unsupported action.');
		}
		$this->logger->info('Provider management action completed', ['app' => 'libresign', 'actor' => $this->session->getUser()->getUID(),
			'clientUid' => $uid, 'kind' => $kind, 'recordId' => $id, 'action' => $action]);
		// Do not send whole batches of Base64 PDFs back to the console.
		unset($result['signedData']);
		foreach ($result['results'] ?? [] as $index => $document) {
			unset($result['results'][$index]['signedData']);
		}
		return $result;
	}
}
