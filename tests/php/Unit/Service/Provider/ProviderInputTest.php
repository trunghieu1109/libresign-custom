<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderInputTest extends TestCase {
	public function testNormalizedReceiptDoesNotRequireAnAccount(): void {
		$result = (new ProviderInput())->normalize(
			['fullName' => ' Nguyen Van A ', 'personalIdNumber' => '001234567890'],
			['method' => 'nfc', 'source' => 'adapter-01', 'reference' => 'receipt-01', 'verifiedAt' => '2026-01-01T00:00:00Z'],
			'external_assertion', 'internal-default'
		);
		$this->assertSame('Nguyen Van A', $result['person']['fullName']);
		$this->assertSame('001234567890', $result['person']['personalIdNumber']);
		$this->assertSame('', $result['person']['email']);
	}

	#[DataProvider('invalidPeople')]
	public function testRejectsInvalidOrUnnormalizedData(array $person): void {
		$this->expectException(ProviderException::class);
		(new ProviderInput())->normalize($person,
			['method' => 'nfc', 'source' => 'adapter', 'reference' => 'receipt', 'verifiedAt' => '2026-01-01T00:00:00Z'],
			'service_otp', 'internal-default');
	}

	public static function invalidPeople(): array {
		$person = ['fullName' => 'Signer', 'personalIdNumber' => '001234567890'];
		return [
			'identifier loses leading zeros' => [array_replace($person, ['personalIdNumber' => 1234567890])],
			'control characters' => [array_replace($person, ['fullName' => "Signer\nCN=Admin"])],
			'invalid email' => [array_replace($person, ['email' => 'invalid'])],
			'local phone' => [array_replace($person, ['phoneNumber' => '0901234567'])],
			'raw NFC payload' => [$person + ['nfcDump' => 'binary']],
			'client controlled status' => [$person + ['status' => 'ACTIVE']],
		];
	}

	public function testReceiptIsRequired(): void {
		$this->expectException(ProviderException::class);
		(new ProviderInput())->normalize(['fullName' => 'Signer', 'personalIdNumber' => '123456789012'], [], 'external_assertion', 'internal-default');
	}
}
