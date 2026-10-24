<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderAccessService;
use OCA\Libresign\Service\Provider\ProviderCertificateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * @psalm-import-type LibresignProviderPerson from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignProviderVerification from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignProviderPackages from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignProviderRegistration from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignProviderCertificate from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignProviderCertificateList from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignProviderPublicCertificate from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignProviderCode from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignProviderError from \OCA\Libresign\ResponseDefinitions
 */
#[OpenAPI(tags: ['provider'])]
class ProviderCertificateController extends AEnvironmentAwareController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ProviderAccessService $access,
		private ProviderCertificateService $certificates,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the available internal CA package and enabled confirmation methods
	 *
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderPackages, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Available package
	 * 400: Invalid input
	 * 401: Authentication required
	 * 403: Integration or scope not enabled
	 * 404: Resource not found
	 * 409: Conflicting state
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: CA or delivery service unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/provider/certificates/packages', requirements: ['apiVersion' => '(v1)'])]
	public function packages(): DataResponse {
		return $this->run('certificates:read', fn ($client) => $this->certificates->packages($client));
	}

	/**
	 * Register a certificate using normalized identity data supplied by a trusted adapter
	 *
	 * @param string $requestId Idempotency identifier scoped to this integration
	 * @param LibresignProviderPerson $person Normalized personal information; no Nextcloud account required
	 * @param LibresignProviderVerification $verification Verification receipt from the identity adapter
	 * @param string $confirmationMethod external_assertion or service_otp
	 * @param string $packageCode Code returned by the packages endpoint
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderRegistration, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Registration created or identical request replayed
	 * 400: Invalid input
	 * 401: Authentication required
	 * 403: Integration, scope or confirmation method not enabled
	 * 404: Resource not found
	 * 409: requestId reused with different input
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: CA unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/provider/certificates/registration', requirements: ['apiVersion' => '(v1)'])]
	public function register(string $requestId, array $person, array $verification, string $confirmationMethod, string $packageCode = 'internal-default'): DataResponse {
		return $this->run('certificates:write', fn ($client) => $this->certificates->register($client, $requestId, $person, $verification, $confirmationMethod, $packageCode));
	}

	/**
	 * Retrieve a registration owned by the calling integration
	 *
	 * @param string $sessionId Registration session identifier
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderRegistration, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Current registration state
	 * 400: Invalid input
	 * 401: Authentication required
	 * 403: Integration or scope not enabled
	 * 404: Session not found in this integration
	 * 409: Conflicting state
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: Service unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/provider/registration-sessions/{sessionId}', requirements: ['apiVersion' => '(v1)'])]
	public function registration(string $sessionId): DataResponse {
		return $this->run('certificates:read', fn ($client) => $this->certificates->registration($client, $sessionId));
	}

	/**
	 * Send or resend an OTP to the stored verified contact
	 *
	 * @param string $sessionId Registration session identifier
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderCode, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Code sent; the code itself is never returned
	 * 400: Invalid input
	 * 401: Authentication required
	 * 403: Integration or method not enabled
	 * 404: Session not found
	 * 409: Session cannot accept an OTP
	 * 410: Session expired
	 * 429: Resend cooldown or send limit reached
	 * 503: Delivery service unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/provider/registration-sessions/{sessionId}/otp', requirements: ['apiVersion' => '(v1)'])]
	public function sendCode(string $sessionId): DataResponse {
		return $this->run('certificates:write', fn ($client) => $this->certificates->sendCode($client, $sessionId));
	}

	/**
	 * Confirm registration and issue exactly one managed certificate
	 *
	 * @param string $sessionId Registration session identifier
	 * @param string $otpValue Six digit code for service_otp
	 * @param string $assertion Base64 RSA-SHA256 signature over the decoded challengeBase64 bytes for external_assertion
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderRegistration, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Certificate active or completed confirmation replayed
	 * 400: Invalid input
	 * 401: Authentication required
	 * 403: Invalid confirmation proof or insufficient scope
	 * 404: Session not found
	 * 409: Session cannot be confirmed
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: CA unavailable or issuance requires administrative recovery
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/provider/registration-sessions/{sessionId}/confirm', requirements: ['apiVersion' => '(v1)'])]
	public function confirm(string $sessionId, string $otpValue = '', string $assertion = ''): DataResponse {
		return $this->run('certificates:write', fn ($client) => $this->certificates->confirm($client, $sessionId, $otpValue, $assertion));
	}

	/**
	 * Cancel a pending registration without issuing a certificate
	 *
	 * @param string $sessionId Registration session identifier
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderRegistration, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Registration cancelled
	 * 400: Invalid input
	 * 401: Authentication required
	 * 403: Integration or scope not enabled
	 * 404: Session not found
	 * 409: Registration is no longer pending
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: Service unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/provider/registration-sessions/{sessionId}/cancel', requirements: ['apiVersion' => '(v1)'])]
	public function cancel(string $sessionId): DataResponse {
		return $this->run('certificates:write', fn ($client) => $this->certificates->cancel($client, $sessionId));
	}

	/**
	 * List certificates owned by the integration, optionally filtering by personal identifier
	 *
	 * @param string $personalIdNumber Personal identifier for an exact, integration-scoped lookup
	 * @param int $pageIndex Zero based page index
	 * @param int $pageSize Number of entries, 1-100
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderCertificateList, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Certificate page
	 * 400: Invalid filter or pagination
	 * 401: Authentication required
	 * 403: Integration or scope not enabled
	 * 404: Resource not found
	 * 409: Conflicting state
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: Service unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/provider/certificates', requirements: ['apiVersion' => '(v1)'])]
	public function certificates(string $personalIdNumber = '', int $pageIndex = 0, int $pageSize = 20): DataResponse {
		return $this->run('certificates:read', fn ($client) => $this->certificates->certificates($client, $personalIdNumber, $pageIndex, $pageSize));
	}

	/**
	 * Retrieve a certificate by its integration-scoped alias
	 *
	 * @param string $certAlias Managed certificate alias
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderCertificate, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Certificate metadata and native revocation status
	 * 400: Invalid input
	 * 401: Authentication required
	 * 403: Integration or scope not enabled
	 * 404: Certificate not found in this integration
	 * 409: Conflicting state
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: Service unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/provider/certificates/{certAlias}', requirements: ['apiVersion' => '(v1)'])]
	public function certificate(string $certAlias): DataResponse {
		return $this->run('certificates:read', fn ($client) => $this->certificates->certificate($client, $certAlias));
	}

	/**
	 * Retrieve the public PEM chain, ordered from leaf to root
	 *
	 * @param string $certAlias Managed certificate alias
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderPublicCertificate, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Public certificate chain; no private key or PFX password
	 * 400: Invalid input
	 * 401: Authentication required
	 * 403: Integration or scope not enabled
	 * 404: Certificate not found
	 * 409: Conflicting state
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: Service unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/provider/certificates/{certAlias}/public', requirements: ['apiVersion' => '(v1)'])]
	public function publicCertificate(string $certAlias): DataResponse {
		return $this->run('certificates:read', fn ($client) => $this->certificates->publicCertificate($client, $certAlias));
	}

	/**
	 * Revoke an integration-owned certificate through LibreSign's existing CRL service
	 *
	 * @param string $certAlias Managed certificate alias
	 * @param int $reasonCode RFC5280 revocation reason, excluding reserved value 7
	 * @param string $reasonText Optional explanation of at most 512 bytes
	 * @return DataResponse<Http::STATUS_OK, LibresignProviderCertificate, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE, LibresignProviderError, array{}>
	 *
	 * 200: Certificate revoked or already revoked
	 * 400: Invalid revocation reason
	 * 401: Authentication required
	 * 403: Integration or revocation scope not enabled
	 * 404: Certificate not found
	 * 409: Revocation could not be completed
	 * 410: Session expired
	 * 429: Rate limit reached
	 * 503: Service unavailable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/provider/certificates/{certAlias}/revoke', requirements: ['apiVersion' => '(v1)'])]
	public function revoke(string $certAlias, int $reasonCode = 0, string $reasonText = ''): DataResponse {
		return $this->run('certificates:revoke', fn ($client) => $this->certificates->revoke($client, $certAlias, $reasonCode, $reasonText));
	}

	private function run(string $scope, callable $operation): DataResponse {
		try {
			$response = new DataResponse($operation($this->access->requireClient($scope)));
		} catch (ProviderException $e) {
			$response = new DataResponse(['error' => $e->error, 'message' => $e->getMessage()], $e->getCode());
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}
}
