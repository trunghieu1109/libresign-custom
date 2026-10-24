<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use Sabre\DAV\UUIDUtil;

/** Tenant-scoped, encrypted workflow state with optimistic concurrency control. */
class ProviderStateStore {
	public function __construct(
		private IDBConnection $db,
		private ICrypto $crypto,
		private ILockingProvider $locks,
	) {
	}

	public function hash(string $value): string {
		return hash('sha256', $this->crypto->calculateHMAC('provider-state:' . $value));
	}

	public function locked(string $key, callable $callback): mixed {
		$path = 'libresign-provider:' . $this->hash($key);
		try {
			$this->locks->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\OCP\Lock\LockedException) {
			throw new ProviderException('operation_busy', 'This operation is being processed; retry shortly.', 409);
		}
		try {
			return $callback();
		} finally {
			$this->locks->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function transaction(callable $callback): mixed {
		$this->db->beginTransaction();
		try {
			$result = $callback();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function create(string $uid, string $kind, string $key, array $data, string $status = 'PENDING', int $ttl = 900): array {
		$record = ['record_id' => UUIDUtil::getUUID(), 'client_uid' => $uid, 'kind' => $kind,
			'key_hash' => $this->hash($key), 'status' => $status, 'created_at' => time(), 'updated_at' => time(),
			'expires_at' => $ttl ? time() + $ttl : 0, 'version' => 0];
		$qb = $this->db->getQueryBuilder();
		$qb->insert('libresign_provider_state');
		foreach ($record + ['encrypted_data' => $this->encrypt($data)] as $name => $value) {
			$qb->setValue($name, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		}
		try {
			$qb->executeStatement();
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return $this->byKey($uid, $kind, $key);
		}
		return $record + ['data' => $data];
	}

	public function get(string $uid, string $kind, string $id): array {
		return $this->find($uid, $kind, 'record_id', $id);
	}

	public function byKey(string $uid, string $kind, string $key): ?array {
		try {
			return $this->find($uid, $kind, 'key_hash', $this->hash($key));
		} catch (ProviderException $e) {
			if ($e->getCode() !== 404) {
				throw $e;
			}
			return null;
		}
	}

	private function find(string $uid, string $kind, string $column, string $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('libresign_provider_state')
			->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		$row = $qb->executeQuery()->fetchAssociative();
		if (!$row) {
			throw new ProviderException('SYS-0002', 'Resource not found in this integration.', 404);
		}
		return $this->decode($row);
	}

	public function save(array &$record): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('libresign_provider_state')
			->set('encrypted_data', $qb->createNamedParameter($this->encrypt($record['data'])))
			->set('status', $qb->createNamedParameter($record['status']))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->set('version', $qb->createNamedParameter($record['version'] + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($record['record_id'])))
			->andWhere($qb->expr()->eq('client_uid', $qb->createNamedParameter($record['client_uid'])))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($record['version'], IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			throw new ProviderException('concurrent_update', 'Workflow changed; retrieve its current state before retrying.', 409);
		}
		$record['version']++;
		$record['updated_at'] = time();
	}

	public function jobs(string $kind, string $status, int $limit = 20): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('libresign_provider_state')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('updated_at', 'ASC')->setMaxResults($limit);
		return array_map($this->decode(...), $qb->executeQuery()->fetchAll());
	}

	/** Paginated workflow records; always constrain both tenant and workflow kind. */
	public function listing(string $uid, string $kind, int $page, int $size): array {
		if (!in_array($kind, ['enrollment', 'signing'], true) || $page < 1 || $size < 1 || $size > 100) {
			throw new ProviderException('invalid_pagination', 'Invalid workflow kind or pagination.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->from('libresign_provider_state')
			->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		$count = clone $qb;
		$count->select($count->func()->count('*'));
		$total = (int)$count->executeQuery()->fetchOne();
		$qb->select('*')->orderBy('created_at', 'DESC')->addOrderBy('record_id', 'DESC')
			->setFirstResult(($page - 1) * $size)->setMaxResults($size);
		return ['items' => array_map($this->decode(...), $qb->executeQuery()->fetchAll()), 'totalItem' => $total];
	}

	private function decode(array $row): array {
		foreach (['created_at', 'updated_at', 'expires_at', 'version'] as $key) {
			$row[$key] = (int)$row[$key];
		}
		$row['data'] = json_decode($this->crypto->decrypt($row['encrypted_data']), true, 128, JSON_THROW_ON_ERROR);
		unset($row['encrypted_data'], $row['id']);
		return $row;
	}

	private function encrypt(array $data): string {
		return $this->crypto->encrypt(json_encode($data, JSON_THROW_ON_ERROR));
	}
}
