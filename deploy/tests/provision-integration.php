<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Opt-in only: prepare-isolated.php must create a disposable private snapshot first.
$configDirectory = getenv('NEXTCLOUD_CONFIG_DIR');
if (PHP_SAPI !== 'cli' || !$configDirectory || !str_starts_with($configDirectory, '/tmp/provider-integration-')) {
	throw new RuntimeException('Requires an isolated /tmp/provider-integration-* NEXTCLOUD_CONFIG_DIR.');
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (\Throwable $error): void {
	fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
	exit(1);
});
if (!str_starts_with(\OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('instanceid'), 'providertest')) {
	throw new RuntimeException('Refusing to test on a non-isolated instance.');
}
$helper = dirname(__DIR__) . '/provision.php';
$assertions = 0;
$check = static function (bool $value, string $message) use (&$assertions): void {
	$assertions++;
	if (!$value) {
		throw new RuntimeException($message);
	}
};
$call = static function (array $input) use ($helper, $configDirectory): array {
	$process = new \Symfony\Component\Process\Process([PHP_BINARY, $helper], '/var/www/html', ['NEXTCLOUD_CONFIG_DIR' => $configDirectory]);
	$process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
	$process->setTimeout(120);
	$process->mustRun();
	return json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
};
$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
$users = \OCP\Server::get(\OCP\IUserManager::class);
$uid = 'deploy-test-' . bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(32));
$credentials = ['uid' => $uid, 'accountPassword' => $password, 'appPassword' => null];
$scopes = ['certificates:read', 'certificates:write', 'certificates:revoke', 'signing:read', 'signing:write'];
$clients = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true, 64, JSON_THROW_ON_ERROR);
$clients['deploy-other'] = ['enabled' => true, 'scopes' => ['certificates:read'], 'callbackSecret' => 'unrelated-secret-keep-exactly'];
$appConfig->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
$root = $appConfig->getValueArray('libresign', 'rootCert', []);
$result = $call(['operation' => 'ca', 'ca' => ['commonName' => 'Ignored on rerun', 'organization' => 'Test', 'country' => 'VN']]);
$check(($result['keptExistingCa'] ?? false) === true, 'Existing CA was not preserved.');
$result = $call(['operation' => 'migrate', 'versions' => ['19002Date20261023120000', '19002Date20261023180000']]);
$check(!isset($result['failure']), 'Migration helper failed.');
$again = $call(['operation' => 'migrate', 'versions' => ['19002Date20261023120000', '19002Date20261023180000']]);
$check(($again['applied'] ?? null) === [], 'Migration rerun was not idempotent.');
$input = ['operation' => 'provision', 'credentials' => $credentials, 'policy' => ['enabled' => true, 'scopes' => $scopes,
	'otpChannel' => 'email', 'mockOtpEnabled' => false, 'trustedSources' => [$uid], 'callbackHosts' => ['adapter.example.test']], 'smtp' => null, 'updateClient' => false];
$result = $call($input);
$check(!isset($result['failure']), 'Provisioning failed: ' . ($result['failure'] ?? 'unknown'));
$credentials = $result['credentials'];
$check(strlen($credentials['appPassword']) === 72, 'Expected Nextcloud App Password.');
$check(strlen($credentials['callbackSecret']) >= 32, 'Expected generated callback secret.');
$check(!\OCP\Server::get(\OCP\IGroupManager::class)->isAdmin($uid), 'Integration was granted admin rights.');
$check($users->checkPassword($uid, $password) !== false, 'New account password did not work.');
$tokens = \OCP\Server::get(\OC\Authentication\Token\IProvider::class);
$count = count($tokens->getTokenByUser($uid));
$check($count === 1, 'Expected exactly one app token.');
$input['credentials'] = $credentials;
$input['policy'] = ['enabled' => false, 'scopes' => []];
$again = $call($input);
$check(($again['credentials']['appPassword'] ?? null) === $credentials['appPassword'], 'Rerun rotated App Password.');
$check(count($tokens->getTokenByUser($uid)) === $count, 'Rerun created another token.');
$appConfig->clearCache();
$after = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true, 64, JSON_THROW_ON_ERROR);
$check($after['deploy-other'] === $clients['deploy-other'], 'Unrelated integration changed.');
$check($after[$uid]['enabled'] === true && $after[$uid]['scopes'] === $scopes, 'Existing integration policy changed without --update-client.');
$check($appConfig->getValueArray('libresign', 'rootCert', []) === $root, 'Root CA metadata changed.');
$input['policy'] = ['mockOtpEnabled' => true];
$result = $call($input);
$check(($result['settings']['policy']['mockOtpEnabled'] ?? null) === false, 'Rerun changed mock OTP without updateClient.');
$input['updateClient'] = true;
$result = $call($input);
$check(($result['settings']['policy']['mockOtpEnabled'] ?? null) === true, 'Explicit mock OTP update did not apply.');
$check($result['credentials']['appPassword'] === $credentials['appPassword'], 'Mock OTP update rotated App Password.');
$input['policy'] = ['mockOtpEnabled' => false];
$result = $call($input);
$check(($result['settings']['policy']['mockOtpEnabled'] ?? null) === false, 'Disabling mock OTP did not apply.');
foreach (['sms', 'email_sms'] as $channel) {
	foreach (['', 'deploy-unavailable-' . $uid] as $gateway) {
		$input['policy'] = ['otpChannel' => $channel, 'otpGateway' => $gateway];
		$result = $call($input);
		$check(!isset($result['failure']), 'Deferred SMS setup blocked core provisioning.');
		$check(($result['settings']['sms']['ready'] ?? null) === false, 'Unconfigured SMS was incorrectly marked ready.');
		$appConfig->clearCache();
		$current = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true, 64, JSON_THROW_ON_ERROR);
		$check($current[$uid]['otpChannel'] === $channel, 'Requested SMS policy was not saved.');
		$check(count($tokens->getTokenByUser($uid)) === $count, 'Deferred SMS setup created an additional App Password.');
	}
}
$newSmsUid = $uid . '-sms';
$smsResult = $call(['operation' => 'provision', 'credentials' => ['uid' => $newSmsUid, 'accountPassword' => $password, 'appPassword' => null],
	'policy' => ['otpChannel' => 'sms', 'otpGateway' => 'deploy-unavailable-' . $uid], 'smtp' => null, 'updateClient' => false]);
$check(!isset($smsResult['failure']), 'Unavailable SMS gateway blocked account creation.');
$check($users->get($newSmsUid) !== null, 'Expected account creation with SMS provider deferred.');
$check(($smsResult['settings']['sms']['status'] ?? null) === 'configuration_pending', 'Expected pending SMS status.');
$invalidSmsUid = $uid . '-invalid-sms';
$invalidSms = $call(['operation' => 'provision', 'credentials' => ['uid' => $invalidSmsUid, 'accountPassword' => $password, 'appPassword' => null],
	'policy' => ['otpChannel' => 'sms', 'otpGateway' => "invalid\nname"], 'smtp' => null, 'updateClient' => false]);
$check(isset($invalidSms['failure']), 'Malformed gateway name was accepted.');
$check($users->get($invalidSmsUid) === null, 'Malformed configuration created an account.');
$input['policy'] = ['otpChannel' => 'email', 'mockOtpEnabled' => false];
$call($input);
$input['policy'] = ['mockRegistrationEnabled' => true];
$input['updateClient'] = true;
$result = $call($input);
$appConfig->clearCache();
$after = json_decode($appConfig->getValueString('libresign', 'provider_clients', '{}'), true, 64, JSON_THROW_ON_ERROR);
$check($after[$uid]['mockRegistrationEnabled'] === true, 'Explicit policy patch did not apply.');
$check($after[$uid]['callbackSecret'] === $credentials['callbackSecret'], 'Patch changed callback secret.');
$check($after[$uid]['scopes'] === $scopes, 'Patch replaced unspecified scopes.');
$input['credentials']['appPassword'] = null;
$input['credentials']['accountPassword'] = 'wrong-existing-password';
$result = $call($input);
$check(isset($result['failure']), 'Existing password mismatch was accepted.');
$check($users->checkPassword($uid, $password) !== false, 'Existing user password was reset.');
$input['credentials'] = ['uid' => $uid . '-invalid-key', 'accountPassword' => $password, 'appPassword' => null];
$input['policy'] = ['assertionPublicKey' => 'invalid-key'];
$result = $call($input);
$check(isset($result['failure']), 'Invalid assertion key was accepted.');
$check($users->get($input['credentials']['uid']) === null, 'Invalid assertion config created a user.');
$input['credentials'] = ['uid' => 'admin', 'accountPassword' => 'unused', 'appPassword' => 'unused'];
$input['policy'] = [];
$result = $call($input);
$check(isset($result['failure']), 'Admin integration account was accepted.');
$result = $call(['operation' => 'check']);
$check(($result['ok'] ?? false) === true, 'Runtime checks failed on the isolated fixture.');
$input['credentials'] = $credentials;
$input['policy'] = [];
$input['smtp'] = ['host' => 'smtp.example.test', 'port' => 587, 'secure' => 'tls', 'auth' => true,
	'username' => 'test-account', 'password' => 'literal-$(not-executed)-$VALUE', 'fromAddress' => 'sign', 'domain' => 'example.test'];
$result = $call($input);
$check(!isset($result['failure']), 'SMTP configuration failed.');
$CONFIG = [];
require $configDirectory . '/config.php';
$check(($CONFIG['mail_smtppassword'] ?? null) === $input['smtp']['password'], 'SMTP secret was not stored literally.');
$check(($CONFIG['mail_smtpport'] ?? null) === 587, 'SMTP port did not apply.');
$appConfig->deleteKey('libresign', 'rootCert');
$result = $call(['operation' => 'ca', 'ca' => ['commonName' => 'Installer Synthetic CA', 'organization' => 'Test', 'country' => 'VN']]);
$check(($result['createdCa'] ?? false) === true, 'Fresh CA creation failed.');
$result = $call(['operation' => 'check']);
$check(($result['ok'] ?? false) === true, 'Generated CA did not pass signing checks.');
$appConfig->deleteKey('libresign', 'rootCert');
$result = $call(['operation' => 'ca', 'ca' => ['commonName' => 'Must Not Replace', 'organization' => 'Test', 'country' => 'VN']]);
$check(isset($result['failure']) && str_contains($result['failure'], 'without metadata'), 'Orphaned CA was silently replaced.');
echo 'PASS: isolated provisioning, rerun, token, policy, migration and CA checks; ' . $assertions . " assertions.\n";
