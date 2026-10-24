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
use OCA\Libresign\Service\Provider\ProviderContract;
use OCA\Libresign\Service\Provider\PublicRegistrationService;
use OCA\Libresign\Service\Provider\PublicSigningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Sabre\DAV\UUIDUtil;

/**
 * Flat JSON integration API. PublicPage allows JSON authentication errors;
 * every action MUST pass through call(), which enforces authentication and scope.
 *
 * @psalm-import-type LibresignPublicEnvelope from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignPublicRegistrationEnvelope from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignPublicIdentityEnvelope from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignPublicReuseEnvelope from \OCA\Libresign\ResponseDefinitions
 */
#[OpenAPI(tags: ['provider-public'])]
class PublicProviderController extends Controller {
	public function __construct(
		IRequest $request,
		private ProviderAccessService $access,
		private PublicRegistrationService $registrations,
		private ProviderCertificateService $certificates,
		private PublicSigningService $signing,
		private LoggerInterface $logger,
	) {
		parent::__construct('libresign', $request);
	}

	/**
	 * List available internal CA packages
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/certificates/packages')]
	public function packages(): DataResponse {
		return $this->call('certificates:read', function ($client) {
			$items = $this->registrations->packages($client);
			return ['items' => $items, 'totalItem' => count($items)];
		});
	}

	/**
	 * Open or reuse a certificate registration
	 * @param string $personalIdNumber Twelve digit personal identifier
	 * @param string $fullName Verified full name
	 * @param string $email Verified email; required for email and email_sms OTP
	 * @param string $phone Vietnamese local or E.164 phone number; required for sms and email_sms OTP
	 * @param string $registrationMethod CARD_READER or QR_SELF_UPLOAD
	 * @param string $packageCode Available internal package
	 * @param bool $reusePreviousDossier Reuse eligible initial identity dossier
	 * @param bool $requireReuseFaceMatch Require adapter face-match before dossier reuse
	 * @param bool $reuseActiveCertificate Return an existing suitable active certificate
	 * @param string $confirmationMethod service_otp or external_assertion
	 * @param string|null $callbackUrl Allowed HTTPS callback URL
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicRegistrationEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/certificates/registration')]
	public function register(string $personalIdNumber = '', string $fullName = '', string $email = '', string $phone = '', string $registrationMethod = '', string $packageCode = 'internal-default', bool $reusePreviousDossier = false, bool $requireReuseFaceMatch = false, bool $reuseActiveCertificate = true, string $confirmationMethod = 'service_otp', ?string $callbackUrl = null): DataResponse {
		return $this->call('certificates:write', fn ($client) => $this->registrations->register($client, $this->key(), $this->body()));
	}

	/**
	 * Submit an adapter-verified identity dossier and evidence
	 * @param string $sessionId Registration UUID
	 * @param string $identifyNumber Personal identifier from the identity document
	 * @param string $name Full name on the identity document
	 * @param string $address Identity document address
	 * @param string $dateOfBirth Date in dd/MM/yyyy or yyyy-MM-dd
	 * @param string $issueDate Identity document issue date
	 * @param string $expiredDate Identity document expiry date
	 * @param string $sex Identity document gender
	 * @param bool $isPass Passing identity verification reported by the trusted integration
	 * @param array<string, mixed> $verification Adapter receipt and verification timestamp
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicIdentityEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/registration-sessions/{sessionId}/identity/info')]
	public function identity(string $sessionId, string $identifyNumber = '', string $name = '', string $address = '', string $dateOfBirth = '', string $issueDate = '', string $expiredDate = '', string $sex = '', bool $isPass = false, array $verification = []): DataResponse {
		return $this->call('certificates:write', fn ($client) => $this->registrations->receive($client, $sessionId, $this->body(), false));
	}

	/**
	 * Submit processed card-reader data; no hardware processing occurs here
	 * @param string $sessionId Registration UUID
	 * @param string $identifyNumber Required twelve digit identity number
	 * @param string $name Required name on the identity document
	 * @param string $address Required permanent address
	 * @param string $dateOfBirth Required date in dd/MM/yyyy or yyyy-MM-dd
	 * @param string $issueDate Required identity document issue date
	 * @param string $expiredDate Required identity document expiry date
	 * @param string $sex Required gender from the identity document
	 * @param string $imageCap Required Base64 captured portrait (PNG/JPEG)
	 * @param string $imageChip Required Base64 portrait read from the chip (PNG/JPEG)
	 * @param string $sodData Required Base64 signed chip data; verified by the adapter
	 * @param bool $isPass Required passing verification reported by the trusted adapter
	 * @param int|null $score Required integer score from 0 to 100
	 * @param string $nationality Optional nationality
	 * @param string $nation Optional ethnicity
	 * @param string $religion Optional religion
	 * @param string $hometown Optional hometown
	 * @param string $character Optional identifying features
	 * @param string $fatherName Optional father name
	 * @param string $motherName Optional mother name
	 * @param string $partnerName Optional spouse name
	 * @param string $mrz Optional ICAO MRZ text lines
	 * @param string $dg1DataBase64 Optional Base64 chip data group 1
	 * @param string $dg2DataBase64 Optional Base64 chip data group 2
	 * @param string $dg13DataBase64 Optional Base64 chip data group 13
	 * @param string $dg14DataBase64 Optional Base64 chip data group 14
	 * @param string|null $phone Optional updated phone before dossier acceptance and OTP delivery
	 * @param string|null $email Optional updated email before dossier acceptance and OTP delivery
	 * @param array<string, mixed> $verification Optional adapter receipt; authenticated integration supplies the default source
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicIdentityEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/registration-sessions/{sessionId}/card-reader/info')]
	public function cardReader(string $sessionId, string $identifyNumber = '', string $name = '', string $address = '', string $dateOfBirth = '', string $issueDate = '', string $expiredDate = '', string $sex = '', string $imageCap = '', string $imageChip = '', string $sodData = '', bool $isPass = false, ?int $score = null, string $nationality = '', string $nation = '', string $religion = '', string $hometown = '', string $character = '', string $fatherName = '', string $motherName = '', string $partnerName = '', string $mrz = '', string $dg1DataBase64 = '', string $dg2DataBase64 = '', string $dg13DataBase64 = '', string $dg14DataBase64 = '', ?string $phone = null, ?string $email = null, array $verification = []): DataResponse {
		return $this->call('certificates:write', fn ($client) => $this->registrations->receive($client, $sessionId, $this->body(), true));
	}

	/**
	 * Verify the OTP without issuing a certificate
	 * @param string $sessionId Registration UUID
	 * @param string $otpCode Six digit code; JSON body is preferred over query logging
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicRegistrationEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/registration-sessions/{sessionId}/otp')]
	public function verifyOtp(string $sessionId, string $otpCode = ''): DataResponse {
		return $this->call('certificates:write', fn ($client) => $this->registrations->action($client, $sessionId, 'otp', $otpCode));
	}

	/**
	 * Send or resend a registration code through the configured OTP channels
	 * @param string $sessionId Registration UUID
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicRegistrationEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/registration-sessions/{sessionId}/otp/resend')]
	public function resendRegistration(string $sessionId): DataResponse {
		return $this->call('certificates:write', fn ($client) => $this->registrations->action($client, $sessionId, 'resend'));
	}

	/**
	 * Issue a certificate after verified OTP consent or an adapter assertion
	 * @param string $sessionId Registration UUID
	 * @param string $assertion Base64 RSA-SHA256 signature of the supplied enrollment challenge
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicRegistrationEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/registration-sessions/{sessionId}/confirm')]
	public function confirmRegistration(string $sessionId, string $assertion = ''): DataResponse {
		return $this->call('certificates:write', function ($client) use ($sessionId, $assertion) {
			$this->key();
			return $this->registrations->action($client, $sessionId, 'confirm', $assertion);
		});
	}

	/**
	 * Cancel an unissued enrollment
	 * @param string $sessionId Registration UUID
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicRegistrationEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/registration-sessions/{sessionId}/cancel')]
	public function cancelRegistration(string $sessionId): DataResponse {
		return $this->call('certificates:write', fn ($client) => $this->registrations->action($client, $sessionId, 'cancel'));
	}

	/**
	 * Retrieve enrollment progress and certificate metadata
	 * @param string $sessionId Registration UUID
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicRegistrationEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/certificates/registration/{sessionId}')]
	public function registration(string $sessionId): DataResponse {
		return $this->call('certificates:read', fn ($client) => $this->registrations->registration($client, $sessionId));
	}

	/**
	 * Determine whether the integration can reuse its initial identity dossier
	 * @param string $personalIdNumber Twelve digit personal identifier
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicReuseEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/certificates/dossier-reuse')]
	public function reuse(string $personalIdNumber = ''): DataResponse {
		return $this->call('certificates:read', fn ($client) => $this->registrations->reuse($client, $personalIdNumber));
	}

	/**
	 * Submit a fresh adapter face-match bound to this reuse enrollment
	 * @param string $sessionId Registration UUID
	 * @param string $portraitBase64 Current portrait PNG or JPEG
	 * @param array<string, mixed> $verification Passing adapter receipt, score, sessionId and timestamp
	 * @param float|null $threshold Requested threshold, never below configured minimum
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicRegistrationEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/registration-sessions/{sessionId}/reuse-face-match')]
	public function faceMatch(string $sessionId, string $portraitBase64 = '', array $verification = [], ?float $threshold = null): DataResponse {
		return $this->call('certificates:write', fn ($client) => $this->registrations->faceMatch($client, $sessionId, $this->body()));
	}

	/**
	 * Find certificates by personal identifier
	 * @param string $personalIdNumber Twelve digit personal identifier
	 * @param int $pageIndex One based page
	 * @param int $pageSize Page size, 1-100
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/certificates')]
	public function certificates(string $personalIdNumber = '', int $pageIndex = 1, int $pageSize = 20): DataResponse {
		return $this->call('certificates:read', fn ($client) => $this->registrations->listing($client, $personalIdNumber, $pageIndex, $pageSize));
	}

	/**
	 * List certificates owned by the integration
	 * @param int $pageIndex One based page
	 * @param int $pageSize Page size, 1-100
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/certificates/mine')]
	public function mine(int $pageIndex = 1, int $pageSize = 20): DataResponse {
		return $this->call('certificates:read', fn ($client) => $this->registrations->listing($client, '', $pageIndex, $pageSize));
	}

	/**
	 * Read certificate metadata by alias
	 * @param string $certAlias Managed certificate alias
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/certificates/{certAlias}')]
	public function certificate(string $certAlias): DataResponse {
		return $this->call('certificates:read', fn ($client) => $this->registrations->certificate($client, $certAlias));
	}

	/**
	 * Read the public certificate chain without private key material
	 * @param string $certAlias Managed certificate alias
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/certificates/{certAlias}/public')]
	public function publicCertificate(string $certAlias): DataResponse {
		return $this->call('certificates:read', fn ($client) => $this->certificates->publicCertificate($client, $certAlias));
	}

	/**
	 * Revoke a managed certificate
	 * @param string $certAlias Managed certificate alias
	 * @param int $reasonCode RFC5280 reason code
	 * @param string $reasonText Optional revocation explanation
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/certificates/{certAlias}/revoke')]
	public function revoke(string $certAlias, int $reasonCode = 0, string $reasonText = ''): DataResponse {
		return $this->call('certificates:revoke', function ($client) use ($certAlias, $reasonCode, $reasonText) {
			$this->certificates->revoke($client, $certAlias, $reasonCode, $reasonText);
			return $this->registrations->certificate($client, $certAlias);
		});
	}

	/**
	 * Read implemented signing limits
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/capabilities')]
	public function capabilities(): DataResponse {
		return $this->call('signing:read', fn ($client) => $this->signing->capabilities($client));
	}

	/**
	 * List implemented signing profiles
	 * @param int $pageIndex One based page
	 * @param int $pageSize Page size, 1-100
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/signing-profiles')]
	public function profiles(int $pageIndex = 1, int $pageSize = 20): DataResponse {
		return $this->call('signing:read', function () use ($pageIndex, $pageSize) {
			if ($pageIndex < 1 || $pageSize < 1 || $pageSize > 100) {
				throw new ProviderException('SYS-0003', 'Invalid pagination.');
			}
			return ['items' => $pageIndex === 1 ? $this->signing->profiles() : [], 'totalItem' => 1];
		});
	}

	/**
	 * Create a PDF signing request and initiate consent
	 * @param string $personalIdNumber Personal identifier when certAlias is omitted
	 * @param string $certAlias Active managed certificate
	 * @param string $fileBase64 PDF for a single document
	 * @param array<array<string, mixed>> $files Batch of up to 100 PDFs
	 * @param string $documentName Name of the single document
	 * @param string $signingProfileCode DEFAULT_PDF
	 * @param string $confirmationMethod service_otp or external_assertion
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_ACCEPTED|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 202: Accepted; signing is still processing
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 413: Batch byte limit exceeded
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/signing-requests')]
	public function createSigning(string $personalIdNumber = '', string $certAlias = '', string $fileBase64 = '', array $files = [], string $documentName = '', string $signingProfileCode = 'DEFAULT_PDF', string $confirmationMethod = 'service_otp'): DataResponse {
		return $this->call('signing:write', fn ($client) => $this->signing->start($client, $this->key(), $this->body()), 202);
	}

	/**
	 * Start PDF signing, or confirm an existing session on the second call
	 * @param string $personalIdNumber Personal identifier when certAlias is omitted
	 * @param string $certAlias Active managed certificate
	 * @param string $fileBase64 PDF for a single document
	 * @param array<array<string, mixed>> $files Batch of up to 100 PDFs
	 * @param string $documentName Name of the single document
	 * @param string $signSessionId Session from the first call, used only for confirmation
	 * @param string $otpValue Six digit signing OTP received through the configured channels
	 * @param string $assertion Adapter signature for external_assertion
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_ACCEPTED|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 202: Accepted; signing is still processing
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 413: Batch byte limit exceeded
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/signing-requests/direct-sign')]
	public function directSign(string $personalIdNumber = '', string $certAlias = '', string $fileBase64 = '', array $files = [], string $documentName = '', string $signSessionId = '', string $otpValue = '', string $assertion = ''): DataResponse {
		return $this->call('signing:write', function ($client) {
			$key = $this->key();
			$body = $this->body();
			return isset($body['signSessionId'])
				? $this->signing->confirm($client, ProviderContract::text($body['signSessionId'], 'signSessionId', 36), $body)
				: $this->signing->start($client, $key, $body);
		}, 0);
	}

	/**
	 * Send or resend a signing code through the configured OTP channels
	 * @param string $signSessionId Signing UUID
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/signing-requests/direct-sign/resend-otp')]
	public function resendSigning(string $signSessionId): DataResponse {
		return $this->call('signing:write', fn ($client) => $this->signing->resend($client, $signSessionId));
	}

	/**
	 * Retrieve signing progress and per-document results
	 * @param string $transactionId Signing UUID
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/signing-requests/{transactionId}')]
	public function signing(string $transactionId): DataResponse {
		return $this->call('signing:read', fn ($client) => $this->signing->get($client, $transactionId));
	}

	/**
	 * Cancel an unconfirmed signing session
	 * @param string $transactionId Signing UUID
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/signing-requests/{transactionId}/cancel')]
	public function cancelSigning(string $transactionId): DataResponse {
		return $this->call('signing:write', fn ($client) => $this->signing->cancel($client, $transactionId));
	}

	/**
	 * Retrieve an individual signing result as Base64
	 * @param string $transactionId Signing UUID
	 * @param string $documentId Document id supplied by the integration
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_GONE|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_SERVICE_UNAVAILABLE, LibresignPublicEnvelope, array{}>
	 *
	 * 200: Current result or confirmation challenge
	 * 400: Invalid input
	 * 401: Integration authentication required
	 * 403: Scope or consent proof rejected
	 * 404: Resource not found in this integration
	 * 409: Conflicting state or idempotency key
	 * 410: Session expired
	 * 429: OTP resend or attempt limit reached
	 * 500: Internal workflow error
	 * 503: Certificate or delivery service unavailable
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/signing-requests/{transactionId}/documents/{documentId}/result')]
	public function result(string $transactionId, string $documentId): DataResponse {
		return $this->call('signing:read', fn ($client) => $this->signing->document($client, $transactionId, $documentId));
	}

	private function key(): string {
		$key = $this->request->getHeader('Idempotency-Key');
		if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $key)) {
			throw new ProviderException('idempotency_key_required', 'Supply an Idempotency-Key header of 8-128 letters, digits, dots, colons, underscores or hyphens.');
		}
		return $key;
	}

	private function body(): array {
		if (!preg_match(IRequest::JSON_CONTENT_TYPE_REGEX, $this->request->getHeader('Content-Type'))) {
			throw new ProviderException('SYS-0003', 'The request body must contain a JSON object.');
		}
		// IRequest->post contains merged route/query parameters on supported Nextcloud.
		// Read the JSON object itself so route metadata cannot enter signed manifests.
		$raw = file_get_contents('php://input');
		try {
			$data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new ProviderException('SYS-0003', 'The request body must contain a JSON object.');
		}
		if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
			throw new ProviderException('SYS-0003', 'The request body must contain a JSON object.');
		}
		return $data;
	}

	private function call(string $scope, callable $operation, int $status = 200): DataResponse {
		$id = UUIDUtil::getUUID();
		try {
			$result = $operation($this->access->requireClient($scope));
			$payload = ['status' => true, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'transId' => $id, 'data' => $result];
			if (isset($result['items'], $result['totalItem'])) {
				$payload['data'] = $result['items'];
				$payload['totalItem'] = $result['totalItem'];
			}
			if ($status === 0) {
				$status = ($result['status'] ?? '') === 'PROCESSING' ? 202 : 200;
			}
		} catch (ProviderException $e) {
			$status = $e->getCode();
			$payload = ['status' => false, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'transId' => $id, 'data' => null,
				'error' => ['code' => $e->error, 'message' => $e->getMessage()]];
		} catch (\Throwable $e) {
			$this->logger->error('Public provider operation failed', ['transId' => $id, 'exceptionClass' => $e::class, 'at' => $e->getFile() . ':' . $e->getLine()]);
			$status = 500;
			$payload = ['status' => false, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'transId' => $id, 'data' => null,
				'error' => ['code' => 'PROVIDER_INTERNAL_ERROR', 'message' => 'The operation could not complete; use transId for investigation.']];
		}
		$response = new DataResponse($payload, $status);
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}
}
