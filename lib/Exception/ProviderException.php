<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Exception;

class ProviderException extends \RuntimeException {
	public function __construct(
		public readonly string $error,
		string $message,
		int $status = 400,
	) {
		parent::__construct($message, $status);
	}
}
