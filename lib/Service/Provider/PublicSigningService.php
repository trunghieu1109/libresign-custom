<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/** Immutable document manifests, per-batch consent, and checkpointed native PDF signing. */
class PublicSigningService {
	public function __construct(
		private ProviderStateStore $store,
		private ProviderArtifactStore $artifacts,
		private ProviderCertificateService $certificates,
		private ProviderPdfSigner $signer,
		private ProviderProofService $proofs,
		private ProviderWebhookService $webhooks,
		private ISecureRandom $random,
		private IAppConfig $config,
		private IUserManager $users,
	) {
	}

	public function profiles(): array {
		return [['id' => 'DEFAULT_PDF', 'code' => 'DEFAULT_PDF', 'name' => 'LibreSign PDF', 'type' => 'PDF', 'status' => 'ACTIVE',
			'description' => 'Native PDF signing using an existing managed OpenSSL certificate.']];
	}

	public function capabilities(array $client): array {
		return ['documentTypes' => ['PDF'], 'maxFiles' => 100, 'maxFileBytes' => 5242880, 'maxDecodedBatchBytes' => 26214400,
			'otpChannel' => $this->proofs->channels($client['config']) ? $client['config']['otpChannel'] : null,
			'supportedOtpChannels' => ProviderProofService::OTP_CHANNELS, 'partialSuccess' => true, 'workstations' => false,
			'signatureCoordinates' => 'PDF lower-left, points', 'keywordMatching' => 'unique text segment, no OCR'];
	}

	public function start(array $client, string $key, array $body): array {
		return $this->store->locked($client['uid'] . ':sign-key:' . $key, function () use ($client, $key, $body): array {
			$fingerprint = ProviderContract::fingerprint($body);
			$existing = $this->store->byKey($client['uid'], 'signing', $key);
			if ($existing) {
				if (!hash_equals($existing['data']['fingerprint'], $fingerprint)) {
					throw new ProviderException('idempotency_conflict', 'Idempotency-Key was already used with different input.', 409);
				}
				return $this->view($client, $existing);
			}
			$alias = $this->resolveAlias($client, $body);
			$credential = $this->certificates->signingCredential($client, $alias);
			$method = $body['confirmationMethod'] ?? 'service_otp';
			$input = ['confirmationMethod' => $method, 'person' => $credential['person']];
			$this->proofs->validateMethod($client['config'], $input);
			$files = $body['files'] ?? null;
			if ($files !== null && (!is_array($files) || !array_is_list($files) || !$files || count($files) > 100 || isset($body['fileBase64']) || isset($body['pdfBase64']))) {
				throw new ProviderException('SYS-0003', 'Use one file or a list of 1-100 files.');
			}
			if ($files === null) {
				$files = [$body];
			}
			$callback = $this->webhooks->validate($client, $body['callbackUrl'] ?? null);
			$record = $this->store->create($client['uid'], 'signing', $key, ['fingerprint' => $fingerprint, 'alias' => $alias,
				'certificateFingerprint' => $credential['certificate']['fingerprintSha256'], 'input' => $input, 'nonce' => $this->random->generate(32),
				'documents' => [], 'batch' => isset($body['files']), 'callbackUrl' => $callback, 'attempts' => 0, 'sendCount' => 0], 'PREPARING');
			$total = 0;
			$ids = [];
			$appearanceArtifacts = [];
			try {
				foreach ($files as $index => $item) {
					if (!is_array($item)) {
						throw new ProviderException('SYS-0003', 'Every files entry must be an object.');
					}
					$params = $this->parameters(array_replace($body, $item));
					$id = ProviderContract::text($item['id'] ?? (string)$index, 'document id', 128);
					if (in_array($id, $ids, true)) {
						throw new ProviderException('SYS-0003', 'Duplicate document id.');
					}
					$ids[] = $id;
					$document = ['id' => $id, 'index' => $index, 'documentName' => $params['documentName'], 'type' => 'PDF', 'status' => 'PENDING', 'params' => $params];
					try {
						$content = ProviderContract::bytes(ProviderContract::alias($item, 'fileBase64', 'pdfBase64'), 'fileBase64');
						$total += strlen($content);
						foreach (['signatureImageBase64', 'stampBase64', 'logoBase64'] as $image) {
							if (!empty($params[$image])) {
								$total += strlen(ProviderContract::bytes($params[$image], $image, 1048576));
							}
						}
						if ($total > 26214400) {
							throw new ProviderException('batch_too_large', 'Decoded documents and images exceed the 25 MiB batch limit.', 413);
						}
						$document['params'] = $this->signer->prepare($content, $params);
						foreach (['signatureImageBase64', 'stampBase64', 'logoBase64'] as $image) {
							if (!empty($document['params'][$image])) {
								$bytes = ProviderContract::bytes($document['params'][$image], $image, 1048576);
								$hash = hash('sha256', $bytes);
								$appearanceArtifacts[$hash] ??= ['artifact' => $this->artifacts->put($record['record_id'], 'appearance:' . $hash, $bytes), 'sha256' => $hash];
								$document['params'][$image] = $appearanceArtifacts[$hash];
							}
						}
						$document['inputHash'] = hash('sha256', $content);
						$document['source'] = $this->artifacts->put($record['record_id'], $index . ':input', $content);
					} catch (\Throwable $e) {
						if ($e instanceof ProviderException && $e->getCode() === 413) {
							throw $e;
						}
						$document['status'] = 'FAILED';
						// Failed files are never signed; avoid retaining unvalidated image bodies in state.
						$document['params'] = array_diff_key($params, array_flip(['signatureImageBase64', 'stampBase64', 'logoBase64']));
						$document['errorCode'] = $e instanceof ProviderException ? $e->error : 'PROVIDER_DOCUMENT_INVALID';
						$document['errorMessage'] = 'Document or requested appearance could not be prepared.';
					}
					$record['data']['documents'][] = $document;
				}
				$record['data']['manifestHash'] = $this->manifestHash($record);
				$record['status'] = 'PENDING';
				$this->store->save($record);
			} catch (\Throwable $e) {
				$record['status'] = 'FAILED';
				$this->store->save($record);
				throw $e;
			}
			if ($method === 'service_otp') {
				$this->send($client, $record);
			}
			return $this->view($client, $record);
		});
	}

	private function parameters(array $body): array {
		if (($body['type'] ?? 'PDF') !== 'PDF' || ($body['signingProfileCode'] ?? 'DEFAULT_PDF') !== 'DEFAULT_PDF') {
			throw new ProviderException('unsupported_signing_profile', 'Only PDF and DEFAULT_PDF are implemented.');
		}
		foreach (['signatureFontSize', 'signatureFontColor'] as $unsupported) {
			if (isset($body[$unsupported])) {
				throw new ProviderException('unsupported_appearance', $unsupported . ' is not supported by this profile.');
			}
		}
		$params = ['documentName' => ProviderContract::text($body['documentName'] ?? 'document.pdf', 'documentName', 255),
			'signerName' => ProviderContract::text($body['signerName'] ?? '', 'signerName', 128, false),
			'reason' => ProviderContract::text($body['reason'] ?? '', 'reason', 512, false),
			'signatureSearchText' => ProviderContract::text($body['signatureSearchText'] ?? '', 'signatureSearchText', 256, false)];
		$page = ProviderContract::alias($body, 'page', 'signaturePage');
		if ($page !== null) {
			if (!is_int($page) || $page < -1) {
				throw new ProviderException('SYS-0003', 'page must be an integer, or 0/-1 for the last page.');
			}
			$params['page'] = $page;
		}
		foreach (['llx', 'lly', 'urx', 'ury'] as $coordinate) {
			$value = ProviderContract::alias($body, $coordinate, 'signature' . ucfirst($coordinate));
			if ($value !== null) {
				if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
					throw new ProviderException('SYS-0003', 'Coordinates must be finite numbers.');
				}
				$params[$coordinate] = $value;
			}
		}
		$coordinates = count(array_intersect(['llx', 'lly', 'urx', 'ury'], array_keys($params)));
		if (($coordinates !== 0 && $coordinates !== 4) || ($coordinates && $params['signatureSearchText'] !== '')) {
			throw new ProviderException('SYS-0003', 'Supply either four coordinates or a keyword.');
		}
		foreach (['signatureImageBase64', 'logoBase64'] as $image) {
			$params[$image] = $body[$image] ?? '';
		}
		$params['stampBase64'] = ProviderContract::alias($body, 'stampBase64', 'sealImageBase64', '');
		return $params;
	}

	private function resolveAlias(array $client, array $body): string {
		$alias = $body['certAlias'] ?? '';
		$person = isset($body['personalIdNumber']) ? ProviderContract::personalId($body['personalIdNumber']) : null;
		if ($alias !== '') {
			$identity = $this->certificates->identity($client, ProviderContract::text($alias, 'certAlias', 36));
			if ($person !== null && $identity['person']['personalIdNumber'] !== $person) {
				throw new ProviderException('identity_conflict', 'certAlias and personalIdNumber refer to different people.', 409);
			}
			return $alias;
		}
		if ($person === null) {
			throw new ProviderException('SYS-0003', 'Provide certAlias or personalIdNumber.');
		}
		$found = $this->certificates->activeCertificates($client, $person);
		if (count($found) !== 1) {
			throw new ProviderException(count($found) ? 'ambiguous_certificate' : 'SYS-0031', 'Select one active certificate or complete certificate registration first.', 409);
		}
		return $found[0]['certAlias'];
	}

	private function challenge(array $record): string {
		return json_encode(['purpose' => 'libresign:provider:document-signing:v1', 'integration' => $record['client_uid'],
			'signSessionId' => $record['record_id'], 'certificateFingerprint' => $record['data']['certificateFingerprint'],
			'manifestHash' => $record['data']['manifestHash'],
			'nonce' => $record['data']['nonce'], 'expiresAt' => $record['expires_at']], JSON_THROW_ON_ERROR);
	}

	private function manifestHash(array $record): string {
		return ProviderContract::fingerprint(array_map(fn ($document) => array_intersect_key($document,
			array_flip(['id', 'index', 'documentName', 'type', 'params', 'inputHash', 'source'])), $record['data']['documents']));
	}

	private function send(array $client, array &$record): void {
		if ($record['data']['input']['confirmationMethod'] !== 'service_otp') {
			throw new ProviderException('wrong_confirmation_method', 'This signing session uses adapter confirmation.', 409);
		}
		$this->proofs->validateMethod($client['config'], $record['data']['input']);
		if ($record['data']['sendCount'] >= 5 || ($record['data']['lastCodeAt'] ?? 0) > time() - 60) {
			throw new ProviderException('code_rate_limited', 'Wait 60 seconds between codes; at most five sends are allowed.', 429);
		}
		$record['data']['sendCount']++;
		$record['data']['lastCodeAt'] = time();
		$record['data']['codeHash'] = '';
		$record['data']['otpChannel'] = $client['config']['otpChannel'] ?? null;
		unset($record['data']['codeExpiresAt']);
		$this->store->save($record);
		$record['data']['codeHash'] = $this->proofs->send($client['config'], $record['data']['input'], false);
		$record['data']['codeExpiresAt'] = min(time() + 300, $record['expires_at']);
		$this->store->save($record);
	}

	public function resend(array $client, string $id): array {
		return $this->store->locked('signing:' . $id, function () use ($client, $id): array {
			$record = $this->store->get($client['uid'], 'signing', $id);
			$this->requirePending($record);
			$this->send($client, $record);
			return $this->view($client, $record);
		});
	}

	public function confirm(array $client, string $id, array $body): array {
		$this->store->locked('signing:' . $id, function () use ($client, $id, $body): void {
			$record = $this->store->get($client['uid'], 'signing', $id);
			if (array_diff(array_keys($body), ['signSessionId', 'otpValue', 'assertion', 'personalIdNumber', 'certAlias'])) {
				throw new ProviderException('snapshot_frozen', 'Only confirmation proof and matching signer identifiers may be submitted.', 409);
			}
			if ((isset($body['certAlias']) && $body['certAlias'] !== $record['data']['alias'])
				|| (isset($body['personalIdNumber']) && $body['personalIdNumber'] !== $record['data']['input']['person']['personalIdNumber'])) {
				throw new ProviderException('identity_conflict', 'Signer identifiers do not match the signing session.', 409);
			}
			if ($record['status'] !== 'PENDING') {
				if (in_array($record['status'], ['PROCESSING', 'SUCCESS', 'PARTIAL_SUCCESS', 'FAILED'], true)) {
					return;
				}
				$this->requirePending($record);
			}
			$this->requirePending($record);
			$record['data']['attempts']++;
			$this->store->save($record);
			if (!$this->proofs->verify($client['config'], $record['data'], $this->challenge($record), (string)($body['otpValue'] ?? ''), (string)($body['assertion'] ?? ''))) {
				if ($record['data']['attempts'] >= 5) {
					$record['status'] = 'LOCKED';
					$this->store->save($record);
				}
				throw new ProviderException('TOTP-0001', 'Invalid or expired signing confirmation.', 403);
			}
			$record['data']['authorizedAt'] = time();
			$record['data']['authorizationHash'] = hash('sha256', $this->challenge($record));
			unset($record['data']['codeHash']);
			$record['status'] = 'PROCESSING';
			$this->store->save($record);
		});
		$this->process($client['uid'], $id, 20);
		return $this->get($client, $id);
	}

	public function process(string $uid, string $id, int $budget = 20): void {
		$this->store->locked('signing:' . $id, function () use ($uid, $id, $budget): void {
			$record = $this->store->get($uid, 'signing', $id);
			if ($record['status'] !== 'PROCESSING') {
				return;
			}
			$until = microtime(true) + $budget;
			// These fields are immutable in this locked processing pass. Check again on every resume.
			$manifestValid = hash_equals($record['data']['manifestHash'], $this->manifestHash($record));
			$authorizationValid = hash_equals($record['data']['authorizationHash'] ?? '', hash('sha256', $this->challenge($record)));
			foreach ($record['data']['documents'] as $index => $document) {
				if (!in_array($document['status'], ['PENDING', 'SIGNING'], true)) {
					continue;
				}
				if (microtime(true) >= $until) {
					return;
				}
				try {
					$client = $this->workerClient($uid);
					if (!$manifestValid) {
						throw new ProviderException('manifest_changed', 'Document manifest no longer matches the authorized snapshot.', 409);
					}
					if (!$authorizationValid) {
						throw new ProviderException('authorization_changed', 'Signing authorization no longer matches this request.', 409);
					}
					if ($record['expires_at'] <= time() || empty($record['data']['authorizedAt'])) {
						throw new ProviderException('authorization_expired', 'Signing authorization has expired.', 410);
					}
					if ($document['status'] === 'SIGNING') {
						throw new ProviderException('recovery_required', 'Previous signing outcome is uncertain; administrative reconciliation is required.', 503);
					}
					$record['data']['documents'][$index]['status'] = 'SIGNING';
					$this->store->save($record);
					$content = $this->artifacts->read($document['source']);
					if (!hash_equals($document['inputHash'], hash('sha256', $content))) {
						throw new ProviderException('input_changed', 'Stored document digest does not match the authorized manifest.', 409);
					}
					$signed = $this->store->locked('certificate:' . $record['data']['alias'],
						fn () => $this->signer->sign($client, $record['data']['alias'], $content, $this->signingParameters($document['params'])));
					$record['data']['documents'][$index]['result'] = $this->artifacts->put($id, $index . ':result', $signed);
					$record['data']['documents'][$index]['outputHash'] = hash('sha256', $signed);
					$record['data']['documents'][$index]['status'] = 'SUCCESS';
				} catch (\Throwable $e) {
					$record['data']['documents'][$index]['status'] = $e instanceof ProviderException && $e->error === 'recovery_required' ? 'RECOVERY_REQUIRED' : 'FAILED';
					$record['data']['documents'][$index]['errorCode'] = $e instanceof ProviderException ? $e->error : 'PROVIDER_SIGNING_FAILED';
					$record['data']['documents'][$index]['errorMessage'] = $e instanceof ProviderException ? $e->getMessage() : 'Native PDF signing could not complete.';
				}
				$this->store->save($record);
			}
			$success = count(array_filter($record['data']['documents'], fn ($document) => $document['status'] === 'SUCCESS'));
			$record['status'] = $success === count($record['data']['documents']) ? 'SUCCESS' : ($success ? 'PARTIAL_SUCCESS' : 'FAILED');
			$payload = null;
			$client = null;
			if ($record['data']['callbackUrl']) {
				$clients = json_decode($this->config->getValueString('libresign', 'provider_clients', '{}'), true);
				$client = ['uid' => $uid, 'config' => $clients[$uid] ?? []];
				// An administrator may disable callback delivery after accepting a job.
				// Terminal signing state must still be durable and available by polling.
				if (!empty($client['config']['enabled'])) {
					// Artifact I/O happens before acquiring the database write transaction.
					$payload = $this->view($client, $record);
					unset($payload['challengeBase64'], $payload['email'], $payload['phone']);
					$payload['requestId'] = $record['record_id'];
				}
			}
			$this->store->transaction(function () use (&$record, $client, $payload): void {
				$this->store->save($record);
				if ($client !== null && $payload !== null) {
					$this->webhooks->queue($client, $record['data']['callbackUrl'], 'signing:' . $record['record_id'], $payload);
				}
			});
		});
	}

	private function signingParameters(array $params): array {
		foreach (['signatureImageBase64', 'stampBase64', 'logoBase64'] as $image) {
			// Existing authorized snapshots keep their original inline Base64 representation.
			if (isset($params[$image]) && is_array($params[$image])) {
				$bytes = $this->artifacts->read($params[$image]['artifact']);
				if (!hash_equals($params[$image]['sha256'], hash('sha256', $bytes))) {
					throw new ProviderException('input_changed', 'Stored document digest does not match the authorized manifest.', 409);
				}
				$params[$image] = base64_encode($bytes);
			}
		}
		return $params;
	}

	private function workerClient(string $uid): array {
		$this->config->clearCache();
		$clients = json_decode($this->config->getValueString('libresign', 'provider_clients', '{}'), true);
		$config = $clients[$uid] ?? [];
		if (($config['enabled'] ?? false) !== true || !in_array('signing:write', $config['scopes'] ?? [], true)
			|| !$this->users->get($uid)?->isEnabled()) {
			throw new ProviderException('integration_disabled', 'Integration signing access has been disabled.', 403);
		}
		return ['uid' => $uid, 'config' => $config];
	}

	public function run(): void {
		foreach ($this->store->jobs('signing', 'PROCESSING', 5, false) as $record) {
			try {
				$this->process($record['client_uid'], $record['record_id']);
			} catch (\Throwable) {
				// CAS/locks and persisted checkpoints allow another worker to reconcile.
			}
		}
	}

	public function get(array $client, string $id): array {
		return $this->view($client, $this->store->get($client['uid'], 'signing', $id));
	}

	public function cancel(array $client, string $id): array {
		return $this->store->locked('signing:' . $id, function () use ($client, $id): array {
			$record = $this->store->get($client['uid'], 'signing', $id);
			$this->requirePending($record);
			$record['status'] = 'CANCELLED';
			unset($record['data']['codeHash']);
			$this->store->save($record);
			return $this->view($client, $record);
		});
	}

	public function document(array $client, string $id, string $documentId): array {
		$record = $this->store->get($client['uid'], 'signing', $id);
		foreach ($record['data']['documents'] as $document) {
			if ($document['id'] === $documentId) {
				return $this->documentView($document);
			}
		}
		throw new ProviderException('SYS-0002', 'Document not found in this signing session.', 404);
	}

	private function requirePending(array $record): void {
		if ($record['status'] !== 'PENDING' || $record['expires_at'] <= time()) {
			throw new ProviderException('invalid_state', 'Signing session is closed, locked or expired.', 409);
		}
	}

	private function view(array $client, array $record): array {
		$data = $record['data'];
		$status = $record['status'] === 'PENDING' && $record['expires_at'] <= time() ? 'EXPIRED' : $record['status'];
		$person = $data['input']['person'];
		$result = ['id' => $record['record_id'], 'transactionId' => $record['record_id'], 'signSessionId' => $record['record_id'],
			'status' => $status, 'personalIdNumber' => $person['personalIdNumber'], 'certAlias' => $data['alias'], 'signerName' => $person['fullName'],
			'documentCount' => count($data['documents']), 'otpRequired' => $data['input']['confirmationMethod'] === 'service_otp' && $status === 'PENDING',
			'otpChannel' => $data['input']['confirmationMethod'] === 'service_otp' ? ($data['otpChannel'] ?? $client['config']['otpChannel'] ?? null) : null,
			'email' => preg_replace('/(^.).*(@.*$)/', '$1***$2', $person['email']),
			'phone' => isset($person['phoneNumber']) ? preg_replace('/.(?=.{4})/', '*', $person['phoneNumber']) : '',
			'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $record['expires_at'])];
		if ($status === 'PENDING' && $data['input']['confirmationMethod'] === 'external_assertion') {
			$result['challengeBase64'] = base64_encode($this->challenge($record));
		}
		$results = [];
		foreach ($data['documents'] as $document) {
			$results[] = $this->documentView($document);
		}
		$result['results'] = $results;
		$result['successCount'] = count(array_filter($results, fn ($item) => $item['status'] === 'SUCCESS'));
		$result['failedCount'] = count(array_filter($results, fn ($item) => in_array($item['status'], ['FAILED', 'RECOVERY_REQUIRED'], true)));
		if (!$data['batch'] && $results) {
			$result['documentName'] = $results[0]['documentName'];
			$result['type'] = 'PDF';
			$result['signedData'] = $results[0]['signedData'];
		}
		return $result;
	}

	private function documentView(array $document): array {
		$item = array_intersect_key($document, array_flip(['id', 'index', 'documentName', 'type', 'status', 'errorCode', 'errorMessage', 'outputHash']));
		$item['signedData'] = isset($document['result']) ? base64_encode($this->artifacts->read($document['result'])) : null;
		return $item;
	}
}
