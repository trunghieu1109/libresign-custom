<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\ProviderCertificateController;
use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderAccessService;
use OCA\Libresign\Service\Provider\ProviderCertificateService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ProviderCertificateControllerTest extends TestCase {
	public function testDeniedIntegrationCannotReachIssuanceService(): void {
		$access = $this->createMock(ProviderAccessService::class);
		$access->expects($this->once())->method('requireClient')->with('certificates:write')
			->willThrowException(new ProviderException('forbidden', 'Integration not enabled', 403));
		$service = $this->createMock(ProviderCertificateService::class);
		$service->expects($this->never())->method('confirm');
		$controller = new ProviderCertificateController('libresign', $this->createMock(IRequest::class), $access, $service);
		$response = $controller->confirm('session', '123456');
		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['error']);
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
	}
}
