<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCP\IAppConfig;
use OCP\IUserSession;

class ProviderAccessService {
	public function __construct(
		private IAppConfig $appConfig,
		private IUserSession $userSession,
	) {
	}

	/** @return array{uid: string, config: array} */
	public function requireClient(string $scope): array {
		$user = $this->userSession->getUser();
		if ($user === null || !$user->isEnabled()) {
			throw new ProviderException('unauthenticated', 'Integration authentication is required.', 401);
		}
		// CLI administration and PHP-FPM can have separate local caches. Refresh
		// authorization so disabling a client or removing a scope takes effect now.
		$this->appConfig->clearCache();
		$clients = json_decode($this->appConfig->getValueString('libresign', 'provider_clients', '{}'), true);
		$config = is_array($clients) ? ($clients[$user->getUID()] ?? null) : null;
		if (!is_array($config) || ($config['enabled'] ?? false) !== true
			|| !is_array($config['scopes'] ?? null) || !in_array($scope, $config['scopes'], true)) {
			throw new ProviderException('forbidden', 'This integration is not allowed to perform this operation.', 403);
		}
		return ['uid' => $user->getUID(), 'config' => $config];
	}
}
