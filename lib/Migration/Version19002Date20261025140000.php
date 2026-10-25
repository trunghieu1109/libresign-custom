<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

/** Add internal list/queue indexes without changing encrypted workflow payloads. */
class Version19002Date20261025140000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private ICrypto $crypto,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$registrations = $schema->getTable('libresign_provider_reg');
		if (!$registrations->hasIndex('ls_prov_client_list')) {
			$registrations->addIndex(['client_uid', 'id'], 'ls_prov_client_list');
		}
		$state = $schema->getTable('libresign_provider_state');
		if (!$state->hasColumn('next_attempt_at')) {
			$state->addColumn('next_attempt_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		}
		if (!$state->hasIndex('ls_prov_state_list')) {
			$state->addIndex(['client_uid', 'kind', 'created_at', 'record_id'], 'ls_prov_state_list');
		}
		if (!$state->hasIndex('ls_prov_event_due')) {
			$state->addIndex(['kind', 'status', 'next_attempt_at', 'updated_at'], 'ls_prov_event_due');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Read one event at a time: old callback payloads can contain a whole PDF batch.
		$after = 0;
		while (true) {
			$qb = $this->db->getQueryBuilder();
			$row = $qb->select('id', 'version', 'encrypted_data')->from('libresign_provider_state')
				->where($qb->expr()->eq('kind', $qb->createNamedParameter('event')))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('PENDING')))
				->andWhere($qb->expr()->eq('next_attempt_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
				->orderBy('id')->setMaxResults(1)->executeQuery()->fetchAssociative();
			if (!$row) {
				return;
			}
			$after = (int)$row['id'];
			$data = json_decode($this->crypto->decrypt($row['encrypted_data']), true, 128, JSON_THROW_ON_ERROR);
			$qb = $this->db->getQueryBuilder();
			$qb->update('libresign_provider_state')
				->set('next_attempt_at', $qb->createNamedParameter((int)($data['nextAt'] ?? 0), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('version', $qb->createNamedParameter((int)$row['version'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}
}
