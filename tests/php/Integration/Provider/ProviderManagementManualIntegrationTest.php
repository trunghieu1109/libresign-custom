<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Integration\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderArtifactStore;
use OCA\Libresign\Service\Provider\ProviderCertificateService;
use OCA\Libresign\Service\Provider\ProviderManagementService;
use OCA\Libresign\Service\Provider\ProviderStateStore;
use OCA\Libresign\Service\Provider\PublicRegistrationService;
use OCA\Libresign\Service\Provider\PublicSigningService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Actual issuance only in the private SQLite/data/CA harness, never serving data. */
class ProviderManagementManualIntegrationTest extends TestCase {
	protected function setUp(): void {
		$config = Server::get(IConfig::class);
		if (getenv('LIBRESIGN_MANAGEMENT_MANUAL_TEST') !== '1'
			|| !str_starts_with($config->getSystemValueString('instanceid'), 'providertest')
			|| !str_starts_with($config->getSystemValueString('datadirectory'), '/tmp/provider-integration-management-manual-')) {
			$this->markTestSkipped('Requires a private prepare-isolated.php snapshot and CA dedicated to this test.');
		}
	}

	public function testManualTestIdentityUsesRealValidationOtpAndIssuanceServices(): void {
		$uid = 'manual-test-' . bin2hex(random_bytes(5));
		$users = Server::get(IUserManager::class);
		$users->createUser($uid, bin2hex(random_bytes(24)));
		$clientConfig = ['enabled' => true, 'scopes' => ['certificates:read', 'certificates:write', 'certificates:revoke'],
			'otpChannel' => 'email', 'mockRegistrationEnabled' => true, 'mockOtpEnabled' => true, 'trustedSources' => [$uid], 'edgeDeployment' => true];
		$appConfig = Server::get(IAppConfig::class);
		$clients = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true);
		$clients[$uid] = $clientConfig;
		$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('isolated-management-admin');
		$admin->method('isEnabled')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($admin);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('isolated-management-admin')->willReturn(true);
		$store = Server::get(ProviderStateStore::class);
		$service = new ProviderManagementService($appConfig, $session, $groups, $users, $store, Server::get(ProviderArtifactStore::class),
			Server::get(PublicRegistrationService::class), Server::get(ProviderCertificateService::class), Server::get(PublicSigningService::class), Server::get(LoggerInterface::class));
		$registration = $service->action($uid, 'enrollment', '', 'create', ['personalIdNumber' => '990000000001', 'fullName' => 'Manual Dossier Test',
			'email' => $uid . '@example.test', 'phone' => '0912345678', 'registrationMethod' => 'CARD_READER', 'confirmationMethod' => 'service_otp'], 'manual-registration-test');
		$id = $registration['sessionId'];
		$this->assertSame('SUBMIT_IDENTITY', $registration['nextAction']);
		$this->assertTrue($service->detail($uid, 'enrollment', $id)['manualDossierAllowed']);
		$payload = ['address' => 'Synthetic address', 'dateOfBirth' => '1990-01-01', 'issueDate' => '2021-01-01', 'expiredDate' => '2030-01-01',
			'sex' => 'Nam', 'email' => $uid . '@example.test', 'phone' => '0912345678', 'testDataConsent' => true];
		try {
			$service->action($uid, 'enrollment', $id, 'manual-identity', array_replace($payload, ['expiredDate' => '2020-01-01']), '');
			$this->fail('Expired identity documents must be rejected.');
		} catch (ProviderException $e) {
			$this->assertSame('SYS-0058', $e->error);
			$this->assertNull($store->get($uid, 'enrollment', $id)['data']['dossier']);
		}
		$accepted = $service->action($uid, 'enrollment', $id, 'manual-identity', $payload, '');
		$this->assertSame('VERIFY_OTP', $accepted['nextAction']);
		$record = $store->get($uid, 'enrollment', $id);
		$this->assertSame('MANUAL_TEST', $record['data']['dossier']['verification']['method']);
		$receipt = $record['data']['dossier']['verification'];
		$service->action($uid, 'enrollment', $id, 'manual-identity', $payload, '');
		$this->assertSame($receipt, $store->get($uid, 'enrollment', $id)['data']['dossier']['verification']);
		try {
			$service->action($uid, 'enrollment', $id, 'manual-identity', array_replace($payload, ['address' => 'Different address']), '');
			$this->fail('Accepted dossiers cannot be replaced.');
		} catch (ProviderException $e) {
			$this->assertSame('dossier_frozen', $e->error);
		}
		try {
			$service->action($uid, 'enrollment', $id, 'otp', ['proof' => 'not-an-otp'], '');
			$this->fail('Manual input must not bypass OTP.');
		} catch (ProviderException $e) {
			$this->assertSame(403, $e->getCode());
		}
		$verified = $service->action($uid, 'enrollment', $id, 'otp', ['proof' => '123456'], '');
		$this->assertSame('PENDING', $verified['status']);
		$this->assertSame('CONFIRM', $verified['nextAction']);
		$issued = $service->action($uid, 'enrollment', $id, 'confirm', [], '');
		$this->assertSame('ACTIVE', $issued['status']);
		$this->assertSame('ACTIVE', $issued['certificate']['status']);
		$detail = $service->detail($uid, 'enrollment', $id);
		$this->assertSame('manual_test', $detail['dossierOrigin']);
		$this->assertSame('Synthetic address', $detail['dossierFields']['address']);
		$this->assertFalse($detail['manualDossierAllowed']);
		$chain = $service->publicCertificate($uid, $issued['certificate']['certAlias']);
		$this->assertStringContainsString('BEGIN CERTIFICATE', $chain['chain'][0]);
	}
}
