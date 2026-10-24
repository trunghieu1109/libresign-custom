<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\IdentifyMethod\SignatureMethod;

use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\IdentifyMethod\SignatureMethod\TokenService;
use OCA\Libresign\Service\MailService;
use OCA\Libresign\Service\TwofactorGatewayService;
use OCP\App\IAppManager;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class TokenServiceTest extends TestCase {
	private ISecureRandom&MockObject $secureRandom;
	private IHasher&MockObject $hasher;
	private MailService&MockObject $mailService;
	private IL10N&MockObject $l10n;
	private ContainerInterface&MockObject $container;
	private IAppManager&MockObject $appManager;
	private LoggerInterface&MockObject $logger;

	#[\Override]
	public function setUp(): void {
		parent::setUp();

		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->hasher = $this->createMock(IHasher::class);
		$this->mailService = $this->createMock(MailService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	public function testSendCodeByGatewayThrowsWhenGatewayAppIsNotEnabled(): void {
		$this->appManager->method('isEnabledForAnyone')->with('twofactor_gateway')->willReturn(false);
		$this->container->expects($this->never())
			->method('get');
		$this->secureRandom->expects($this->never())
			->method('generate');

		$this->expectException(LibresignException::class);
		$this->expectExceptionCode(TwofactorGatewayService::CODE_NOT_ENABLED);

		$this->createService()->sendCodeByGateway('+5511999999999', 'sms');
	}

	public function testSendCodeByGatewayThrowsWhenGatewayIsIncomplete(): void {
		$this->appManager->method('isEnabledForAnyone')->with('twofactor_gateway')->willReturn(true);
		$this->container->method('get')
			->with('OCA\\TwoFactorGateway\\Service\\GatewayDirectIntegrationService')
			->willReturn(new TokenServiceGatewayIntegrationStub(false));
		$this->secureRandom->expects($this->never())
			->method('generate');
		$this->l10n->method('t')
			->willReturnCallback(static fn (string $text, mixed $parameters = []): string => is_array($parameters)
				? vsprintf($text, $parameters)
				: sprintf($text, $parameters));

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Gateway sms not configured on Two-Factor Gateway.');

		$this->createService()->sendCodeByGateway('+5511999999999', 'sms');
	}

	public function testSendCodeByGatewayUsesGatewayServiceAndReturnsHashedCode(): void {
		$this->appManager->method('isEnabledForAnyone')->with('twofactor_gateway')->willReturn(true);
		$integrationService = new TokenServiceGatewayIntegrationStub(true);
		$this->container->method('get')
			->with('OCA\\TwoFactorGateway\\Service\\GatewayDirectIntegrationService')
			->willReturn($integrationService);
		$this->secureRandom->expects($this->once())
			->method('generate')
			->with(TokenService::TOKEN_LENGTH, ISecureRandom::CHAR_DIGITS)
			->willReturn('123456');
		$this->l10n->expects($this->once())
			->method('t')
			->with('%s is your LibreSign verification code.', '123456')
			->willReturn('123456 is your LibreSign verification code.');
		$this->hasher->expects($this->once())
			->method('hash')
			->with('123456')
			->willReturn('hashed-code');

		self::assertSame('hashed-code', $this->createService()->sendCodeByGateway('+5511999999999', 'sms'));
		self::assertSame([
			['gateway' => 'sms', 'identifier' => '+5511999999999', 'message' => '123456 is your LibreSign verification code.'],
		], $integrationService->sentMessages);
	}

	public function testSendCodeByEmailSendsCodeAndReturnsHashedCode(): void {
		$this->secureRandom->expects($this->once())
			->method('generate')
			->with(TokenService::TOKEN_LENGTH, ISecureRandom::CHAR_DIGITS)
			->willReturn('123456');
		$this->mailService->expects($this->once())
			->method('sendCodeToSign')
			->with('signer@domain.coop', 'John Doe', '123456');
		$this->hasher->expects($this->once())
			->method('hash')
			->with('123456')
			->willReturn('hashed-code');

		self::assertSame('hashed-code', $this->createService()->sendCodeByEmail('signer@domain.coop', 'John Doe'));
	}

	public function testRegistrationCodeUsesRegistrationEmailAndOnlyReturnsTheHash(): void {
		$this->secureRandom->expects($this->once())->method('generate')
			->with(TokenService::TOKEN_LENGTH, ISecureRandom::CHAR_DIGITS)->willReturn('123456');
		$this->mailService->expects($this->once())->method('sendCodeToRegister')->with('signer@example.com', 'Signer', '123456');
		$this->mailService->expects($this->never())->method('sendCodeToSign');
		$this->hasher->expects($this->once())->method('hash')->with('123456')->willReturn('hashed-code');
		self::assertSame('hashed-code', $this->createService()->sendRegistrationCodeByEmail('signer@example.com', 'Signer'));
	}

	public static function purposes(): array {
		return ['registration' => [true], 'signing' => [false]];
	}

	#[DataProvider('purposes')]
	public function testBothChannelsReceiveExactlyTheSameCode(bool $registration): void {
		$gateway = $this->enableGateway();
		$this->secureRandom->expects($this->once())->method('generate')
			->with(TokenService::TOKEN_LENGTH, ISecureRandom::CHAR_DIGITS)->willReturn('123456');
		$this->mailService->expects($this->once())->method($registration ? 'sendCodeToRegister' : 'sendCodeToSign')
			->with('signer@example.com', 'Signer', '123456');
		$this->mailService->expects($this->never())->method($registration ? 'sendCodeToSign' : 'sendCodeToRegister');
		$this->hasher->expects($this->once())->method('hash')->with('123456')->willReturn('hashed-code');
		self::assertSame('hashed-code', $this->createService()->sendCodeByEmailAndGateway('signer@example.com', 'Signer', '+84901234567', 'sms', $registration));
		self::assertSame([['gateway' => 'sms', 'identifier' => '+84901234567', 'message' => '123456 is your LibreSign verification code.']], $gateway->sentMessages);
	}

	public function testBothChannelsCheckGatewayBeforeSendingEmail(): void {
		$this->appManager->method('isEnabledForAnyone')->willReturn(false);
		$this->mailService->expects($this->never())->method('sendCodeToRegister');
		$this->mailService->expects($this->never())->method('sendCodeToSign');
		$this->secureRandom->expects($this->never())->method('generate');
		$this->hasher->expects($this->never())->method('hash');
		$this->expectException(LibresignException::class);
		$this->createService()->sendCodeByEmailAndGateway('signer@example.com', 'Signer', '+84901234567', 'sms');
	}

	public function testSmsFailureAfterEmailDoesNotReturnAUsableHash(): void {
		$this->enableGateway(failDelivery: true);
		$this->secureRandom->method('generate')->willReturn('123456');
		$this->mailService->expects($this->once())->method('sendCodeToRegister')->with('signer@example.com', 'Signer', '123456');
		$this->hasher->expects($this->never())->method('hash');
		$this->expectException(\RuntimeException::class);
		$this->createService()->sendCodeByEmailAndGateway('signer@example.com', 'Signer', '+84901234567', 'sms', true);
	}

	public function testEmailFailureDoesNotReturnAUsableHashOrSendSms(): void {
		$gateway = $this->enableGateway();
		$this->secureRandom->method('generate')->willReturn('123456');
		$this->mailService->method('sendCodeToSign')->willThrowException(new \RuntimeException('Mail unavailable'));
		$this->hasher->expects($this->never())->method('hash');
		try {
			$this->createService()->sendCodeByEmailAndGateway('signer@example.com', 'Signer', '+84901234567', 'sms');
			$this->fail('Expected mail delivery failure');
		} catch (\RuntimeException) {
			self::assertSame([], $gateway->sentMessages);
		}
	}

	private function enableGateway(bool $failDelivery = false): TokenServiceGatewayIntegrationStub {
		$this->appManager->method('isEnabledForAnyone')->with('twofactor_gateway')->willReturn(true);
		$gateway = new TokenServiceGatewayIntegrationStub(true, $failDelivery);
		$this->container->method('get')->with('OCA\\TwoFactorGateway\\Service\\GatewayDirectIntegrationService')->willReturn($gateway);
		$this->l10n->method('t')->willReturnCallback(static fn ($text, $code) => sprintf($text, $code));
		return $gateway;
	}

	private function createService(): TokenService {
		return new TokenService(
			$this->secureRandom,
			$this->hasher,
			$this->mailService,
			$this->l10n,
			new TwofactorGatewayService(
				$this->container,
				$this->appManager,
				$this->logger,
			),
		);
	}
}

final class TokenServiceGatewayIntegrationStub {
	/** @var list<array{gateway: string, identifier: string, message: string}> */
	public array $sentMessages = [];

	public function __construct(
		private bool $complete,
		private bool $failDelivery = false,
	) {
	}

	public function ensureAvailable(string $gatewayName): void {
	}

	public function isGatewayComplete(string $gatewayName): bool {
		return $this->complete;
	}

	public function send(string $gatewayName, string $identifier, string $message): void {
		if ($this->failDelivery) {
			throw new \RuntimeException('Gateway unavailable');
		}
		$this->sentMessages[] = [
			'gateway' => $gatewayName,
			'identifier' => $identifier,
			'message' => $message,
		];
	}
}
