<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ICrypto;

/** Short-lived, enrollment-only capabilities for the opt-in development page. */
class ProviderRegistrationLinkService {
	public function __construct(
		private ICrypto $crypto,
		private IURLGenerator $urls,
		private IAppConfig $config,
		private IUserManager $users,
	) {
	}

	public function validate(array $client): void {
		$base = $client['config']['registrationBaseUrl'] ?? '';
		if ($base !== '') {
			if (!is_string($base) || !filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https'
				|| parse_url($base, PHP_URL_FRAGMENT) !== null || parse_url($base, PHP_URL_USER) !== null) {
				throw new ProviderException('adapter_ui_required', 'registrationBaseUrl must be an HTTPS page URL without credentials or a fragment.', 503);
			}
		} elseif (($client['config']['mockRegistrationEnabled'] ?? false) !== true) {
			throw new ProviderException('adapter_ui_required', 'Configure registrationBaseUrl or explicitly enable the development registration mock.', 503);
		}
	}

	public function link(array $client, array $record): array {
		$this->validate($client);
		$base = $client['config']['registrationBaseUrl'] ?? '';
		if ($base !== '') {
			return ['registrationUrl' => $base . (str_contains($base, '?') ? '&' : '?') . 'sessionId=' . rawurlencode($record['record_id']),
				'registrationMode' => 'adapter'];
		}
		$payload = rtrim(strtr(base64_encode(json_encode(['purpose' => 'provider-registration-mock-v1',
			'client' => $client['uid'], 'session' => $record['record_id'], 'expires' => $record['expires_at']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		$token = $payload . '.' . $this->mac($payload);
		return ['registrationUrl' => $this->urls->linkToRouteAbsolute('libresign.providerMock.show', ['token' => $token]),
			'registrationMode' => 'mock'];
	}

	/** Re-read authorization on every anonymous request; a UUID alone grants nothing. */
	public function resolve(string $token): array {
		$parts = explode('.', $token);
		if (strlen($token) > 2048 || count($parts) !== 2 || !hash_equals($this->mac($parts[0]), $parts[1])) {
			throw new ProviderException('invalid_registration_link', 'The registration link is invalid.', 403);
		}
		try {
			$data = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true), true, 8, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new ProviderException('invalid_registration_link', 'The registration link is invalid.', 403);
		}
		if (!is_array($data) || ($data['purpose'] ?? '') !== 'provider-registration-mock-v1'
			|| !is_string($data['client'] ?? null) || !is_string($data['session'] ?? null) || !is_int($data['expires'] ?? null)) {
			throw new ProviderException('invalid_registration_link', 'The registration link is invalid.', 403);
		}
		if ($data['expires'] <= time()) {
			throw new ProviderException('registration_link_expired', 'The registration link has expired. Create a new session.', 410);
		}
		$this->config->clearCache();
		$clients = json_decode($this->config->getValueString('libresign', 'provider_clients', '{}'), true);
		$client = is_array($clients) ? ($clients[$data['client']] ?? null) : null;
		if (!is_array($client) || ($client['enabled'] ?? false) !== true || ($client['mockRegistrationEnabled'] ?? false) !== true
			|| !is_array($client['scopes'] ?? null) || !in_array('certificates:write', $client['scopes'], true)
			|| !$this->users->get($data['client'])?->isEnabled()) {
			throw new ProviderException('registration_mock_disabled', 'This registration mock is unavailable.', 403);
		}
		return ['client' => ['uid' => $data['client'], 'config' => $client], 'sessionId' => $data['session'], 'expiresAt' => $data['expires']];
	}

	private function mac(string $payload): string {
		return bin2hex($this->crypto->calculateHMAC('libresign:registration-mock:' . $payload));
	}
}
