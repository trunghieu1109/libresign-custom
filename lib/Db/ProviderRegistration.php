<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getRegistrationId()
 * @method void setRegistrationId(string $value)
 * @method string getClientUid()
 * @method void setClientUid(string $value)
 * @method string getRequestKey()
 * @method void setRequestKey(string $value)
 * @method string getSubjectHash()
 * @method void setSubjectHash(string $value)
 * @method string getStatus()
 * @method void setStatus(string $value)
 * @method ?string getSerialNumber()
 * @method void setSerialNumber(?string $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $value)
 * @method int getVersion()
 * @method void setVersion(int $value)
 * @method string getEncryptedData()
 * @method void setEncryptedData(string $value)
 */
class ProviderRegistration extends Entity {
	protected string $registrationId = '';
	protected string $clientUid = '';
	protected string $requestKey = '';
	protected string $subjectHash = '';
	protected string $status = '';
	protected ?string $serialNumber = null;
	protected int $createdAt = 0;
	protected int $expiresAt = 0;
	protected int $version = -1;
	protected string $encryptedData = '';

	public function __construct() {
		foreach (['createdAt', 'expiresAt', 'version'] as $field) {
			$this->addType($field, Types::BIGINT);
		}
	}
}
