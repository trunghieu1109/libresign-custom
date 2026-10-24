<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderAccessService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;

class ProviderAccessServiceTest extends TestCase {
	#[DataProvider('clients')]
	public function testOnlyExplicitlyEnabledClientsWithTheRequiredScopeCanAccess(?array $config, bool $enabled, int $status): void {
		$appConfig = self::getMockAppConfig();
		$appConfig->setValueString('libresign', 'provider_clients', json_encode($config === null ? [] : ['partner-a' => $config]));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('partner-a');
		$user->method('isEnabled')->willReturn($enabled);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$service = new ProviderAccessService($appConfig, $session);
		if ($status !== 200) {
			$this->expectException(ProviderException::class);
			$this->expectExceptionCode($status);
		}
		$this->assertSame('partner-a', $service->requireClient('certificates:write')['uid']);
	}

	public static function clients(): array {
		return [
			'ordinary Nextcloud user' => [null, true, 403],
			'disabled integration' => [['enabled' => false, 'scopes' => ['certificates:write']], true, 403],
			'read only integration' => [['enabled' => true, 'scopes' => ['certificates:read']], true, 403],
			'disabled account' => [['enabled' => true, 'scopes' => ['certificates:write']], false, 401],
			'allowed integration' => [['enabled' => true, 'scopes' => ['certificates:write']], true, 200],
		];
	}

	public function testAnonymousAccessIsDenied(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->expectExceptionCode(401);
		(new ProviderAccessService(self::getMockAppConfig(), $session))->requireClient('certificates:read');
	}
}
