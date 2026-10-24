<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;

class ProviderInput {
	public function normalize(array $person, array $verification, string $method, string $packageCode): array {
		if ($packageCode !== 'internal-default') {
			throw new ProviderException('unknown_package', 'Select a package returned by the packages endpoint.');
		}
		if (!in_array($method, ['external_assertion', 'service_otp'], true)) {
			throw new ProviderException('invalid_confirmation_method', 'Unsupported confirmation method.');
		}
		$this->onlyKeys($person, ['fullName', 'personalIdNumber', 'email', 'phoneNumber']);
		$this->onlyKeys($verification, ['method', 'source', 'reference', 'verifiedAt']);
		$normalizedPerson = [
			'fullName' => $this->text($person, 'fullName', 128),
			'personalIdNumber' => $this->personalId($person['personalIdNumber'] ?? ''),
			'email' => $this->text($person, 'email', 254, false),
			'phoneNumber' => $this->text($person, 'phoneNumber', 20, false),
		];
		if ($normalizedPerson['email'] !== '' && filter_var($normalizedPerson['email'], FILTER_VALIDATE_EMAIL) === false) {
			throw new ProviderException('invalid_input', 'Invalid email address.');
		}
		if ($normalizedPerson['phoneNumber'] !== '' && !preg_match('/^\+[1-9][0-9]{7,14}$/D', $normalizedPerson['phoneNumber'])) {
			throw new ProviderException('invalid_input', 'phoneNumber must use international format, e.g. +84901234567.');
		}
		$receipt = [];
		foreach (['method', 'source', 'reference', 'verifiedAt'] as $key) {
			$receipt[$key] = $this->text($verification, $key, $key === 'reference' ? 256 : 64);
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $receipt['verifiedAt'])
			|| strtotime($receipt['verifiedAt']) === false || strtotime($receipt['verifiedAt']) > time() + 60) {
			throw new ProviderException('invalid_input', 'verifiedAt must be a past RFC3339 timestamp.');
		}
		return ['packageCode' => $packageCode, 'confirmationMethod' => $method, 'person' => $normalizedPerson, 'verification' => $receipt];
	}

	public function personalId(mixed $value): string {
		if (!is_string($value) || !preg_match('/^[A-Za-z0-9-]{6,32}$/D', $value)) {
			throw new ProviderException('invalid_input', 'personalIdNumber must be a string of 6-32 letters, digits or hyphens.');
		}
		return $value;
	}

	private function onlyKeys(array $input, array $allowed): void {
		if (array_diff(array_keys($input), $allowed) !== []) {
			throw new ProviderException('invalid_input', 'Unexpected input fields; send normalized identity data and a verification receipt.');
		}
	}

	private function text(array $input, string $key, int $max, bool $required = true): string {
		$value = $input[$key] ?? '';
		if (!is_string($value) || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) || ($required && trim($value) === '')) {
			throw new ProviderException('invalid_input', 'Invalid field: ' . $key);
		}
		return trim($value);
	}
}
