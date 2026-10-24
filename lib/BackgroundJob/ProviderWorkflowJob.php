<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Service\Provider\ProviderWebhookService;
use OCA\Libresign\Service\Provider\PublicSigningService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class ProviderWorkflowJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private PublicSigningService $signing,
		private ProviderWebhookService $webhooks,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	public function run($argument): void {
		$this->signing->run();
		$this->webhooks->run();
	}
}
