<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Command\Configure;

use OCA\Libresign\Service\Provider\ProviderProofService;
use OCP\IAppConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Merge explicitly selected settings without replacing credentials, scopes or other clients. */
class ProviderClient extends Command {
	public function __construct(
		private IAppConfig $config,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('libresign:provider:configure')->setDescription('Configure an existing integration and show non-secret OTP and registration settings')
			->addArgument('uid', InputArgument::REQUIRED, 'Existing integration account UID')
			->addOption('otp-channel', null, InputOption::VALUE_REQUIRED, 'email, sms or email_sms (same code to both)')
			->addOption('otp-gateway', null, InputOption::VALUE_REQUIRED, 'Configured Two-Factor Gateway name; empty removes the setting')
			->addOption('mock-otp', null, InputOption::VALUE_REQUIRED, 'true accepts any six-digit OTP after delivery; integration testing only; false verifies the actual code')
			->addOption('allow-dossier-reuse', null, InputOption::VALUE_REQUIRED, 'true or false')
			->addOption('mock-registration', null, InputOption::VALUE_REQUIRED, 'true or false; development only')
			->addOption('require-face-match', null, InputOption::VALUE_REQUIRED, 'true or false')
			->addOption('face-threshold', null, InputOption::VALUE_REQUIRED, 'Adapter minimum score')
			->addOption('face-scale', null, InputOption::VALUE_REQUIRED, 'Adapter maximum score')
			->addOption('registration-base-url', null, InputOption::VALUE_REQUIRED, 'Real HTTPS registration page; empty removes the override');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$uid = $input->getArgument('uid');
			$this->config->clearCache();
			$clients = json_decode($this->config->getValueString('libresign', 'provider_clients', '{}'), true, 64, JSON_THROW_ON_ERROR);
			if (!is_array($clients) || !is_array($clients[$uid] ?? null) || !$this->users->get($uid)?->isEnabled()) {
				throw new \InvalidArgumentException('Select an existing enabled integration account.');
			}
			$next = $clients[$uid];
			$channel = $input->getOption('otp-channel');
			$gateway = $input->getOption('otp-gateway');
			if ($channel !== null) {
				if (!in_array($channel, ProviderProofService::OTP_CHANNELS, true)) {
					throw new \InvalidArgumentException('otp-channel must be email, sms or email_sms.');
				}
				$next['otpChannel'] = $channel;
			}
			if ($gateway !== null) {
				if (strlen($gateway) > 128 || preg_match('/[\x00-\x1f\x7f]/', $gateway)) {
					throw new \InvalidArgumentException('Invalid otp-gateway name.');
				}
				$next['otpGateway'] = trim($gateway);
			}
			if (($channel !== null || $gateway !== null) && in_array($next['otpChannel'] ?? '', ['sms', 'email_sms'], true)
				&& (!is_string($next['otpGateway'] ?? null) || trim($next['otpGateway']) === '')) {
				throw new \InvalidArgumentException('Set otp-gateway when enabling sms or email_sms.');
			}
			foreach (['allow-dossier-reuse' => 'edgeDeployment', 'mock-registration' => 'mockRegistrationEnabled', 'mock-otp' => 'mockOtpEnabled', 'require-face-match' => 'requireReuseFaceMatch'] as $option => $key) {
				$value = $input->getOption($option);
				if ($value !== null) {
					if (!in_array($value, ['true', 'false'], true)) {
						throw new \InvalidArgumentException($option . ' must be true or false.');
					}
					$next[$key] = $value === 'true';
				}
			}
			foreach (['face-threshold' => 'reuseFaceThreshold', 'face-scale' => 'reuseFaceScale'] as $option => $key) {
				$value = $input->getOption($option);
				if ($value !== null) {
					if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) {
						throw new \InvalidArgumentException($option . ' must be a finite positive number.');
					}
					$next[$key] = (float)$value;
				}
			}
			if (isset($next['reuseFaceThreshold']) || isset($next['reuseFaceScale']) || !empty($next['requireReuseFaceMatch'])) {
				if (!isset($next['reuseFaceThreshold'], $next['reuseFaceScale']) || $next['reuseFaceThreshold'] > $next['reuseFaceScale']) {
					throw new \InvalidArgumentException('Set both face-threshold and face-scale, with threshold <= scale.');
				}
			}
			$url = $input->getOption('registration-base-url');
			if ($url !== null) {
				if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
					|| parse_url($url, PHP_URL_FRAGMENT) !== null || parse_url($url, PHP_URL_USER) !== null)) {
					throw new \InvalidArgumentException('registration-base-url must be an HTTPS page URL without credentials or a fragment.');
				}
				$next['registrationBaseUrl'] = $url;
			}
			if ($next !== $clients[$uid]) {
				$clients[$uid] = $next;
				$this->config->setValueString('libresign', 'provider_clients', json_encode($clients, JSON_THROW_ON_ERROR));
			}
			$output->writeln(json_encode(['uid' => $uid] + array_intersect_key($next, array_flip([
				'otpChannel', 'otpGateway', 'mockOtpEnabled',
				'edgeDeployment', 'mockRegistrationEnabled', 'requireReuseFaceMatch', 'reuseFaceThreshold', 'reuseFaceScale', 'registrationBaseUrl',
			])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
			return self::SUCCESS;
		} catch (\JsonException|\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}
	}
}
