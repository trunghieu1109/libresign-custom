<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Vendor\Endroid\QrCode\QrCode;
use OCA\Libresign\Vendor\Endroid\QrCode\Writer\PngWriter;

/** HubRs-shaped enrollment orchestration, delegating actual issuance to the existing provider. */
class PublicRegistrationService {
	public function __construct(
		private ProviderStateStore $store,
		private ProviderArtifactStore $artifacts,
		private ProviderCertificateService $certificates,
		private ProviderInput $input,
		private ProviderProofService $proofs,
		private ProviderWebhookService $webhooks,
		private ProviderRegistrationLinkService $links,
	) {
	}

	public function packages(array $client): array {
		$packages = $this->certificates->packages($client)['items'];
		return array_map(fn ($item) => ['caVendor' => 'LibreSign OpenSSL', 'code' => $item['code'], 'name' => $item['name'],
			'description' => 'Certificate issued by the configured internal OpenSSL CA.', 'validityDays' => $item['validityDays'],
			'confirmationMethods' => $item['confirmationMethods']], $packages);
	}

	public function register(array $client, string $key, array $body): array {
		$person = ['personalIdNumber' => ProviderContract::personalId($body['personalIdNumber'] ?? null),
			'fullName' => ProviderContract::text($body['fullName'] ?? null, 'fullName', 128),
			'email' => ProviderContract::text($body['email'] ?? '', 'email', 254, false),
			'phoneNumber' => ProviderContract::phone($body['phone'] ?? '')];
		$method = $body['registrationMethod'] ?? '';
		if (!in_array($method, ['CARD_READER', 'QR_SELF_UPLOAD'], true)) {
			throw new ProviderException('SYS-0003', 'registrationMethod must be CARD_READER or QR_SELF_UPLOAD.');
		}
		$confirmation = $body['confirmationMethod'] ?? 'service_otp';
		if ($method === 'QR_SELF_UPLOAD') {
			$this->links->validate($client);
			if (($client['config']['registrationBaseUrl'] ?? '') === '' && $confirmation !== 'service_otp') {
				throw new ProviderException('unsupported_mock_session', 'The development QR mock supports OTP only.', 409);
			}
		}
		$this->input->normalize($person, ['method' => $method, 'source' => $client['uid'], 'reference' => $key,
			'verifiedAt' => gmdate('Y-m-d\TH:i:s\Z')], $confirmation, $body['packageCode'] ?? 'internal-default');
		$this->proofs->validateMethod($client['config'], ['person' => $person, 'confirmationMethod' => $confirmation]);
		$normalized = ['person' => $person, 'registrationMethod' => $method, 'confirmationMethod' => $confirmation,
			'packageCode' => $body['packageCode'] ?? 'internal-default', 'callbackUrl' => $this->webhooks->validate($client, $body['callbackUrl'] ?? null),
			'reusePreviousDossier' => ProviderContract::boolean($body, 'reusePreviousDossier', false),
			'requireReuseFaceMatch' => ProviderContract::boolean($body, 'requireReuseFaceMatch', false) || !empty($client['config']['requireReuseFaceMatch']),
			'reuseActiveCertificate' => ProviderContract::boolean($body, 'reuseActiveCertificate', true)];
		$fingerprint = ProviderContract::fingerprint($normalized);
		return $this->store->locked($client['uid'] . ':person:' . $person['personalIdNumber'], function () use ($client, $key, $normalized, $fingerprint): array {
			$replayed = $this->store->byKey($client['uid'], 'enrollment', $key);
			if ($replayed) {
				if (!hash_equals($replayed['data']['fingerprint'], $fingerprint)) {
					throw new ProviderException('idempotency_conflict', 'Idempotency-Key was already used with different input.', 409);
				}
				return $this->view($client, $replayed);
			}
			$subjectKey = $normalized['person']['personalIdNumber'];
			$subject = $this->store->byKey($client['uid'], 'subject', $subjectKey)
				?? $this->store->create($client['uid'], 'subject', $subjectKey, ['pending' => null, 'dossier' => null], 'READY', 0);
			$previousDossier = null;
			$native = null;
			$existing = $this->active($client, $subjectKey);
			if (!$existing && $normalized['reusePreviousDossier']) {
				$previousDossier = empty($subject['data']['dossier']) ? null : $this->store->get($client['uid'], 'enrollment', $subject['data']['dossier']);
				$eligibility = $this->reuseData($client, $previousDossier);
				if (!$eligibility['reusable']) {
					throw new ProviderException($eligibility['cccdExpired'] ? 'SYS-0058' : 'dossier_unavailable', 'Collect a new verified identity dossier.', 409);
				}
			}
			if ($existing && !$normalized['reuseActiveCertificate']) {
				throw new ProviderException('SYS-0030', 'An active certificate already exists.', 409);
			}
			if ($existing) {
				if (!hash_equals(ProviderContract::fingerprint($existing['person']), ProviderContract::fingerprint($normalized['person']))) {
					throw new ProviderException('identity_conflict', 'Existing certificate identity/contact differs; supply a separately verified update.', 409);
				}
			} elseif (!empty($subject['data']['pending'])) {
				$pending = $this->store->get($client['uid'], 'enrollment', $subject['data']['pending']);
				if ($pending['status'] === 'PENDING' && $pending['expires_at'] > time()) {
					if (!hash_equals($pending['data']['fingerprint'], $fingerprint)) {
						throw new ProviderException('identity_conflict', 'A pending enrollment exists with different input.', 409);
					}
					return $this->view($client, $pending) + ['reusedSession' => true];
				}
			}
			$record = $this->store->create($client['uid'], 'enrollment', $key, ['input' => $normalized, 'fingerprint' => $fingerprint,
				'legacySession' => $existing['certAlias'] ?? null, 'reusedCertificate' => $existing !== null, 'dossier' => null,
				'step' => $existing ? 'COMPLETE' : 'AWAITING_DOSSIER'], $existing ? 'ACTIVE' : 'PENDING');
			if (!$existing) {
				$subject['data']['pending'] = $record['record_id'];
				$this->store->save($subject);
				if ($normalized['reusePreviousDossier']) {
					$eligibility = $this->reuseData($client, $previousDossier);
					if (!$eligibility['reusable']) {
						throw new ProviderException($eligibility['cccdExpired'] ? 'SYS-0058' : 'dossier_unavailable', 'Collect a new verified identity dossier.', 409);
					}
					$record['data']['dossier'] = $previousDossier['data']['dossier'];
					$record['data']['step'] = $normalized['requireReuseFaceMatch'] ? 'FACE_MATCH_REQUIRED' : 'DOSSIER_ACCEPTED';
					$this->store->save($record);
					if (!$normalized['requireReuseFaceMatch']) {
						$native = $this->prepare($client, $record);
					}
				}
			}
			return $this->view($client, $record, $native);
		});
	}

	private function active(array $client, string $personalId): ?array {
		$found = $this->certificates->activeCertificates($client, $personalId);
		if (count($found) > 1) {
			throw new ProviderException('ambiguous_certificate', 'More than one active certificate exists; select a certAlias explicitly.', 409);
		}
		if ($found) {
			$credential = $this->certificates->signingCredential($client, $found[0]['certAlias']);
			return $found[0] + ['person' => $credential['person']];
		}
		return null;
	}

	public function receive(array $client, string $id, array $body, bool $cardReader): array {
		return $this->store->locked('enrollment:' . $id, function () use ($client, $id, $body, $cardReader): array {
			$record = $this->pending($client, $id);
			$native = null;
			if ($record['data']['input']['reusePreviousDossier']) {
				throw new ProviderException('invalid_state', 'This session uses a stored dossier.', 409);
			}
			$fingerprint = ProviderContract::fingerprint($body);
			if (!empty($record['data']['dossier'])) {
				if (($record['data']['dossier']['fingerprint'] ?? '') !== $fingerprint) {
					throw new ProviderException('dossier_frozen', 'The accepted dossier cannot be replaced in this session.', 409);
				}
				if (!$record['data']['legacySession']) {
					$native = $this->prepare($client, $record);
				}
				return ['session' => $this->view($client, $record, $native), 'faceMatch' => $record['data']['dossier']['faceMatch'], 'certificate' => null];
			}
			$person = $record['data']['input']['person'];
			if (ProviderContract::personalId($body['identifyNumber'] ?? $body['personalIdNumber'] ?? null) !== $person['personalIdNumber']
				|| ProviderContract::text($body['name'] ?? $body['fullName'] ?? null, 'name', 128) !== $person['fullName']) {
				throw new ProviderException('identity_conflict', 'Dossier does not match the person who opened the session.', 409);
			}
			$fields = ['address', 'dateOfBirth', 'issueDate', 'expiredDate', 'sex'];
			$dossier = ['fingerprint' => $fingerprint, 'fields' => [], 'evidence' => []];
			foreach (array_merge($fields, ['nationality', 'nation', 'religion', 'hometown', 'character', 'fatherName', 'motherName', 'partnerName', 'mrz']) as $field) {
				if (in_array($field, $fields, true) || isset($body[$field])) {
					$dossier['fields'][$field] = in_array($field, ['dateOfBirth', 'issueDate', 'expiredDate'], true)
						? ProviderContract::date($body[$field] ?? null, $field)
						: ($field === 'mrz' ? $this->mrz($body[$field]) : ProviderContract::text($body[$field] ?? null, $field, 1024));
				}
			}
			if ($dossier['fields']['expiredDate'] < gmdate('Y-m-d')) {
				throw new ProviderException('SYS-0058', 'Identity document has expired.');
			}
			if ($dossier['fields']['dateOfBirth'] > $dossier['fields']['issueDate']
				|| $dossier['fields']['issueDate'] > gmdate('Y-m-d')
				|| $dossier['fields']['issueDate'] >= $dossier['fields']['expiredDate']) {
				throw new ProviderException('SYS-0003', 'Identity document dates are inconsistent.');
			}
			if ($cardReader && (!isset($body['score']) || !is_int($body['score']) || $body['score'] < 0 || $body['score'] > 100)) {
				throw new ProviderException('SYS-0003', 'Card-reader score must be an integer from 0 to 100.');
			}
			if (!ProviderContract::boolean($body, 'isPass', false)) {
				throw new ProviderException('identity_not_verified', 'The trusted adapter must report a successful identity verification.', 403);
			}
			$input = $record['data']['input'];
			$receipt = $this->receipt($client, $body['verification'] ?? [], $id, $input['registrationMethod']);
			// A trusted dossier may update contact before it is frozen and any OTP is sent.
			if (array_key_exists('email', $body)) {
				$person['email'] = ProviderContract::text($body['email'], 'email', 254, false);
			}
			if (array_key_exists('phone', $body)) {
				$person['phoneNumber'] = ProviderContract::phone($body['phone']);
			}
			$normalized = $this->input->normalize($person, $receipt, $input['confirmationMethod'], $input['packageCode']);
			$this->proofs->validateMethod($client['config'], $normalized);
			$dossier['verification'] = $normalized['verification'];
			$totalEvidence = 0;
			foreach (['imageCap', 'imageChip', 'sodData', 'dg1DataBase64', 'dg2DataBase64', 'dg13DataBase64', 'dg14DataBase64', 'documentFrontBase64', 'documentBackBase64'] as $field) {
				if (($cardReader && in_array($field, ['imageCap', 'imageChip', 'sodData'], true)) || isset($body[$field])) {
					$content = ProviderContract::bytes($body[$field] ?? null, $field);
					$totalEvidence += strlen($content);
					if ($totalEvidence > 20971520) {
						throw new ProviderException('evidence_too_large', 'Decoded identity evidence exceeds 20 MiB.', 413);
					}
					if (str_contains(strtolower($field), 'image') || str_contains($field, 'document')) {
						$size = @getimagesizefromstring($content);
						if (!$size || !in_array($size['mime'], ['image/png', 'image/jpeg'], true) || $size[0] * $size[1] > 16000000) {
							throw new ProviderException('SYS-0003', 'Evidence image must be a bounded PNG or JPEG.');
						}
					}
					$dossier['evidence'][$field] = ['artifact' => $this->artifacts->put($id, 'identity:' . $field, $content), 'sha256' => hash('sha256', $content)];
				}
			}
			$dossier['faceMatch'] = ['isPass' => true, 'score' => $body['score'] ?? null];
			$record['data']['input']['person'] = $normalized['person'];
			$record['data']['dossier'] = $dossier;
			$record['data']['step'] = 'DOSSIER_ACCEPTED';
			$this->store->save($record);
			$native = $this->prepare($client, $record);
			return ['session' => $this->view($client, $record, $native), 'faceMatch' => $dossier['faceMatch'], 'certificate' => null];
		});
	}

	private function receipt(array $client, mixed $receipt, string $id, string $method): array {
		if (!is_array($receipt) || ($receipt !== [] && array_is_list($receipt))) {
			throw new ProviderException('SYS-0003', 'verification must be an object.');
		}
		$receipt += ['source' => $client['uid'], 'reference' => $id, 'verifiedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'method' => $method];
		if (!empty($client['config']['trustedSources']) && !in_array($receipt['source'], $client['config']['trustedSources'], true)) {
			throw new ProviderException('untrusted_adapter', 'Verification source is not configured for this integration.', 403);
		}
		return array_intersect_key($receipt, array_flip(['method', 'source', 'reference', 'verifiedAt']));
	}

	private function mrz(mixed $value): string {
		if (!is_string($value) || strlen($value) > 256 || preg_match('/[^A-Z0-9<\r\n ]/', $value)) {
			throw new ProviderException('SYS-0003', 'MRZ must contain bounded ICAO text lines.');
		}
		return $value;
	}

	private function prepare(array $client, array &$record): array {
		if ($record['data']['step'] === 'FACE_MATCH_REQUIRED') {
			throw new ProviderException('SYS-0059', 'Face-match is required before issuance.', 409);
		}
		$input = $record['data']['input'];
		$legacy = $this->certificates->register($client, 'public:' . $record['record_id'], $input['person'], $record['data']['dossier']['verification'],
			$input['confirmationMethod'], $input['packageCode']);
		$record['data']['legacySession'] = $legacy['sessionId'];
		$record['data']['step'] = 'AWAITING_CONFIRMATION';
		$this->store->save($record);
		if ($input['confirmationMethod'] === 'service_otp') {
			$sent = $this->certificates->sendCode($client, $legacy['sessionId']);
			$legacy['otpChannel'] = $client['config']['otpChannel'] ?? null;
			$legacy['otpExpiresAt'] = $sent['expiresAt'];
		}
		return $legacy;
	}

	public function reuse(array $client, string $personalId): array {
		$personalId = ProviderContract::personalId($personalId);
		$subject = $this->store->byKey($client['uid'], 'subject', $personalId);
		$dossier = empty($subject['data']['dossier']) ? null : $this->store->get($client['uid'], 'enrollment', $subject['data']['dossier']);
		return $this->reuseData($client, $dossier);
	}

	private function reuseData(array $client, ?array $dossier): array {
		$expiry = $dossier['data']['dossier']['fields']['expiredDate'] ?? null;
		$edge = ($client['config']['edgeDeployment'] ?? false) === true;
		$expired = $expiry !== null && $expiry < gmdate('Y-m-d');
		$reason = !$edge ? 'REUSE_NOT_ENABLED' : ($dossier === null ? 'NO_INITIAL_DOSSIER' : ($expired ? 'IDENTITY_DOCUMENT_EXPIRED' : 'ELIGIBLE'));
		return ['edgeDeployment' => $edge, 'reusable' => $reason === 'ELIGIBLE', 'reasonCode' => $reason,
			'hasPreviousDossier' => $dossier !== null, 'cccdExpired' => $expired,
			'defaultReusePreviousDossier' => false, 'defaultRequireReuseFaceMatch' => !empty($client['config']['requireReuseFaceMatch']),
			'reuseFaceThreshold' => $client['config']['reuseFaceThreshold'] ?? null, 'reuseFaceScale' => $client['config']['reuseFaceScale'] ?? null,
			'fullName' => $dossier['data']['input']['person']['fullName'] ?? null, 'phone' => $dossier['data']['input']['person']['phoneNumber'] ?? null,
			'idExpireDate' => $expiry];
	}

	public function faceMatch(array $client, string $id, array $body): array {
		return $this->store->locked('enrollment:' . $id, function () use ($client, $id, $body): array {
			$record = $this->pending($client, $id);
			if ($record['data']['step'] !== 'FACE_MATCH_REQUIRED') {
				throw new ProviderException('invalid_state', 'This session is not awaiting a reuse face-match.', 409);
			}
			$minimum = $client['config']['reuseFaceThreshold'] ?? null;
			$scale = $client['config']['reuseFaceScale'] ?? 1;
			if (!is_numeric($minimum) || !is_numeric($scale) || $minimum <= 0 || $minimum > $scale) {
				throw new ProviderException('face_policy_missing', 'Configure the adapter score scale and minimum face-match threshold.', 503);
			}
			$verification = $body['verification'] ?? [];
			$receipt = $this->receipt($client, $verification, $id, 'FACE_MATCH');
			$input = $record['data']['input'];
			$receipt = $this->input->normalize($input['person'], $receipt, $input['confirmationMethod'], $input['packageCode'])['verification'];
			$score = $verification['score'] ?? null;
			$threshold = max((float)$minimum, (float)($body['threshold'] ?? $minimum));
			if (!is_numeric($score) || $score < $threshold || $score > $scale || ($verification['isPass'] ?? false) !== true
				|| ($verification['sessionId'] ?? '') !== $id || strtotime($verification['verifiedAt'] ?? '') < $record['created_at'] - 60) {
				throw new ProviderException('SYS-0025', 'A fresh, passing adapter face-match bound to this session is required.', 403);
			}
			$portrait = ProviderContract::bytes($body['portraitBase64'] ?? null, 'portraitBase64');
			$size = @getimagesizefromstring($portrait);
			if (!$size || !in_array($size['mime'], ['image/png', 'image/jpeg'], true) || $size[0] * $size[1] > 16000000) {
				throw new ProviderException('SYS-0003', 'portraitBase64 must be a bounded PNG or JPEG.');
			}
			$record['data']['reuseFaceMatch'] = ['verification' => $receipt, 'score' => $score,
				'threshold' => $threshold, 'portrait' => $this->artifacts->put($id, 'reuse-portrait', $portrait)];
			$record['data']['step'] = 'DOSSIER_ACCEPTED';
			$this->store->save($record);
			$native = $this->prepare($client, $record);
			return $this->view($client, $record, $native);
		});
	}

	public function action(array $client, string $id, string $action, string $proof = ''): array {
		return $this->store->locked('enrollment:' . $id, function () use ($client, $id, $action, $proof): array {
			$record = $this->store->get($client['uid'], 'enrollment', $id);
			if ($record['status'] === 'ACTIVE' && $action === 'confirm') {
				$result = $this->view($client, $record);
				$this->issuedCallback($client, $record, $result['certificate'] ?? null);
				return $result;
			}
			$this->requirePending($record);
			$legacy = $record['data']['legacySession'];
			$native = null;
			if ($action === 'cancel') {
				if ($legacy) {
					$native = $this->certificates->cancel($client, $legacy);
				}
				$record['status'] = 'CANCELLED';
			} else {
				if (!$legacy) {
					throw new ProviderException('dossier_required', 'Submit the identity dossier and required face-match first.', 409);
				}
				if ($action === 'resend') {
					try {
						$this->certificates->sendCode($client, $legacy);
					} catch (ProviderException $e) {
						if ($e->error === 'delivery_failed') {
							$record['data']['step'] = 'AWAITING_CONFIRMATION';
							$this->store->save($record);
						}
						throw $e;
					}
					$record['data']['step'] = 'AWAITING_CONFIRMATION';
				} elseif ($action === 'otp') {
					$this->certificates->verifyConfirmation($client, $legacy, $proof);
					$record['data']['step'] = 'VERIFIED';
				} elseif ($action === 'confirm') {
					if ($record['data']['input']['confirmationMethod'] === 'external_assertion') {
						$native = $this->certificates->confirm($client, $legacy, '', $proof);
					} else {
						$native = $this->certificates->issueVerified($client, $legacy);
					}
					$record['status'] = 'ACTIVE';
					$record['data']['step'] = 'COMPLETE';
				} else {
					throw new \InvalidArgumentException('Unknown enrollment action');
				}
			}
			$this->store->transaction(function () use (&$record, $client, $id, $native): void {
				$this->store->save($record);
				if ($record['status'] === 'ACTIVE') {
					$subject = $this->store->byKey($client['uid'], 'subject', $record['data']['input']['person']['personalIdNumber']);
					if (empty($subject['data']['dossier']) && !$record['data']['input']['reusePreviousDossier']) {
						$subject['data']['dossier'] = $id;
						$this->store->save($subject);
					}
					$this->issuedCallback($client, $record, isset($native['certificate'])
						? ProviderContract::certificate($native['certificate'], ['person' => $record['data']['input']['person']]) : null);
				}
			});
			return $this->view($client, $record, $native);
		});
	}

	private function issuedCallback(array $client, array $record, ?array $certificate = null): void {
		if ($record['data']['reusedCertificate'] || !$record['data']['input']['callbackUrl']) {
			return;
		}
		$certificate ??= $this->certificate($client, $record['data']['legacySession']);
		$this->webhooks->queue($client, $record['data']['input']['callbackUrl'], 'certificate:' . $record['record_id'],
			array_intersect_key($certificate, array_flip(['subjectName', 'validFrom', 'validTo', 'status', 'certAlias', 'personalIdNumber'])) + ['sessionId' => $record['record_id']]);
	}

	public function registration(array $client, string $id): array {
		return $this->view($client, $this->store->get($client['uid'], 'enrollment', $id));
	}

	private function pending(array $client, string $id): array {
		$record = $this->store->get($client['uid'], 'enrollment', $id);
		$this->requirePending($record);
		return $record;
	}

	private function requirePending(array $record): void {
		if ($record['status'] !== 'PENDING') {
			throw new ProviderException('invalid_state', 'Enrollment is ' . $record['status'] . '.', 409);
		}
		if ($record['expires_at'] <= time()) {
			throw new ProviderException('SYS-0027', 'Enrollment session has expired.', 410);
		}
	}

	private function view(array $client, array $record, ?array $native = null): array {
		$data = $record['data'];
		$status = $record['status'] === 'PENDING' && $record['expires_at'] <= time() ? 'EXPIRED' : $record['status'];
		$native ??= $data['legacySession'] ? $this->certificates->registration($client, $data['legacySession']) : null;
		if (in_array($native['status'] ?? '', ['ISSUING', 'RECOVERY_REQUIRED', 'LOCKED'], true)) {
			$status = $native['status'];
		}
		$person = $data['input']['person'];
		$next = match (true) {
			$status === 'ACTIVE' => 'USE_CERTIFICATE',
			in_array($status, ['EXPIRED', 'CANCELLED', 'LOCKED'], true) => 'CREATE_NEW_SESSION',
			in_array($status, ['ISSUING', 'RECOVERY_REQUIRED'], true) => 'CONTACT_ADMIN',
			$data['step'] === 'FACE_MATCH_REQUIRED' => 'SUBMIT_FACE_MATCH',
			$data['step'] === 'AWAITING_DOSSIER' => 'SUBMIT_IDENTITY',
			$data['step'] === 'VERIFIED' => 'CONFIRM',
			$data['input']['confirmationMethod'] === 'external_assertion' => 'CONFIRM_ASSERTION',
			default => 'VERIFY_OTP',
		};
		$result = ['sessionId' => $record['record_id'], 'status' => $status, 'step' => $data['step'],
			'registrationMethod' => $data['input']['registrationMethod'], 'reusedCertificate' => $data['reusedCertificate'],
			'personalIdNumber' => $person['personalIdNumber'], 'fullName' => $person['fullName'], 'phone' => $person['phoneNumber'], 'email' => $person['email'],
			'birthDate' => $data['dossier']['fields']['dateOfBirth'] ?? null, 'address' => $data['dossier']['fields']['address'] ?? null,
			'reusePreviousDossier' => $data['input']['reusePreviousDossier'], 'requireReuseFaceMatch' => $data['input']['requireReuseFaceMatch'],
			'reusedDossier' => !$data['reusedCertificate'] && $data['input']['reusePreviousDossier'] && !empty($data['dossier']),
			'confirmationMethod' => $data['input']['confirmationMethod'], 'nextAction' => $next,
			'otpRequired' => $status === 'PENDING' && $next === 'VERIFY_OTP',
			'otpChannel' => $data['input']['confirmationMethod'] === 'service_otp' ? ($native['otpChannel'] ?? $client['config']['otpChannel'] ?? null) : null,
			'otpExpiresAt' => !empty($native['otpExpiresAt']) ? gmdate('Y-m-d\TH:i:s\Z', min($native['otpExpiresAt'], $record['expires_at'])) : null,
			'registrationUrl' => null, 'qrCode' => null, 'registrationMode' => null,
			'message' => match ($next) {
				'USE_CERTIFICATE' => 'Enrollment is complete. Use certificate.certAlias for signing.',
				'SUBMIT_IDENTITY' => 'Submit the verified identity dossier.',
				'SUBMIT_FACE_MATCH' => 'Submit a fresh passing adapter face-match.',
				'VERIFY_OTP' => 'Verify the OTP, then confirm issuance.',
				'CONFIRM' => 'OTP verified. Confirm certificate issuance.',
				'CONFIRM_ASSERTION' => 'Confirm issuance with the adapter assertion.',
				'CREATE_NEW_SESSION' => 'Start a new enrollment using a new Idempotency-Key.',
				default => 'An administrator must inspect this enrollment.',
			}, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $record['expires_at'])];
		if ($data['input']['registrationMethod'] === 'QR_SELF_UPLOAD') {
			$result = array_replace($result, $this->links->link($client, $record));
			$result['qrCode'] = base64_encode((new PngWriter())->write(new QrCode(data: $result['registrationUrl']))->getString());
		}
		if ($status === 'ACTIVE') {
			$result['certificate'] = ProviderContract::certificate($native['certificate'], ['person' => $person]);
		} elseif ($data['legacySession'] && $data['input']['confirmationMethod'] === 'external_assertion') {
			$result['challengeBase64'] = $native['challengeBase64'] ?? null;
		}
		return $result;
	}

	public function certificate(array $client, string $alias): array {
		$details = $this->certificates->certificateDetails($client, $alias);
		return ProviderContract::certificate($details['certificate'], $details['identity']);
	}

	public function listing(array $client, string $personalId, int $page, int $size): array {
		if ($page < 1) {
			throw new ProviderException('SYS-0003', 'pageIndex starts at one.');
		}
		if ($personalId !== '') {
			ProviderContract::personalId($personalId);
		}
		$list = $this->certificates->certificateDetailsPage($client, $personalId, $page - 1, $size);
		return ['items' => array_map(fn ($item) => ProviderContract::certificate($item['certificate'], $item['identity']), $list['items']),
			'totalItem' => $this->certificates->countCertificates($client, $personalId)];
	}
}
