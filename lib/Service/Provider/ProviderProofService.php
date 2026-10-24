<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\IdentifyMethod\SignatureMethod\TokenService;
use OCP\Security\IHasher;

class ProviderProofService {
	public const OTP_CHANNELS = ['email', 'sms', 'email_sms'];

	public function __construct(
		private TokenService $tokens,
		private IHasher $hasher,
	) {
	}

	/** @return list<string> */
	public function methods(array $config): array {
		$methods = [];
		if (is_string($config['assertionPublicKey'] ?? null) && $config['assertionPublicKey'] !== '') {
			$methods[] = 'external_assertion';
		}
		if ($this->channels($config)) {
			$methods[] = 'service_otp';
		}
		return $methods;
	}

	/** @return list<string> */
	public function channels(array $config): array {
		return match ($config['otpChannel'] ?? null) {
			'email' => ['email'],
			'sms' => ['sms'],
			'email_sms' => ['email', 'sms'],
			default => [],
		};
	}

	public function validateMethod(array $config, array $input): void {
		if (!in_array($input['confirmationMethod'], $this->methods($config), true)) {
			throw new ProviderException('method_not_enabled', 'This confirmation method is not enabled for the integration.', 403);
		}
		if ($input['confirmationMethod'] === 'service_otp') {
			foreach ($this->channels($config) as $channel) {
				$field = $channel === 'email' ? 'email' : 'phoneNumber';
				if (!is_string($input['person'][$field] ?? null) || trim($input['person'][$field]) === '') {
					throw new ProviderException('missing_contact', 'A verified ' . $field . ' is required for OTP delivery.');
				}
			}
			if (in_array('sms', $this->channels($config), true) && (!is_string($config['otpGateway'] ?? null) || trim($config['otpGateway']) === '')) {
				throw new ProviderException('gateway_not_configured', 'The integration has no configured SMS gateway.', 503);
			}
		}
	}

	public function send(array $config, array $input, bool $registration = true): string {
		$this->validateMethod($config, $input);
		try {
			$person = $input['person'];
			return match ($config['otpChannel']) {
				'email' => $registration
					? $this->tokens->sendRegistrationCodeByEmail($person['email'], $person['fullName'])
					: $this->tokens->sendCodeByEmail($person['email'], $person['fullName']),
				'sms' => $this->tokens->sendCodeByGateway($person['phoneNumber'], $config['otpGateway']),
				'email_sms' => $this->tokens->sendCodeByEmailAndGateway($person['email'], $person['fullName'], $person['phoneNumber'], $config['otpGateway'], $registration),
			};
		} catch (\Throwable) {
			throw new ProviderException('delivery_failed', 'The verification code could not be delivered.', 503);
		}
	}

	public function verify(array $config, array $state, string $challenge, string $otpValue, string $assertion): bool {
		$this->validateMethod($config, $state['input']);
		if ($state['input']['confirmationMethod'] === 'service_otp') {
			return preg_match('/^[0-9]{6}$/D', $otpValue) === 1
				&& ($state['codeExpiresAt'] ?? 0) > time()
				&& isset($state['codeHash']) && $state['codeHash'] !== ''
				// Integration mock changes only code comparison, after successful delivery.
				&& (($config['mockOtpEnabled'] ?? false) === true || $this->hasher->verify($otpValue, $state['codeHash']));
		}
		if (strlen($assertion) > 2048 || $assertion === '') {
			return false;
		}
		$key = openssl_pkey_get_public($config['assertionPublicKey']);
		$details = $key ? openssl_pkey_get_details($key) : false;
		if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
			throw new ProviderException('invalid_assertion_key', 'Configure an RSA public key of at least 2048 bits.', 503);
		}
		$signature = base64_decode($assertion, true);
		return $signature !== false && openssl_verify($challenge, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
	}
}
