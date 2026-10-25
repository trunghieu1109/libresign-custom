<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Db\CrlMapper;
use OCA\Libresign\Db\ProviderRegistration;
use OCA\Libresign\Db\ProviderRegistrationMapper;
use OCA\Libresign\Enum\CRLReason;
use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Handler\SignEngine\Pkcs12Handler;
use OCA\Libresign\Service\Crl\CrlService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DatabaseException;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class ProviderCertificateService {
	public function __construct(
		private ProviderRegistrationMapper $registrations,
		private ProviderInput $input,
		private ProviderProofService $proofs,
		private ProviderCredentialStore $credentials,
		private Pkcs12Handler $pkcs12,
		private CertificateEngineFactory $engines,
		private CrlMapper $crlMapper,
		private CrlService $crl,
		private ICrypto $crypto,
		private ISecureRandom $random,
		private LoggerInterface $logger,
	) {
	}

	public function packages(array $client): array {
		$this->requireSetup();
		return ['items' => [[
			'code' => 'internal-default',
			'name' => 'LibreSign internal CA',
			'certificateEngine' => 'openssl',
			'validityDays' => $this->engines->getEngine()->getLeafExpiryInDays(),
			'confirmationMethods' => $this->proofs->methods($client['config']),
		]]];
	}

	public function register(array $client, string $requestId, array $person, array $verification, string $confirmationMethod, string $packageCode): array {
		if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $requestId)) {
			throw new ProviderException('invalid_request_id', 'requestId must contain 8-128 letters, digits, dots, colons, underscores or hyphens.');
		}
		$input = $this->input->normalize($person, $verification, $confirmationMethod, $packageCode);
		$this->proofs->validateMethod($client['config'], $input);
		$key = hash('sha256', $this->crypto->calculateHMAC('provider-request:' . $requestId));
		$fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
		try {
			return $this->replayed($this->registrations->findForClient($client['uid'], $key, true), $fingerprint);
		} catch (DoesNotExistException) {
			// New idempotent request.
		}
		$this->requireSetup();
		$registration = new ProviderRegistration();
		$registration->setRegistrationId($this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC));
		$registration->setClientUid($client['uid']);
		$registration->setRequestKey($key);
		$registration->setSubjectHash($this->subjectHash($client['uid'], $input['person']['personalIdNumber']));
		$registration->setStatus('PENDING');
		$registration->setVersion(0);
		$registration->setCreatedAt(time());
		$registration->setExpiresAt(time() + 900);
		$state = ['input' => $input, 'fingerprint' => $fingerprint, 'nonce' => $this->random->generate(32), 'attempts' => 0, 'sendCount' => 0];
		$this->setState($registration, $state);
		try {
			$this->registrations->insert($registration);
		} catch (DatabaseException $e) {
			if ($e->getReason() !== DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return $this->replayed($this->registrations->findForClient($client['uid'], $key, true), $fingerprint);
		}
		return $this->registrationData($registration, $state);
	}

	public function registration(array $client, string $sessionId): array {
		return $this->registrationData($this->find($client, $sessionId));
	}

	public function sendCode(array $client, string $sessionId): array {
		$registration = $this->find($client, $sessionId);
		$state = $this->requirePending($registration);
		if ($state['input']['confirmationMethod'] !== 'service_otp') {
			throw new ProviderException('wrong_confirmation_method', 'This registration uses an external assertion.', 409);
		}
		$this->proofs->validateMethod($client['config'], $state['input']);
		if (($state['lastCodeAt'] ?? 0) > time() - 60 || $state['sendCount'] >= 5) {
			throw new ProviderException('code_rate_limited', 'Wait at least 60 seconds between codes; at most five sends are allowed per session.', 429);
		}
		// Reserve delivery before calling a gateway; a failed send still consumes its slot.
		$state['lastCodeAt'] = time();
		$state['sendCount']++;
		$state['codeHash'] = '';
		$state['otpChannel'] = $client['config']['otpChannel'] ?? null;
		unset($state['proofVerifiedAt'], $state['proofHash'], $state['codeExpiresAt']);
		$this->persist($registration, $state);
		$state['codeHash'] = $this->proofs->send($client['config'], $state['input']);
		$state['codeExpiresAt'] = min(time() + 300, $registration->getExpiresAt());
		$this->persist($registration, $state);
		return ['sessionId' => $sessionId, 'status' => 'CODE_SENT', 'expiresAt' => $state['codeExpiresAt']];
	}

	public function confirm(array $client, string $sessionId, string $otpValue, string $assertion): array {
		$this->verifyConfirmation($client, $sessionId, $otpValue, $assertion);
		return $this->issueVerified($client, $sessionId);
	}

	/** Verify consent separately for the HubRs-compatible OTP/confirm contract. */
	public function verifyConfirmation(array $client, string $sessionId, string $otpValue, string $assertion = ''): array {
		$registration = $this->find($client, $sessionId);
		if ($registration->getStatus() === 'ACTIVE') {
			return $this->registrationData($registration);
		}
		$state = $this->requirePending($registration);
		$this->requireSetup();
		$state['attempts']++;
		$this->persist($registration, $state);
		if (!$this->proofs->verify($client['config'], $state, $this->challenge($registration, $state), $otpValue, $assertion)) {
			if ($state['attempts'] >= 5) {
				$registration->setStatus('LOCKED');
				$this->persist($registration, $state);
			}
			throw new ProviderException('invalid_proof', 'Confirmation failed; at most five attempts are allowed per session.', 403);
		}
		$state['confirmedAt'] = time();
		$state['proofHash'] = hash('sha256', $state['input']['confirmationMethod'] === 'service_otp' ? $state['codeHash'] : $assertion);
		$state['proofVerifiedAt'] = time();
		$this->persist($registration, $state);
		return ['sessionId' => $sessionId, 'status' => 'VERIFIED'];
	}

	public function issueVerified(array $client, string $sessionId): array {
		$registration = $this->find($client, $sessionId);
		if ($registration->getStatus() === 'ACTIVE') {
			return $this->registrationData($registration);
		}
		$state = $this->requirePending($registration);
		$this->requireSetup();
		if (empty($state['proofVerifiedAt'])) {
			throw new ProviderException('confirmation_required', 'Verify consent before issuing this certificate.', 403);
		}
		$state['password'] = $this->random->generate(64);
		unset($state['codeHash']);
		$registration->setStatus('ISSUING');
		$this->persist($registration, $state);
		try {
			$pfx = $this->pkcs12->generateCertificate([
				'host' => $state['input']['person']['email'] ?: 'provider-' . substr($registration->getSubjectHash(), 0, 32) . '.invalid',
				'uid' => 'provider:' . $registration->getSubjectHash(),
				'name' => $state['input']['person']['fullName'],
			], $state['password'], $state['input']['person']['fullName']);
			$public = $this->publicData($pfx, $state['password']);
			// Native issuance creates the CRL record. Check it before activating the alias.
			$native = $this->crlMapper->findBySerialNumber($public['serialNumber']);
			// The OpenSSL engine initially uses CN as owner. A provider subject
			// must not collide with a Nextcloud UID used by native account revocation.
			$native->setOwner('provider:' . $registration->getSubjectHash());
			$this->crlMapper->update($native);
			$registration->setSerialNumber($public['serialNumber']);
			$state['certificate'] = $public;
			$this->persist($registration, $state);
			$this->credentials->save($sessionId, $pfx);
			if (!hash_equals(hash('sha256', $pfx), hash('sha256', $this->credentials->read($sessionId)))) {
				throw new \RuntimeException('Managed credential read-back failed');
			}
			$registration->setStatus('ACTIVE');
			$this->persist($registration, $state);
		} catch (\Throwable) {
			// Never issue again after an uncertain CA/storage failure.
			$registration->setStatus('RECOVERY_REQUIRED');
			try {
				$this->persist($registration, $state);
			} catch (\Throwable) {
				// The durable ISSUING state also prevents an automatic reissue.
			}
			$this->logger->error('Provider certificate issuance needs recovery', ['sessionId' => $sessionId]);
			throw new ProviderException('issuance_requires_recovery', 'Issuance could not be completed; an administrator must inspect this session before retrying.', 503);
		}
		return $this->registrationData($registration, $state);
	}

	public function cancel(array $client, string $sessionId): array {
		$registration = $this->find($client, $sessionId);
		$state = null;
		if ($registration->getStatus() !== 'CANCELLED') {
			$state = $this->requirePending($registration);
			$registration->setStatus('CANCELLED');
			unset($state['codeHash']);
			$this->persist($registration, $state);
		}
		return $this->registrationData($registration, $state);
	}

	public function certificates(array $client, string $personalIdNumber, int $pageIndex, int $pageSize): array {
		$page = $this->certificateDetailsPage($client, $personalIdNumber, $pageIndex, $pageSize);
		$page['items'] = array_column($page['items'], 'certificate');
		return $page;
	}

	/** Internal projection; the OCS and public adapters keep their existing response models. */
	public function certificateDetailsPage(array $client, string $personalIdNumber, int $pageIndex, int $pageSize): array {
		if ($pageIndex < 0 || $pageIndex > 100000 || $pageSize < 1 || $pageSize > 100) {
			throw new ProviderException('invalid_pagination', 'pageIndex must be 0-100000 and pageSize must be 1-100.');
		}
		$hash = $personalIdNumber === '' ? null : $this->subjectHash($client['uid'], $this->input->personalId($personalIdNumber));
		$rows = $this->registrations->certificates($client['uid'], $hash, $pageSize + 1, $pageIndex * $pageSize);
		$items = array_slice($rows, 0, $pageSize);
		$native = $this->crlMapper->findBySerialNumbers(array_map(fn (ProviderRegistration $r) => $r->getSerialNumber(), $items));
		return [
			'items' => array_map(function (ProviderRegistration $r) use ($native): array {
				$state = $this->state($r);
				if (!isset($native[$r->getSerialNumber()])) {
					throw new DoesNotExistException('Certificate revocation record not found.');
				}
				return ['certificate' => $this->certificateData($r, $state, $native[$r->getSerialNumber()]), 'identity' => $state['input']];
			}, $items),
			'pageIndex' => $pageIndex, 'pageSize' => $pageSize, 'hasMore' => count($rows) > $pageSize,
		];
	}

	public function certificate(array $client, string $certAlias): array {
		return $this->certificateData($this->findCertificate($client, $certAlias));
	}

	public function certificateDetails(array $client, string $certAlias): array {
		$registration = $this->findCertificate($client, $certAlias);
		$state = $this->state($registration);
		return ['certificate' => $this->certificateData($registration, $state), 'identity' => $state['input']];
	}

	/** Resolve at most two active certificates: callers must reject ambiguity. */
	public function activeCertificates(array $client, string $personalIdNumber): array {
		$hash = $this->subjectHash($client['uid'], $this->input->personalId($personalIdNumber));
		$before = PHP_INT_MAX;
		$found = [];
		do {
			$rows = $this->registrations->activeCandidates($client['uid'], $hash, $before);
			$native = $this->crlMapper->findBySerialNumbers(array_map(fn (ProviderRegistration $r) => $r->getSerialNumber(), $rows));
			foreach ($rows as $row) {
				$before = (int)$row->getId();
				if (!isset($native[$row->getSerialNumber()])) {
					throw new DoesNotExistException('Certificate revocation record not found.');
				}
				$certificate = $this->certificateData($row, null, $native[$row->getSerialNumber()]);
				if ($certificate['status'] === 'ACTIVE') {
					$found[] = $certificate;
					if (count($found) === 2) {
						return $found;
					}
				}
			}
		} while (count($rows) === 100);
		return $found;
	}

	public function publicCertificate(array $client, string $certAlias): array {
		$registration = $this->findCertificate($client, $certAlias);
		return ['certAlias' => $certAlias, 'format' => 'PEM', 'chain' => $this->state($registration)['certificate']['chain']];
	}

	/** Internal metadata; no private key or password is exposed by an HTTP endpoint. */
	public function identity(array $client, string $certAlias): array {
		return $this->state($this->find($client, $certAlias))['input'];
	}

	public function countCertificates(array $client, string $personalIdNumber = ''): int {
		return $this->registrations->countCertificates($client['uid'], $personalIdNumber === '' ? null : $this->subjectHash($client['uid'], $this->input->personalId($personalIdNumber)));
	}

	/** Resolve a managed key only after the calling workflow has verified document consent. */
	public function signingCredential(array $client, string $certAlias): array {
		$registration = $this->findCertificate($client, $certAlias);
		$state = $this->state($registration);
		$certificate = $this->certificateData($registration, $state);
		if ($certificate['status'] !== 'ACTIVE' || $certificate['validFrom'] > time()) {
			throw new ProviderException('SYS-0031', 'Certificate is expired, revoked or not yet valid.', 409);
		}
		$pfx = $this->credentials->read($certAlias);
		if (!openssl_pkcs12_read($pfx, $parts, $state['password'])
			|| !hash_equals($certificate['fingerprintSha256'], openssl_x509_fingerprint($parts['cert'], 'sha256'))) {
			throw new ProviderException('credential_unavailable', 'Managed certificate material does not match its alias.', 503);
		}
		return ['pfx' => $pfx, 'password' => $state['password'], 'certificate' => $certificate, 'person' => $state['input']['person']];
	}

	public function revoke(array $client, string $certAlias, int $reasonCode, string $reasonText): array {
		$registration = $this->findCertificate($client, $certAlias);
		$reason = CRLReason::tryFrom($reasonCode);
		if ($reason === null || strlen($reasonText) > 512 || preg_match('/[\x00-\x1f\x7f]/', $reasonText)) {
			throw new ProviderException('invalid_revocation', 'Invalid revocation reason.');
		}
		if ($this->crlMapper->findBySerialNumber($registration->getSerialNumber())->isRevoked()) {
			return $this->certificateData($registration);
		}
		if (!$this->crl->revokeCertificate($registration->getSerialNumber(), $reason, $reasonText, $client['uid'])) {
			throw new ProviderException('revocation_failed', 'Certificate revocation could not be completed.', 409);
		}
		return $this->certificateData($registration);
	}

	private function requireSetup(): void {
		$engine = $this->engines->getEngine();
		if ($engine->getName() !== 'openssl' || !$engine->isSetupOk()) {
			throw new ProviderException('ca_not_ready', 'This provider increment requires a configured LibreSign OpenSSL CA.', 503);
		}
	}

	private function find(array $client, string $id): ProviderRegistration {
		try {
			return $this->registrations->findForClient($client['uid'], $id);
		} catch (DoesNotExistException) {
			throw new ProviderException('not_found', 'Registration or certificate not found.', 404);
		}
	}

	private function findCertificate(array $client, string $alias): ProviderRegistration {
		$registration = $this->find($client, $alias);
		if ($registration->getStatus() !== 'ACTIVE' || $registration->getSerialNumber() === null) {
			throw new ProviderException('not_found', 'Certificate not found.', 404);
		}
		return $registration;
	}

	private function requirePending(ProviderRegistration $registration): array {
		if ($registration->getStatus() !== 'PENDING') {
			throw new ProviderException('invalid_state', 'Registration is ' . $registration->getStatus() . '.', 409);
		}
		if ($registration->getExpiresAt() <= time()) {
			throw new ProviderException('session_expired', 'The registration session has expired.', 410);
		}
		$state = $this->state($registration);
		if ($state['attempts'] >= 5) {
			throw new ProviderException('session_locked', 'The confirmation attempt limit has been reached.', 409);
		}
		return $state;
	}

	private function subjectHash(string $clientUid, string $personalId): string {
		return hash('sha256', $this->crypto->calculateHMAC('provider-subject:' . json_encode([$clientUid, $personalId], JSON_THROW_ON_ERROR)));
	}

	private function state(ProviderRegistration $registration): array {
		return json_decode($this->crypto->decrypt($registration->getEncryptedData()), true, 32, JSON_THROW_ON_ERROR);
	}

	private function setState(ProviderRegistration $registration, array $state): void {
		$registration->setEncryptedData($this->crypto->encrypt(json_encode($state, JSON_THROW_ON_ERROR)));
	}

	private function persist(ProviderRegistration $registration, array $state): void {
		$this->setState($registration, $state);
		$this->registrations->saveState($registration);
	}

	private function replayed(ProviderRegistration $registration, string $fingerprint): array {
		$state = $this->state($registration);
		if (!hash_equals($state['fingerprint'], $fingerprint)) {
			throw new ProviderException('idempotency_conflict', 'requestId was already used with different input.', 409);
		}
		return $this->registrationData($registration, $state);
	}

	private function challenge(ProviderRegistration $registration, array $state): string {
		return json_encode([
			'purpose' => 'libresign:provider:certificate-registration:v1',
			'clientUid' => $registration->getClientUid(), 'sessionId' => $registration->getRegistrationId(),
			'inputSha256' => $state['fingerprint'], 'nonce' => $state['nonce'], 'expiresAt' => $registration->getExpiresAt(),
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
	}

	private function registrationData(ProviderRegistration $registration, ?array $state = null): array {
		$state ??= $this->state($registration);
		$status = $registration->getStatus();
		if ($status === 'PENDING' && $registration->getExpiresAt() <= time()) {
			$status = 'EXPIRED';
		}
		$result = ['sessionId' => $registration->getRegistrationId(), 'status' => $status,
			'createdAt' => $registration->getCreatedAt(), 'expiresAt' => $registration->getExpiresAt(),
			'confirmationMethod' => $state['input']['confirmationMethod'], 'otpChannel' => $state['otpChannel'] ?? null,
			'otpExpiresAt' => $state['codeExpiresAt'] ?? null];
		if ($status === 'PENDING' && $state['input']['confirmationMethod'] === 'external_assertion') {
			$result['challengeBase64'] = base64_encode($this->challenge($registration, $state));
		}
		if ($status === 'ACTIVE') {
			$result['certificate'] = $this->certificateData($registration, $state);
		}
		return $result;
	}

	private function certificateData(ProviderRegistration $registration, ?array $state = null, ?\OCA\Libresign\Db\Crl $native = null): array {
		$state ??= $this->state($registration);
		$public = $state['certificate'];
		$native ??= $this->crlMapper->findBySerialNumber($registration->getSerialNumber());
		return [
			'certAlias' => $registration->getRegistrationId(), 'serialNumber' => $public['serialNumber'],
			'fullName' => $state['input']['person']['fullName'],
			'status' => $registration->getStatus() !== 'ACTIVE' ? $registration->getStatus() : ($native->isRevoked() ? 'REVOKED' : ($public['validTo'] <= time() ? 'EXPIRED' : 'ACTIVE')),
			'validFrom' => $public['validFrom'], 'validTo' => $public['validTo'], 'fingerprintSha256' => $public['fingerprintSha256'],
		];
	}

	private function publicData(string $pfx, string $password): array {
		if (!openssl_pkcs12_read($pfx, $parts, $password) || !($parsed = openssl_x509_parse($parts['cert']))) {
			throw new \RuntimeException('The generated PFX could not be read');
		}
		return ['serialNumber' => $parsed['serialNumber'], 'validFrom' => $parsed['validFrom_time_t'], 'validTo' => $parsed['validTo_time_t'],
			'fingerprintSha256' => openssl_x509_fingerprint($parts['cert'], 'sha256'),
			'chain' => array_values(array_unique(array_merge([$parts['cert']], $parts['extracerts'] ?? [])))];
	}
}
