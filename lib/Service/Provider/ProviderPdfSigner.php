<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Provider;

use OCA\Libresign\DataObjects\VisibleElementAssoc;
use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Exception\ProviderException;
use OCA\Libresign\Handler\SignEngine\JSignPdf\JSignPdfHandler;
use OCA\Libresign\Handler\SignEngine\Pkcs12Handler;
use OCA\Libresign\Service\File\Pdf\PdfParser;
use OCA\Libresign\Service\File\Pdf\PdfValidator;
use OCA\Libresign\Service\Signature\PdfSignatureValidationService;
use OCA\Libresign\Service\TsaValidationService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use Sabre\DAV\UUIDUtil;

/** Uses native PDF validation/DocMDP and signing; provider workflows own identity and consent checks. */
class ProviderPdfSigner {
	public function __construct(
		private ProviderCertificateService $certificates,
		private PdfValidator $validator,
		private PdfParser $parser,
		private Pkcs12Handler $engine,
		private JSignPdfHandler $jSignPdf,
		private PdfSignatureValidationService $signatureValidator,
		private TsaValidationService $tsa,
		private IAppDataFactory $appData,
		private IRootFolder $root,
		private ITempManager $temp,
	) {
	}

	/** Resolve all appearance before consent so the snapshot cannot change while signing. */
	public function prepare(string $content, array $params): array {
		$this->validator->validate($content, $params['documentName']);
		$document = $this->parser->parse($content, $params['documentName']);
		$pages = $document->getPages();
		$page = $params['page'] ?? null;
		$keyword = $params['signatureSearchText'] ?? '';
		if ($keyword !== '') {
			$matches = [];
			foreach ($pages as $index => $pdfPage) {
				foreach ($pdfPage->getDataTm() as $item) {
					if (isset($item[1]) && is_string($item[1]) && mb_stripos($item[1], $keyword) !== false) {
						$matches[] = ['page' => $index + 1, 'llx' => max(0, (float)$item[0][4]), 'lly' => max(0, (float)$item[0][5] - 50)];
					}
				}
			}
			if (count($matches) !== 1) {
				throw new ProviderException('keyword_not_unique', 'Signature text must match exactly one text segment; use coordinates otherwise.');
			}
			$params = array_replace($params, $matches[0]);
			$params['urx'] = $params['llx'] + 160;
			$params['ury'] = $params['lly'] + 50;
			$page = $params['page'];
		}
		$visible = isset($params['llx'], $params['lly'], $params['urx'], $params['ury']);
		if ($visible) {
			$page ??= 1;
			$page = $page <= 0 ? count($pages) : $page;
			if (!isset($pages[$page - 1])) {
				throw new ProviderException('SYS-0003', 'Signature page does not exist.');
			}
			$details = $pages[$page - 1]->getDetails();
			$box = $details['CropBox'] ?? $details['MediaBox'] ?? null;
			if (!is_array($box) || count($box) !== 4) {
				throw new ProviderException('page_geometry_unavailable', 'Cannot determine the PDF page bounds.');
			}
			if ($params['llx'] < (float)$box[0] || $params['lly'] < (float)$box[1]
				|| $params['urx'] > (float)$box[2] || $params['ury'] > (float)$box[3]
				|| $params['llx'] >= $params['urx'] || $params['lly'] >= $params['ury']) {
				throw new ProviderException('SYS-0003', 'Signature rectangle is outside the page bounds or has no area.');
			}
			$params['page'] = $page;
		}
		foreach (['signatureImageBase64', 'stampBase64', 'logoBase64'] as $image) {
			if (!empty($params[$image])) {
				$bytes = ProviderContract::bytes($params[$image], $image, 1048576);
				$size = @getimagesizefromstring($bytes);
				if (!$size || $size['mime'] !== 'image/png' || $size[0] * $size[1] > 4000000 || !$visible) {
					throw new ProviderException('SYS-0003', 'Signature images require a valid rectangle and a bounded PNG.');
				}
				$decoded = new \Imagick();
				try {
					$decoded->readImageBlob($bytes);
					if ($decoded->getNumberImages() !== 1) {
						throw new \RuntimeException('A single image is required.');
					}
				} catch (\Throwable) {
					throw new ProviderException('SYS-0003', 'Signature PNG could not be decoded.');
				} finally {
					$decoded->clear();
				}
			}
		}
		return $params;
	}

	public function sign(array $client, string $alias, string $content, array $params): string {
		$credential = $this->certificates->signingCredential($client, $alias);
		$this->validator->validate($content, $params['documentName']);
		$this->tsa->validateConfiguration();
		// Native engines require an OCP File. The working file is in private appdata,
		// never in the integration user's Files and never selected by a client path.
		$this->appData->get('libresign')->getFolder('/')->getOrCreateFolder('provider-work');
		$folder = $this->root->get($this->root->getAppDataDirectoryName() . '/libresign/provider-work')->newFolder(UUIDUtil::getUUID());
		$file = $folder->newFile('document.pdf', $content);
		$this->jSignPdf->clearRequestParameters();
		try {
			$elements = [];
			if (isset($params['llx'])) {
				$element = new FileElement();
				$element->setPage($params['page']);
				foreach (['llx', 'lly', 'urx', 'ury'] as $coordinate) {
					$element->{'set' . ucfirst($coordinate)}((int)round($params[$coordinate]));
				}
				$elements[] = new VisibleElementAssoc($element, $this->appearance($params));
			}
			$parts = [];
			openssl_pkcs12_read($credential['pfx'], $parts, $credential['password']);
			$parsed = openssl_x509_parse($parts['cert']);
			$this->engine->setCertificate($credential['pfx'])->setPassword($credential['password'])->setInputFile($file)
				->setVisibleElements($elements)->setSignatureParams(['SignerCommonName' => $params['signerName'] ?: $credential['person']['fullName'],
					'IssuerCommonName' => $parsed['issuer']['CN'] ?? '', 'DocumentUUID' => '', 'ValidationURL' => '',
					'LocalSignerTimezone' => 'UTC', 'LocalSignerSignatureDateTime' => gmdate('c'), 'Reason' => $params['reason'] ?? '', 'ProviderAppearance' => true]);
			$signed = $this->engine->sign()->getContent();
			$valid = false;
			foreach ($this->signatureValidator->validateFromString($signed) as $signature) {
				foreach ($signature['certificates'] as $pem) {
					if (($signature['signatureValidation']['isValid'] ?? false)
						&& hash_equals($credential['certificate']['fingerprintSha256'], openssl_x509_fingerprint($pem, 'sha256'))) {
						$valid = true;
					}
				}
			}
			if (!$valid) {
				throw new ProviderException('signature_validation_failed', 'The output signature could not be verified against the selected certificate.', 503);
			}
			return $signed;
		} finally {
			$this->engine->setCertificate('')->setPassword('')->setVisibleElements([])->setSignatureParams([]);
			$this->jSignPdf->clearRequestParameters();
			$folder->delete();
		}
	}

	private function appearance(array $params): string {
		$images = array_filter(array_intersect_key($params, array_flip(['signatureImageBase64', 'stampBase64', 'logoBase64'])));
		if (!$images) {
			return '';
		}
		$canvas = new \Imagick();
		$canvas->newImage(700, 200, new \ImagickPixel('transparent'), 'png');
		$x = 0;
		foreach ($images as $base64) {
			$image = new \Imagick();
			$image->readImageBlob(ProviderContract::bytes($base64, 'signature image', 1048576));
			$image->thumbnailImage((int)(700 / count($images)), 200, true);
			$canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, $x, 0);
			$x += (int)(700 / count($images));
			$image->clear();
		}
		$path = $this->temp->getTemporaryFile('.png');
		$canvas->writeImage($path);
		$canvas->clear();
		return $path;
	}
}
