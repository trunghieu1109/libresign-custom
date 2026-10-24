<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\IdentifyMethod\SignatureMethod\TokenService;
use OCA\Libresign\Service\Provider\ProviderArtifactStore;
use OCA\Libresign\Service\Provider\ProviderCertificateService;
use OCA\Libresign\Service\Provider\ProviderPdfSigner;
use OCA\Libresign\Service\Provider\ProviderProofService;
use OCA\Libresign\Service\Provider\ProviderStateStore;
use OCA\Libresign\Service\Provider\ProviderWebhookService;
use OCA\Libresign\Service\Provider\PublicSigningService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicSigningServiceTest extends TestCase {
	private array $record = [];
	private array $client;
	private TokenService $tokens;
	private ProviderPdfSigner $signer;
	private PublicSigningService $service;

	private function configure(string $channel): void {
		$this->client = ['uid' => 'partner', 'config' => ['enabled' => true, 'scopes' => ['signing:write'], 'otpChannel' => $channel, 'otpGateway' => 'sms']];
		$store = $this->createMock(ProviderStateStore::class);
		$store->method('locked')->willReturnCallback(fn ($key, $callback) => $callback());
		$store->method('transaction')->willReturnCallback(fn ($callback) => $callback());
		$store->method('create')->willReturnCallback(function ($uid, $kind, $key, $data, $status) {
			return $this->record = ['record_id' => 'session', 'client_uid' => $uid, 'status' => $status,
				'expires_at' => time() + 900, 'data' => $data];
		});
		$store->method('save')->willReturnCallback(function (array &$record) {
			$this->record = $record;
		});
		$store->method('get')->willReturnCallback(fn () => $this->record);
		$artifacts = $this->createMock(ProviderArtifactStore::class);
		$artifacts->method('put')->willReturnCallback(fn ($session, $name) => $name);
		$artifacts->method('read')->willReturn('%PDF-test');
		$certificates = $this->createMock(ProviderCertificateService::class);
		$person = ['personalIdNumber' => '001234567890', 'fullName' => 'Signer', 'email' => 'signer@example.com', 'phoneNumber' => '+84901234567'];
		$certificates->method('identity')->willReturn(['person' => $person]);
		$certificates->method('signingCredential')->willReturn(['person' => $person, 'certificate' => ['fingerprintSha256' => 'fingerprint']]);
		$this->signer = $this->createMock(ProviderPdfSigner::class);
		$this->signer->method('prepare')->willReturnCallback(fn ($content, $params) => $params);
		$this->tokens = $this->createMock(TokenService::class);
		$hasher = $this->createMock(IHasher::class);
		$hasher->method('verify')->willReturnCallback(fn ($code, $hash) => $hash === 'hash-' . $code);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn () => json_encode(['partner' => $this->client['config']]));
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$this->service = new PublicSigningService($store, $artifacts, $certificates, $this->signer,
			new ProviderProofService($this->tokens, $hasher), $this->createMock(ProviderWebhookService::class),
			$this->createMock(ISecureRandom::class), $config, $users);
	}

	public static function modes(): array {
		$cases = [];
		foreach (['email' => 'sendCodeByEmail', 'sms' => 'sendCodeByGateway', 'email_sms' => 'sendCodeByEmailAndGateway'] as $channel => $method) {
			foreach ([false, true] as $batch) {
				$cases[$channel . ($batch ? ' batch' : ' single')] = [$channel, $method, $batch];
			}
		}
		return $cases;
	}

	private function start(bool $batch = false): array {
		$file = ['fileBase64' => base64_encode('%PDF-test'), 'documentName' => 'test.pdf'];
		return $this->service->start($this->client, 'signing-key', ['certAlias' => 'certificate'] + ($batch ? ['files' => [$file, $file]] : $file));
	}

	#[DataProvider('modes')]
	public function testSendResendAndConfirmUseConfiguredChannelForSingleAndBatch(string $channel, string $method, bool $batch): void {
		$this->configure($channel);
		$this->tokens->expects($this->exactly(2))->method($method)->willReturnOnConsecutiveCalls('hash-123456', 'hash-654321');
		$this->signer->expects($this->exactly($batch ? 2 : 1))->method('sign')->willReturn('%PDF-test');
		$session = $this->start($batch);
		$this->assertSame($channel, $session['otpChannel']);
		$this->assertSame('********4567', $session['phone']);
		$this->assertStringNotContainsString('hash-123456', json_encode($session));
		$this->assertSame($channel, $this->service->capabilities($this->client)['otpChannel']);
		$this->assertSame(['email', 'sms', 'email_sms'], $this->service->capabilities($this->client)['supportedOtpChannels']);
		try {
			$this->service->resend($this->client, 'session');
			$this->fail('Expected resend rate limit');
		} catch (ProviderException $e) {
			$this->assertSame(429, $e->getCode());
		}
		$this->record['data']['lastCodeAt'] = time() - 61;
		$this->assertSame($channel, $this->service->resend($this->client, 'session')['otpChannel']);
		try {
			$this->service->confirm($this->client, 'session', ['otpValue' => '123456']);
			$this->fail('Expected old code to be rejected');
		} catch (ProviderException $e) {
			$this->assertSame('TOTP-0001', $e->error);
			$this->assertSame('PENDING', $this->record['status']);
		}
		$this->assertSame('SUCCESS', $this->service->confirm($this->client, 'session', ['otpValue' => '654321'])['status']);
		$this->assertArrayNotHasKey('codeHash', $this->record['data']);
	}

	public function testFailedDualResendInvalidatesPreviousCodeAndKeepsAttemptCount(): void {
		$this->configure('email_sms');
		$this->tokens->method('sendCodeByEmailAndGateway')->willReturnCallback(function () {
			if ($this->record['data']['sendCount'] > 1) {
				throw new \RuntimeException('SMS unavailable after email');
			}
			return 'hash-123456';
		});
		$this->signer->expects($this->never())->method('sign');
		$this->start();
		$this->record['data']['lastCodeAt'] = time() - 61;
		$this->record['data']['attempts'] = 2;
		try {
			$this->service->resend($this->client, 'session');
			$this->fail('Expected failed delivery');
		} catch (ProviderException $e) {
			$this->assertSame('delivery_failed', $e->error);
			$this->assertSame('', $this->record['data']['codeHash']);
			$this->assertArrayNotHasKey('codeExpiresAt', $this->record['data']);
			$this->assertSame(2, $this->record['data']['sendCount']);
			$this->assertSame(2, $this->record['data']['attempts']);
		}
		$this->expectExceptionCode(403);
		$this->service->confirm($this->client, 'session', ['otpValue' => '123456']);
	}

	public function testExpiredCodeIsRejectedBeforeSigning(): void {
		$this->configure('sms');
		$this->tokens->method('sendCodeByGateway')->willReturn('hash-123456');
		$this->signer->expects($this->never())->method('sign');
		$this->start();
		$this->record['data']['codeExpiresAt'] = time() - 1;
		$this->expectExceptionCode(403);
		$this->service->confirm($this->client, 'session', ['otpValue' => '123456']);
	}

	public static function batches(): array {
		return ['single PDF' => [false], 'batch' => [true]];
	}

	#[DataProvider('batches')]
	public function testMockEmailOtpSignsWithAnySixDigitsUsingTheExistingContract(bool $batch): void {
		$this->configure('email');
		$this->client['config']['mockOtpEnabled'] = true;
		$this->tokens->expects($this->once())->method('sendCodeByEmail')->willReturn('hash-123456');
		$this->signer->expects($this->exactly($batch ? 2 : 1))->method('sign')->willReturn('%PDF-test');
		$session = $this->start($batch);
		$this->assertSame('email', $session['otpChannel']);
		$this->assertTrue($session['otpRequired']);
		$this->assertArrayNotHasKey('mockOtpEnabled', $session);
		try {
			$this->service->confirm($this->client, 'session', ['otpValue' => '12345']);
			$this->fail('Expected malformed mock OTP to be rejected');
		} catch (ProviderException $e) {
			$this->assertSame(403, $e->getCode());
		}
		$this->assertSame('SUCCESS', $this->service->confirm($this->client, 'session', ['otpValue' => '000000'])['status']);
	}
}
