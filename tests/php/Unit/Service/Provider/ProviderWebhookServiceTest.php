<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderStateStore;
use OCA\Libresign\Service\Provider\ProviderWebhookService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class ProviderWebhookServiceTest extends TestCase {
	public function testRetryTimeIsRecheckedAfterAcquiringTheEventLock(): void {
		$store = $this->createMock(ProviderStateStore::class);
		$store->expects($this->once())->method('jobs')->with('event', 'PENDING', 20, false)
			->willReturn([['record_id' => 'event-1', 'client_uid' => 'demo']]);
		$store->method('locked')->willReturnCallback(fn ($key, $callback) => $callback());
		$store->method('get')->willReturn(['status' => 'PENDING', 'data' => ['nextAt' => time() + 3600]]);
		$store->expects($this->never())->method('save');
		$http = $this->createMock(IClientService::class);
		$http->expects($this->never())->method('newClient');
		(new ProviderWebhookService($store, $http, $this->createMock(IAppConfig::class)))->run();
	}

	public function testChangedDeliveryConfigurationDoesNotBlockPersistingCompletion(): void {
		$store = $this->createMock(ProviderStateStore::class);
		$http = $this->createMock(IClientService::class);
		$store->expects($this->once())->method('create')->with('demo', 'event', 'certificate:session', $this->callback(
			fn ($data) => $data['url'] === 'https://adapter.example.test/event' && $data['payload']['status'] === 'ACTIVE'
		), 'PENDING', 0)->willReturn([]);
		$http->expects($this->never())->method('newClient');
		$service = new ProviderWebhookService($store, $http, $this->createMock(IAppConfig::class));
		$service->queue(['uid' => 'demo', 'config' => []], 'https://adapter.example.test/event', 'certificate:session', ['status' => 'ACTIVE']);
	}

	public function testOnlyAllowedHttpsCallbackIsAccepted(): void {
		$service = new ProviderWebhookService($this->createMock(ProviderStateStore::class), $this->createMock(IClientService::class), $this->createMock(IAppConfig::class));
		$client = ['uid' => 'demo', 'config' => ['callbackHosts' => ['adapter.example.test'], 'callbackSecret' => str_repeat('s', 32)]];
		$this->assertSame('https://adapter.example.test/event', $service->validate($client, 'https://adapter.example.test/event'));
		$this->expectException(ProviderException::class);
		$service->validate($client, 'https://attacker.example.test/event');
	}

	public function testSignedPayloadDeliveryUsesPersistedEventAndDoesNotFollowRedirects(): void {
		$store = $this->createMock(ProviderStateStore::class);
		$http = $this->createMock(IClientService::class);
		$config = $this->createMock(IAppConfig::class);
		$secret = str_repeat('secret', 8);
		$client = ['enabled' => true, 'callbackHosts' => ['adapter.example.test'], 'callbackSecret' => $secret];
		$config->method('getValueString')->willReturn(json_encode(['demo' => $client]));
		$event = ['record_id' => 'event-1', 'client_uid' => 'demo', 'status' => 'PENDING',
			'data' => ['nextAt' => 0, 'attempts' => 0, 'url' => 'https://adapter.example.test/event', 'payload' => ['status' => 'ACTIVE', 'certAlias' => 'alias']]];
		$store->method('jobs')->willReturn([$event]);
		$store->method('get')->willReturn($event);
		$store->method('locked')->willReturnCallback(fn ($key, $callback) => $callback());
		$store->expects($this->once())->method('save')->with($this->callback(fn ($saved) => $saved['status'] === 'DELIVERED'));
		$connection = $this->createMock(IClient::class);
		$http->method('newClient')->willReturn($connection);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(204);
		$connection->expects($this->once())->method('post')->with('https://adapter.example.test/event', $this->callback(function ($options) use ($secret): bool {
			return $options['allow_redirects'] === false && $options['headers']['X-Event-Signature'] === hash_hmac('sha256', $options['body'], $secret)
				&& json_decode($options['body'], true)['eventId'] === 'event-1';
		}))->willReturn($response);
		(new ProviderWebhookService($store, $http, $config))->run();
	}

	public function testFailedDeliveryRemainsPendingWithBackoff(): void {
		$store = $this->createMock(ProviderStateStore::class);
		$http = $this->createMock(IClientService::class);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{}');
		$event = ['record_id' => 'event-1', 'client_uid' => 'demo', 'status' => 'PENDING',
			'data' => ['nextAt' => 0, 'attempts' => 0, 'url' => 'https://adapter.example.test/event', 'payload' => []]];
		$store->method('jobs')->willReturn([$event]);
		$store->method('get')->willReturn($event);
		$store->method('locked')->willReturnCallback(fn ($key, $callback) => $callback());
		$store->expects($this->once())->method('save')->with($this->callback(fn ($saved) => $saved['status'] === 'PENDING' && $saved['data']['attempts'] === 1 && $saved['data']['nextAt'] > time()));
		$http->expects($this->never())->method('newClient');
		(new ProviderWebhookService($store, $http, $config))->run();
	}
}
