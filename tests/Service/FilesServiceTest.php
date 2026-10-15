<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Tests\Service;

use OCA\Files_FullTextSearch\ConfigLexicon;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCA\Files_FullTextSearch\Service\ConfigService;
use OCA\Files_FullTextSearch\Service\ExtensionService;
use OCA\Files_FullTextSearch\Service\ExternalFilesService;
use OCA\Files_FullTextSearch\Service\FilesService;
use OCA\Files_FullTextSearch\Service\GroupFoldersService;
use OCA\Files_FullTextSearch\Service\LocalFilesService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Comments\ICommentsManager;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\StorageNotAvailableException;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexOptions;
use OCP\FullTextSearch\Model\IRunner;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use TypeError;

class FilesServiceTest extends TestCase {
	private FilesService $service;
	private Folder&MockObject $userFolder;
	private IAppConfig&MockObject $appConfig;
	private LocalFilesService&MockObject $localFilesService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->userFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($this->userFolder);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValueInt')->willReturnMap([
			[ConfigLexicon::FILES_CHUNK_SIZE, 0, false, 2],
			[ConfigLexicon::FILES_EXTERNAL, 0, false, 1],
		]);
		$this->localFilesService = $this->createMock(LocalFilesService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$this->service = new FilesService(
			$rootFolder,
			$this->appConfig,
			$userManager,
			$this->createMock(IURLGenerator::class),
			$this->createMock(ICommentsManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ConfigService::class),
			$this->localFilesService,
			$this->createMock(ExternalFilesService::class),
			$this->createMock(GroupFoldersService::class),
			$this->createMock(ExtensionService::class),
			$this->createMock(IFullTextSearchManager::class),
			$this->logger,
		);
	}

	private function file(int $id = 42): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn('/alice/files/example.txt');
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getExtension')->willReturn('txt');
		$file->method('getMimetype')->willReturn('text/plain');
		$file->method('getOwner')->willReturn(null);
		$file->method('getParent')->willThrowException(new NotFoundException());
		$file->method('getMTime')->willReturn(100);
		return $file;
	}

	public static function stats(): array {
		return [
			'false' => [false, 0, 0],
			'empty' => [[], 0, 0],
			'populated' => [['ctime' => 123, 'atime' => 456], 123, 456],
			'partial' => [['ctime' => 123], 123, 0],
		];
	}

	#[DataProvider('stats')]
	public function testOptionalStatMetadata(array|false $stat, int $created, int $accessed): void {
		$file = $this->file();
		$file->method('stat')->willReturn($stat);
		$this->userFolder->method('get')->willReturn($file);
		$this->userFolder->method('getById')->with(42)->willReturn([$file]);
		$this->logger->expects(self::never())->method('warning');

		$documents = $this->service->getFilesFromUser('alice', '/example.txt');

		self::assertCount(1, $documents);
		self::assertSame(['creationTime' => $created, 'accessedTime' => $accessed], $documents[0]->getMore());
		self::assertSame(100, $documents[0]->getModifiedTime());
	}

	public static function failures(): array {
		return [
			'type error' => [new TypeError('Invalid metadata')],
			'deleted' => [new NotFoundException()],
			'offline' => [self::storageError()],
		];
	}

	private static function storageError(): StorageNotAvailableException {
		// The exception constructor needs Nextcloud's running translation service.
		return (new ReflectionClass(StorageNotAvailableException::class))->newInstanceWithoutConstructor();
	}

	#[DataProvider('failures')]
	public function testBadFileDoesNotDiscardHealthyFiles(Throwable $error): void {
		$bad = $this->file(41);
		$bad->method('stat')->willThrowException($error);
		$healthy = $this->file();
		$healthy->method('stat')->willReturn(false);
		$this->userFolder->method('get')->willReturn($this->userFolder);
		$this->userFolder->method('getPath')->willReturn('/alice/files');
		$this->userFolder->method('getParent')->willThrowException(new NotFoundException());
		$this->userFolder->method('getDirectoryListing')->willReturn([$healthy, $bad, $healthy]);
		$this->userFolder->method('getById')->willReturnCallback(fn (int $id): array => [$id === 41 ? $bad : $healthy]);
		$this->logger->expects(self::once())->method('warning')->with(
			'Unable to generate fulltextsearch document',
			self::callback(fn (array $context): bool => $context['exception'] === $error),
		);

		self::assertCount(2, $this->service->getFilesFromUser('alice', '/'));
	}

	#[DataProvider('failures')]
	public function testUnavailableChunkIsSkipped(Throwable $error): void {
		$this->userFolder->method('get')->willThrowException($error);
		$this->logger->expects(self::once())->method('warning');

		self::assertSame([], $this->service->getFilesFromUser('alice', '/deleted'));
	}

	public function testChunkDiscoveryContinuesPastOfflineFolder(): void {
		$offline = $this->createMock(Folder::class);
		$offline->method('getDirectoryListing')->willThrowException(self::storageError());
		$file = $this->file();
		$this->userFolder->method('get')->willReturn($this->userFolder);
		$this->userFolder->method('getDirectoryListing')->willReturn([$offline, $file]);
		$this->logger->expects(self::once())->method('warning');

		self::assertSame(['/example.txt'], $this->service->getChunksFromUser('alice', $this->createMock(IIndexOptions::class)));
	}

	public function testDirectoryReadFailureDoesNotAbortIndexing(): void {
		$this->userFolder->method('get')->willReturn($this->userFolder);
		$this->userFolder->method('getPath')->willReturn('/alice/files');
		$this->userFolder->method('getParent')->willThrowException(new NotFoundException());
		$this->userFolder->method('getDirectoryListing')->willThrowException(self::storageError());
		$this->logger->expects(self::once())->method('warning');

		self::assertSame([], $this->service->getFilesFromUser('alice', '/'));
	}

	public function testNoindexFolderIsStillExcluded(): void {
		$this->userFolder->method('get')->willReturn($this->userFolder);
		$this->userFolder->method('getPath')->willReturn('/alice/files');
		$this->userFolder->method('getParent')->willThrowException(new NotFoundException());
		$this->userFolder->method('nodeExists')->with('.noindex')->willReturn(true);
		$this->userFolder->expects(self::never())->method('getDirectoryListing');
		$this->logger->expects(self::never())->method('warning');

		self::assertSame([], $this->service->getFilesFromUser('alice', '/'));
	}

	public function testRunnerErrorsAreNotSwallowed(): void {
		$this->userFolder->method('get')->willReturn($this->userFolder);
		$this->userFolder->method('getParent')->willThrowException(new NotFoundException());
		$runner = $this->createMock(IRunner::class);
		$runner->method('updateAction')->willThrowException(new RuntimeException('Stop runner'));
		$this->service->setRunner($runner);

		$this->expectExceptionMessage('Stop runner');
		$this->service->getFilesFromUser('alice', '/');
	}

	public function testAccessTypeErrorPreventsIndexingPartialDocument(): void {
		$file = $this->file();
		$this->userFolder->method('get')->willReturn($file);
		$this->localFilesService->method('updateDocumentAccess')->willThrowException(new TypeError('Invalid access'));
		$index = $this->createMock(IIndex::class);
		$index->expects(self::once())->method('setStatus')->with(IIndex::INDEX_IGNORE);
		$document = new FilesDocument('files', '42');
		$document->setViewerId('alice')->setPath('example.txt');
		$document->setIndex($index);
		$this->logger->expects(self::once())->method('warning');

		$this->service->generateDocument($document);
	}

	public function testTemporaryStorageFailureDoesNotRemoveLiveDocument(): void {
		$error = self::storageError();
		$this->userFolder->method('getById')->willThrowException($error);
		$index = $this->createMock(IIndex::class);
		$index->method('getOwnerId')->willReturn('alice');
		$index->method('getDocumentId')->willReturn('42');
		$index->expects(self::never())->method('setStatus');

		$this->expectExceptionObject($error);
		(new ReflectionMethod(FilesService::class, 'generateDocumentFromIndex'))->invoke($this->service, $index);
	}

	public function testConfirmedMissingFileRemovesLiveDocument(): void {
		$this->userFolder->method('getById')->willReturn([]);
		$index = $this->createMock(IIndex::class);
		$index->method('getOwnerId')->willReturn('alice');
		$index->method('getProviderId')->willReturn('files');
		$index->method('getDocumentId')->willReturn('42');
		$index->expects(self::once())->method('setStatus')->with(IIndex::INDEX_REMOVE);

		$document = (new ReflectionMethod(FilesService::class, 'generateDocumentFromIndex'))->invoke($this->service, $index);

		self::assertSame($index, $document->getIndex());
	}
}
