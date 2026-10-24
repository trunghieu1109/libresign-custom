<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;

class ProviderWebhookService {
	public function __construct(
		private ProviderStateStore $store,
		private IClientService $http,
		private IAppConfig $config,
	) {
	}

	public function validate(array $client, mixed $url): ?string {
		if ($url === null || $url === '') {
			return null;
		}
		$url = ProviderContract::text($url, 'callbackUrl', 2048);
		if (!is_string($client['config']['callbackSecret'] ?? null) || strlen($client['config']['callbackSecret']) < 32) {
			throw new ProviderException('callback_not_configured', 'Configure a callbackSecret of at least 32 characters.', 503);
		}
		$parts = parse_url($url);
		if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
			|| !in_array(strtolower($parts['host'] ?? ''), $client['config']['callbackHosts'] ?? [], true)) {
			throw new ProviderException('callback_not_allowed', 'Configure an allowed HTTPS callback host for this integration.', 403);
		}
		return $url;
	}

	public function queue(array $client, ?string $url, string $key, array $payload): void {
		if (!$url) {
			return;
		}
		// Workflows already validated this frozen URL at request creation. A later
		// delivery configuration change must not roll back an issued certificate or
		// completed signing state. The worker revalidates current policy before HTTP.
		$this->store->create($client['uid'], 'event', $key, ['url' => $url, 'payload' => $payload, 'attempts' => 0, 'nextAt' => time()], 'PENDING', 0);
	}

	public function run(): void {
		foreach ($this->store->jobs('event', 'PENDING') as $event) {
			if ($event['data']['nextAt'] > time()) {
				continue;
			}
			try {
				$this->store->locked('event:' . $event['record_id'], function () use ($event): void {
					$event = $this->store->get($event['client_uid'], 'event', $event['record_id']);
					if ($event['status'] !== 'PENDING') {
						return;
					}
					$clients = json_decode($this->config->getValueString('libresign', 'provider_clients', '{}'), true);
					$client = ['uid' => $event['client_uid'], 'config' => $clients[$event['client_uid']] ?? []];
					try {
						if (empty($client['config']['enabled']) || empty($client['config']['callbackSecret'])) {
							throw new ProviderException('callback_unavailable', 'Enabled callback configuration is required.', 503);
						}
						$this->validate($client, $event['data']['url']);
						$body = json_encode($event['data']['payload'] + ['eventId' => $event['record_id']], JSON_THROW_ON_ERROR);
						$response = $this->http->newClient()->post($event['data']['url'], ['body' => $body, 'timeout' => 15,
							'allow_redirects' => false, 'headers' => ['Content-Type' => 'application/json', 'X-Event-Id' => $event['record_id'],
								'X-Event-Signature' => hash_hmac('sha256', $body, $client['config']['callbackSecret'])]]);
						if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
							throw new \RuntimeException('Callback returned non-success status');
						}
						$event['status'] = 'DELIVERED';
					} catch (\Throwable) {
						$event['data']['attempts']++;
						$event['data']['nextAt'] = time() + min(3600, 60 * (2 ** min(6, $event['data']['attempts'])));
						if ($event['data']['attempts'] >= 8) {
							$event['status'] = 'FAILED';
						}
					}
					$this->store->save($event);
				});
			} catch (\Throwable) {
				// Another worker may own this event; polling remains the source of truth.
			}
		}
	}
}
