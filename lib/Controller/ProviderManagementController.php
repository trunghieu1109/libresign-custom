<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderManagementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** All routes retain Nextcloud's default administrator requirement. */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class ProviderManagementController extends Controller {
	public function __construct(IRequest $request, private ProviderManagementService $management, private LoggerInterface $logger) {
		parent::__construct('libresign', $request);
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/management/clients')]
	public function clients(): Response {
		return $this->respond(fn () => $this->management->clients());
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/management/records')]
	public function records(string $clientUid, string $kind, int $pageIndex = 1, int $pageSize = 20, string $personalIdNumber = ''): Response {
		return $this->respond(fn () => $this->management->listing($clientUid, $kind, $pageIndex, $pageSize, $personalIdNumber));
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/management/record')]
	public function record(string $clientUid, string $kind, string $id): Response {
		return $this->respond(fn () => $this->management->detail($clientUid, $kind, $id));
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/management/public-certificate')]
	public function publicCertificate(string $clientUid, string $id): Response {
		return $this->respond(fn () => $this->management->publicCertificate($clientUid, $id));
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/management/pdf')]
	public function pdf(string $clientUid, string $sessionId, string $documentId, string $version = 'result', bool $download = false): Response {
		return $this->respond(function () use ($clientUid, $sessionId, $documentId, $version, $download) {
			$pdf = $this->management->pdf($clientUid, $sessionId, $documentId, $version);
			if ($download) {
				return new DataDownloadResponse($pdf['content'], $pdf['name'], 'application/pdf');
			}
			$response = new DataDisplayResponse($pdf['content'], 200, ['Content-Type' => 'application/pdf']);
			// Binary responses default to frame-ancestors 'none'. Permit only the
			// same-origin management page, while retaining the restrictive defaults.
			$policy = new EmptyContentSecurityPolicy();
			$policy->addAllowedFrameAncestorDomain("'self'");
			$response->setContentSecurityPolicy($policy);
			$response->addHeader('Content-Disposition', "inline; filename*=UTF-8''" . rawurlencode($pdf['name']));
			return $response;
		});
	}

	// Mutations require the regular Nextcloud requesttoken/CSRF check.
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/management/action')]
	public function action(string $clientUid, string $kind, string $action, string $id = '', array $payload = [], string $requestId = ''): Response {
		return $this->respond(fn () => $this->management->action($clientUid, $kind, $id, $action, $payload, $requestId));
	}

	private function respond(callable $operation): Response {
		try {
			$data = $operation();
			$response = $data instanceof Response ? $data : new DataResponse(['data' => $data]);
		} catch (ProviderException $e) {
			$response = new DataResponse(['error' => ['code' => $e->error, 'message' => $e->getMessage()]], $e->getCode());
		} catch (\Throwable $e) {
			$this->logger->error('Provider management failed', ['exceptionClass' => $e::class]);
			$response = new DataResponse(['error' => ['code' => 'management_failed', 'message' => 'Không thể xử lý. Vui lòng kiểm tra nhật ký LibreSign.']], 500);
		}
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
