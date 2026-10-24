<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderRegistrationLinkService;
use OCA\Libresign\Service\Provider\PublicRegistrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class ProviderMockController extends Controller {
	public function __construct(
		IRequest $request,
		private ProviderRegistrationLinkService $links,
		private PublicRegistrationService $registrations,
		private LoggerInterface $logger,
	) {
		parent::__construct('libresign', $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/provider/mock-registration')]
	public function show(string $token = ''): TemplateResponse {
		return $this->page($token);
	}

	// Use the normal Nextcloud CSRF check in addition to the signed link.
	#[PublicPage]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/provider/mock-registration')]
	public function submit(string $token = '', string $action = '', string $otpCode = '', string $consent = ''): TemplateResponse {
		return $this->page($token, $action, $otpCode, $consent);
	}

	private function page(string $token, string $action = '', string $otp = '', string $consent = ''): TemplateResponse {
		$session = null;
		$error = null;
		$notice = null;
		$status = 200;
		try {
			$link = $this->links->resolve($token);
			$client = $link['client'];
			$id = $link['sessionId'];
			$session = $this->registrations->registration($client, $id);
			if ($session['registrationMethod'] !== 'QR_SELF_UPLOAD' || $session['confirmationMethod'] !== 'service_otp') {
				throw new ProviderException('unsupported_mock_session', 'This page only confirms QR enrollment by OTP.', 409);
			}
			if ($action === 'resend') {
				$session = $this->registrations->action($client, $id, 'resend');
				$notice = 'Đã gửi mã mới qua kênh OTP của phiên đăng ký.';
			} elseif ($action === 'confirm') {
				if ($consent !== 'yes') {
					throw new ProviderException('consent_required', 'Vui lòng xác nhận đồng ý cấp chứng thư.', 400);
				}
				if ($session['status'] !== 'ACTIVE') {
					$this->registrations->action($client, $id, 'otp', trim($otp));
				}
				$session = $this->registrations->action($client, $id, 'confirm');
			} elseif ($action !== '') {
				throw new ProviderException('invalid_action', 'Thao tác không hợp lệ.', 400);
			}
		} catch (ProviderException $e) {
			$status = $e->getCode();
			$error = $e->getMessage();
			if ($session !== null) {
				try {
					$session = $this->registrations->registration($client, $id);
				} catch (\Throwable) {
					$session = null;
				}
			}
		} catch (\Throwable $e) {
			$status = 500;
			$error = 'Chưa thể hoàn tất. Vui lòng liên hệ người quản trị phiên đăng ký.';
			$this->logger->error('Provider registration mock failed', ['exceptionClass' => $e::class]);
		}
		// The public page exposes only masked identity, never API credentials or artifacts.
		$person = $session ? ['name' => $session['fullName'], 'personalId' => '********' . substr($session['personalIdNumber'], -4),
			'email' => preg_replace('/(^.).*(@.*$)/', '$1***$2', $session['email']),
			'phone' => preg_replace('/.(?=.{4})/', '*', $session['phone'])] : null;
		$response = new TemplateResponse('libresign', 'provider_mock', ['token' => $token, 'session' => $session ? array_intersect_key($session,
			array_flip(['status', 'step', 'expiresAt', 'otpExpiresAt', 'nextAction', 'otpChannel'])) : null, 'person' => $person,
			'error' => $error, 'notice' => $notice], TemplateResponse::RENDER_AS_GUEST, $status);
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		$response->addHeader('X-Robots-Tag', 'noindex, nofollow');
		return $response;
	}
}
