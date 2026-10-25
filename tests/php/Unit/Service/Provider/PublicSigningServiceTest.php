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
	private ProviderArtifactStore $artifacts;
	private array $artifactData = [];
	private int $artifactReads = 0;
	private int $stateBytesWritten = 0;

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
			$this->stateBytesWritten += strlen(json_encode($record['data'], JSON_THROW_ON_ERROR));
			$this->record = $record;
		});
		$store->method('get')->willReturnCallback(fn () => $this->record);
		$artifacts = $this->artifacts = $this->createMock(ProviderArtifactStore::class);
		$artifacts->method('put')->willReturnCallback(function ($session, $name, $content) {
			$this->artifactData[$name] = $content;
			return $name;
		});
		$artifacts->method('read')->willReturnCallback(function ($key) {
			$this->artifactReads++;
			return $this->artifactData[$key];
		});
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

	public function testDocumentResultReadsOnlyTheRequestedArtifactAndPreservesItsModel(): void {
		$this->configure('email');
		$this->record = ['data' => ['documents' => [
			['id' => 'other', 'status' => 'SUCCESS', 'result' => 'unavailable'],
			['id' => 'wanted', 'index' => 1, 'documentName' => 'test.pdf', 'type' => 'PDF', 'status' => 'SUCCESS', 'result' => 'wanted-result', 'outputHash' => 'digest'],
		]]];
		$this->artifactData['wanted-result'] = '%PDF-test';
		$this->artifacts->expects($this->once())->method('read')->with('wanted-result');
		$this->assertSame(['id' => 'wanted', 'index' => 1, 'documentName' => 'test.pdf', 'type' => 'PDF',
			'status' => 'SUCCESS', 'outputHash' => 'digest', 'signedData' => base64_encode('%PDF-test')], $this->service->document($this->client, 'session', 'wanted'));
	}

	public function testMissingDocumentDoesNotReadOtherResults(): void {
		$this->configure('email');
		$this->record = ['data' => ['documents' => [['id' => 'other', 'result' => 'unavailable']]]];
		$this->artifacts->expects($this->never())->method('read');
		$this->expectExceptionCode(404);
		$this->service->document($this->client, 'session', 'missing');
	}

	public function testCompletionWithoutCallbackDoesNotReadResultArtifacts(): void {
		$this->configure('email');
		$this->tokens->method('sendCodeByEmail')->willReturn('hash-123456');
		$this->start(true);
		$this->signer->method('sign')->willReturn('%PDF-test');
		// Two inputs during processing and two outputs for the response; no unused callback payload.
		$this->artifacts->expects($this->exactly(4))->method('read');
		$this->assertSame('SUCCESS', $this->service->confirm($this->client, 'session', ['otpValue' => '123456'])['status']);
	}

	#[DataProvider('batches')]
	public function testAppearanceArtifactsKeepTheSameSignerInputAndRejectTampering(bool $tamper): void {
		$this->configure('email');
		$this->tokens->method('sendCodeByEmail')->willReturn('hash-123456');
		$image = base64_encode('synthetic-image');
		$this->service->start($this->client, 'appearance-key', ['certAlias' => 'certificate', 'fileBase64' => base64_encode('%PDF-test'), 'signatureImageBase64' => $image]);
		$reference = $this->record['data']['documents'][0]['params']['signatureImageBase64'];
		$this->assertIsArray($reference);
		if ($tamper) {
			$this->artifactData[$reference['artifact']] = 'changed';
			$this->signer->expects($this->never())->method('sign');
		} else {
			$this->signer->expects($this->once())->method('sign')->with($this->anything(), 'certificate', '%PDF-test',
				$this->callback(fn ($params) => $params['signatureImageBase64'] === $image))->willReturn('%PDF-test');
		}
		$result = $this->service->confirm($this->client, 'session', ['otpValue' => '123456']);
		$this->assertSame($tamper ? 'FAILED' : 'SUCCESS', $result['status']);
		if ($tamper) {
			$this->assertSame('input_changed', $result['results'][0]['errorCode']);
		}
	}

	public function testPreviouslyAuthorizedInlineAppearanceStillSigns(): void {
		$this->configure('email');
		$this->tokens->method('sendCodeByEmail')->willReturn('hash-123456');
		$this->start();
		$image = base64_encode('legacy-image');
		$this->record['data']['documents'][0]['params']['signatureImageBase64'] = $image;
		$this->record['data']['manifestHash'] = \OCA\Libresign\Service\Provider\ProviderContract::fingerprint(array_map(
			fn ($document) => array_intersect_key($document, array_flip(['id', 'index', 'documentName', 'type', 'params', 'inputHash', 'source'])),
			$this->record['data']['documents']));
		$this->signer->expects($this->once())->method('sign')->with($this->anything(), 'certificate', '%PDF-test',
			$this->callback(fn ($params) => $params['signatureImageBase64'] === $image))->willReturn('%PDF-test');
		$this->assertSame('SUCCESS', $this->service->confirm($this->client, 'session', ['otpValue' => '123456'])['status']);
	}

	public function testUncertainSigningCheckpointIsNeverSignedAgain(): void {
		$this->configure('email');
		$this->tokens->method('sendCodeByEmail')->willReturn('hash-123456');
		$this->start();
		$this->record['data']['documents'][0]['status'] = 'SIGNING';
		$this->signer->expects($this->never())->method('sign');
		$result = $this->service->confirm($this->client, 'session', ['otpValue' => '123456']);
		$this->assertSame('FAILED', $result['status']);
		$this->assertSame('RECOVERY_REQUIRED', $result['results'][0]['status']);
		$this->assertSame('recovery_required', $result['results'][0]['errorCode']);
	}

	public function testChangedManifestIsRejectedBeforeSigning(): void {
		$this->configure('email');
		$this->tokens->method('sendCodeByEmail')->willReturn('hash-123456');
		$this->start();
		$this->record['data']['documents'][0]['params']['reason'] = 'changed after consent';
		$this->signer->expects($this->never())->method('sign');
		$result = $this->service->confirm($this->client, 'session', ['otpValue' => '123456']);
		$this->assertSame('manifest_changed', $result['results'][0]['errorCode']);
	}

	public function testDisablingIntegrationBetweenFilesStopsRemainingSignatures(): void {
		$this->configure('email');
		$this->tokens->method('sendCodeByEmail')->willReturn('hash-123456');
		$this->start(true);
		$this->signer->expects($this->once())->method('sign')->willReturnCallback(function () {
			$this->client['config']['enabled'] = false;
			return '%PDF-test';
		});
		$result = $this->service->confirm($this->client, 'session', ['otpValue' => '123456']);
		$this->assertSame('PARTIAL_SUCCESS', $result['status']);
		$this->assertSame('integration_disabled', $result['results'][1]['errorCode']);
	}

	public function testOriginalAndOptimizedResponsesAndIo(): void {
		$baseline = getenv('LIBRESIGN_PROVIDER_BASELINE_DIR');
		if (!$baseline) {
			$this->markTestSkipped('Optional comparison requires a git archive of the original backend.');
		}
		$source = file_get_contents($baseline . '/lib/Service/Provider/PublicSigningService.php');
		$source = str_replace('class PublicSigningService {', 'class PublicSigningServiceBaseline extends PublicSigningService {', $source);
		eval(substr($source, 5));
		$metrics = [];
		$responses = [];
		foreach (['before', 'after'] as $variant) {
			$this->configure('email');
			$this->tokens->method('sendCodeByEmail')->willReturn('hash-123456');
			$this->signer->method('sign')->willReturn('%PDF-test');
			$service = $this->service;
			if ($variant === 'before') {
				$reflection = new \ReflectionClass(PublicSigningService::class);
				$args = array_map(fn ($p) => $reflection->getProperty($p->getName())->getValue($service), $reflection->getConstructor()->getParameters());
				$legacyClass = PublicSigningService::class . 'Baseline';
				$service = new $legacyClass(...$args);
			}
			$files = array_fill(0, 100, ['fileBase64' => base64_encode('%PDF-test')]);
			$service->start($this->client, 'batch-key', ['certAlias' => 'certificate', 'files' => $files]);
			$this->artifactReads = 0;
			$service->confirm($this->client, 'session', ['otpValue' => '123456']);
			$metrics['complete_100_without_callback'][$variant] = ['artifactReads' => $this->artifactReads];
			$this->artifactReads = 0;
			$responses['document'][$variant] = $service->document($this->client, 'session', '99');
			$metrics['one_result_from_100'][$variant] = ['artifactReads' => $this->artifactReads];
			// Synthetic signer isolates checkpoint serialization from Java/PDF engine cost.
			$files = array_fill(0, 20, ['fileBase64' => base64_encode('%PDF-test'), 'signatureImageBase64' => base64_encode(str_repeat('x', 262144))]);
			$service->start($this->client, 'appearance-batch-key', ['certAlias' => 'certificate', 'files' => $files]);
			$this->stateBytesWritten = 0;
			$start = hrtime(true);
			$responses['appearance'][$variant] = $service->confirm($this->client, 'session', ['otpValue' => '123456']);
			unset($responses['appearance'][$variant]['expiresAt']);
			$metrics['checkpoint_20_with_256k_image'][$variant] = ['serializedBytesWritten' => $this->stateBytesWritten, 'milliseconds' => (hrtime(true) - $start) / 1000000];
		}
		$this->assertSame($responses['document']['before'], $responses['document']['after']);
		$this->assertSame($responses['appearance']['before'], $responses['appearance']['after']);
		$this->assertSame(100, $metrics['one_result_from_100']['before']['artifactReads']);
		$this->assertSame(1, $metrics['one_result_from_100']['after']['artifactReads']);
		$this->assertSame(300, $metrics['complete_100_without_callback']['before']['artifactReads']);
		$this->assertSame(200, $metrics['complete_100_without_callback']['after']['artifactReads']);
		$this->assertLessThan($metrics['checkpoint_20_with_256k_image']['before']['serializedBytesWritten'] / 100,
			$metrics['checkpoint_20_with_256k_image']['after']['serializedBytesWritten']);
		if ($path = getenv('LIBRESIGN_PROVIDER_BENCHMARK_OUTPUT')) {
			file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
		}
	}
}
