<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Db\Crl;
use OCA\Libresign\Db\CrlMapper;
use OCA\Libresign\Db\ProviderRegistration;
use OCA\Libresign\Db\ProviderRegistrationMapper;
use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Handler\CertificateEngine\AEngineHandler;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Handler\SignEngine\Pkcs12Handler;
use OCA\Libresign\Service\Crl\CrlService;
use OCA\Libresign\Service\Provider\ProviderCertificateService;
use OCA\Libresign\Service\Provider\ProviderCredentialStore;
use OCA\Libresign\Service\Provider\ProviderInput;
use OCA\Libresign\Service\Provider\ProviderProofService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ProviderCertificateServiceTest extends TestCase {
	private array $rows;
	private array $client = ['uid' => 'partner-a', 'config' => ['assertionPublicKey' => 'configured']];
	private ProviderCertificateService $service;
	private ProviderProofService $proofs;
	private ProviderCredentialStore $credentials;
	private Pkcs12Handler $pkcs12;
	private CrlService $crl;
	private Crl $native;
	private bool $conflict = false;

	protected function setUp(): void {
		$this->rows = [];
		$mapper = $this->createMock(ProviderRegistrationMapper::class);
		$mapper->method('insert')->willReturnCallback(function (ProviderRegistration $r) {
			$r->setId(count($this->rows) + 1);
			$this->rows[$r->getRegistrationId()] = clone $r;
			return $r;
		});
		$mapper->method('findForClient')->willReturnCallback(function ($client, $id, $byKey = false) {
			foreach ($this->rows as $r) {
				if ($r->getClientUid() === $client && ($byKey ? $r->getRequestKey() : $r->getRegistrationId()) === $id) {
					return clone $r;
				}
			}
			throw new DoesNotExistException('Not found');
		});
		$mapper->method('saveState')->willReturnCallback(function (ProviderRegistration $r) {
			if ($this->conflict || $this->rows[$r->getRegistrationId()]->getVersion() !== $r->getVersion()) {
				throw new ProviderException('concurrent_update', 'Conflict', 409);
			}
			$r->setVersion($r->getVersion() + 1);
			$this->rows[$r->getRegistrationId()] = clone $r;
		});
		$mapper->method('certificates')->willReturnCallback(fn ($client) => array_values(array_filter($this->rows,
			fn ($r) => $r->getClientUid() === $client && $r->getSerialNumber() !== null)));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn ($value) => base64_encode($value));
		$crypto->method('decrypt')->willReturnCallback(fn ($value) => base64_decode($value));
		$crypto->method('calculateHMAC')->willReturnCallback(fn ($value) => hash_hmac('sha512', $value, 'test-secret', true));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn ($length) => substr(bin2hex(random_bytes($length)), 0, $length));
		$engine = $this->createMock(AEngineHandler::class);
		$engine->method('getName')->willReturn('openssl');
		$engine->method('isSetupOk')->willReturn(true);
		$engine->method('getLeafExpiryInDays')->willReturn(365);
		$factory = $this->createMock(CertificateEngineFactory::class);
		$factory->method('getEngine')->willReturn($engine);
		$this->native = new Crl();
		$this->native->setSerialNumber('42');
		$crlMapper = $this->createMock(CrlMapper::class);
		$crlMapper->method('findBySerialNumber')->with('42')->willReturn($this->native);
		$this->proofs = $this->createMock(ProviderProofService::class);
		$this->credentials = $this->createMock(ProviderCredentialStore::class);
		$this->pkcs12 = $this->createMock(Pkcs12Handler::class);
		$this->crl = $this->createMock(CrlService::class);
		$this->service = new ProviderCertificateService($mapper, new ProviderInput(), $this->proofs, $this->credentials,
			$this->pkcs12, $factory, $crlMapper, $this->crl, $crypto, $random, new NullLogger());
	}

	private function register(string $method = 'external_assertion', string $name = 'Signer'): array {
		return $this->service->register($this->client, 'business-registration-001',
			['fullName' => $name, 'personalIdNumber' => '001234567890', 'email' => 'signer@example.com'],
			['method' => 'nfc', 'source' => 'adapter-01', 'reference' => 'receipt-01', 'verifiedAt' => '2026-01-01T00:00:00Z'],
			$method, 'internal-default');
	}

	private function enableIssuance(bool $storageFails = false): void {
		$this->proofs->method('verify')->willReturn(true);
		$pfx = '';
		$this->pkcs12->expects($this->once())->method('generateCertificate')->willReturnCallback(function ($user, $password, $name) use (&$pfx) {
			$this->assertStringStartsWith('provider:', $user['uid']);
			$this->assertStringNotContainsString('001234567890', $user['uid']);
			$key = openssl_pkey_new(['private_key_bits' => 2048]);
			$csr = openssl_csr_new(['commonName' => $name], $key);
			$cert = openssl_csr_sign($csr, null, $key, 1, [], 42);
			openssl_pkcs12_export($cert, $pfx, $key, $password);
			return $pfx;
		});
		if ($storageFails) {
			$this->credentials->method('save')->willThrowException(new \RuntimeException('Storage unavailable'));
		} else {
			$this->credentials->expects($this->once())->method('save');
			$this->credentials->method('read')->willReturnCallback(function () use (&$pfx) {
				return $pfx;
			});
		}
	}

	public function testRegistrationIsIdempotentAndDoesNotIssueBeforeConfirmation(): void {
		$this->pkcs12->expects($this->never())->method('generateCertificate');
		$first = $this->register();
		$this->assertSame($first, $this->register());
		$this->assertCount(1, $this->rows);
		$challenge = json_decode(base64_decode($first['challengeBase64']), true);
		$this->assertSame('partner-a', $challenge['clientUid']);
		$this->assertSame($first['sessionId'], $challenge['sessionId']);
		$this->expectExceptionCode(409);
		$this->register(name: 'Different signer');
	}

	public function testConfirmationCreatesOneCredentialAndReplayDoesNotCreateAnother(): void {
		$this->enableIssuance();
		$session = $this->register()['sessionId'];
		$result = $this->service->confirm($this->client, $session, '', 'assertion');
		$this->assertSame('ACTIVE', $result['status']);
		$this->assertSame('42', $result['certificate']['serialNumber']);
		$this->assertStringStartsWith('provider:', $this->native->getOwner());
		$this->assertSame($result, $this->service->confirm($this->client, $session, '', ''));
		$this->assertStringNotContainsString('password', json_encode($result));
		$this->assertStringNotContainsString('PRIVATE KEY', json_encode($this->service->publicCertificate($this->client, $session)));
	}

	public function testIntegrationCannotReadOrRevokeAnotherIntegrationsCertificate(): void {
		$session = $this->register()['sessionId'];
		$this->crl->expects($this->never())->method('revokeCertificate');
		$this->expectExceptionCode(404);
		$this->service->revoke(['uid' => 'partner-b'], $session, 0, '');
	}

	public function testFiveInvalidProofsLockTheSessionWithoutIssuing(): void {
		$this->proofs->method('verify')->willReturn(false);
		$this->pkcs12->expects($this->never())->method('generateCertificate');
		$session = $this->register()['sessionId'];
		for ($i = 0; $i < 5; $i++) {
			try {
				$this->service->confirm($this->client, $session, '000000', '');
				$this->fail('Expected invalid proof');
			} catch (ProviderException $e) {
				$this->assertSame(403, $e->getCode());
			}
		}
		$this->assertSame('LOCKED', $this->service->registration($this->client, $session)['status']);
		$this->expectExceptionCode(409);
		$this->service->confirm($this->client, $session, '123456', '');
	}

	public function testExpiryAndCancellationPreventIssuance(): void {
		$this->pkcs12->expects($this->never())->method('generateCertificate');
		$session = $this->register()['sessionId'];
		$this->rows[$session]->setExpiresAt(time() - 1);
		$this->assertSame('EXPIRED', $this->service->registration($this->client, $session)['status']);
		$this->expectExceptionCode(410);
		$this->service->confirm($this->client, $session, '', 'proof');
	}

	public function testCancellationIsIdempotentAndStopsConfirmation(): void {
		$this->pkcs12->expects($this->never())->method('generateCertificate');
		$session = $this->register()['sessionId'];
		$cancelled = $this->service->cancel($this->client, $session);
		$this->assertSame('CANCELLED', $cancelled['status']);
		$this->assertSame($cancelled, $this->service->cancel($this->client, $session));
		$this->expectExceptionCode(409);
		$this->service->confirm($this->client, $session, '', 'proof');
	}

	public function testStorageFailureNeverAutomaticallyReissues(): void {
		$this->enableIssuance(storageFails: true);
		$session = $this->register()['sessionId'];
		try {
			$this->service->confirm($this->client, $session, '', 'proof');
			$this->fail('Expected failed storage');
		} catch (ProviderException $e) {
			$this->assertSame(503, $e->getCode());
		}
		$this->assertSame('RECOVERY_REQUIRED', $this->service->registration($this->client, $session)['status']);
		$this->expectExceptionCode(409);
		$this->service->confirm($this->client, $session, '', 'proof');
	}

	public function testConcurrentMutationIsRejectedBeforeCallingCa(): void {
		$session = $this->register()['sessionId'];
		$this->conflict = true;
		$this->pkcs12->expects($this->never())->method('generateCertificate');
		$this->expectExceptionCode(409);
		$this->service->confirm($this->client, $session, '', 'proof');
	}

	public function testCodeResendIsRateLimitedAndDoesNotExposeTheCode(): void {
		$this->proofs->expects($this->once())->method('send')->willReturn('secret-code-hash');
		$session = $this->register('service_otp')['sessionId'];
		$result = $this->service->sendCode($this->client, $session);
		$this->assertSame('CODE_SENT', $result['status']);
		$this->assertStringNotContainsString('secret-code-hash', json_encode($result));
		$this->expectExceptionCode(429);
		$this->service->sendCode($this->client, $session);
	}

	public function testNativeRevocationStateIsReflectedWithoutChangingRegistration(): void {
		$this->enableIssuance();
		$session = $this->register()['sessionId'];
		$this->service->confirm($this->client, $session, '', 'proof');
		$this->native->setStatus('revoked');
		$this->assertSame('REVOKED', $this->service->certificate($this->client, $session)['status']);
		$this->assertSame('ACTIVE', $this->service->registration($this->client, $session)['status']);
	}

	public function testFailedDualResendInvalidatesCodeAndPreviouslyVerifiedProof(): void {
		$this->client['config']['otpChannel'] = 'email_sms';
		$this->client['config']['otpGateway'] = 'sms';
		$count = 0;
		$this->proofs->method('send')->willReturnCallback(function () use (&$count) {
			if (++$count > 1) {
				throw new ProviderException('delivery_failed', 'SMS delivery failed.', 503);
			}
			return 'old-code-hash';
		});
		$session = $this->register('service_otp')['sessionId'];
		$this->service->sendCode($this->client, $session);
		$this->assertSame('email_sms', $this->service->registration($this->client, $session)['otpChannel']);
		$row = $this->rows[$session];
		$state = json_decode(base64_decode($row->getEncryptedData()), true);
		$state['lastCodeAt'] = time() - 61;
		$state['attempts'] = 2;
		$state['proofVerifiedAt'] = time();
		$state['proofHash'] = 'old-proof';
		$row->setEncryptedData(base64_encode(json_encode($state)));
		try {
			$this->service->sendCode($this->client, $session);
			$this->fail('Expected failed dual delivery');
		} catch (ProviderException $e) {
			$this->assertSame('delivery_failed', $e->error);
		}
		$state = json_decode(base64_decode($this->rows[$session]->getEncryptedData()), true);
		$this->assertSame('', $state['codeHash']);
		$this->assertArrayNotHasKey('codeExpiresAt', $state);
		$this->assertArrayNotHasKey('proofVerifiedAt', $state);
		$this->assertArrayNotHasKey('proofHash', $state);
		$this->assertSame(2, $state['sendCount']);
		$this->assertSame(2, $state['attempts']);
		$this->pkcs12->expects($this->never())->method('generateCertificate');
		$this->expectExceptionCode(403);
		$this->service->issueVerified($this->client, $session);
	}
}
