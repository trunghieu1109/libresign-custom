<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ICrypto;

/** Private application storage; never puts a managed PFX into a user's Files. */
class ProviderCredentialStore {
	public function __construct(
		private IAppDataFactory $appDataFactory,
		private ICrypto $crypto,
	) {
	}

	private function folder(): ISimpleFolder {
		return $this->appDataFactory->get('libresign')->getFolder('/')->getOrCreateFolder('provider-credentials');
	}

	public function save(string $alias, string $pfx): void {
		$this->assertAlias($alias);
		$this->folder()->newFile($alias . '.pfx.enc', $this->crypto->encrypt($pfx));
	}

	public function read(string $alias): string {
		$this->assertAlias($alias);
		return $this->crypto->decrypt($this->folder()->getFile($alias . '.pfx.enc')->getContent());
	}

	private function assertAlias(string $alias): void {
		if (!preg_match('/^[a-zA-Z0-9]{32}$/D', $alias)) {
			throw new \InvalidArgumentException('Invalid managed certificate alias');
		}
	}
}
