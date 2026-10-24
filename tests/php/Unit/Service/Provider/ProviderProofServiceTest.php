<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\IdentifyMethod\SignatureMethod\TokenService;
use OCA\Libresign\Service\Provider\ProviderProofService;
use OCP\Security\IHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderProofServiceTest extends TestCase {
	public function testExternalAssertionIsBoundToExactChallengeBytes(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048]);
		$config = ['assertionPublicKey' => openssl_pkey_get_details($key)['key']];
		$service = new ProviderProofService($this->createMock(TokenService::class), $this->createMock(IHasher::class));
		$state = ['input' => ['confirmationMethod' => 'external_assertion']];
		$challenge = '{"sessionId":"session-a","inputSha256":"abc"}';
		openssl_sign($challenge, $signature, $key, OPENSSL_ALGO_SHA256);
		$this->assertTrue($service->verify($config, $state, $challenge, '', base64_encode($signature)));
		$this->assertFalse($service->verify($config, $state, str_replace('session-a', 'session-b', $challenge), '', base64_encode($signature)));
		$this->assertFalse($service->verify($config, $state, $challenge, '', 'invalid base64!'));
	}

	public function testOtpRequiresUnexpiredCodeAndHashVerification(): void {
		$hasher = $this->createMock(IHasher::class);
		$hasher->expects($this->once())->method('verify')->with('123456', 'hashed-code')->willReturn(true);
		$service = new ProviderProofService($this->createMock(TokenService::class), $hasher);
		$state = ['input' => ['confirmationMethod' => 'service_otp', 'person' => ['email' => 'signer@example.com']],
			'codeHash' => 'hashed-code', 'codeExpiresAt' => time() + 60];
		$this->assertTrue($service->verify(['otpChannel' => 'email'], $state, '', '123456', ''));
		$state['codeExpiresAt'] = time() - 1;
		$this->assertFalse($service->verify(['otpChannel' => 'email'], $state, '', '123456', ''));
	}

	public function testOtpUsesStoredContactAndNativeDeliveryService(): void {
		$tokens = $this->createMock(TokenService::class);
		$tokens->expects($this->once())->method('sendRegistrationCodeByEmail')->with('stored@example.com', 'Signer')->willReturn('hash');
		$service = new ProviderProofService($tokens, $this->createMock(IHasher::class));
		$this->assertSame('hash', $service->send(['otpChannel' => 'email'], ['confirmationMethod' => 'service_otp', 'person' => ['email' => 'stored@example.com', 'fullName' => 'Signer']]));
	}

	public function testSmsReusesConfiguredTwoFactorGateway(): void {
		$tokens = $this->createMock(TokenService::class);
		$tokens->expects($this->once())->method('sendCodeByGateway')->with('+84901234567', 'gateway')->willReturn('hash');
		$service = new ProviderProofService($tokens, $this->createMock(IHasher::class));
		$this->assertSame('hash', $service->send(['otpChannel' => 'sms', 'otpGateway' => 'gateway'], ['confirmationMethod' => 'service_otp', 'person' => ['phoneNumber' => '+84901234567']]));
	}

	public function testClientCannotChooseAnUnconfiguredConfirmationMethod(): void {
		$this->expectException(ProviderException::class);
		$this->expectExceptionCode(403);
		(new ProviderProofService($this->createMock(TokenService::class), $this->createMock(IHasher::class)))
			->validateMethod(['otpChannel' => 'email'], ['confirmationMethod' => 'external_assertion']);
	}

	public static function deliveryModes(): array {
		return [
			'email registration' => ['email', true, 'sendRegistrationCodeByEmail', ['stored@example.com', 'Signer']],
			'email signing' => ['email', false, 'sendCodeByEmail', ['stored@example.com', 'Signer']],
			'sms registration' => ['sms', true, 'sendCodeByGateway', ['+84901234567', 'gateway']],
			'sms signing' => ['sms', false, 'sendCodeByGateway', ['+84901234567', 'gateway']],
			'both registration' => ['email_sms', true, 'sendCodeByEmailAndGateway', ['stored@example.com', 'Signer', '+84901234567', 'gateway', true]],
			'both signing' => ['email_sms', false, 'sendCodeByEmailAndGateway', ['stored@example.com', 'Signer', '+84901234567', 'gateway', false]],
		];
	}

	#[DataProvider('deliveryModes')]
	public function testDeliveryUsesConfiguredChannelsAndCorrectPurpose(string $channel, bool $registration, string $method, array $args): void {
		$tokens = $this->createMock(TokenService::class);
		$tokens->expects($this->once())->method($method)->with(...$args)->willReturn('hash');
		$service = new ProviderProofService($tokens, $this->createMock(IHasher::class));
		$this->assertSame('hash', $service->send(['otpChannel' => $channel, 'otpGateway' => 'gateway'],
			['confirmationMethod' => 'service_otp', 'person' => ['email' => 'stored@example.com', 'fullName' => 'Signer', 'phoneNumber' => '+84901234567']], $registration));
	}

	public static function invalidDeliveryConfig(): array {
		return [
			'missing email' => ['email_sms', 'gateway', ['phoneNumber' => '+84901234567'], 'missing_contact'],
			'missing phone' => ['email_sms', 'gateway', ['email' => 'signer@example.com'], 'missing_contact'],
			'blank phone' => ['sms', 'gateway', ['phoneNumber' => ' '], 'missing_contact'],
			'missing gateway for sms' => ['sms', '', ['phoneNumber' => '+84901234567'], 'gateway_not_configured'],
			'missing gateway for both' => ['email_sms', '', ['email' => 'signer@example.com', 'phoneNumber' => '+84901234567'], 'gateway_not_configured'],
			'unknown channel' => ['push', 'gateway', [], 'method_not_enabled'],
		];
	}

	#[DataProvider('invalidDeliveryConfig')]
	public function testInvalidConfigIsRejectedBeforeAnyDelivery(string $channel, string $gateway, array $person, string $error): void {
		$tokens = $this->createMock(TokenService::class);
		foreach (['sendRegistrationCodeByEmail', 'sendCodeByEmail', 'sendCodeByGateway', 'sendCodeByEmailAndGateway'] as $method) {
			$tokens->expects($this->never())->method($method);
		}
		$service = new ProviderProofService($tokens, $this->createMock(IHasher::class));
		try {
			$service->send(['otpChannel' => $channel, 'otpGateway' => $gateway], ['confirmationMethod' => 'service_otp', 'person' => $person]);
			$this->fail('Expected invalid OTP configuration');
		} catch (ProviderException $e) {
			$this->assertSame($error, $e->error);
		}
	}

	public function testDeliveryFailureDoesNotExposeProviderDetails(): void {
		$tokens = $this->createMock(TokenService::class);
		$tokens->method('sendCodeByEmailAndGateway')->willThrowException(new \RuntimeException('secret provider response'));
		$service = new ProviderProofService($tokens, $this->createMock(IHasher::class));
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('The verification code could not be delivered.');
		$service->send(['otpChannel' => 'email_sms', 'otpGateway' => 'gateway'], ['confirmationMethod' => 'service_otp',
			'person' => ['email' => 'signer@example.com', 'fullName' => 'Signer', 'phoneNumber' => '+84901234567']]);
	}

	public static function mockCodes(): array {
		return ['leading zeroes' => ['000000', true], 'arbitrary code' => ['987654', true],
			'empty' => ['', false], 'five digits' => ['12345', false], 'seven digits' => ['1234567', false],
			'letters' => ['abcdef', false], 'mixed' => ['12345a', false], 'space' => ['12345 ', false],
			'newline' => ["123456\n", false], 'unicode digits' => ['１２３４５６', false]];
	}

	#[DataProvider('mockCodes')]
	public function testMockOtpAcceptsOnlySixAsciiDigits(string $code, bool $expected): void {
		$hasher = $this->createMock(IHasher::class);
		$hasher->expects($this->never())->method('verify');
		$service = new ProviderProofService($this->createMock(TokenService::class), $hasher);
		$state = ['input' => ['confirmationMethod' => 'service_otp', 'person' => ['email' => 'signer@example.com']],
			'codeHash' => 'hash-of-another-code', 'codeExpiresAt' => time() + 60];
		$this->assertSame($expected, $service->verify(['otpChannel' => 'email', 'mockOtpEnabled' => true], $state, '', $code, ''));
	}

	public static function invalidMockStates(): array {
		return ['not sent' => [[]], 'failed delivery' => [['codeHash' => '', 'codeExpiresAt' => PHP_INT_MAX]],
			'expired' => [['codeHash' => 'hash', 'codeExpiresAt' => 1]],
			'no expiry' => [['codeHash' => 'hash']]];
	}

	#[DataProvider('invalidMockStates')]
	public function testMockOtpStillRequiresSuccessfulUnexpiredDelivery(array $delivery): void {
		$hasher = $this->createMock(IHasher::class);
		$hasher->expects($this->never())->method('verify');
		$service = new ProviderProofService($this->createMock(TokenService::class), $hasher);
		$state = ['input' => ['confirmationMethod' => 'service_otp', 'person' => ['email' => 'signer@example.com']]] + $delivery;
		$this->assertFalse($service->verify(['otpChannel' => 'email', 'mockOtpEnabled' => true], $state, '', '123456', ''));
	}

	public static function disabledMockFlags(): array {
		return ['default' => [[]], 'disabled' => [['mockOtpEnabled' => false]], 'string true' => [['mockOtpEnabled' => 'true']],
			'integer' => [['mockOtpEnabled' => 1]]];
	}

	#[DataProvider('disabledMockFlags')]
	public function testRealCodeVerificationRemainsDefaultAndInputCannotEnableMock(array $config): void {
		$hasher = $this->createMock(IHasher::class);
		$hasher->expects($this->once())->method('verify')->with('123456', 'different-code-hash')->willReturn(false);
		$service = new ProviderProofService($this->createMock(TokenService::class), $hasher);
		$state = ['input' => ['confirmationMethod' => 'service_otp', 'person' => ['email' => 'signer@example.com'], 'mockOtpEnabled' => true],
			'codeHash' => 'different-code-hash', 'codeExpiresAt' => time() + 60];
		$this->assertFalse($service->verify(['otpChannel' => 'email'] + $config, $state, '', '123456', ''));
	}

	public function testMockOtpDoesNotReplaceAssertionVerification(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048]);
		$service = new ProviderProofService($this->createMock(TokenService::class), $this->createMock(IHasher::class));
		$this->assertFalse($service->verify(['mockOtpEnabled' => true, 'assertionPublicKey' => openssl_pkey_get_details($key)['key']],
			['input' => ['confirmationMethod' => 'external_assertion']], 'exact-challenge', '123456', ''));
	}

	public function testMockOtpKeepsRegistrationAndSigningEmailDelivery(): void {
		$tokens = $this->createMock(TokenService::class);
		$tokens->expects($this->once())->method('sendRegistrationCodeByEmail')->with('signer@example.com', 'Signer')->willReturn('registration-hash');
		$tokens->expects($this->once())->method('sendCodeByEmail')->with('signer@example.com', 'Signer')->willReturn('signing-hash');
		$service = new ProviderProofService($tokens, $this->createMock(IHasher::class));
		$config = ['otpChannel' => 'email', 'mockOtpEnabled' => true];
		$input = ['confirmationMethod' => 'service_otp', 'person' => ['email' => 'signer@example.com', 'fullName' => 'Signer']];
		$this->assertSame('registration-hash', $service->send($config, $input));
		$this->assertSame('signing-hash', $service->send($config, $input, false));
	}
}
