<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderRegistrationLinkService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class ProviderRegistrationLinkServiceTest extends TestCase {
	private ProviderRegistrationLinkService $service;
	private array $client = ['uid' => 'partner-a', 'config' => ['enabled' => true, 'mockRegistrationEnabled' => true, 'scopes' => ['certificates:write']]];
	private bool $enabled = true;

	protected function setUp(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(fn ($value) => hash_hmac('sha512', $value, 'fixture-secret', true));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(fn ($route, $params) => 'http://localhost/mock?' . http_build_query($params));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn () => json_encode(['partner-a' => $this->client['config']]));
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('partner-a')->willReturn($user);
		$this->service = new ProviderRegistrationLinkService($crypto, $urls, $config, $users);
	}

	private function token(?int $expiry = null): string {
		$link = $this->service->link($this->client, ['record_id' => 'fixture-enrollment', 'expires_at' => $expiry ?? time() + 60]);
		parse_str(parse_url($link['registrationUrl'], PHP_URL_QUERY), $query);
		return $query['token'];
	}

	public function testMockLinkIsBoundToItsIntegrationSessionAndExpiry(): void {
		$expires = time() + 60;
		$resolved = $this->service->resolve($this->token($expires));
		$this->assertSame('partner-a', $resolved['client']['uid']);
		$this->assertSame('fixture-enrollment', $resolved['sessionId']);
		$this->assertSame($expires, $resolved['expiresAt']);
	}

	public function testChangingThePayloadCannotSwitchTheSessionOrTenant(): void {
		$token = $this->token();
		[$payload, $signature] = explode('.', $token);
		$data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
		$data['client'] = 'partner-b';
		$data['session'] = 'someone-elses-enrollment';
		$payload = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
		$this->expectExceptionCode(403);
		$this->service->resolve($payload . '.' . $signature);
	}

	public function testExpiredLinksCannotReadOrConfirmEnrollments(): void {
		$this->expectExceptionCode(410);
		$this->service->resolve($this->token(time() - 1));
	}

	public function testDisablingMockRevokesPreviouslyIssuedLinks(): void {
		$token = $this->token();
		$this->client['config']['mockRegistrationEnabled'] = false;
		$this->expectExceptionCode(403);
		$this->service->resolve($token);
	}

	public function testRemovingWriteScopeRevokesPreviouslyIssuedLinks(): void {
		$token = $this->token();
		$this->client['config']['scopes'] = [];
		$this->expectExceptionCode(403);
		$this->service->resolve($token);
	}

	public function testDisablingTheMachineAccountRevokesPreviouslyIssuedLinks(): void {
		$token = $this->token();
		$this->enabled = false;
		$this->expectExceptionCode(403);
		$this->service->resolve($token);
	}

	public function testMalformedScopeConfigurationCannotAuthorizeThePublicPage(): void {
		$token = $this->token();
		$this->client['config']['scopes'] = 'certificates:write';
		$this->expectExceptionCode(403);
		$this->service->resolve($token);
	}

	public function testExternalHttpsPageOverridesTheMockAndPreservesItsQuery(): void {
		$this->client['config']['registrationBaseUrl'] = 'https://adapter.example.test/register?source=his';
		$link = $this->service->link($this->client, ['record_id' => 'fixture', 'expires_at' => time() + 60]);
		$this->assertSame('adapter', $link['registrationMode']);
		$this->assertSame('https://adapter.example.test/register?source=his&sessionId=fixture', $link['registrationUrl']);
		$this->assertStringNotContainsString('token=', $link['registrationUrl']);
	}

	public function testQrRequiresAnExternalPageOrExplicitMockOptIn(): void {
		$this->client['config']['mockRegistrationEnabled'] = false;
		$this->expectExceptionCode(503);
		$this->service->validate($this->client);
	}

	public function testExternalPageCannotUseAnUnsafeSchemeOrFragment(): void {
		foreach (['http://adapter.example.test/register', 'javascript:alert(1)', 'https://user:password@example.test/register', 'https://example.test/register#fragment'] as $url) {
			$this->client['config']['registrationBaseUrl'] = $url;
			try {
				$this->service->validate($this->client);
				$this->fail('Expected invalid URL.');
			} catch (ProviderException $e) {
				$this->assertSame(503, $e->getCode());
			}
		}
	}
}
