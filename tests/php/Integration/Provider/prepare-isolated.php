<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Run as the application user. All writes, including CA/tool installation,
// go to a private test data directory and a SQLite backup, never the live DB.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
$testDirectory = $argv[1] ?? '';
if (!str_starts_with($testDirectory, sys_get_temp_dir() . '/provider-integration-') || file_exists($testDirectory)) {
	throw new RuntimeException('Supply a new private /tmp/provider-integration-* directory.');
}
umask(0077);
mkdir($testDirectory . '/config', 0700, true);
mkdir($testDirectory . '/data', 0700, true);
$CONFIG = [];
require '/var/www/html/config/config.php';
if (($CONFIG['dbtype'] ?? '') !== 'sqlite3') {
	throw new RuntimeException('This isolated harness supports the documented SQLite development runtime only.');
}
$database = new SQLite3($CONFIG['datadirectory'] . '/owncloud.db', SQLITE3_OPEN_READONLY);
$backup = new SQLite3($testDirectory . '/data/owncloud.db');
if (!$database->backup($backup)) {
	throw new RuntimeException('Could not snapshot the development database.');
}
$database->close();
$backup->close();
$CONFIG['datadirectory'] = $testDirectory . '/data';
$CONFIG['instanceid'] = 'providertest' . bin2hex(random_bytes(4));
$CONFIG['logfile'] = $testDirectory . '/nextcloud.log';
$CONFIG['maintenance'] = false;
$CONFIG['trusted_domains'][] = '127.0.0.1:18086';
file_put_contents($testDirectory . '/data/.ncdata', '# Nextcloud data directory');
file_put_contents($testDirectory . '/config/config.php', "<?php\n\$CONFIG = " . var_export($CONFIG, true) . ";\n");
putenv('NEXTCLOUD_CONFIG_DIR=' . $testDirectory . '/config');
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
$config = \OCP\Server::get(\OCP\IAppConfig::class);
$tools = [];
if (in_array('--use-runtime-tools', $argv, true)) {
	// Read-only binary paths may be shared; DB, CA, appdata and working files stay isolated.
	foreach (['java_path', 'java_binary', 'jsignpdf_path', 'pdftk_path'] as $key) {
		$value = $config->getValueString('libresign', $key, '');
		if ($value !== '') {
			if (!file_exists($value)) {
				throw new RuntimeException('Configured runtime tool is missing: ' . $key);
			}
			$tools[$key] = $value;
		}
	}
}
foreach (['rootCert', 'ca_id', 'config_path', 'java_path', 'jsignpdf_path', 'java_binary'] as $key) {
	$config->deleteKey('libresign', $key);
}
$pki = $testDirectory . '/pki';
mkdir($pki, 0700);
\OCP\Server::get(\OCA\Libresign\Service\Install\InstallService::class)->generate('Provider Integration Test CA', 'openssl',
	['O' => ['value' => 'Synthetic Test'], 'C' => ['value' => 'VN']], ['configPath' => $pki]);
foreach ($tools as $key => $value) {
	$config->setValueString('libresign', $key, $value);
}
echo "Prepared private SQLite snapshot, data directory and synthetic CA.\n";
