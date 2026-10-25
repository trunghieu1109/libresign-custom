<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Integration\Provider;

use Doctrine\DBAL\Logging\DebugStack;
use OCA\Libresign\Db\ProviderRegistrationMapper;
use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Handler\SignEngine\Pkcs12Handler;
use OCA\Libresign\Service\Provider\ProviderCertificateService;
use OCA\Libresign\Service\Provider\ProviderStateStore;
use OCA\Libresign\Service\Provider\PublicRegistrationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real issuance and email OTP; opt-in, disposable CA/SQLite/datadir only. */
class ProviderIssuanceOptimizationTest extends TestCase {
	private int $decryptions = 0;
	private int $issuances = 0;
	private float $engineMilliseconds = 0;

	public static function flows(): array {
		return ['OTP' => ['service_otp', false], 'assertion' => ['external_assertion', false],
			'OTP with callback' => ['service_otp', true], 'assertion with callback' => ['external_assertion', true]];
	}

	private function make(string $class, array $overrides = []): object {
		$constructor = (new \ReflectionClass($class))->getConstructor();
		return new $class(...array_map(fn ($p) => $overrides[$p->getType()->getName()] ?? Server::get($p->getType()->getName()), $constructor->getParameters()));
	}

	private function measure(callable $call): array {
		$db = Server::get(IDBConnection::class);
		$logger = new DebugStack();
		$db->getInner()->getConfiguration()->setSQLLogger($logger);
		$this->decryptions = $this->issuances = 0;
		$this->engineMilliseconds = 0;
		$start = hrtime(true);
		try {
			$result = $call();
		} finally {
			$db->getInner()->getConfiguration()->setSQLLogger(null);
		}
		$queries = array_filter($logger->queries, fn ($q) => str_contains($q['sql'], 'libresign_provider_reg')
			|| str_contains($q['sql'], 'libresign_provider_state') || str_contains($q['sql'], 'libresign_crl'));
		return ['result' => $result, 'queries' => count($queries), 'workflowDecryptions' => $this->decryptions,
			'issuanceCalls' => $this->issuances, 'engineMilliseconds' => $this->engineMilliseconds,
			'milliseconds' => (hrtime(true) - $start) / 1000000];
	}

	private function otp(string $email): string {
		$base = rtrim(getenv('LIBRESIGN_PROVIDER_TEST_MAILPIT'), '/');
		$messages = json_decode(file_get_contents($base . '/api/v1/search?query=' . rawurlencode('to:' . $email)), true, 32, JSON_THROW_ON_ERROR);
		$this->assertNotEmpty($messages['messages']);
		$message = json_decode(file_get_contents($base . '/api/v1/message/' . $messages['messages'][0]['ID']), true, 32, JSON_THROW_ON_ERROR);
		$this->assertSame(1, preg_match('/\b([0-9]{6})\b/', $message['Text'], $matches));
		return $matches[1];
	}

	private function stableResponse(array $response): array {
		foreach ($response as $key => &$value) {
			if (in_array($key, ['sessionId', 'certAlias', 'challengeBase64', 'expiresAt', 'otpExpiresAt', 'validFrom', 'validTo', 'serialNumber', 'fingerprintSha256'], true)) {
				$value = get_debug_type($value);
			} elseif ($key === 'email') {
				$value = 'synthetic@example.test';
			} elseif (is_array($value)) {
				$value = $this->stableResponse($value);
			}
		}
		return $response;
	}

	#[DataProvider('flows')]
	public function testIssuanceStepsAgainstOriginalBackend(string $method, bool $callback): void {
		$config = Server::get(IConfig::class);
		$baseline = getenv('LIBRESIGN_PROVIDER_BASELINE_DIR');
		if (!$baseline || !getenv('LIBRESIGN_PROVIDER_TEST_MAILPIT')
			|| !str_starts_with($config->getSystemValueString('instanceid'), 'providertest')
			|| !str_starts_with($config->getSystemValueString('datadirectory'), '/tmp/provider-integration-issuance-')) {
			$this->markTestSkipped('Requires a private issuance fixture, original source archive and test Mailpit.');
		}
		foreach (['ProviderCertificateService', 'PublicRegistrationService'] as $name) {
			if (!class_exists('OCA\\Libresign\\Service\\Provider\\' . $name . 'Baseline', false)) {
				$source = file_get_contents($baseline . '/lib/Service/Provider/' . $name . '.php');
				eval(substr(str_replace('class ' . $name . ' {', 'class ' . $name . 'Baseline extends ' . $name . ' {', $source), 5));
			}
		}
		$key = openssl_pkey_new(['private_key_bits' => 2048]);
		$metrics = $responses = [];
		foreach (['before', 'after'] as $variant) {
			$realCrypto = Server::get(ICrypto::class);
			$crypto = $this->createMock(ICrypto::class);
			$crypto->method('calculateHMAC')->willReturnCallback($realCrypto->calculateHMAC(...));
			$crypto->method('encrypt')->willReturnCallback($realCrypto->encrypt(...));
			$crypto->method('decrypt')->willReturnCallback(function ($value, $password = '') use ($realCrypto): string {
				$this->decryptions++;
				return $realCrypto->decrypt($value, $password);
			});
			$store = new ProviderStateStore(Server::get(IDBConnection::class), $crypto, Server::get(ILockingProvider::class));
			$realEngine = Server::get(Pkcs12Handler::class);
			$engine = $this->createMock(Pkcs12Handler::class);
			$engine->method('generateCertificate')->willReturnCallback(function ($user, $password, $name) use ($realEngine): string {
				$this->issuances++;
				$start = hrtime(true);
				try {
					return $realEngine->generateCertificate($user, $password, $name);
				} finally {
					$this->engineMilliseconds += (hrtime(true) - $start) / 1000000;
				}
			});
			$certificates = $this->make(ProviderCertificateService::class . ($variant === 'before' ? 'Baseline' : ''), [ICrypto::class => $crypto, Pkcs12Handler::class => $engine]);
			$service = $this->make(PublicRegistrationService::class . ($variant === 'before' ? 'Baseline' : ''),
				[ProviderCertificateService::class => $certificates, ProviderStateStore::class => $store]);
			$uid = 'issue-bench-' . bin2hex(random_bytes(5));
			$client = ['uid' => $uid, 'config' => ['otpChannel' => 'email', 'edgeDeployment' => true,
				'assertionPublicKey' => openssl_pkey_get_details($key)['key'],
				'callbackHosts' => ['adapter.example.test'], 'callbackSecret' => str_repeat('s', 32)]];
			$body = ['personalIdNumber' => '001234567890', 'fullName' => 'Synthetic Issuance Signer', 'email' => $uid . '@example.test',
				'phone' => '0912345678', 'registrationMethod' => 'CARD_READER', 'confirmationMethod' => $method];
			if ($callback) {
				$body['callbackUrl'] = 'https://adapter.example.test/certificate';
			}
			$measured = $this->measure(fn () => $service->register($client, 'initial-issue-key', $body));
			$id = $measured['result']['sessionId'];
			$responses['registration'][$variant] = $this->stableResponse($measured['result']);
			unset($measured['result']);
			$metrics['registration'][$variant] = $measured;
			$dossier = ['identifyNumber' => $body['personalIdNumber'], 'name' => $body['fullName'], 'address' => 'Synthetic address',
				'dateOfBirth' => '1990-01-01', 'issueDate' => '2021-01-01', 'expiredDate' => '2030-01-01', 'sex' => 'Nam', 'isPass' => true];
			$measured = $this->measure(fn () => $service->receive($client, $id, $dossier, false));
			$this->assertSame('AWAITING_CONFIRMATION', $measured['result']['session']['step']);
			$responses['identity'][$variant] = $this->stableResponse($measured['result']);
			unset($measured['result']);
			$metrics['identity'][$variant] = $measured;
			if ($method === 'service_otp') {
				try {
					$service->action($client, $id, 'resend');
					$this->fail('Resend must retain its rate limit.');
				} catch (ProviderException $e) {
					$this->assertSame('code_rate_limited', $e->error);
				}
				$record = $store->get($uid, 'enrollment', $id);
				$mapper = Server::get(ProviderRegistrationMapper::class);
				$legacy = $mapper->findForClient($uid, $record['data']['legacySession']);
				$state = json_decode($realCrypto->decrypt($legacy->getEncryptedData()), true, 32, JSON_THROW_ON_ERROR);
				$state['lastCodeAt'] = time() - 61;
				$legacy->setEncryptedData($realCrypto->encrypt(json_encode($state, JSON_THROW_ON_ERROR)));
				$mapper->saveState($legacy);
				$measured = $this->measure(fn () => $service->action($client, $id, 'resend'));
				$responses['resend'][$variant] = $this->stableResponse($measured['result']);
				unset($measured['result']);
				$metrics['resend'][$variant] = $measured;
				$code = $this->otp($body['email']);
				$wrong = str_pad((string)(((int)$code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
				try {
					$service->action($client, $id, 'otp', $wrong);
					$this->fail('A wrong OTP must not authorize issuance.');
				} catch (ProviderException $e) {
					$this->assertSame('invalid_proof', $e->error);
				}
				$measured = $this->measure(fn () => $service->action($client, $id, 'otp', $code));
				$this->assertSame('VERIFIED', $measured['result']['step']);
				$responses['otp'][$variant] = $this->stableResponse($measured['result']);
				unset($measured['result']);
				$metrics['otp'][$variant] = $measured;
				$proof = '';
			} else {
				$challenge = $service->registration($client, $id)['challengeBase64'];
				openssl_sign(base64_decode($challenge), $signature, $key, OPENSSL_ALGO_SHA256);
				$proof = base64_encode($signature);
			}
			$measured = $this->measure(fn () => $service->action($client, $id, 'confirm', $proof));
			$this->assertSame(1, $measured['issuanceCalls']);
			$this->assertSame('ACTIVE', $measured['result']['status']);
			$this->assertSame('ACTIVE', $measured['result']['certificate']['status']);
			$alias = $measured['result']['certificate']['certAlias'];
			$issuedCertificate = $measured['result']['certificate'];
			$responses['confirm'][$variant] = $this->stableResponse($measured['result']);
			unset($measured['result']);
			$metrics['confirm'][$variant] = $measured;
			$measured = $this->measure(fn () => $service->action($client, $id, 'confirm', $proof));
			$this->assertSame(0, $measured['issuanceCalls']);
			$this->assertSame($alias, $measured['result']['certificate']['certAlias']);
			$responses['confirm_retry'][$variant] = $this->stableResponse($measured['result']);
			unset($measured['result']);
			$metrics['confirm_retry'][$variant] = $measured;
			$event = $store->byKey($uid, 'event', 'certificate:' . $id);
			if ($callback) {
				$this->assertSame(array_intersect_key($issuedCertificate, array_flip(['subjectName', 'validFrom', 'validTo', 'status', 'certAlias', 'personalIdNumber']))
					+ ['sessionId' => $id], $event['data']['payload']);
			} else {
				$this->assertNull($event);
			}
			$credential = $certificates->signingCredential($client, $alias);
			$this->assertTrue(openssl_pkcs12_read($credential['pfx'], $parts, $credential['password']));
			$chain = $certificates->publicCertificate($client, $alias)['chain'];
			$this->assertSame(1, openssl_x509_verify($parts['cert'], openssl_pkey_get_public($chain[count($chain) - 1])));
			$this->assertSame($body['fullName'], openssl_x509_parse($parts['cert'])['subject']['CN']);
			$certificates->revoke($client, $alias, 0, 'Synthetic benchmark complete');
			$measured = $this->measure(fn () => $service->register($client, 'reuse-issue-key', $body + ['reusePreviousDossier' => true]));
			$this->assertTrue($measured['result']['reusedDossier']);
			$responses['reuse_registration'][$variant] = $this->stableResponse($measured['result']);
			unset($measured['result']);
			$metrics['reuse_registration'][$variant] = $measured;
		}
		foreach ($responses as $step => $variants) {
			$this->assertSame($variants['before'], $variants['after'], 'Compatible response model at ' . $step);
		}
		$this->assertLessThan($metrics['confirm']['before']['queries'], $metrics['confirm']['after']['queries']);
		$this->assertLessThan($metrics['identity']['before']['queries'], $metrics['identity']['after']['queries']);
		$this->assertLessThan($metrics['reuse_registration']['before']['queries'], $metrics['reuse_registration']['after']['queries']);
		if ($path = getenv('LIBRESIGN_PROVIDER_ISSUANCE_OUTPUT')) {
			$report = file_exists($path) ? json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : [];
			$report[$method . ($callback ? '_callback' : '')] = $metrics;
			file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
		}
	}
}
