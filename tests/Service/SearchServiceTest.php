<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Tests\Service;

use OC\FullTextSearch\Model\IndexDocument;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCA\Files_FullTextSearch\Service\ExtensionService;
use OCA\Files_FullTextSearch\Service\FilesService;
use OCA\Files_FullTextSearch\Service\SearchService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\IMimeTypeDetector;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

class SearchServiceTest extends TestCase {
	private SearchService $service;
	private FilesService&MockObject $filesService;
	private ExtensionService&MockObject $extensionService;
	private IURLGenerator&MockObject $urlGenerator;
	private LoggerInterface&MockObject $logger;
	/** @var list<FilesDocument> */
	private array $enrichedDocuments = [];

	protected function setUp(): void {
		$this->filesService = $this->createMock(FilesService::class);
		$this->filesService->method('secureUsername')->willReturnCallback(fn (string $userId): string => str_replace('.', '\\.', $userId));
		$this->extensionService = $this->createMock(ExtensionService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);
		$mimeTypeDetector->method('mimeTypeIcon')->willReturn('/core/img/filetypes/text.svg');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('instanceid')->willReturn('instance1');
		$this->service = new SearchService(
			$mimeTypeDetector,
			$this->urlGenerator,
			$this->createMock(IAppConfig::class),
			$this->filesService,
			$config,
			$this->extensionService,
			$this->logger,
		);
	}

	private function file(string $path): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getMimetype')->willReturn('text/plain');
		$file->method('getSize')->willReturn(100);
		$file->method('getMTime')->willReturn(100);
		$file->method('getEtag')->willReturn('etag');
		$file->method('getPermissions')->willReturn(31);
		return $file;
	}

	private function makeSearchResult(array $documents, string $userId): ISearchResult&MockObject {
		$request = $this->createMock(ISearchRequest::class);
		$request->method('getAuthor')->willReturn($userId);
		$result = $this->createMock(ISearchResult::class);
		$result->method('getRequest')->willReturn($request);
		$result->method('getDocuments')->willReturn($documents);
		$result->expects(self::once())->method('setDocuments')->willReturnCallback(function (array $enriched) use ($result): ISearchResult {
			$this->enrichedDocuments = $enriched;
			return $result;
		});
		$this->extensionService->expects(self::once())->method('searchResult')->with($result);
		return $result;
	}

	public function testDottedUsernameKeepsSuccessfulResult(): void {
		$file = $this->file('/john.doe/files/Documents/report.txt');
		$this->filesService->expects(self::once())->method('getFileFromId')->with('john.doe', 42)->willReturn($file);
		$this->urlGenerator->expects(self::once())->method('linkToRoute')
			->with('files.view.index', ['dir' => '/Documents/', 'scrollto' => 'report.txt'])->willReturn('/document/42');
		$this->logger->expects(self::never())->method('warning');
		$result = $this->makeSearchResult([new IndexDocument('files', '42')], 'john.doe');

		$this->service->improveSearchResult($result);

		self::assertCount(1, $this->enrichedDocuments);
		$document = $this->enrichedDocuments[0];
		self::assertSame('Documents/report.txt', $document->getTitle());
		self::assertSame('/Documents/report.txt', $document->getPath());
		self::assertSame('/document/42', $document->getLink());
		self::assertSame('00000042instance1', $document->getInfo('webdav'));
		self::assertSame('/core/img/filetypes/text.svg', $document->getInfoArray('unified')['icon']);
	}

	public function testTypeErrorDropsOnlyBrokenHit(): void {
		$error = new TypeError('Invalid file metadata');
		$this->filesService->expects(self::exactly(3))->method('getFileFromId')->willReturnCallback(
			fn (string $userId, int $fileId): File => $fileId === 42 ? throw $error : $this->file('/' . $userId . '/files/report' . $fileId . '.txt'),
		);
		$this->logger->expects(self::once())->method('warning')
			->with('Exception while improving searchresult', ['exception' => $error]);
		$result = $this->makeSearchResult([
			new IndexDocument('files', '41'),
			new IndexDocument('files', '42'),
			new IndexDocument('files', '43'),
		], 'alice');

		$this->service->improveSearchResult($result);

		self::assertSame(['41', '43'], array_map(fn (FilesDocument $document): string => $document->getId(), $this->enrichedDocuments));
		self::assertSame(['report41.txt', 'report43.txt'], array_map(fn (FilesDocument $document): string => $document->getTitle(), $this->enrichedDocuments));
	}

	public function testExtensionFilterDoesNotRewriteUsername(): void {
		$request = $this->createMock(ISearchRequest::class);
		$request->method('getOption')->willReturnCallback(fn (string $option): string => $option === 'files_extension' ? 'txt' : '');
		$request->expects(self::once())->method('addRegexFilters')->with([['title' => '.*\\.txt']])->willReturnSelf();
		$this->filesService->expects(self::never())->method('secureUsername');
		$this->extensionService->expects(self::once())->method('searchRequest')->with($request);

		$this->service->improveSearchRequest($request);
	}
}
