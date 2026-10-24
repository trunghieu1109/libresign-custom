<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version19002Date20261023180000 extends SimpleMigrationStep {
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		\OCP\Server::get(\OCP\BackgroundJob\IJobList::class)->add(\OCA\Libresign\BackgroundJob\ProviderWorkflowJob::class);
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('libresign_provider_state')) {
			return null;
		}
		$table = $schema->createTable('libresign_provider_state');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->setPrimaryKey(['id']);
		foreach (['record_id' => 36, 'client_uid' => 64, 'kind' => 24, 'key_hash' => 64, 'status' => 32] as $field => $length) {
			$table->addColumn($field, Types::STRING, ['length' => $length, 'notnull' => true]);
		}
		foreach (['created_at', 'updated_at', 'expires_at', 'version'] as $field) {
			$table->addColumn($field, Types::BIGINT, ['notnull' => true]);
		}
		$table->addColumn('encrypted_data', Types::TEXT, ['notnull' => true]);
		$table->addUniqueIndex(['record_id'], 'ls_prov_state_id');
		$table->addUniqueIndex(['client_uid', 'kind', 'key_hash'], 'ls_prov_state_key');
		$table->addIndex(['kind', 'status', 'updated_at'], 'ls_prov_state_jobs');
		return $schema;
	}
}
