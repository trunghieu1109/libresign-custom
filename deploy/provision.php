<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Administrative helper, never a web endpoint. Requests and secrets use stdin.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
ini_set('display_errors', 'stderr');
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';

class DeployFailure extends \RuntimeException {
}

/** Validate policy shape; SMS provider configuration can be completed later. */
function validateDeployOtpPolicy(array $policy): void {
	$channel = $policy['otpChannel'] ?? null;
	if ($channel !== null && !in_array($channel, \OCA\Libresign\Service\Provider\ProviderProofService::OTP_CHANNELS, true)) {
		throw new DeployFailure('otpChannel must be email, sms or email_sms.');
	}
	if (isset($policy['mockOtpEnabled']) && !is_bool($policy['mockOtpEnabled'])) {
		throw new DeployFailure('mockOtpEnabled must be a JSON boolean.');
	}
	if (!in_array($channel, ['sms', 'email_sms'], true)) {
		return;
	}
	$name = $policy['otpGateway'] ?? '';
	if (!is_string($name) || strlen($name) > 128 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
		throw new DeployFailure('Invalid otpGateway name.');
	}
}

function deploySmsAppStatus(): array {
	$apps = \OCP\Server::get(\OCP\App\IAppManager::class);
	try {
		$apps->getAppPath('twofactor_gateway');
		$installed = true;
	} catch (\OCP\App\AppPathNotFoundException) {
		$installed = false;
	}
	return ['installed' => $installed, 'enabled' => $apps->isEnabledForAnyone('twofactor_gateway')];
}

/** Read-only readiness information. Never sends an SMS or blocks core installation. */
function deploySmsReadiness(array $policy): array {
	if (!in_array($policy['otpChannel'] ?? null, ['sms', 'email_sms'], true)) {
		return ['required' => false, 'ready' => false, 'status' => 'not_requested'];
	}
	$result = ['required' => true, 'ready' => false, 'status' => 'configuration_pending'] + deploySmsAppStatus();
	$name = $policy['otpGateway'] ?? '';
	if (!$result['enabled'] || !is_string($name) || trim($name) === '') {
		return $result;
	}
	try {
		$gateway = \OCP\Server::get(\OCA\Libresign\Service\TwofactorGatewayService::class);
		$gateway->ensureAvailable($name);
		if ($gateway->isGatewayComplete($name)) {
			$result['ready'] = true;
			$result['status'] = 'configured';
		}
	} catch (\Throwable) {
		// Delivery remains unavailable until configured; backend validation still enforces it.
	}
	return $result;
}

try {
	$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
	$config = \OCP\Server::get(\OCP\IConfig::class);
	$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
	$appConfig->clearCache();
	$operation = $input['operation'] ?? '';
	$result = [];
	if ($operation === 'sms-status') {
		$result = deploySmsAppStatus();
	} elseif ($operation === 'migrate') {
		$migrations = new \OC\DB\MigrationService('libresign', \OCP\Server::get(\OC\DB\Connection::class));
		$completed = $migrations->getMigratedVersions();
		$applied = [];
		foreach ($input['versions'] as $version) {
			if (!preg_match('/^19002Date[0-9]{14}$/D', $version)) {
				throw new DeployFailure('Unexpected custom migration ID.');
			}
			if (!in_array($version, $completed, true)) {
				$migrations->executeStep($version);
				$applied[] = $version;
			}
		}
		$result = ['applied' => $applied];
	} elseif ($operation === 'ca') {
		if ($appConfig->getValueArray('libresign', 'rootCert', []) !== []) {
			$result = ['keptExistingCa' => true];
		} else {
			$pki = $config->getSystemValueString('datadirectory') . '/appdata_' . $config->getSystemValueString('instanceid') . '/libresign/pki';
			// Orphaned CA files require recovery, rather than creating a different issuer.
			if (is_dir($pki)) {
				foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pki, \FilesystemIterator::SKIP_DOTS)) as $file) {
					if (in_array($file->getFilename(), ['ca.pem', 'ca-key.pem'], true)) {
						throw new DeployFailure('CA files exist without metadata. Restore metadata before retrying.');
					}
				}
			}
			$directory = $pki . '/deploy';
			umask(0077);
			if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
				throw new DeployFailure('Cannot create private CA directory.');
			}
			\OCP\Server::get(\OCA\Libresign\Service\Install\InstallService::class)->generate(
				$input['ca']['commonName'], 'openssl',
				['O' => ['value' => $input['ca']['organization']], 'C' => ['value' => $input['ca']['country']]],
				['configPath' => $directory],
			);
			$result = ['createdCa' => true];
		}
	} elseif ($operation === 'check') {
		$checks = \OCP\Server::get(\OCA\Libresign\Service\SetupCheckResultService::class)->getFormattedChecks();
		$errors = [];
		foreach ($checks as $check) {
			if ($check->getStatus() === 'error') {
				$errors[] = $check->getResource();
			}
		}
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		foreach (['libresign_provider_reg', 'libresign_provider_state'] as $table) {
			if (!$db->tableExists($table)) {
				$errors[] = $table;
			}
		}
		if (!\OCP\Server::get(\OCP\BackgroundJob\IJobList::class)->has(\OCA\Libresign\BackgroundJob\ProviderWorkflowJob::class, null)) {
			$errors[] = 'ProviderWorkflowJob';
		}
		$result = ['ok' => count($checks) > 0 && !$errors, 'errors' => $errors];
	} elseif ($operation === 'provision') {
		$credentials = $input['credentials'];
		$uid = $credentials['uid'];
		if (!is_string($uid) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.@-]{0,63}$/D', $uid)) {
			throw new DeployFailure('Invalid integration UID.');
		}
		$users = \OCP\Server::get(\OCP\IUserManager::class);
		$user = $users->get($uid);
		if ($user !== null && (!$user->isEnabled() || \OCP\Server::get(\OCP\IGroupManager::class)->isAdmin($uid))) {
			throw new DeployFailure('Integration must be an enabled non-admin account.');
		}
		// Validate policy, SMTP and assertion material before any account writes.
		$clients = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($clients)) {
			throw new DeployFailure('Invalid existing provider_clients JSON; restore it before retrying.');
		}
		$exists = array_key_exists($uid, $clients);
		if ($exists && !is_array($clients[$uid])) {
			throw new DeployFailure('Invalid existing integration policy.');
		}
		$policy = $exists ? $clients[$uid] : ['enabled' => true, 'scopes' => [], 'otpChannel' => 'email', 'trustedSources' => [$uid]];
		if (!$exists || ($input['updateClient'] ?? false) === true) {
			$policy = array_replace($policy, $input['policy']);
		}
		validateDeployOtpPolicy($policy);
		if (!empty($policy['assertionPublicKey'])) {
			$key = openssl_pkey_get_public($policy['assertionPublicKey']);
			$details = $key ? openssl_pkey_get_details($key) : false;
			if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
				throw new DeployFailure('Assertion public key must be RSA with at least 2048 bits.');
			}
		}
		if (!empty($policy['callbackHosts']) && empty($policy['callbackSecret'])) {
			$policy['callbackSecret'] = bin2hex(random_bytes(32));
		}
		if (!empty($policy['callbackHosts']) && strlen($policy['callbackSecret']) < 32) {
			throw new DeployFailure('Callback secret must have at least 32 characters.');
		}
		if ($user === null) {
			$user = $users->createUser($uid, $credentials['accountPassword']);
			if ($user === false) {
				throw new DeployFailure('Could not create integration account.');
			}
			$user->setDisplayName('LibreSign Integration ' . $uid);
		}
		if (empty($credentials['appPassword'])) {
			// Same token API and event as Nextcloud user:auth-tokens:add; never reset a password.
			$manager = \OCP\Server::get(\OC\User\Manager::class);
			$authenticated = $manager->checkPasswordNoLogging($uid, $credentials['accountPassword']);
			if ($authenticated === false || $authenticated->getUID() !== $user->getUID()) {
				throw new DeployFailure('Account already exists with another password. Supply passwordFile or appPasswordFile; its password was preserved.');
			}
			$random = \OCP\Server::get(\OCP\Security\ISecureRandom::class);
			$token = $random->generate(72, \OCP\Security\ISecureRandom::CHAR_UPPER . \OCP\Security\ISecureRandom::CHAR_LOWER . \OCP\Security\ISecureRandom::CHAR_DIGITS);
			$generated = \OCP\Server::get(\OC\Authentication\Token\IProvider::class)->generateToken(
				$token, $uid, $uid, $credentials['accountPassword'], 'LibreSign deployment',
				\OC\Authentication\Token\IToken::PERMANENT_TOKEN, \OC\Authentication\Token\IToken::DO_NOT_REMEMBER,
			);
			\OCP\Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->dispatchTyped(new \OC\Authentication\Events\AppPasswordCreatedEvent($generated));
			$credentials['appPassword'] = $token;
		}
		if (!$exists || $policy !== $clients[$uid]) {
			$clients[$uid] = $policy;
			$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
		}
		if (($input['smtp'] ?? null) !== null) {
			$smtp = $input['smtp'];
			$config->setSystemValues([
				'mail_smtpmode' => 'smtp', 'mail_smtphost' => $smtp['host'], 'mail_smtpport' => $smtp['port'],
				'mail_smtpsecure' => $smtp['secure'], 'mail_smtpauth' => $smtp['auth'],
				'mail_smtpname' => $smtp['username'], 'mail_smtppassword' => $smtp['password'],
				'mail_from_address' => $smtp['fromAddress'], 'mail_domain' => $smtp['domain'],
			]);
		}
		$result = ['credentials' => array_replace($credentials, ['callbackSecret' => $policy['callbackSecret'] ?? null]),
			'settings' => ['uid' => $uid, 'preservedExistingPolicy' => $exists && !($input['updateClient'] ?? false),
				'policy' => array_diff_key($policy, array_flip(['callbackSecret', 'assertionPublicKey'])), 'sms' => deploySmsReadiness($policy)]];
	} elseif ($operation === 'http') {
		$host = $input['host'];
		if (!is_string($host) || !preg_match('/^[A-Za-z0-9.-]+(?::[0-9]+)?$/D', $host)) {
			throw new DeployFailure('Invalid HTTP Host.');
		}
		$clients = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true, 64, JSON_THROW_ON_ERROR);
		$policy = $clients[$input['uid']] ?? [];
		if (($policy['enabled'] ?? false) !== true) {
			throw new DeployFailure('Integration policy is disabled; it was preserved. Use --update-client only if enabling it is intended.');
		}
		validateDeployOtpPolicy($policy);
		$authorization = 'Authorization: Basic ' . base64_encode($input['uid'] . ':' . $input['appPassword']);
		$request = static function (string $path, bool $authenticated) use ($host, $authorization): array {
			$headers = ['Host: ' . $host, 'Accept: application/json', 'OCS-APIRequest: true'];
			if ($authenticated) {
				$headers[] = $authorization;
			}
			$context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers),
				'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
			$body = @file_get_contents('http://nginx/index.php/apps/libresign/api/v1/public' . $path, false, $context);
			preg_match('/^HTTP\/\S+ ([0-9]{3})/', $http_response_header[0] ?? '', $match);
			return [(int)($match[1] ?? 0), $body === false ? null : json_decode($body, true)];
		};
		$passed = true;
		foreach (['/capabilities' => 'signing:read', '/certificates/packages' => 'certificates:read', '/signing-profiles' => 'signing:read'] as $path => $scope) {
			[$status, $body] = $request($path, true);
			$allowed = ($policy['enabled'] ?? false) === true && in_array($scope, $policy['scopes'] ?? [], true);
			$passed = $passed && $status === ($allowed ? 200 : 403) && ($body['status'] ?? null) === $allowed;
			if ($path === '/capabilities' && $allowed) {
				$expectedChannel = in_array($policy['otpChannel'] ?? null, \OCA\Libresign\Service\Provider\ProviderProofService::OTP_CHANNELS, true) ? $policy['otpChannel'] : null;
				$passed = $passed && array_key_exists('otpChannel', $body['data'] ?? []) && $body['data']['otpChannel'] === $expectedChannel
					&& ($body['data']['supportedOtpChannels'] ?? null) === \OCA\Libresign\Service\Provider\ProviderProofService::OTP_CHANNELS;
			}
		}
		[$anonymousStatus, $anonymous] = $request('/capabilities', false);
		$result = ['ok' => $passed && $anonymousStatus === 401 && ($anonymous['status'] ?? null) === false,
			'otpChannel' => $policy['otpChannel'] ?? null, 'mockOtpEnabled' => ($policy['mockOtpEnabled'] ?? false) === true,
			'sms' => deploySmsReadiness($policy)];
	} else {
		throw new DeployFailure('Unknown administrative operation.');
	}
	echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (\Throwable $error) {
	// Only our fixed administrative messages may leave this boundary.
	echo json_encode(['failure' => $error instanceof DeployFailure ? $error->getMessage() : 'Nextcloud provisioning failed; inspect the private Nextcloud log.'], JSON_THROW_ON_ERROR) . "\n";
}
