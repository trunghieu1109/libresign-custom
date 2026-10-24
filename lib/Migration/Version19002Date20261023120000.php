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

class Version19002Date20261023120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('libresign_provider_reg')) {
			return null;
		}
		$table = $schema->createTable('libresign_provider_reg');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->setPrimaryKey(['id']);
		foreach (['registration_id' => 32, 'client_uid' => 64, 'request_key' => 64, 'subject_hash' => 64, 'status' => 24] as $name => $length) {
			$table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => true]);
		}
		$table->addColumn('serial_number', Types::STRING, ['length' => 128, 'notnull' => false]);
		$table->addColumn('encrypted_data', Types::TEXT, ['notnull' => true]);
		foreach (['created_at', 'expires_at', 'version'] as $name) {
			$table->addColumn($name, Types::BIGINT, ['notnull' => true]);
		}
		$table->addUniqueIndex(['registration_id'], 'ls_prov_reg_id');
		$table->addUniqueIndex(['client_uid', 'request_key'], 'ls_prov_request');
		$table->addIndex(['client_uid', 'subject_hash'], 'ls_prov_subject');
		return $schema;
	}
}
