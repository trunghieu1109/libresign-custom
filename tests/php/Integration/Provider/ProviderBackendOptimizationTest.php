<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Integration\Provider;

use DateTime;
use Doctrine\DBAL\Logging\DebugStack;
use OC\Migration\NullOutput;
use OCA\Libresign\Db\CrlMapper;
use OCA\Libresign\Db\ProviderRegistration;
use OCA\Libresign\Db\ProviderRegistrationMapper;
use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Migration\Version19002Date20261025140000;
use OCA\Libresign\Service\Provider\ProviderCertificateService;
use OCA\Libresign\Service\Provider\ProviderStateStore;
use OCA\Libresign\Service\Provider\PublicRegistrationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Real SQL and encryption on a disposable SQLite/datadir prepared by prepare-isolated.php. */
class ProviderBackendOptimizationTest extends TestCase {
	private IDBConnection $db;
	private ICrypto $crypto;
	private string $uid;
	private array $client;
	private array $records = [];
	private string $personalId = '001234567890';

	protected function setUp(): void {
		if (!str_starts_with(Server::get(IConfig::class)->getSystemValueString('instanceid'), 'providertest')) {
			$this->markTestSkipped('Requires a private provider integration snapshot.');
		}
		$this->db = Server::get(IDBConnection::class);
		$this->crypto = Server::get(ICrypto::class);
		$this->uid = 'provider-perf-' . bin2hex(random_bytes(5));
		$this->client = ['uid' => $this->uid, 'config' => []];
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		if (isset($this->db)) {
			$this->db->getInner()->getConfiguration()->setSQLLogger(null);
			$this->db->rollBack();
		}
	}

	private function seed(int $count, string $status = 'ACTIVE', ?string $uid = null): void {
		$uid ??= $this->uid;
		$mapper = Server::get(ProviderRegistrationMapper::class);
		$crl = Server::get(CrlMapper::class);
		for ($i = 0; $i < $count; $i++) {
			$serial = (string)random_int(100000000000, 999999999999);
			$validTo = $status === 'EXPIRED' ? time() - 60 : 1893456000;
			$state = ['input' => ['person' => ['personalIdNumber' => $this->personalId, 'fullName' => 'Synthetic Signer',
				'phoneNumber' => '+84901234567', 'email' => 'synthetic@example.test'], 'confirmationMethod' => 'external_assertion'],
				'certificate' => ['serialNumber' => $serial, 'validFrom' => 1704067200, 'validTo' => $validTo, 'fingerprintSha256' => hash('sha256', $serial)]];
			$r = new ProviderRegistration();
			$r->setRegistrationId(bin2hex(random_bytes(16)));
			$r->setClientUid($uid);
			$r->setRequestKey(bin2hex(random_bytes(32)));
			$r->setSubjectHash(hash('sha256', $this->crypto->calculateHMAC('provider-subject:' . json_encode([$uid, $this->personalId], JSON_THROW_ON_ERROR))));
			$r->setStatus('ACTIVE');
			$r->setSerialNumber($serial);
			$r->setVersion(0);
			$r->setCreatedAt(time());
			$r->setExpiresAt(time() + 900);
			$r->setEncryptedData($this->crypto->encrypt(json_encode($state, JSON_THROW_ON_ERROR)));
			$mapper->insert($r);
			$native = $crl->createCertificate($serial, 'provider:synthetic', 'openssl', 'test', 1,
				new DateTime('@1704067200'), new DateTime('@' . $validTo));
			if ($status === 'REVOKED') {
				$native->setStatus('revoked');
				$crl->update($native);
			}
			$this->records[] = $r;
		}
	}

	private function measured(callable $call): array {
		$logger = new DebugStack();
		$this->db->getInner()->getConfiguration()->setSQLLogger($logger);
		$start = hrtime(true);
		$result = $call();
		$milliseconds = (hrtime(true) - $start) / 1000000;
		$this->db->getInner()->getConfiguration()->setSQLLogger(null);
		$queries = array_filter($logger->queries, fn ($q) => str_contains($q['sql'], 'libresign_provider_reg')
			|| str_contains($q['sql'], 'libresign_provider_state') || str_contains($q['sql'], 'libresign_crl'));
		return ['result' => $result, 'queries' => count($queries), 'milliseconds' => $milliseconds];
	}

	private function medianMeasurement(callable $call): array {
		$samples = [];
		for ($i = 0; $i < 5; $i++) {
			$measurement = $this->measured($call);
			$samples[] = $measurement['milliseconds'];
		}
		sort($samples);
		$measurement['milliseconds'] = $samples[2];
		return $measurement;
	}

	public function testPublicPageUsesThreeQueriesAndReflectsCurrentRevocation(): void {
		$this->seed(25);
		$service = Server::get(PublicRegistrationService::class);
		$page = $this->measured(fn () => $service->listing($this->client, '', 1, 20));
		$this->assertSame(3, $page['queries']);
		$this->assertCount(20, $page['result']['items']);
		$this->assertSame(25, $page['result']['totalItem']);
		$this->assertSame(5, count($service->listing($this->client, $this->personalId, 2, 20)['items']));
		$this->assertSame(['items' => [], 'totalItem' => 0], $service->listing(['uid' => 'unrelated'], '', 1, 20));
		$last = $this->records[24];
		$native = Server::get(CrlMapper::class)->findBySerialNumber($last->getSerialNumber());
		$native->setStatus('revoked');
		Server::get(CrlMapper::class)->update($native);
		$this->assertSame('REVOKED', $service->listing($this->client, '', 1, 20)['items'][0]['status']);
		$this->assertSame('REVOKED', $service->certificate($this->client, $last->getRegistrationId())['status']);
		$this->expectExceptionCode(404);
		$service->certificate(['uid' => 'unrelated'], $last->getRegistrationId());
	}

	public function testActiveLookupSkipsHistoryAndRetainsAmbiguityAndTenantIsolation(): void {
		$this->seed(110, 'REVOKED');
		$this->seed(110, 'EXPIRED');
		$this->seed(1);
		$this->seed(1, uid: 'another-tenant');
		$service = Server::get(ProviderCertificateService::class);
		$found = $this->measured(fn () => $service->activeCertificates($this->client, $this->personalId));
		$this->assertSame(2, $found['queries']);
		$this->assertCount(1, $found['result']);
		$this->assertSame($this->records[220]->getRegistrationId(), $found['result'][0]['certAlias']);
		$this->assertSame([], $service->activeCertificates(['uid' => 'unrelated'], $this->personalId));
		$this->seed(2);
		$this->assertCount(2, $service->activeCertificates($this->client, $this->personalId));
	}

	public function testMissingRevocationRecordStillFailsClosed(): void {
		$this->seed(1);
		$crl = Server::get(CrlMapper::class);
		$crl->delete($crl->findBySerialNumber($this->records[0]->getSerialNumber()));
		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		Server::get(ProviderCertificateService::class)->activeCertificates($this->client, $this->personalId);
	}

	public function testCallbackDueFilteringPrecedesLimitAndMigrationPreservesPayload(): void {
		$store = Server::get(ProviderStateStore::class);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('libresign_provider_state')->where($qb->expr()->eq('kind', $qb->createNamedParameter('event')))->executeStatement();
		$future = time() + 3600;
		for ($i = 0; $i < 21; $i++) {
			$event = $store->create($this->uid, 'event', 'event-' . $i, ['nextAt' => $i < 20 ? $future : time() - 1], 'PENDING', 0);
		}
		$due = $store->jobs('event', 'PENDING', 20, false);
		$this->assertContains($event['record_id'], array_column($due, 'record_id'));
		$this->assertArrayNotHasKey('data', $due[0]);
		$stale = $event;
		$event['data']['nextAt'] = $future;
		$store->save($event);
		try {
			$store->save($stale);
			$this->fail('A stale worker must not overwrite the current retry schedule.');
		} catch (ProviderException $e) {
			$this->assertSame('concurrent_update', $e->error);
		}
		$this->assertNotContains($event['record_id'], array_column($store->jobs('event', 'PENDING', 20, false), 'record_id'));
		// Simulate a pre-upgrade pending event; the migration only projects its retry time.
		$qb = $this->db->getQueryBuilder();
		$qb->update('libresign_provider_state')->set('next_attempt_at', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($event['record_id'])))->executeStatement();
		$before = $store->get($this->uid, 'event', $event['record_id']);
		$migration = new Version19002Date20261025140000($this->db, $this->crypto);
		$migration->postSchemaChange(new NullOutput(), fn () => null, []);
		$migration->postSchemaChange(new NullOutput(), fn () => null, []);
		$after = $store->get($this->uid, 'event', $event['record_id']);
		$this->assertSame($before['data'], $after['data']);
		$this->assertSame($before['version'], $after['version']);
		$this->assertSame($future, (int)$after['next_attempt_at']);
		$this->assertNotContains($event['record_id'], array_column($store->jobs('event', 'PENDING', 20, false), 'record_id'));
	}

	public function testMigrationCanRunAgainAndListQueriesUseTheirOrderingIndexes(): void {
		$schema = new \OC\DB\SchemaWrapper($this->db->getInner());
		$migration = new Version19002Date20261025140000($this->db, $this->crypto);
		$migration->changeSchema(new NullOutput(), fn () => $schema, []);
		$migration->changeSchema(new NullOutput(), fn () => $schema, []);
		$this->assertTrue($schema->getTable('libresign_provider_state')->hasColumn('next_attempt_at'));
		$this->assertTrue($schema->getTable('libresign_provider_reg')->hasIndex('ls_prov_client_list'));
		$this->assertTrue($schema->getTable('libresign_provider_state')->hasIndex('ls_prov_state_list'));
		$this->assertTrue($schema->getTable('libresign_provider_state')->hasIndex('ls_prov_event_due'));
		$prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		foreach ([
			"SELECT * FROM {$prefix}libresign_provider_reg WHERE client_uid = ? AND serial_number IS NOT NULL ORDER BY id DESC LIMIT 21",
			"SELECT * FROM {$prefix}libresign_provider_state WHERE client_uid = ? AND kind = 'enrollment' ORDER BY created_at DESC, record_id DESC LIMIT 20",
		] as $sql) {
			$plan = $this->db->executeQuery('EXPLAIN QUERY PLAN ' . $sql, [$this->uid])->fetchAll();
			$this->assertStringNotContainsString('TEMP B-TREE', json_encode($plan, JSON_THROW_ON_ERROR));
		}
	}

	public function testBaselineResponsesAndMeasuredQueryCounts(): void {
		$baseline = getenv('LIBRESIGN_PROVIDER_BASELINE_DIR');
		if (!$baseline) {
			$this->markTestSkipped('Optional comparison requires a git archive of the original backend.');
		}
		foreach (['ProviderCertificateService', 'PublicRegistrationService'] as $name) {
			$source = file_get_contents($baseline . '/lib/Service/Provider/' . $name . '.php');
			$source = str_replace('class ' . $name . ' {', 'class ' . $name . 'Baseline extends ' . $name . ' {', $source);
			eval(substr($source, 5));
		}
		$make = static function (string $class, array $overrides = []): object {
			$constructor = (new \ReflectionClass($class))->getConstructor();
			return new $class(...array_map(fn ($p) => $overrides[$p->getType()->getName()] ?? Server::get($p->getType()->getName()), $constructor->getParameters()));
		};
		$legacyCertificates = $make(ProviderCertificateService::class . 'Baseline');
		$legacy = $make(PublicRegistrationService::class . 'Baseline', [ProviderCertificateService::class => $legacyCertificates]);
		$optimized = Server::get(PublicRegistrationService::class);
		$this->seed(100);
		$metrics = [];
		foreach ([20, 100] as $size) {
			$old = $this->medianMeasurement(fn () => $legacy->listing($this->client, '', 1, $size));
			$new = $this->medianMeasurement(fn () => $optimized->listing($this->client, '', 1, $size));
			$this->assertSame($old['result'], $new['result']);
			$this->assertSame(2 + 2 * $size, $old['queries']);
			$this->assertSame(3, $new['queries']);
			unset($old['result'], $new['result']);
			$metrics['certificate_page_' . $size] = ['before' => $old, 'after' => $new];
		}
		$alias = $this->records[0]->getRegistrationId();
		$old = $this->medianMeasurement(fn () => $legacy->certificate($this->client, $alias));
		$new = $this->medianMeasurement(fn () => $optimized->certificate($this->client, $alias));
		$this->assertSame($old['result'], $new['result']);
		$this->assertSame(3, $old['queries']);
		$this->assertSame(2, $new['queries']);
		unset($old['result'], $new['result']);
		$metrics['certificate_detail'] = ['before' => $old, 'after' => $new];
		$identity = Server::get(ProviderCertificateService::class)->identity($this->client, $alias);
		$enrollment = Server::get(ProviderStateStore::class)->create($this->uid, 'enrollment', 'issued-session', [
			'input' => $identity + ['registrationMethod' => 'CARD_READER', 'reusePreviousDossier' => false, 'requireReuseFaceMatch' => false],
			'legacySession' => $alias, 'reusedCertificate' => false, 'dossier' => null, 'step' => 'COMPLETE',
		], 'ACTIVE');
		$old = $this->medianMeasurement(fn () => $legacy->registration($this->client, $enrollment['record_id']));
		$new = $this->medianMeasurement(fn () => $optimized->registration($this->client, $enrollment['record_id']));
		$this->assertSame($old['result'], $new['result']);
		$this->assertSame(6, $old['queries']);
		$this->assertSame(3, $new['queries']);
		unset($old['result'], $new['result']);
		$metrics['completed_enrollment_status'] = ['before' => $old, 'after' => $new];
		$crl = Server::get(CrlMapper::class);
		foreach ($this->records as $r) {
			$native = $crl->findBySerialNumber($r->getSerialNumber());
			$native->setStatus('revoked');
			$crl->update($native);
		}
		$this->seed(120, 'EXPIRED');
		$this->seed(1);
		$old = $this->medianMeasurement(function () use ($legacyCertificates): array {
			$found = [];
			$page = 0;
			do {
				$list = $legacyCertificates->certificates($this->client, $this->personalId, $page++, 100);
				foreach ($list['items'] as $item) {
					if ($item['status'] === 'ACTIVE') {
						$found[] = $item;
					}
				}
			} while ($list['hasMore']);
			return $found;
		});
		$new = $this->medianMeasurement(fn () => Server::get(ProviderCertificateService::class)->activeCertificates($this->client, $this->personalId));
		$this->assertSame($old['result'], $new['result']);
		$this->assertSame(224, $old['queries']);
		$this->assertSame(2, $new['queries']);
		unset($old['result'], $new['result']);
		$metrics['active_lookup_221_records'] = ['before' => $old, 'after' => $new];
		if ($path = getenv('LIBRESIGN_PROVIDER_BENCHMARK_OUTPUT')) {
			file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
		}
	}
}
