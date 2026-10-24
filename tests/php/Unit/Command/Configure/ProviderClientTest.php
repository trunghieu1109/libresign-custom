<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Command\Configure;

use OCA\Libresign\Command\Configure\ProviderClient;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ProviderClientTest extends TestCase {
	private array $clients = ['partner-a' => ['enabled' => true, 'scopes' => ['certificates:write'], 'callbackSecret' => 'secret-do-not-print'],
		'partner-b' => ['enabled' => false, 'callbackSecret' => 'another-secret']];
	private CommandTester $command;
	private int $writes = 0;

	protected function setUp(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn () => json_encode($this->clients));
		$config->method('setValueString')->willReturnCallback(function ($app, $key, $value) {
			$this->clients = json_decode($value, true);
			$this->writes++;
			return true;
		});
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$this->command = new CommandTester(new ProviderClient($config, $users));
	}

	public function testSelectedChangesPreserveSecretsScopesAndOtherIntegrations(): void {
		$other = $this->clients['partner-b'];
		$this->assertSame(0, $this->command->execute(['uid' => 'partner-a', '--allow-dossier-reuse' => 'true',
			'--mock-registration' => 'true', '--face-threshold' => '0.7', '--face-scale' => '1']));
		$this->assertTrue($this->clients['partner-a']['edgeDeployment']);
		$this->assertTrue($this->clients['partner-a']['mockRegistrationEnabled']);
		$this->assertSame(['certificates:write'], $this->clients['partner-a']['scopes']);
		$this->assertSame('secret-do-not-print', $this->clients['partner-a']['callbackSecret']);
		$this->assertSame($other, $this->clients['partner-b']);
		$this->assertStringNotContainsString('secret', $this->command->getDisplay());
	}

	public function testInvalidThresholdDoesNotPartiallyApplyOtherOptions(): void {
		$before = $this->clients;
		$this->assertSame(1, $this->command->execute(['uid' => 'partner-a', '--allow-dossier-reuse' => 'true',
			'--face-threshold' => '70', '--face-scale' => '1']));
		$this->assertSame($before, $this->clients);
		$this->assertSame(0, $this->writes);
	}

	public function testInspectingConfigurationDoesNotWriteOrExposeSecrets(): void {
		$this->assertSame(0, $this->command->execute(['uid' => 'partner-a']));
		$this->assertSame(0, $this->writes);
		$this->assertStringNotContainsString('secret', $this->command->getDisplay());
	}

	public function testRequiringFaceMatchNeedsAnExplicitAdapterPolicy(): void {
		$this->assertSame(1, $this->command->execute(['uid' => 'partner-a', '--require-face-match' => 'true']));
		$this->assertSame(0, $this->writes);
	}

	public function testRealUrlCanReplaceTheMockWithoutChangingOtherClientSettings(): void {
		$this->assertSame(0, $this->command->execute(['uid' => 'partner-a', '--mock-registration' => 'false',
			'--registration-base-url' => 'https://adapter.example.test/register']));
		$this->assertSame('https://adapter.example.test/register', $this->clients['partner-a']['registrationBaseUrl']);
		$this->assertFalse($this->clients['partner-a']['mockRegistrationEnabled']);
		$this->assertSame('secret-do-not-print', $this->clients['partner-a']['callbackSecret']);
	}

	public function testEnableBothOtpChannelsAndSwitchBackToEmail(): void {
		$this->assertSame(0, $this->command->execute(['uid' => 'partner-a', '--otp-channel' => 'email_sms', '--otp-gateway' => 'sms']));
		$this->assertSame('email_sms', $this->clients['partner-a']['otpChannel']);
		$this->assertSame('sms', $this->clients['partner-a']['otpGateway']);
		$this->assertSame('secret-do-not-print', $this->clients['partner-a']['callbackSecret']);
		$this->assertStringNotContainsString('secret', $this->command->getDisplay());
		$this->assertSame(0, $this->command->execute(['uid' => 'partner-a', '--otp-channel' => 'email', '--otp-gateway' => '']));
		$this->assertSame('email', $this->clients['partner-a']['otpChannel']);
	}

	public function testSmsRequiresGatewayWithoutPartiallyApplyingOtherOptions(): void {
		$before = $this->clients;
		$this->assertSame(1, $this->command->execute(['uid' => 'partner-a', '--otp-channel' => 'sms', '--mock-registration' => 'true']));
		$this->assertSame($before, $this->clients);
		$this->assertSame(0, $this->writes);
	}

	public function testUnknownOtpChannelCannotBeConfigured(): void {
		$this->assertSame(1, $this->command->execute(['uid' => 'partner-a', '--otp-channel' => 'push']));
		$this->assertSame(0, $this->writes);
	}

	public function testGatewayCannotBeRemovedWhileSmsRemainsEnabled(): void {
		$this->clients['partner-a'] += ['otpChannel' => 'email_sms', 'otpGateway' => 'sms'];
		$this->assertSame(1, $this->command->execute(['uid' => 'partner-a', '--otp-gateway' => '']));
		$this->assertSame(0, $this->writes);
	}

	public function testMockOtpCanBeEnabledAndDisabledWithoutChangingOtherClientsOrDelivery(): void {
		$this->clients['partner-a']['otpChannel'] = 'email';
		$before = $this->clients;
		$this->assertSame(0, $this->command->execute(['uid' => 'partner-a', '--mock-otp' => 'true']));
		$this->assertTrue($this->clients['partner-a']['mockOtpEnabled']);
		$this->assertSame($before['partner-b'], $this->clients['partner-b']);
		$this->assertSame('email', $this->clients['partner-a']['otpChannel']);
		$this->assertSame('secret-do-not-print', $this->clients['partner-a']['callbackSecret']);
		$this->assertStringNotContainsString('secret', $this->command->getDisplay());
		$this->assertSame(0, $this->command->execute(['uid' => 'partner-a', '--mock-otp' => 'false']));
		$this->assertFalse($this->clients['partner-a']['mockOtpEnabled']);
	}

	public function testInvalidMockOtpOptionDoesNotPartiallyApplyChanges(): void {
		$before = $this->clients;
		$this->assertSame(1, $this->command->execute(['uid' => 'partner-a', '--mock-otp' => 'yes', '--otp-channel' => 'email']));
		$this->assertSame($before, $this->clients);
		$this->assertSame(0, $this->writes);
	}
}
