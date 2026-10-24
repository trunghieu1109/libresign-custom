<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Integration\Provider;

use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCA\Libresign\Db\ProviderRegistrationMapper;
use OCA\Libresign\Service\Crl\CrlService;
use OCA\Libresign\Service\Provider\ProviderStateStore;
use OCA\Libresign\Service\Signature\PdfSignatureValidationService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Opt-in HTTP tests using temporary integrations, actual OpenSSL/JSignPdf and a test inbox. */
class PublicProviderApiIntegrationTest extends TestCase {
	private string $uid;
	private string $password;
	private string $appPassword;
	private array $config;
	private array $publicSessions = [];
	private bool $mockOtp = false;

	private function request(string $method, string $path, ?array $body = null, ?string $key = null, ?string $uid = null): array {
		$url = rtrim(getenv('LIBRESIGN_PROVIDER_TEST_BASE_URL'), '/') . '/index.php/apps/libresign/api/v1/public/' . $path;
		$handle = curl_init($url);
		$headers = ['Host: ' . getenv('LIBRESIGN_PROVIDER_TEST_HOST'), 'Accept: application/json', 'Content-Type: application/json'];
		if ($key !== null) {
			$headers[] = 'Idempotency-Key: ' . $key;
		}
		curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 90]);
		if ($uid !== 'anonymous') {
			curl_setopt($handle, CURLOPT_USERPWD, ($uid ?? $this->uid) . ':' . ($uid === null ? $this->appPassword : $this->password));
		}
		if ($body !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
		}
		$raw = curl_exec($handle);
		$this->assertIsString($raw, 'HTTP request failed');
		$status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
		curl_close($handle);
		$decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
		$this->assertArrayNotHasKey('ocs', $decoded);
		$this->assertArrayHasKey('transId', $decoded);
		$this->assertIsBool($decoded['status']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $decoded['timestamp']);
		if ($status >= 400) {
			$this->assertNull($decoded['data']);
			$this->assertIsString($decoded['error']['code']);
		}
		return [$status, $decoded];
	}

	/** Advance only a synthetic fixture's resend clock instead of sleeping or changing server limits. */
	private function allowResend(string $id, bool $registration): void {
		$store = Server::get(ProviderStateStore::class);
		$record = $store->get($this->uid, $registration ? 'enrollment' : 'signing', $id);
		if (!$registration) {
			$record['data']['lastCodeAt'] = time() - 61;
			$store->save($record);
			return;
		}
		$mapper = Server::get(ProviderRegistrationMapper::class);
		$legacy = $mapper->findForClient($this->uid, $record['data']['legacySession']);
		$crypto = Server::get(ICrypto::class);
		$state = json_decode($crypto->decrypt($legacy->getEncryptedData()), true, 128, JSON_THROW_ON_ERROR);
		$state['lastCodeAt'] = time() - 61;
		$legacy->setEncryptedData($crypto->encrypt(json_encode($state, JSON_THROW_ON_ERROR)));
		$mapper->saveState($legacy);
	}

	private function ok(string $method, string $path, ?array $body = null, ?string $key = null, int $expected = 200): array {
		[$status, $response] = $this->request($method, $path, $body, $key);
		$this->assertSame($expected, $status, json_encode($response));
		$this->assertTrue($response['status'], json_encode($response));
		return $response['data'];
	}

	private function otp(string $email): string {
		$base = rtrim(getenv('LIBRESIGN_PROVIDER_TEST_MAILPIT'), '/');
		$messages = json_decode(file_get_contents($base . '/api/v1/search?query=' . rawurlencode('to:' . $email)), true, 32, JSON_THROW_ON_ERROR);
		$this->assertNotEmpty($messages['messages'], 'Expected an email OTP in Mailpit');
		$message = json_decode(file_get_contents($base . '/api/v1/message/' . $messages['messages'][0]['ID']), true, 32, JSON_THROW_ON_ERROR);
		$this->assertSame(1, preg_match('/\b([0-9]{6})\b/', $message['Text'], $matches));
		if ($this->mockOtp) {
			$code = str_pad((string)(((int)$matches[1] + 1) % 1000000), 6, '0', STR_PAD_LEFT);
			$this->assertNotSame($matches[1], $code, 'Mock fixture must submit a different code from the delivered email.');
			return $code;
		}
		return $matches[1];
	}

	public static function otpModes(): array {
		return ['real email OTP' => [false], 'mock six-digit OTP' => [true]];
	}

	private function assertSigned(string $base64, string $fingerprint, int $minimumSignatures = 1): void {
		$content = base64_decode($base64, true);
		$this->assertStringStartsWith('%PDF-', $content);
		$valid = 0;
		foreach (Server::get(PdfSignatureValidationService::class)->validateFromString($content) as $result) {
			foreach ($result['certificates'] as $certificate) {
				if (openssl_x509_fingerprint($certificate, 'sha256') === $fingerprint && $result['signatureValidation']['isValid']) {
					$valid++;
				}
			}
		}
		$this->assertGreaterThanOrEqual($minimumSignatures, $valid, 'Expected valid signatures from the selected managed certificate');
	}

	#[DataProvider('otpModes')]
	public function testRegistrationReuseEmailAndAdapterSigningBatchAndTenantIsolation(bool $mockOtp): void {
		$this->mockOtp = $mockOtp;
		if (!getenv('LIBRESIGN_PROVIDER_TEST_BASE_URL') || !getenv('LIBRESIGN_PROVIDER_TEST_HOST') || !getenv('LIBRESIGN_PROVIDER_TEST_MAILPIT')) {
			$this->markTestSkipped('Requires isolated configured OpenSSL/JSignPdf runtime and Mailpit.');
		}
		if (!str_starts_with(Server::get(IConfig::class)->getSystemValueString('instanceid'), 'providertest')) {
			$this->markTestSkipped('Use prepare-isolated.php and NEXTCLOUD_CONFIG_DIR; never run fixture mutations on live data.');
		}
		$this->uid = 'public-provider-test-' . bin2hex(random_bytes(5));
		$this->password = bin2hex(random_bytes(24));
		$other = $this->uid . '-other';
		$users = Server::get(IUserManager::class);
		$appConfig = Server::get(IAppConfig::class);
		$key = openssl_pkey_new(['private_key_bits' => 2048]);
		$this->config = ['enabled' => true, 'scopes' => ['certificates:read', 'certificates:write', 'certificates:revoke', 'signing:read', 'signing:write'],
			'otpChannel' => 'email', 'mockOtpEnabled' => $mockOtp, 'assertionPublicKey' => openssl_pkey_get_details($key)['key'], 'edgeDeployment' => true,
			'reuseFaceThreshold' => 0.7, 'reuseFaceScale' => 1, 'registrationBaseUrl' => 'https://adapter.example.test/register',
			'callbackHosts' => ['adapter.example.test'], 'callbackSecret' => bin2hex(random_bytes(32))];
		$users->createUser($this->uid, $this->password);
		$users->createUser($other, $this->password);
		$this->appPassword = bin2hex(random_bytes(36));
		Server::get(IProvider::class)->generateToken($this->appPassword, $this->uid, $this->uid, $this->password,
			'Isolated provider HTTP test', IToken::PERMANENT_TOKEN, IToken::DO_NOT_REMEMBER);
		try {
			$this->assertSame(401, $this->request('GET', 'certificates/packages', uid: 'anonymous')[0]);
			$this->assertSame(403, $this->request('GET', 'certificates/packages')[0]);
			$appConfig->clearCache();
			$clients = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true);
			$clients[$this->uid] = $this->config;
			$clients[$other] = $this->config;
			$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
			$packages = $this->ok('GET', 'certificates/packages');
			$this->assertSame('internal-default', $packages[0]['code']);
			$this->assertSame(['PDF'], $this->ok('GET', 'capabilities')['documentTypes']);
			$this->assertSame('DEFAULT_PDF', $this->ok('GET', 'signing-profiles')[0]['code']);
			[$status, $page] = $this->request('GET', 'signing-profiles?pageIndex=2&pageSize=1');
			$this->assertSame(200, $status);
			$this->assertSame([], $page['data']);
			$this->assertSame(1, $page['totalItem']);
			$this->assertSame(400, $this->request('GET', 'signing-profiles?pageIndex=0')[0]);
			$input = ['personalIdNumber' => '001234567890', 'fullName' => 'Nguyễn Văn API', 'phone' => '0912345678',
				'email' => $this->uid . '@example.test', 'registrationMethod' => 'CARD_READER', 'packageCode' => 'internal-default',
				'callbackUrl' => 'https://adapter.example.test/certificate'];
			// Opening an enrollment must accept SMS modes without attempting delivery before a dossier exists.
			foreach (['sms', 'email_sms'] as $index => $channel) {
				$clients[$this->uid]['otpChannel'] = $channel;
				$clients[$this->uid]['otpGateway'] = 'sms';
				$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
				$capabilities = $this->ok('GET', 'capabilities');
				$this->assertSame($channel, $capabilities['otpChannel']);
				$this->assertSame(['email', 'sms', 'email_sms'], $capabilities['supportedOtpChannels']);
				$modeInput = array_replace($input, ['personalIdNumber' => '00123456788' . $index]);
				if ($channel === 'sms') {
					$modeInput['email'] = '';
				} else {
					$missingPhone = array_replace($modeInput, ['phone' => '']);
					$this->assertSame(400, $this->request('POST', 'certificates/registration', $missingPhone, 'public-otp-missing-phone')[0]);
				}
				$modeSession = $this->ok('POST', 'certificates/registration', $modeInput, 'public-otp-mode-' . $channel);
				$this->publicSessions[] = $modeSession['sessionId'];
				$this->assertSame('SUBMIT_IDENTITY', $modeSession['nextAction']);
				$this->assertSame($channel, $modeSession['otpChannel']);
				$this->assertFalse($modeSession['otpRequired']);
			}
			$clients[$this->uid] = $this->config;
			$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
			$this->assertSame(400, $this->request('POST', 'certificates/registration', $input)[0]);
			$registration = $this->ok('POST', 'certificates/registration', $input, 'public-registration-001');
			$id = $registration['sessionId'];
			$this->publicSessions[] = $id;
			$this->assertSame('AWAITING_DOSSIER', $registration['step']);
			$this->assertSame($id, $this->ok('POST', 'certificates/registration', $input, 'public-registration-retry')['sessionId']);
			$this->assertSame(409, $this->request('POST', "registration-sessions/$id/confirm", [], 'public-confirm-no-dossier')[0]);
			$pdfPath = getenv('LIBRESIGN_PROVIDER_TEST_PDF_PATH') ?: __DIR__ . '/../../fixtures/pdfs/small_valid.pdf';
			$png = base64_encode(file_get_contents($pdfPath));
			$dossier = ['identifyNumber' => $input['personalIdNumber'], 'name' => $input['fullName'], 'address' => 'Hà Nội',
				'dateOfBirth' => '01/01/1990', 'issueDate' => '01/01/2021', 'expiredDate' => '01/01/2030', 'sex' => 'Nam',
				'isPass' => true, 'score' => 95, 'sodData' => base64_encode('synthetic-chip-integrity-data'),
				'nationality' => 'Việt Nam', 'nation' => 'Kinh', 'religion' => 'Không', 'hometown' => 'Hà Nội',
				'character' => 'Synthetic fixture', 'fatherName' => 'Test Father', 'motherName' => 'Test Mother', 'partnerName' => 'Test Spouse',
				'mrz' => "IDVNM001234567890<<<<<<<<<<<<<<\n9001011M3001019VNM<<<<<<<<<<<\nNGUYEN<<VAN<API<<<<<<<<<<<<<<",
				'dg1DataBase64' => base64_encode('DG1 fixture'), 'dg2DataBase64' => base64_encode('DG2 fixture'),
				'dg13DataBase64' => base64_encode('DG13 fixture'), 'dg14DataBase64' => base64_encode('DG14 fixture'),
				'phone' => $input['phone'], 'email' => $input['email']];
			$this->assertSame(400, $this->request('POST', "registration-sessions/$id/card-reader/info", $dossier)[0]);
			$dossier['imageCap'] = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACAQAAAABazTCJAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';
			$dossier['imageChip'] = $dossier['imageCap'];
			$invalidReceipt = $dossier;
			$invalidReceipt['verification'] = ['verifiedAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)];
			$this->assertSame(400, $this->request('POST', "registration-sessions/$id/card-reader/info", $invalidReceipt)[0]);
			$received = $this->ok('POST', "registration-sessions/$id/card-reader/info", $dossier);
			$this->assertSame($input['personalIdNumber'], $received['session']['personalIdNumber']);
			$this->assertSame($input['fullName'], $received['session']['fullName']);
			$this->assertSame('+84912345678', $received['session']['phone']);
			$this->assertSame('1990-01-01', $received['session']['birthDate']);
			$this->assertSame('Hà Nội', $received['session']['address']);
			$this->assertSame('VERIFY_OTP', $received['session']['nextAction']);
			$this->assertTrue($received['session']['otpRequired']);
			$this->assertNotNull($received['session']['otpExpiresAt']);
			$this->assertNull($received['session']['registrationUrl']);
			$this->assertNull($received['certificate']);
			$stored = Server::get(ProviderStateStore::class)->get($this->uid, 'enrollment', $id);
			$this->assertSame('Việt Nam', $stored['data']['dossier']['fields']['nationality']);
			$this->assertSame('DG14 fixture', Server::get(\OCA\Libresign\Service\Provider\ProviderArtifactStore::class)
				->read($stored['data']['dossier']['evidence']['dg14DataBase64']['artifact']));
			$changedContact = $dossier;
			$changedContact['email'] = 'changed-after-acceptance@example.test';
			$this->assertSame(409, $this->request('POST', "registration-sessions/$id/card-reader/info", $changedContact)[0]);
			$this->assertSame(429, $this->request('POST', "registration-sessions/$id/otp/resend", [])[0]);
			$code = $this->otp($input['email']);
			$this->assertSame(403, $this->request('POST', "registration-sessions/$id/otp", ['otpCode' => '12345'])[0]);
			if ($mockOtp) {
				$clients[$this->uid]['mockOtpEnabled'] = false;
				$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
				$this->assertSame(403, $this->request('POST', "registration-sessions/$id/otp", ['otpCode' => $code])[0]);
				$clients[$this->uid]['mockOtpEnabled'] = true;
				$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
			}
			$this->assertSame('VERIFIED', $this->ok('POST', "registration-sessions/$id/otp", ['otpCode' => $code])['step']);
			$this->allowResend($id, true);
			$this->assertSame('AWAITING_CONFIRMATION', $this->ok('POST', "registration-sessions/$id/otp/resend", [])['step']);
			$this->assertSame(403, $this->request('POST', "registration-sessions/$id/confirm", [], 'public-confirm-after-resend')[0]);
			$this->ok('POST', "registration-sessions/$id/otp", ['otpCode' => $this->otp($input['email'])]);
			$active = $this->ok('POST', "registration-sessions/$id/confirm", [], 'public-confirm-001');
			$alias = $active['certificate']['certAlias'];
			$fingerprint = $active['certificate']['fingerprintSha256'];
			$this->assertSame('ACTIVE', $active['status']);
			$this->assertSame('USE_CERTIFICATE', $active['nextAction']);
			$this->assertFalse($active['otpRequired']);
			$this->assertSame($alias, $this->ok('POST', "registration-sessions/$id/confirm", [], 'public-confirm-retry')['certificate']['certAlias']);
			$this->assertSame($alias, $this->ok('GET', "certificates/registration/$id")['certificate']['certAlias']);
			$this->assertTrue($this->ok('POST', 'certificates/registration', $input, 'public-registration-existing')['reusedCertificate']);
			$this->assertCount(1, $this->ok('GET', 'certificates?personalIdNumber=' . $input['personalIdNumber']));
			$this->assertCount(1, $this->ok('GET', 'certificates/mine?pageIndex=1&pageSize=20'));
			$this->assertSame([], $this->ok('GET', 'certificates/mine?pageIndex=2&pageSize=20'));
			[$status, $page] = $this->request('GET', 'certificates?personalIdNumber=' . $input['personalIdNumber'] . '&pageIndex=2&pageSize=1');
			$this->assertSame([], $page['data']);
			$this->assertSame(1, $page['totalItem']);
			$metadata = $this->ok('GET', "certificates/$alias");
			$this->assertSame($input['fullName'], $metadata['subjectName']);
			$this->assertSame('+84912345678', $metadata['phone']);
			$this->assertTrue($metadata['isConfirmed']);
			$public = $this->ok('GET', "certificates/$alias/public");
			$this->assertSame('PEM', $public['format']);
			$this->assertSame($fingerprint, openssl_x509_fingerprint($public['chain'][0], 'sha256'));
			$this->assertSame(404, $this->request('GET', 'certificates/' . $alias, uid: $other)[0]);
			$reuse = $this->ok('GET', 'certificates/dossier-reuse?personalIdNumber=' . $input['personalIdNumber']);
			$this->assertTrue($reuse['reusable']);
			$this->assertTrue($reuse['hasPreviousDossier']);
			$this->assertSame('ELIGIBLE', $reuse['reasonCode']);
			$appConfig->clearCache();
			$clients = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true);
			$clients[$this->uid]['edgeDeployment'] = false;
			$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients));
			$disabledReuse = $this->ok('GET', 'certificates/dossier-reuse?personalIdNumber=' . $input['personalIdNumber']);
			$this->assertFalse($disabledReuse['reusable']);
			$this->assertTrue($disabledReuse['hasPreviousDossier']);
			$this->assertSame('REUSE_NOT_ENABLED', $disabledReuse['reasonCode']);
			$clients[$this->uid]['edgeDeployment'] = true;
			$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients));
			$signInput = ['certAlias' => $alias, 'fileBase64' => $png, 'documentName' => 'contract.pdf', 'type' => 'PDF'];
			$sign = $this->ok('POST', 'signing-requests/direct-sign', $signInput, 'public-sign-email-001');
			$signId = $sign['signSessionId'];
			$this->assertTrue($sign['otpRequired']);
			$this->assertSame(404, $this->request('GET', "signing-requests/$signId", uid: $other)[0]);
			$this->assertSame(409, $this->request('POST', 'signing-requests/direct-sign', ['signSessionId' => $signId, 'fileBase64' => $png, 'otpValue' => $code], 'public-sign-mutated')[0]);
			$this->assertSame(429, $this->request('POST', 'signing-requests/direct-sign/resend-otp', ['signSessionId' => $signId])[0]);
			$this->allowResend($signId, false);
			$this->assertSame($signId, $this->ok('POST', 'signing-requests/direct-sign/resend-otp', ['signSessionId' => $signId])['signSessionId']);
			$code = $this->otp($input['email']);
			$signed = $this->ok('POST', 'signing-requests/direct-sign', ['signSessionId' => $signId, 'otpValue' => $code], 'public-sign-confirm-001');
			$this->assertSame('SUCCESS', $signed['status'], json_encode($signed));
			$this->assertSigned($signed['signedData'], $fingerprint);
			$this->assertSame($signed['signedData'], $this->ok('POST', 'signing-requests/direct-sign', ['signSessionId' => $signId], 'public-sign-confirm-retry')['signedData']);
			$signInput['confirmationMethod'] = 'external_assertion';
			$signInput['fileBase64'] = $signed['signedData'];
			$signInput['page'] = 1;
			$signInput += ['llx' => 10, 'lly' => 10, 'urx' => 180, 'ury' => 65];
			$signInput['signatureImageBase64'] = $dossier['imageCap'];
			$adapter = $this->ok('POST', 'signing-requests/direct-sign', $signInput, 'public-sign-adapter-001');
			$this->assertArrayHasKey('challengeBase64', $adapter);
			openssl_sign(base64_decode($adapter['challengeBase64']), $proof, $key, OPENSSL_ALGO_SHA256);
			$adapterSigned = $this->ok('POST', 'signing-requests/direct-sign', ['signSessionId' => $adapter['signSessionId'], 'assertion' => base64_encode($proof)], 'public-sign-adapter-confirm');
			$this->assertSame('SUCCESS', $adapterSigned['status'], json_encode($adapterSigned));
			$this->assertSigned($adapterSigned['signedData'], $fingerprint, 2);
			$badImage = $signInput;
			$badImage['signatureImageBase64'] = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=';
			$badImageSession = $this->ok('POST', 'signing-requests/direct-sign', $badImage, 'public-bad-image');
			$this->assertSame('FAILED', $badImageSession['results'][0]['status']);
			$this->assertSame('SYS-0003', $badImageSession['results'][0]['errorCode']);
			$this->ok('POST', 'signing-requests/' . $badImageSession['signSessionId'] . '/cancel', []);
			$certifiedInput = ['certAlias' => $alias, 'confirmationMethod' => 'external_assertion',
				'fileBase64' => base64_encode(file_get_contents(__DIR__ . '/../../fixtures/pdfs/real_jsignpdf_level1.pdf'))];
			$restricted = $this->ok('POST', 'signing-requests/direct-sign', $certifiedInput, 'public-docmdp-restricted');
			$this->assertSame('FAILED', $restricted['results'][0]['status'], 'DocMDP must reject additional signatures before consent.');
			$this->ok('POST', 'signing-requests/' . $restricted['signSessionId'] . '/cancel', []);
			$batch = $this->ok('POST', 'signing-requests', ['certAlias' => $alias, 'confirmationMethod' => 'external_assertion', 'files' => [
				['id' => 'GOOD', 'documentName' => 'good.pdf', 'fileBase64' => $png],
				['id' => 'BROKEN', 'documentName' => 'broken.pdf', 'fileBase64' => base64_encode('broken PDF')]]], 'public-batch-001', 202);
			openssl_sign(base64_decode($batch['challengeBase64']), $proof, $key, OPENSSL_ALGO_SHA256);
			$batchResult = $this->ok('POST', 'signing-requests/direct-sign', ['signSessionId' => $batch['signSessionId'], 'assertion' => base64_encode($proof)], 'public-batch-confirm');
			$this->assertSame('PARTIAL_SUCCESS', $batchResult['status'], json_encode($batchResult));
			$this->assertSame(1, $batchResult['successCount']);
			$this->assertSame(1, $batchResult['failedCount']);
			$this->assertSame('BROKEN', $batchResult['results'][1]['id']);
			$this->assertNull($batchResult['results'][1]['signedData']);
			$this->assertSigned($batchResult['results'][0]['signedData'], $fingerprint);
			$this->assertSame('SUCCESS', $this->ok('GET', 'signing-requests/' . $batch['signSessionId'] . '/documents/GOOD/result')['status']);
			if (getenv('LIBRESIGN_PROVIDER_TEST_LARGE_BATCH') === '1') {
				$documents = [];
				for ($index = 0; $index < 100; $index++) {
					$documents[] = ['id' => 'PDF-' . $index, 'documentName' => 'batch-' . $index . '.pdf', 'fileBase64' => $png];
				}
				$large = $this->ok('POST', 'signing-requests', ['certAlias' => $alias, 'confirmationMethod' => 'external_assertion', 'files' => $documents], 'public-100-pdfs', 202);
				openssl_sign(base64_decode($large['challengeBase64']), $proof, $key, OPENSSL_ALGO_SHA256);
				[$httpStatus, $accepted] = $this->request('POST', 'signing-requests/direct-sign', ['signSessionId' => $large['signSessionId'], 'assertion' => base64_encode($proof)], 'public-100-confirm');
				$this->assertContains($httpStatus, [200, 202], json_encode($accepted));
				$service = Server::get(\OCA\Libresign\Service\Provider\PublicSigningService::class);
				for ($attempt = 0; $attempt < 30 && $accepted['data']['status'] === 'PROCESSING'; $attempt++) {
					$service->process($this->uid, $large['signSessionId'], 20);
					$accepted['data'] = $service->get(['uid' => $this->uid, 'config' => $this->config], $large['signSessionId']);
				}
				$this->assertSame('SUCCESS', $accepted['data']['status']);
				$this->assertSame(100, $accepted['data']['successCount']);
				$this->assertSame(0, $accepted['data']['failedCount']);
				$this->assertSigned($accepted['data']['results'][0]['signedData'], $fingerprint);
				$this->assertSigned($accepted['data']['results'][99]['signedData'], $fingerprint);
			}
			$this->assertSame(400, $this->request('POST', 'signing-requests/direct-sign', ['certAlias' => $alias, 'type' => 'XML', 'fileBase64' => $png], 'public-unsupported-xml')[0]);
			$this->ok('POST', 'certificates/' . $alias . '/revoke', ['reasonCode' => 5]);
			$this->assertSame(409, $this->request('POST', 'signing-requests/direct-sign', $signInput, 'public-revoked-sign')[0]);
			$input['reusePreviousDossier'] = true;
			$input['requireReuseFaceMatch'] = true;
			$reissue = $this->ok('POST', 'certificates/registration', $input, 'public-reissue-001');
			$this->assertSame('FACE_MATCH_REQUIRED', $reissue['step']);
			$reissueId = $reissue['sessionId'];
			$this->assertSame(409, $this->request('POST', "registration-sessions/$reissueId/confirm", [], 'public-face-required-confirm')[0]);
			$face = ['portraitBase64' => $dossier['imageCap'], 'verification' => ['score' => 0.9, 'isPass' => true,
				'sessionId' => $reissueId, 'verifiedAt' => gmdate('Y-m-d\TH:i:s\Z')]];
			$badFace = $face;
			$badFace['verification']['score'] = 0.2;
			$badFace['threshold'] = 0.1;
			$this->assertSame(403, $this->request('POST', "registration-sessions/$reissueId/reuse-face-match", $badFace)[0]);
			$futureFace = $face;
			$futureFace['verification']['verifiedAt'] = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
			$this->assertSame(400, $this->request('POST', "registration-sessions/$reissueId/reuse-face-match", $futureFace)[0]);
			$this->ok('POST', "registration-sessions/$reissueId/reuse-face-match", $face);
			$this->ok('POST', "registration-sessions/$reissueId/otp", ['otpCode' => $this->otp($input['email'])]);
			$newCert = $this->ok('POST', "registration-sessions/$reissueId/confirm", [], 'public-reissue-confirm')['certificate'];
			$this->assertNotSame($alias, $newCert['certAlias']);
			$this->assertNotSame($fingerprint, $newCert['fingerprintSha256']);
			$this->assertSame('REVOKED', $this->ok('GET', "certificates/$alias")['status']);
			$assertInput = ['certAlias' => $newCert['certAlias'], 'fileBase64' => $png, 'documentName' => 'fresh.pdf', 'confirmationMethod' => 'external_assertion'];
			$pendingSign = $this->ok('POST', 'signing-requests/direct-sign', $assertInput, 'public-negative-sign');
			$badProof = base64_encode('wrong-proof');
			$this->assertSame(403, $this->request('POST', 'signing-requests/direct-sign', ['signSessionId' => $pendingSign['signSessionId'], 'assertion' => $badProof], 'public-negative-proof')[0]);
			$this->assertSame('CANCELLED', $this->ok('POST', 'signing-requests/' . $pendingSign['signSessionId'] . '/cancel', [])['status']);
			$assertInput['files'] = array_fill(0, 101, ['fileBase64' => $png]);
			unset($assertInput['fileBase64']);
			$this->assertSame(400, $this->request('POST', 'signing-requests', $assertInput, 'public-too-many-files')[0]);
			$input2 = $input;
			$input2['personalIdNumber'] = '001234567891';
			$input2['fullName'] = 'Nguyễn Văn Cancel';
			$input2['reusePreviousDossier'] = false;
			$cancel = $this->ok('POST', 'certificates/registration', $input2, 'public-cancel-registration');
			$this->assertSame('CANCELLED', $this->ok('POST', 'registration-sessions/' . $cancel['sessionId'] . '/cancel', [])['status']);
			$this->assertSame(404, $this->request('POST', 'registration-sessions/' . $cancel['sessionId'] . '/cancel', [], uid: $other)[0]);
			// CAS rejects stale snapshots, including when the runtime has no file-lock backend.
			$store = Server::get(ProviderStateStore::class);
			$event = $store->byKey($this->uid, 'event', 'certificate:' . $id);
			$this->assertNotNull($event);
			$this->assertSame($alias, $event['data']['payload']['certAlias']);
			$this->assertSame('PENDING', $event['status']);
			$first = $store->get($this->uid, 'enrollment', $id);
			$stale = $first;
			$store->save($first);
			try {
				$store->save($stale);
				$this->fail('Stale workflow state was accepted');
			} catch (\OCA\Libresign\Exception\ProviderException $e) {
				$this->assertSame('concurrent_update', $e->error);
			}
			$input2['personalIdNumber'] = '001234567892';
			$input2['registrationMethod'] = 'QR_SELF_UPLOAD';
			$qr = $this->ok('POST', 'certificates/registration', $input2, 'public-qr-registration');
			$this->assertStringStartsWith('https://adapter.example.test/register?sessionId=', $qr['registrationUrl']);
			$this->assertStringStartsWith("\x89PNG", base64_decode($qr['qrCode']));
			$assertionRegistration = $input2;
			$assertionRegistration['personalIdNumber'] = '001234567893';
			$assertionRegistration['registrationMethod'] = 'CARD_READER';
			$assertionRegistration['confirmationMethod'] = 'external_assertion';
			$assertionSession = $this->ok('POST', 'certificates/registration', $assertionRegistration, 'public-enroll-assertion');
			$assertionId = $assertionSession['sessionId'];
			$assertionDossier = $dossier;
			$assertionDossier['identifyNumber'] = $assertionRegistration['personalIdNumber'];
			$assertionDossier['name'] = $assertionRegistration['fullName'];
			$acceptedDossier = $this->ok('POST', "registration-sessions/$assertionId/identity/info", $assertionDossier);
			$this->assertIsString($acceptedDossier['session']['challengeBase64']);
			// An assertion that approved document signing must not approve certificate issuance.
			$this->assertSame(403, $this->request('POST', "registration-sessions/$assertionId/confirm", ['assertion' => base64_encode($proof)], 'public-enroll-cross-purpose')[0]);
			openssl_sign(base64_decode($acceptedDossier['session']['challengeBase64']), $enrollmentProof, $key, OPENSSL_ALGO_SHA256);
			$assertionIssued = $this->ok('POST', "registration-sessions/$assertionId/confirm", ['assertion' => base64_encode($enrollmentProof)], 'public-enroll-assertion-confirm');
			$this->assertSame('ACTIVE', $assertionIssued['status']);
			$this->assertSame($assertionRegistration['personalIdNumber'], $assertionIssued['certificate']['personalIdNumber']);
		} finally {
			$this->cleanup([$this->uid, $other]);
		}
	}

	/** Anonymous browser requests keep the real CSRF middleware and cookies enabled. */
	private function browser(string $url, string $cookies, ?array $form = null): array {
		$path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
		$ch = curl_init(rtrim(getenv('LIBRESIGN_PROVIDER_TEST_BASE_URL'), '/') . $path);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
			CURLOPT_HTTPHEADER => ['Host: ' . getenv('LIBRESIGN_PROVIDER_TEST_HOST'), 'Accept: text/html'],
			CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies]);
		if ($form !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
		}
		$html = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$this->assertIsString($html);
		return [$status, $html];
	}

	#[DataProvider('otpModes')]
	public function testQrMockContactUpdateConsentCsrfAndExternalUrlOverride(bool $mockOtp): void {
		$this->mockOtp = $mockOtp;
		if (!getenv('LIBRESIGN_PROVIDER_TEST_BASE_URL') || !getenv('LIBRESIGN_PROVIDER_TEST_HOST')
			|| !getenv('LIBRESIGN_PROVIDER_TEST_MAILPIT') || !str_starts_with(Server::get(IConfig::class)->getSystemValueString('instanceid'), 'providertest')) {
			$this->markTestSkipped('Requires the isolated provider integration runtime.');
		}
		$this->uid = 'public-qr-test-' . bin2hex(random_bytes(5));
		$this->password = bin2hex(random_bytes(24));
		$this->appPassword = bin2hex(random_bytes(36));
		$users = Server::get(IUserManager::class);
		$users->createUser($this->uid, $this->password);
		Server::get(IProvider::class)->generateToken($this->appPassword, $this->uid, $this->uid, $this->password,
			'Isolated QR HTTP test', IToken::PERMANENT_TOKEN, IToken::DO_NOT_REMEMBER);
		$config = Server::get(IAppConfig::class);
		$config->clearCache();
		$clients = json_decode($config->getValueString('libresign', 'provider_clients', '{}'), true);
		$clients[$this->uid] = ['enabled' => true, 'mockRegistrationEnabled' => true, 'mockOtpEnabled' => $mockOtp, 'otpChannel' => 'email', 'edgeDeployment' => true,
			'scopes' => ['certificates:read', 'certificates:write', 'signing:read', 'signing:write']];
		$config->setValueString('libresign', 'provider_clients', json_encode($clients));
		$cookies = tempnam(sys_get_temp_dir(), 'provider-qr-cookie-');
		try {
			$input = ['personalIdNumber' => '001234567894', 'fullName' => 'Nguyễn Văn QR', 'phone' => '0912345678',
				'email' => $this->uid . '-original@example.test', 'registrationMethod' => 'QR_SELF_UPLOAD', 'packageCode' => 'internal-default'];
			$session = $this->ok('POST', 'certificates/registration', $input, 'qr-mock-enroll');
			$id = $session['sessionId'];
			$this->assertSame('mock', $session['registrationMode']);
			$this->assertSame('SUBMIT_IDENTITY', $session['nextAction']);
			$this->assertFalse($session['otpRequired']);
			$this->assertStringStartsWith("\x89PNG", base64_decode($session['qrCode']));
			$url = $session['registrationUrl'];
			parse_str(parse_url($url, PHP_URL_QUERY), $query);
			$token = $query['token'];
			[$http, $html] = $this->browser($url, $cookies);
			$this->assertSame(200, $http);
			$this->assertStringNotContainsString($input['personalIdNumber'], $html);
			$this->assertStringNotContainsString($this->appPassword, $html);
			$this->assertStringNotContainsString('name="otpCode"', $html);
			$this->assertSame(403, $this->browser($url . 'tampered', $cookies)[0]);
			$email = $this->uid . '@example.test';
			$dossier = ['identifyNumber' => $input['personalIdNumber'], 'name' => $input['fullName'], 'address' => 'Hà Nội',
				'dateOfBirth' => '01/01/1990', 'issueDate' => '01/01/2021', 'expiredDate' => '01/01/2030', 'sex' => 'Nam',
				'isPass' => true, 'email' => $email, 'phone' => '0987654321'];
			$badContact = $dossier;
			$badContact['email'] = 'invalid-address';
			$this->assertSame(400, $this->request('POST', "registration-sessions/$id/identity/info", $badContact)[0]);
			$accepted = $this->ok('POST', "registration-sessions/$id/identity/info", $dossier);
			$this->assertSame($email, $accepted['session']['email']);
			$this->assertSame('+84987654321', $accepted['session']['phone']);
			$this->assertTrue($accepted['session']['otpRequired']);
			$this->assertSame('VERIFY_OTP', $accepted['session']['nextAction']);
			[$http, $html] = $this->browser($url, $cookies);
			$this->assertSame(200, $http);
			$this->assertStringContainsString('name="otpCode"', $html);
			$this->assertSame(1, preg_match('/name="requesttoken" value="([^"]+)"/', $html, $csrf));
			$form = ['token' => $token, 'action' => 'confirm', 'otpCode' => $this->otp($email), 'consent' => 'yes'];
			$this->assertSame(412, $this->browser($url, $cookies, $form)[0]);
			$form['requesttoken'] = html_entity_decode($csrf[1], ENT_QUOTES);
			$missingConsent = $form;
			unset($missingConsent['consent']);
			$this->assertSame(400, $this->browser($url, $cookies, $missingConsent)[0]);
			[$http, $html] = $this->browser($url, $cookies, $form);
			$this->assertSame(200, $http, strip_tags($html));
			$this->assertStringContainsString('Đã hoàn tất cấp chứng thư số', $html);
			$active = $this->ok('GET', 'certificates/registration/' . $id);
			$this->assertSame('ACTIVE', $active['status']);
			$this->assertSame($email, $active['certificate']['email']);
			$this->assertSame($active['certificate']['certAlias'], $this->ok('POST', "registration-sessions/$id/confirm", [], 'qr-confirm-retry')['certificate']['certAlias']);
			$this->assertTrue($this->ok('POST', 'certificates/registration', array_replace($input, ['email' => $email, 'phone' => '0987654321']), 'qr-active-reuse')['reusedCertificate']);
			$config->clearCache();
			$clients = json_decode($config->getValueString('libresign', 'provider_clients', '{}'), true);
			$clients[$this->uid]['mockRegistrationEnabled'] = false;
			$config->setValueString('libresign', 'provider_clients', json_encode($clients));
			$this->assertSame(403, $this->browser($url, $cookies)[0]);
			$input['personalIdNumber'] = '001234567895';
			$this->assertSame(503, $this->request('POST', 'certificates/registration', $input, 'qr-no-mock')[0]);
			$clients[$this->uid]['registrationBaseUrl'] = 'https://adapter.example.test/register?source=his';
			$config->setValueString('libresign', 'provider_clients', json_encode($clients));
			$external = $this->ok('POST', 'certificates/registration', $input, 'qr-external');
			$this->assertSame('adapter', $external['registrationMode']);
			$this->assertStringStartsWith('https://adapter.example.test/register?source=his&sessionId=', $external['registrationUrl']);
			$this->assertStringNotContainsString('token=', $external['registrationUrl']);
		} finally {
			$this->cleanup([$this->uid]);
			unlink($cookies);
		}
	}

	private function cleanup(array $uids): void {
		$db = Server::get(IDBConnection::class);
		$factory = Server::get(IAppDataFactory::class);
		$folder = $factory->get('libresign')->getFolder('/')->getOrCreateFolder('provider-artifacts');
		foreach ($uids as $uid) {
			$qb = $db->getQueryBuilder();
			$qb->select('*')->from('libresign_provider_state')->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($uid)));
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$state = json_decode(Server::get(ICrypto::class)->decrypt($row['encrypted_data']), true);
				preg_match_all('/[a-f0-9]{64}\.enc/', json_encode($state), $matches);
				foreach (array_unique($matches[0]) as $name) {
					if ($folder->fileExists($name)) {
						$folder->getFile($name)->delete();
					}
				}
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('libresign_provider_state')->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($uid)))->executeStatement();
			$qb = $db->getQueryBuilder();
			$qb->select('registration_id')->from('libresign_provider_reg')->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($uid)));
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$mapper = Server::get(ProviderRegistrationMapper::class);
				$record = $mapper->findForClient($uid, $row['registration_id']);
				if ($record->getSerialNumber()) {
					Server::get(CrlService::class)->revokeCertificate($record->getSerialNumber());
				}
				$credentials = $factory->get('libresign')->getFolder('/')->getOrCreateFolder('provider-credentials');
				if ($credentials->fileExists($row['registration_id'] . '.pfx.enc')) {
					$credentials->getFile($row['registration_id'] . '.pfx.enc')->delete();
				}
				$mapper->delete($record);
			}
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		$config = Server::get(IAppConfig::class);
		$config->clearCache();
		$clients = json_decode($config->getValueString('libresign', 'provider_clients', '{}'), true);
		foreach ($uids as $uid) {
			unset($clients[$uid]);
		}
		$config->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
	}
}
