<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCA\Libresign\Exception\ProviderException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ProviderRegistration> */
class ProviderRegistrationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'libresign_provider_reg', ProviderRegistration::class);
	}

	public function findForClient(string $clientUid, string $id, bool $byRequestKey = false): ProviderRegistration {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($clientUid)))
			->andWhere($qb->expr()->eq($byRequestKey ? 'request_key' : 'registration_id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	/** @return list<ProviderRegistration> */
	public function certificates(string $clientUid, ?string $subjectHash, int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($clientUid)))
			->andWhere($qb->expr()->isNotNull('serial_number'))
			->orderBy('id', 'DESC')->setMaxResults($limit)->setFirstResult($offset);
		if ($subjectHash !== null) {
			$qb->andWhere($qb->expr()->eq('subject_hash', $qb->createNamedParameter($subjectHash)));
		}
		return $this->findEntities($qb);
	}

	public function countCertificates(string $clientUid, ?string $subjectHash): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($clientUid)))
			->andWhere($qb->expr()->isNotNull('serial_number'));
		if ($subjectHash !== null) {
			$qb->andWhere($qb->expr()->eq('subject_hash', $qb->createNamedParameter($subjectHash)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Compare-and-swap protects every mutation, even when file locking is disabled. */
	public function saveState(ProviderRegistration $registration): void {
		$qb = $this->db->getQueryBuilder();
		$version = $registration->getVersion();
		$affected = $qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($registration->getStatus()))
			->set('serial_number', $qb->createNamedParameter($registration->getSerialNumber()))
			->set('encrypted_data', $qb->createNamedParameter($registration->getEncryptedData()))
			->set('version', $qb->createNamedParameter($version + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($registration->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($affected !== 1) {
			throw new ProviderException('concurrent_update', 'The registration changed; retrieve it before retrying.', 409);
		}
		$registration->setVersion($version + 1);
	}
}
