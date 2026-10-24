<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderArtifactStore;
use OCA\Libresign\Service\Provider\ProviderCertificateService;
use OCA\Libresign\Service\Provider\ProviderManagementService;
use OCA\Libresign\Service\Provider\ProviderStateStore;
use OCA\Libresign\Service\Provider\PublicRegistrationService;
use OCA\Libresign\Service\Provider\PublicSigningService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProviderManagementServiceTest extends TestCase {
	private ProviderManagementService $service;
	private ProviderStateStore $store;
	private ProviderArtifactStore $artifacts;
	private PublicRegistrationService $registrations;
	private PublicSigningService $signing;
	private ProviderCertificateService $certificates;
	private array $clients;
	private bool $admin = true;
	private bool $authenticated = true;
	private bool $enabled = true;

	protected function setUp(): void {
		$this->clients = ['partner-a' => ['enabled' => true, 'scopes' => ['certificates:write', 'signing:write'], 'otpChannel' => 'email', 'assertionPublicKey' => 'PRIVATE-CONFIG', 'callbackSecret' => 'SECRET']];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn () => json_encode($this->clients));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->authenticated ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn () => $this->admin);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$this->store = $this->createMock(ProviderStateStore::class);
		$this->artifacts = $this->createMock(ProviderArtifactStore::class);
		$this->registrations = $this->createMock(PublicRegistrationService::class);
		$this->signing = $this->createMock(PublicSigningService::class);
		$this->certificates = $this->createMock(ProviderCertificateService::class);
		$this->service = new ProviderManagementService($config, $session, $groups, $users, $this->store, $this->artifacts,
			$this->registrations, $this->certificates, $this->signing, $this->createMock(LoggerInterface::class));
	}

	#[DataProvider('deniedActors')]
	public function testAuthorizationPrecedesReadingAnyWorkflow(bool $authenticated, bool $admin, bool $enabled, int $status): void {
		$this->authenticated = $authenticated;
		$this->admin = $admin;
		$this->enabled = $enabled;
		$this->store->expects($this->never())->method('get');
		$this->artifacts->expects($this->never())->method('read');
		$this->expectException(ProviderException::class);
		$this->expectExceptionCode($status);
		$this->service->pdf('partner-a', 'session', '0', 'result');
	}

	public static function deniedActors(): array {
		return ['anonymous' => [false, false, true, 401], 'ordinary account' => [true, false, true, 403], 'disabled admin' => [true, true, false, 401]];
	}

	public function testClientListExposesOnlySafeMetadata(): void {
		$list = $this->service->clients();
		$this->assertSame('partner-a', $list['items'][0]['uid']);
		$this->assertStringNotContainsString('SECRET', json_encode($list));
		$this->assertStringNotContainsString('PRIVATE-CONFIG', json_encode($list));
	}

	private function record(): array {
		return ['record_id' => 'session', 'status' => 'PENDING', 'created_at' => time() - 30, 'expires_at' => time() - 1,
			'data' => ['input' => ['person' => ['fullName' => 'Test Signer', 'personalIdNumber' => '000000000001'], 'confirmationMethod' => 'service_otp'],
				'codeHash' => 'OTP-SECRET', 'nonce' => 'NONCE', 'documents' => [['id' => '0', 'documentName' => 'test.pdf', 'status' => 'SUCCESS', 'source' => 'INPUT-KEY', 'result' => 'OUTPUT-KEY']]]];
	}

	public function testListingIsTenantScopedAndExcludesInternalSecrets(): void {
		$this->store->expects($this->once())->method('listing')->with('partner-a', 'signing', 2, 20)->willReturn(['items' => [$this->record()], 'totalItem' => 21]);
		$result = $this->service->listing('partner-a', 'signing', 2, 20, '');
		$this->assertSame('EXPIRED', $result['items'][0]['status']);
		$this->assertSame(1, $result['items'][0]['successCount']);
		foreach (['OTP-SECRET', 'NONCE', 'INPUT-KEY', 'OUTPUT-KEY', 'data'] as $secret) {
			$this->assertStringNotContainsString($secret, json_encode($result));
		}
	}

	public function testSigningDetailDoesNotReadArtifactContents(): void {
		$this->store->expects($this->once())->method('get')->with('partner-a', 'signing', 'session')->willReturn($this->record());
		$this->artifacts->expects($this->never())->method('read');
		$result = $this->service->detail('partner-a', 'signing', 'session');
		$this->assertTrue($result['results'][0]['hasSource']);
		$this->assertTrue($result['results'][0]['hasResult']);
		$this->assertArrayNotHasKey('source', $result['results'][0]);
		$this->assertArrayNotHasKey('result', $result['results'][0]);
	}

	public function testEnrollmentListSurfacesNativeRecoveryState(): void {
		$record = $this->record();
		$record['data']['legacySession'] = 'native-session';
		$this->store->method('listing')->with('partner-a', 'enrollment', 1, 20)->willReturn(['items' => [$record], 'totalItem' => 1]);
		$this->certificates->expects($this->once())->method('registration')->with($this->anything(), 'native-session')->willReturn(['status' => 'RECOVERY_REQUIRED']);
		$this->assertSame('RECOVERY_REQUIRED', $this->service->listing('partner-a', 'enrollment', 1, 20, '')['items'][0]['status']);
	}

	public function testUnknownActionDoesNotRunSigning(): void {
		$this->signing->expects($this->never())->method('confirm');
		$this->expectExceptionCode(400);
		$this->service->action('partner-a', 'signing', 'session', 'skip-proof', [], '');
	}

	public function testPdfResolvesDocumentInsideSelectedTenant(): void {
		$this->store->expects($this->once())->method('get')->with('partner-a', 'signing', 'session')->willReturn($this->record());
		$this->artifacts->expects($this->once())->method('read')->with('OUTPUT-KEY')->willReturn('%PDF-fixture');
		$this->assertSame(['content' => '%PDF-fixture', 'name' => 'signed-test.pdf'], $this->service->pdf('partner-a', 'session', '0', 'result'));
	}

	public function testUnknownDocumentCannotSelectAnArtifact(): void {
		$this->store->method('get')->with('partner-a', 'signing', 'session')->willReturn($this->record());
		$this->artifacts->expects($this->never())->method('read');
		$this->expectExceptionCode(404);
		$this->service->pdf('partner-a', 'session', 'other-document', 'source');
	}

	public function testAnotherTenantSessionIsNotRead(): void {
		$this->store->expects($this->once())->method('get')->with('partner-a', 'signing', 'partner-b-session')->willThrowException(new ProviderException('not_found', 'Not in tenant', 404));
		$this->artifacts->expects($this->never())->method('read');
		$this->expectExceptionCode(404);
		$this->service->pdf('partner-a', 'partner-b-session', '0', 'result');
	}

	public function testUnfinishedResultCannotBeDownloaded(): void {
		$record = $this->record();
		$record['data']['documents'][0]['status'] = 'RECOVERY_REQUIRED';
		$this->store->method('get')->willReturn($record);
		$this->artifacts->expects($this->never())->method('read');
		$this->expectExceptionCode(404);
		$this->service->pdf('partner-a', 'session', '0', 'result');
	}

	public function testArtifactColumnCannotBeChosenByCaller(): void {
		$this->store->expects($this->never())->method('get');
		$this->expectExceptionCode(400);
		$this->service->pdf('partner-a', 'session', '0', 'encrypted_data');
	}

	public function testDisabledIntegrationCanBeInspectedButCannotSign(): void {
		$this->clients['partner-a']['enabled'] = false;
		$this->store->method('get')->willReturn($this->record());
		$this->assertSame('EXPIRED', $this->service->detail('partner-a', 'signing', 'session')['status']);
		$this->signing->expects($this->never())->method('confirm');
		$this->expectExceptionCode(403);
		$this->service->action('partner-a', 'signing', 'session', 'confirm', ['otpValue' => '123456'], '');
	}

	public function testAdminCannotWriteWithoutIntegrationScope(): void {
		$this->clients['partner-a']['scopes'] = ['signing:read'];
		$this->signing->expects($this->never())->method('confirm');
		$this->expectExceptionCode(403);
		$this->service->action('partner-a', 'signing', 'session', 'confirm', [], '');
	}

	public function testSigningConfirmationUsesExistingProofChecksAndOmitsPdfPayloads(): void {
		$this->signing->expects($this->once())->method('confirm')->with($this->callback(fn ($client) => $client['uid'] === 'partner-a'), 'session', ['otpValue' => '654321'])
			->willReturn(['status' => 'SUCCESS', 'signedData' => 'BINARY', 'results' => [['id' => '0', 'signedData' => 'BINARY']]]);
		$result = $this->service->action('partner-a', 'signing', 'session', 'confirm', ['otpValue' => '654321'], '');
		$this->assertSame('SUCCESS', $result['status']);
		$this->assertStringNotContainsString('BINARY', json_encode($result));
	}

	public function testRejectedProofIsNotTurnedIntoSuccess(): void {
		$this->signing->method('confirm')->willThrowException(new ProviderException('TOTP-0001', 'Wrong OTP', 403));
		$this->expectExceptionCode(403);
		$this->service->action('partner-a', 'signing', 'session', 'confirm', ['otpValue' => 'wrong'], '');
	}

	public function testCreationRequiresAnIdempotencyKey(): void {
		$this->signing->expects($this->never())->method('start');
		$this->expectExceptionCode(400);
		$this->service->action('partner-a', 'signing', '', 'create', [], '');
	}

	public function testEnrollmentOtpDoesNotAutomaticallyIssueCertificate(): void {
		$this->registrations->expects($this->once())->method('action')->with($this->anything(), 'session', 'otp', '654321')->willReturn(['status' => 'PENDING', 'step' => 'VERIFIED']);
		$this->assertSame('VERIFIED', $this->service->action('partner-a', 'enrollment', 'session', 'otp', ['proof' => '654321'], '')['step']);
	}

	public function testUnknownIntegrationIsRejected(): void {
		$this->store->expects($this->never())->method('get');
		$this->expectExceptionCode(404);
		$this->service->detail('unknown', 'signing', 'session');
	}

	private function manualRecord(): array {
		return ['record_id' => 'enrollment', 'status' => 'PENDING', 'expires_at' => time() + 900,
			'data' => ['step' => 'AWAITING_DOSSIER', 'dossier' => null, 'legacySession' => null,
				'input' => ['reusePreviousDossier' => false, 'person' => ['fullName' => 'Test Signer', 'personalIdNumber' => '000000000001']]]];
	}

	private function manualPayload(): array {
		return ['address' => 'Địa chỉ thử', 'dateOfBirth' => '1990-01-01', 'issueDate' => '2021-01-01', 'expiredDate' => '2030-01-01',
			'sex' => 'Nam', 'email' => 'test@example.test', 'phone' => '0912345678', 'testDataConsent' => true];
	}

	public function testManualDossierUsesStoredIdentityAndServerMarkedTestReceipt(): void {
		$this->clients['partner-a']['mockRegistrationEnabled'] = true;
		$this->store->expects($this->once())->method('get')->with('partner-a', 'enrollment', 'enrollment')->willReturn($this->manualRecord());
		$this->registrations->expects($this->once())->method('receive')->with($this->callback(fn ($client) => $client['uid'] === 'partner-a'), 'enrollment',
			$this->callback(function ($body): bool {
				$this->assertSame('000000000001', $body['identifyNumber']);
				$this->assertSame('Test Signer', $body['name']);
				$this->assertSame('MANUAL_TEST', $body['verification']['method']);
				$this->assertSame('partner-a', $body['verification']['source']);
				$this->assertStringStartsWith('manual-test:', $body['verification']['reference']);
				$this->assertArrayNotHasKey('testDataConsent', $body);
				return true;
			}), false)->willReturn(['session' => ['status' => 'PENDING', 'nextAction' => 'VERIFY_OTP']]);
		$this->assertSame('VERIFY_OTP', $this->service->action('partner-a', 'enrollment', 'enrollment', 'manual-identity', $this->manualPayload(), '')['nextAction']);
	}

	#[DataProvider('manualDossierRejections')]
	public function testManualDossierEnforcesTestModeAuthorizationAndImmutableState(string $scenario, int $status): void {
		$this->clients['partner-a']['mockRegistrationEnabled'] = true;
		$record = $this->manualRecord();
		$payload = $this->manualPayload();
		switch ($scenario) {
			case 'mock disabled': $this->clients['partner-a']['mockRegistrationEnabled'] = false; break;
			case 'integration disabled': $this->clients['partner-a']['enabled'] = false; break;
			case 'scope missing': $this->clients['partner-a']['scopes'] = []; break;
			case 'ordinary user': $this->admin = false; break;
			case 'source not trusted': $this->clients['partner-a']['trustedSources'] = ['hardware-adapter']; break;
			case 'consent missing': $payload['testDataConsent'] = false; break;
			case 'receipt spoofed': $payload['verification'] = ['method' => 'CARD_READER']; break;
			case 'identity spoofed': $payload['identifyNumber'] = '999999999999'; break;
			case 'expired': $record['expires_at'] = time() - 1; break;
			case 'closed': $record['status'] = 'ACTIVE'; break;
			case 'reusing dossier': $record['data']['input']['reusePreviousDossier'] = true; break;
			case 'face match required': $record['data']['step'] = 'FACE_MATCH_REQUIRED'; break;
			case 'adapter dossier frozen': $record['data']['dossier'] = ['verification' => ['method' => 'CARD_READER']]; break;
		}
		$this->store->method('get')->willReturn($record);
		$this->registrations->expects($this->never())->method('receive');
		$this->expectException(ProviderException::class);
		$this->expectExceptionCode($status);
		$this->service->action('partner-a', 'enrollment', 'enrollment', 'manual-identity', $payload, '');
	}

	public static function manualDossierRejections(): array {
		return [
			['mock disabled', 403], ['integration disabled', 403], ['scope missing', 403], ['ordinary user', 403], ['source not trusted', 403],
			['consent missing', 400], ['receipt spoofed', 400], ['identity spoofed', 400], ['expired', 410], ['closed', 409],
			['reusing dossier', 409], ['face match required', 409], ['adapter dossier frozen', 409],
		];
	}

	public function testManualRetryPreservesTheOriginalReceiptInsteadOfChangingItsTimestamp(): void {
		$this->clients['partner-a']['mockRegistrationEnabled'] = true;
		$record = $this->manualRecord();
		$receipt = ['method' => 'MANUAL_TEST', 'source' => 'partner-a', 'reference' => 'manual-test:original', 'verifiedAt' => '2026-01-01T00:00:00Z'];
		$record['data']['dossier'] = ['verification' => $receipt];
		$record['data']['step'] = 'AWAITING_CONFIRMATION';
		$this->store->method('get')->willReturn($record);
		$this->registrations->expects($this->once())->method('receive')->with($this->anything(), 'enrollment',
			$this->callback(fn ($body) => $body['verification'] === $receipt), false)->willReturn(['session' => ['status' => 'PENDING']]);
		$this->assertSame('PENDING', $this->service->action('partner-a', 'enrollment', 'enrollment', 'manual-identity', $this->manualPayload(), '')['status']);
	}

	public function testEnrollmentDetailAdvertisesManualInputOnlyForExplicitTestMode(): void {
		$this->clients['partner-a']['mockRegistrationEnabled'] = true;
		$this->store->method('get')->willReturn($this->manualRecord());
		$this->registrations->method('registration')->willReturn(['status' => 'PENDING', 'nextAction' => 'SUBMIT_IDENTITY']);
		$this->assertTrue($this->service->detail('partner-a', 'enrollment', 'enrollment')['manualDossierAllowed']);
		$this->clients['partner-a']['mockRegistrationEnabled'] = false;
		$this->assertFalse($this->service->detail('partner-a', 'enrollment', 'enrollment')['manualDossierAllowed']);
	}

	public function testEnrollmentDetailLabelsTestOriginAndOmitsEvidenceContents(): void {
		$record = $this->manualRecord();
		$record['data']['dossier'] = ['verification' => ['method' => 'MANUAL_TEST'],
			'fields' => ['address' => 'Test address', 'imageCap' => 'EVIDENCE-SECRET'], 'evidence' => ['key' => 'ENCRYPTED-ARTIFACT']];
		$this->store->method('get')->willReturn($record);
		$this->registrations->method('registration')->willReturn(['status' => 'PENDING', 'nextAction' => 'VERIFY_OTP']);
		$result = $this->service->detail('partner-a', 'enrollment', 'enrollment');
		$this->assertSame('manual_test', $result['dossierOrigin']);
		$this->assertSame(['address' => 'Test address'], $result['dossierFields']);
		$this->assertStringNotContainsString('EVIDENCE-SECRET', json_encode($result));
		$this->assertStringNotContainsString('ENCRYPTED-ARTIFACT', json_encode($result));
	}
}
