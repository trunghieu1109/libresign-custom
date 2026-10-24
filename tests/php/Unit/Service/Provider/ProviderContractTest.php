<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Provider;

use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Service\Provider\ProviderContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderContractTest extends TestCase {
	public function testEquivalentObjectOrderHasSameFingerprintButDocumentOrderDoesNot(): void {
		$this->assertSame(ProviderContract::fingerprint(['a' => ['x' => 1, 'y' => 2], 'b' => 3]), ProviderContract::fingerprint(['b' => 3, 'a' => ['y' => 2, 'x' => 1]]));
		$this->assertNotSame(ProviderContract::fingerprint([['id' => 'A'], ['id' => 'B']]), ProviderContract::fingerprint([['id' => 'B'], ['id' => 'A']]));
	}

	public static function invalidDates(): array {
		return [['31/02/2026'], ['2026-13-01'], ['2026-01-40'], ['not-a-date']];
	}

	#[DataProvider('invalidDates')]
	public function testInvalidCalendarDatesAreRejected(string $value): void {
		$this->expectException(ProviderException::class);
		ProviderContract::date($value, 'expiredDate');
	}

	public function testLocalPhoneAndDocumentDatesNormalize(): void {
		$this->assertSame('+84912345678', ProviderContract::phone('0912345678'));
		$this->assertSame('2030-01-01', ProviderContract::date('01/01/2030', 'expiredDate'));
		$this->assertSame('001234567890', ProviderContract::personalId('001234567890'));
	}

	public function testConflictingAliasesAreRejected(): void {
		$this->expectException(ProviderException::class);
		ProviderContract::alias(['fileBase64' => 'a', 'pdfBase64' => 'b'], 'fileBase64', 'pdfBase64');
	}

	public function testIntegerIdentityCannotLoseLeadingZeros(): void {
		$this->expectException(ProviderException::class);
		ProviderContract::personalId(123456789012);
	}

	public function testEvidenceSizeIsCheckedAfterDecoding(): void {
		$this->expectException(ProviderException::class);
		ProviderContract::bytes(base64_encode('too much'), 'image', 3);
	}

	public function testStringFalseCannotDisableFacePolicy(): void {
		$this->expectException(ProviderException::class);
		ProviderContract::boolean(['required' => 'false'], 'required', true);
	}
}
