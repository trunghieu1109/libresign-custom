<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\Exception\ProviderException;

/** Normalization at the public HTTP boundary; the legacy contract stays unchanged. */
class ProviderContract {
	public static function fingerprint(array $data): string {
		$normalize = function (array $value) use (&$normalize): array {
			if (!array_is_list($value)) {
				ksort($value);
			}
			foreach ($value as &$item) {
				if (is_array($item)) {
					$item = $normalize($item);
				}
			}
			return $value;
		};
		return hash('sha256', json_encode($normalize($data), JSON_THROW_ON_ERROR));
	}

	public static function text(mixed $value, string $field, int $max = 256, bool $required = true): string {
		if (!is_string($value) || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) || ($required && trim($value) === '')) {
			throw new ProviderException('SYS-0003', 'Invalid field: ' . $field);
		}
		return trim($value);
	}

	public static function personalId(mixed $value): string {
		if (!is_string($value) || !preg_match('/^[0-9]{12}$/D', $value)) {
			throw new ProviderException('SYS-0003', 'personalIdNumber must be a string of twelve digits.');
		}
		return $value;
	}

	public static function boolean(array $body, string $field, bool $default): bool {
		if (!array_key_exists($field, $body)) {
			return $default;
		}
		if (!is_bool($body[$field])) {
			throw new ProviderException('SYS-0003', $field . ' must be a boolean.');
		}
		return $body[$field];
	}

	public static function phone(mixed $value): string {
		$value = self::text($value, 'phone', 20, false);
		if (preg_match('/^0[0-9]{9}$/D', $value)) {
			$value = '+84' . substr($value, 1);
		}
		if ($value !== '' && !preg_match('/^\+[1-9][0-9]{7,14}$/D', $value)) {
			throw new ProviderException('SYS-0003', 'phone must be a Vietnamese local number or an E.164 number.');
		}
		return $value;
	}

	public static function date(mixed $value, string $field): string {
		$value = self::text($value, $field, 10);
		$format = str_contains($value, '/') ? '!d/m/Y' : '!Y-m-d';
		$date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
		$errors = \DateTimeImmutable::getLastErrors();
		if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
			throw new ProviderException('SYS-0003', 'Invalid calendar date: ' . $field);
		}
		return $date->format('Y-m-d');
	}

	public static function bytes(mixed $base64, string $field, int $max = 5242880): string {
		if (!is_string($base64) || strlen($base64) > (int)(ceil($max / 3) * 4) || $base64 === '') {
			throw new ProviderException('SYS-0003', $field . ' must contain bounded, plain Base64.');
		}
		$decoded = base64_decode($base64, true);
		if ($decoded === false || strlen($decoded) > $max || $decoded === '') {
			throw new ProviderException('SYS-0003', 'Invalid Base64: ' . $field);
		}
		return $decoded;
	}

	public static function alias(array $body, string $canonical, string $alias, mixed $default = null): mixed {
		if (isset($body[$canonical], $body[$alias]) && $body[$canonical] !== $body[$alias]) {
			throw new ProviderException('SYS-0003', 'Conflicting fields: ' . $canonical . ' and ' . $alias);
		}
		return $body[$canonical] ?? $body[$alias] ?? $default;
	}

	public static function certificate(array $certificate, array $identity): array {
		$person = $identity['person'];
		return ['certAlias' => $certificate['certAlias'], 'subjectName' => $certificate['fullName'],
			'personalIdNumber' => $person['personalIdNumber'], 'phone' => $person['phoneNumber'], 'email' => $person['email'],
			'status' => $certificate['status'], 'isConfirmed' => true, 'serialNumber' => $certificate['serialNumber'],
			'validFrom' => gmdate('Y-m-d\TH:i:s\Z', $certificate['validFrom']),
			'validTo' => gmdate('Y-m-d\TH:i:s\Z', $certificate['validTo']), 'fingerprintSha256' => $certificate['fingerprintSha256']];
	}
}
