<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Integration\Provider;

use OCA\Libresign\Db\CrlMapper;
use OCA\Libresign\Db\ProviderRegistrationMapper;
use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Crl\CrlService;
use OCA\Libresign\Service\Provider\ProviderCredentialStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Opt-in: creates temporary integration accounts and issues/revokes test certificates. */
class ProviderApiIntegrationTest extends TestCase {
	private string $baseUrl;
	private string $host;
	private string $uid;
	private string $password;
	private array $sessions = [];

	private function request(string $method, string $path, ?array $body = null, ?string $uid = null, bool $anonymous = false): array {
		$handle = curl_init($this->baseUrl . '/ocs/v2.php/apps/libresign/api/v1/' . $path . (str_contains($path, '?') ? '&' : '?') . 'format=json');
		curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => ['Host: ' . $this->host, 'OCS-APIRequest: true', 'Content-Type: application/json'], CURLOPT_TIMEOUT => 30]);
		if (!$anonymous) {
			curl_setopt($handle, CURLOPT_USERPWD, ($uid ?? $this->uid) . ':' . $this->password);
		}
		if ($body !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
		}
		$raw = curl_exec($handle);
		$this->assertIsString($raw, 'HTTP request failed');
		$status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
		curl_close($handle);
		$decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
		return [$status, $decoded['ocs']['data'] ?? $decoded];
	}

	private function register(array $input): array {
		[$status, $registration] = $this->request('POST', 'provider/certificates/registration', $input);
		$this->assertSame(200, $status, json_encode($registration));
		$this->sessions[] = $registration['sessionId'];
		return $registration;
	}

	private function receiveOtp(string $email): string {
		$url = rtrim(getenv('LIBRESIGN_PROVIDER_TEST_MAILPIT'), '/');
		$messages = json_decode(file_get_contents($url . '/api/v1/search?query=' . rawurlencode('to:' . $email)), true, 32, JSON_THROW_ON_ERROR);
		$this->assertNotEmpty($messages['messages'], 'Expected registration code in the test Mailpit inbox');
		$id = $messages['messages'][0]['ID'];
		$message = json_decode(file_get_contents($url . '/api/v1/message/' . $id), true, 32, JSON_THROW_ON_ERROR);
		$this->assertSame(1, preg_match('/\b([0-9]{6})\b/', $message['Text'], $matches));
		return $matches[1];
	}

	public function testHeadlessCertificateApisWithBothConfirmationMethods(): void {
		if (!getenv('LIBRESIGN_PROVIDER_TEST_BASE_URL') || !getenv('LIBRESIGN_PROVIDER_TEST_HOST') || !getenv('LIBRESIGN_PROVIDER_TEST_MAILPIT')) {
			$this->markTestSkipped('Requires an isolated, configured Nextcloud/LibreSign CA and a test Mailpit inbox. See docs/12-provider-certificate-api.md.');
		}
		if (!str_starts_with(Server::get(IConfig::class)->getSystemValueString('instanceid'), 'providertest')) {
			$this->markTestSkipped('Use prepare-isolated.php; never run fixture mutations on live data.');
		}
		$this->baseUrl = rtrim(getenv('LIBRESIGN_PROVIDER_TEST_BASE_URL'), '/');
		$this->host = getenv('LIBRESIGN_PROVIDER_TEST_HOST');
		$this->uid = 'provider-test-' . bin2hex(random_bytes(6));
		$this->password = bin2hex(random_bytes(24));
		$otherUid = $this->uid . '-other';
		$users = Server::get(IUserManager::class);
		$appConfig = Server::get(IAppConfig::class);
		$previous = $appConfig->getValueString('libresign', 'provider_clients', '{}');
		$mapper = Server::get(ProviderRegistrationMapper::class);
		$crl = Server::get(CrlService::class);
		$users->createUser($this->uid, $this->password);
		$users->createUser($otherUid, $this->password);
		try {
			$this->assertSame(401, $this->request('GET', 'provider/certificates/packages', anonymous: true)[0]);
			$this->assertSame(403, $this->request('GET', 'provider/certificates/packages')[0]);
			$key = openssl_pkey_new(['private_key_bits' => 2048]);
			$config = ['enabled' => true, 'scopes' => ['certificates:read', 'certificates:write', 'certificates:revoke'],
				'assertionPublicKey' => openssl_pkey_get_details($key)['key'], 'otpChannel' => 'email'];
			$clients = json_decode($previous, true, 32, JSON_THROW_ON_ERROR);
			$clients[$this->uid] = $config;
			$clients[$otherUid] = $config;
			$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
			[$status, $packages] = $this->request('GET', 'provider/certificates/packages');
			$this->assertSame(200, $status, json_encode($packages));
			$this->assertSame(['external_assertion', 'service_otp'], $packages['items'][0]['confirmationMethods']);
			$input = ['requestId' => 'registration-001', 'confirmationMethod' => 'external_assertion',
				'person' => ['fullName' => 'Nguyễn Văn API', 'personalIdNumber' => '001234567890', 'email' => $this->uid . '@example.test'],
				'verification' => ['method' => 'nfc', 'source' => 'test-adapter', 'reference' => 'synthetic-receipt', 'verifiedAt' => gmdate('Y-m-d\TH:i:s\Z')]];
			$registration = $this->register($input);
			$session = $registration['sessionId'];
			$this->assertSame('PENDING', $registration['status']);
			$this->assertSame($session, $this->register($input)['sessionId']);
			$changed = $input;
			$changed['person']['fullName'] = 'Different subject';
			$this->assertSame(409, $this->request('POST', 'provider/certificates/registration', $changed)[0]);
			$this->assertSame(403, $this->request('POST', "provider/registration-sessions/$session/confirm", ['assertion' => base64_encode('wrong')])[0]);
			openssl_sign(base64_decode($registration['challengeBase64']), $signature, $key, OPENSSL_ALGO_SHA256);
			[$status, $active] = $this->request('POST', "provider/registration-sessions/$session/confirm", ['assertion' => base64_encode($signature)]);
			$this->assertSame(200, $status, json_encode($active));
			$this->assertSame('ACTIVE', $active['status']);
			$this->assertSame($active, $this->request('POST', "provider/registration-sessions/$session/confirm", [])[1]);
			$this->assertSame($active, $this->request('GET', "provider/registration-sessions/$session")[1]);
			[$status, $public] = $this->request('GET', "provider/certificates/$session/public");
			$this->assertSame(200, $status);
			$this->assertCount(2, $public['chain']);
			$this->assertSame(1, openssl_x509_verify($public['chain'][0], openssl_pkey_get_public($public['chain'][1])));
			$this->assertSame($input['person']['fullName'], openssl_x509_parse($public['chain'][0])['subject']['CN']);
			$this->assertStringNotContainsString('PRIVATE KEY', json_encode($public));
			$this->assertStringNotContainsString('password', json_encode($active));
			$this->assertFalse($users->userExists($input['person']['email']));
			$record = $mapper->findForClient($this->uid, $session);
			$this->assertStringNotContainsString('001234567890', $record->getEncryptedData());
			$state = json_decode(Server::get(ICrypto::class)->decrypt($record->getEncryptedData()), true, 32, JSON_THROW_ON_ERROR);
			$this->assertTrue(openssl_pkcs12_read(Server::get(ProviderCredentialStore::class)->read($session), $parts, $state['password']));
			$this->assertSame($active['certificate']['serialNumber'], openssl_x509_parse($parts['cert'])['serialNumber']);
			$native = Server::get(CrlMapper::class)->findBySerialNumber($active['certificate']['serialNumber']);
			$this->assertSame('provider:' . $record->getSubjectHash(), $native->getOwner());
			$this->assertSame(404, $this->request('GET', "provider/registration-sessions/$session", uid: $otherUid)[0]);
			$this->assertSame(404, $this->request('GET', "provider/certificates/$session/public", uid: $otherUid)[0]);
			$this->assertSame(404, $this->request('POST', "provider/certificates/$session/revoke", [], $otherUid)[0]);
			$this->assertSame([], $this->request('GET', 'provider/certificates', uid: $otherUid)[1]['items']);
			$this->assertCount(1, $this->request('GET', 'provider/certificates?personalIdNumber=001234567890')[1]['items']);
			[$status, $revoked] = $this->request('POST', "provider/certificates/$session/revoke", ['reasonCode' => 5]);
			$this->assertSame(200, $status);
			$this->assertSame('REVOKED', $revoked['status']);
			$this->assertSame('REVOKED', $this->request('GET', "provider/certificates/$session")[1]['status']);
			$input['requestId'] = 'registration-002';
			$input['confirmationMethod'] = 'service_otp';
			$otpSession = $this->register($input)['sessionId'];
			$this->assertSame(200, $this->request('POST', "provider/registration-sessions/$otpSession/otp", [])[0]);
			$this->assertSame(429, $this->request('POST', "provider/registration-sessions/$otpSession/otp", [])[0]);
			$code = $this->receiveOtp($input['person']['email']);
			$wrong = $code === '000000' ? '111111' : '000000';
			$this->assertSame(403, $this->request('POST', "provider/registration-sessions/$otpSession/confirm", ['otpValue' => $wrong])[0]);
			[$status, $otpActive] = $this->request('POST', "provider/registration-sessions/$otpSession/confirm", ['otpValue' => $code]);
			$this->assertSame(200, $status, json_encode($otpActive));
			$this->assertSame('ACTIVE', $otpActive['status']);
			$this->assertNotSame($active['certificate']['serialNumber'], $otpActive['certificate']['serialNumber']);
			$clients[$otherUid]['enabled'] = false;
			$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
			$this->assertSame(403, $this->request('GET', 'provider/certificates', uid: $otherUid)[0]);
			$input['requestId'] = 'registration-003';
			$pending = $this->register($input)['sessionId'];
			$stale = $mapper->findForClient($this->uid, $pending);
			$this->assertSame('CANCELLED', $this->request('POST', "provider/registration-sessions/$pending/cancel", [])[1]['status']);
			$this->assertSame(409, $this->request('POST', "provider/registration-sessions/$pending/confirm", ['otpValue' => $code])[0]);
			try {
				$stale->setStatus('ISSUING');
				$mapper->saveState($stale);
				$this->fail('Stale database version was accepted');
			} catch (ProviderException $e) {
				$this->assertSame(409, $e->getCode());
			}
		} finally {
			foreach (array_unique($this->sessions) as $session) {
				$record = $mapper->findForClient($this->uid, $session);
				if ($record->getSerialNumber() !== null) {
					$crl->revokeCertificate($record->getSerialNumber());
				}
				$folder = Server::get(IAppDataFactory::class)->get('libresign')->getFolder('/')->getOrCreateFolder('provider-credentials');
				if ($folder->fileExists($session . '.pfx.enc')) {
					$folder->getFile($session . '.pfx.enc')->delete();
				}
				$mapper->delete($record);
			}
			$appConfig->setValueString('libresign', 'provider_clients', $previous);
			$users->get($this->uid)?->delete();
			$users->get($otherUid)?->delete();
		}
	}
}
