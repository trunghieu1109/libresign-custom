<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Security\ICrypto;

/** Encrypted identity evidence and immutable signing inputs/results. */
class ProviderArtifactStore {
	public function __construct(
		private IAppDataFactory $factory,
		private ICrypto $crypto,
	) {
	}

	public function put(string $id, string $name, string $content): string {
		$key = hash('sha256', $id . ':' . $name) . '.enc';
		$folder = $this->factory->get('libresign')->getFolder('/')->getOrCreateFolder('provider-artifacts');
		if ($folder->fileExists($key)) {
			if (!hash_equals(hash('sha256', $content), hash('sha256', $this->read($key)))) {
				throw new \RuntimeException('Immutable provider artifact already exists with different content');
			}
			return $key;
		}
		$folder->newFile($key, $this->crypto->encrypt($content));
		return $key;
	}

	public function read(string $key): string {
		if (!preg_match('/^[a-f0-9]{64}\.enc$/D', $key)) {
			throw new \InvalidArgumentException('Invalid provider artifact');
		}
		return $this->crypto->decrypt($this->factory->get('libresign')->getFolder('provider-artifacts')->getFile($key)->getContent());
	}

	public function find(string $id, string $name): ?string {
		try {
			return $this->read(hash('sha256', $id . ':' . $name) . '.enc');
		} catch (\OCP\Files\NotFoundException) {
			return null;
		}
	}
}
