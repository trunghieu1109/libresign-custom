<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\ProviderManagementController;
use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderManagementService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProviderManagementControllerTest extends TestCase {
	public function testEveryEndpointRetainsAdminAuthenticationAndMutationRequiresCsrf(): void {
		foreach (['clients', 'records', 'record', 'publicCertificate', 'pdf', 'action'] as $name) {
			$method = new \ReflectionMethod(ProviderManagementController::class, $name);
			$this->assertEmpty($method->getAttributes(NoAdminRequired::class));
			$this->assertEmpty($method->getAttributes(PublicPage::class));
			if ($name === 'action') {
				$this->assertEmpty($method->getAttributes(NoCSRFRequired::class));
			}
		}
	}

	public function testPermissionErrorHasNoStoreHeader(): void {
		$service = $this->createMock(ProviderManagementService::class);
		$service->method('clients')->willThrowException(new ProviderException('forbidden', 'Admin required', 403));
		$controller = new ProviderManagementController($this->createMock(IRequest::class), $service, $this->createMock(LoggerInterface::class));
		$response = $controller->clients();
		$this->assertSame(403, $response->getStatus());
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
	}

	public function testPdfIsInlineAndDownloadIsAttachment(): void {
		$service = $this->createMock(ProviderManagementService::class);
		$service->method('pdf')->with('partner', 'session', '0', 'result')->willReturn(['content' => '%PDF-fixture', 'name' => 'signed-test.pdf']);
		$controller = new ProviderManagementController($this->createMock(IRequest::class), $service, $this->createMock(LoggerInterface::class));
		$inline = $controller->pdf('partner', 'session', '0');
		$this->assertSame('%PDF-fixture', $inline->render());
		$this->assertStringStartsWith('inline;', $inline->getHeaders()['Content-Disposition']);
		$this->assertSame('application/pdf', $inline->getHeaders()['Content-Type']);
		$this->assertMatchesRegularExpression("/(?:^|;)frame-ancestors 'self'(?:;|$)/", $inline->getHeaders()['Content-Security-Policy']);
		$this->assertStringContainsString("default-src 'none'", $inline->getHeaders()['Content-Security-Policy']);
		$download = $controller->pdf('partner', 'session', '0', 'result', true);
		$this->assertStringStartsWith('attachment;', $download->getHeaders()['Content-Disposition']);
		$this->assertStringContainsString("frame-ancestors 'none'", $download->getHeaders()['Content-Security-Policy']);
	}

	public function testPdfPermissionErrorRemainsUnframeableAndDoesNotReturnFileBytes(): void {
		$service = $this->createMock(ProviderManagementService::class);
		$service->method('pdf')->willThrowException(new ProviderException('forbidden', 'Admin required', 403));
		$controller = new ProviderManagementController($this->createMock(IRequest::class), $service, $this->createMock(LoggerInterface::class));
		$response = $controller->pdf('partner', 'session', '0');
		$this->assertSame(403, $response->getStatus());
		$this->assertStringContainsString("frame-ancestors 'none'", $response->getHeaders()['Content-Security-Policy']);
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
	}
}
