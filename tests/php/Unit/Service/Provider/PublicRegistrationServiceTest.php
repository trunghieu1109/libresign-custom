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
use OCA\Libresign\Service\Provider\ProviderInput;
use OCA\Libresign\Service\Provider\ProviderProofService;
use OCA\Libresign\Service\Provider\ProviderRegistrationLinkService;
use OCA\Libresign\Service\Provider\ProviderStateStore;
use OCA\Libresign\Service\Provider\ProviderWebhookService;
use OCA\Libresign\Service\Provider\PublicRegistrationService;
use OCP\Security\IHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicRegistrationServiceTest extends TestCase {
	private PublicRegistrationService $service;
	private ProviderCertificateService $certificates;
	private array $record = [];

	protected function setUp(): void {
		$store = $this->createMock(ProviderStateStore::class);
		$store->method('locked')->willReturnCallback(fn ($key, $callback) => $callback());
		$store->method('get')->willReturnCallback(fn () => $this->record);
		$store->method('save')->willReturnCallback(function (array &$record) {
			$this->record = $record;
		});
		$store->method('create')->willReturnCallback(fn ($uid, $kind, $key, $data, $status = 'PENDING')
			=> ['record_id' => 'enrollment', 'status' => $status, 'expires_at' => time() + 900, 'data' => $data]);
		$certificates = $this->certificates = $this->createMock(ProviderCertificateService::class);
		$certificates->method('certificates')->willReturn(['items' => [], 'hasMore' => false]);
		$this->service = new PublicRegistrationService($store, $this->createMock(ProviderArtifactStore::class), $certificates, new ProviderInput(),
			new ProviderProofService($this->createMock(TokenService::class), $this->createMock(IHasher::class)),
			$this->createMock(ProviderWebhookService::class), $this->createMock(ProviderRegistrationLinkService::class));
	}

	public static function channels(): array {
		return ['email' => ['email', 'signer@example.com', ''], 'sms' => ['sms', '', '0912345678'],
			'both' => ['email_sms', 'signer@example.com', '0912345678']];
	}

	#[DataProvider('channels')]
	public function testPublicEnrollmentAcceptsConfiguredChannelAndOnlyRequiresItsContacts(string $channel, string $email, string $phone): void {
		$session = $this->service->register(['uid' => 'partner', 'config' => ['otpChannel' => $channel, 'otpGateway' => 'sms']], 'registration-key',
			['personalIdNumber' => '001234567890', 'fullName' => 'Signer', 'registrationMethod' => 'CARD_READER', 'email' => $email, 'phone' => $phone]);
		$this->assertSame('PENDING', $session['status']);
		$this->assertSame($channel, $session['otpChannel']);
		$this->assertSame('SUBMIT_IDENTITY', $session['nextAction']);
		$this->assertSame($phone === '' ? '' : '+84912345678', $session['phone']);
	}

	public function testDualDeliveryCannotStartWithoutPhone(): void {
		$this->expectException(ProviderException::class);
		$this->expectExceptionMessage('A verified phoneNumber is required');
		$this->service->register(['uid' => 'partner', 'config' => ['otpChannel' => 'email_sms', 'otpGateway' => 'sms']], 'registration-key',
			['personalIdNumber' => '001234567890', 'fullName' => 'Signer', 'registrationMethod' => 'CARD_READER', 'email' => 'signer@example.com']);
	}

	public function testFailedResendReturnsPreviouslyVerifiedEnrollmentToAwaitingOtp(): void {
		$this->record = ['status' => 'PENDING', 'expires_at' => time() + 900,
			'data' => ['legacySession' => 'native-session', 'step' => 'VERIFIED']];
		$this->certificates->method('sendCode')->willThrowException(new ProviderException('delivery_failed', 'SMS unavailable', 503));
		try {
			$this->service->action(['uid' => 'partner', 'config' => ['otpChannel' => 'email_sms']], 'enrollment', 'resend');
			$this->fail('Expected failed delivery');
		} catch (ProviderException $e) {
			$this->assertSame('delivery_failed', $e->error);
			$this->assertSame('AWAITING_CONFIRMATION', $this->record['data']['step']);
		}
	}

	public function testRateLimitedResendPreservesPreviouslyVerifiedStep(): void {
		$this->record = ['status' => 'PENDING', 'expires_at' => time() + 900,
			'data' => ['legacySession' => 'native-session', 'step' => 'VERIFIED']];
		$this->certificates->method('sendCode')->willThrowException(new ProviderException('code_rate_limited', 'Wait', 429));
		try {
			$this->service->action(['uid' => 'partner', 'config' => ['otpChannel' => 'email_sms']], 'enrollment', 'resend');
			$this->fail('Expected rate limit');
		} catch (ProviderException $e) {
			$this->assertSame(429, $e->getCode());
			$this->assertSame('VERIFIED', $this->record['data']['step']);
		}
	}
}
