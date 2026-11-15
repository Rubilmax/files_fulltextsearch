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
use OCA\Files_FullTextSearch\Service\LocalFilesService;
use OCA\Files_FullTextSearch\Tests\GroupFoldersTestCase;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Comments\ICommentsManager;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndexOptions;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\Server;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

#[Group('DB')]
class FilesServiceGroupFoldersTest extends GroupFoldersTestCase {
	private FilesService $service;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueInt')
			->willReturnCallback(static fn (string $key): int => match ($key) {
				ConfigLexicon::FILES_CHUNK_SIZE => FilesService::CHUNK_TREE_SIZE,
				default => 0,
			});

		$this->service = new FilesService(
			$this->rootFolder,
			$appConfig,
			Server::get(IUserManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ICommentsManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ConfigService::class),
			Server::get(LocalFilesService::class),
			$this->createMock(ExternalFilesService::class),
			$this->createGroupFoldersService(),
			$this->createMock(ExtensionService::class),
			$this->createMock(IFullTextSearchManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function createGroupFolderWithContent(bool $acl = false): void {
		$this->createGroupFolder('ffts_docs', acl: $acl);
		$folder = $this->getFolder(self::ALICE, 'ffts_docs');
		$folder->newFile('a.txt', 'content');
		$folder->newFolder('sub')->newFile('b.txt', 'content');
	}

	/**
	 * @return list<string>
	 */
	private function getChunks(string $userId): array {
		$options = $this->createMock(IIndexOptions::class);
		$options->method('getOption')->with('path', '/')->willReturn('/');

		return array_values(array_filter(
			$this->service->getChunksFromUser($userId, $options),
			static fn (string $chunk): bool => str_starts_with($chunk, '/ffts_docs')
		));
	}

	/**
	 * @return array<string, string> path => source of the documents within the groupfolder
	 */
	private function getDocuments(string $userId): array {
		$this->getFolder($userId, '');

		$documents = [];
		foreach ($this->service->getFilesFromUser($userId, '/') as $document) {
			$this->assertInstanceOf(FilesDocument::class, $document);
			if (str_starts_with($document->getPath(), 'ffts_docs')) {
				$documents[$document->getPath()] = $document->getSource();
			}
		}
		ksort($documents);

		return $documents;
	}

	public function testGroupFolderIsChunkedForFirstUserOnly(): void {
		$this->createGroupFolderWithContent();

		$this->assertSame(['/ffts_docs/a.txt', '/ffts_docs/sub'], $this->getChunks(self::ALICE));
		$this->assertSame([], $this->getChunks(self::BOB));
	}

	public function testGroupFolderDocumentsAreGeneratedForFirstUserOnly(): void {
		$this->createGroupFolderWithContent();

		$expected = [
			'ffts_docs' => ConfigLexicon::FILES_GROUP_FOLDERS,
			'ffts_docs/a.txt' => ConfigLexicon::FILES_GROUP_FOLDERS,
			'ffts_docs/sub' => ConfigLexicon::FILES_GROUP_FOLDERS,
			'ffts_docs/sub/b.txt' => ConfigLexicon::FILES_GROUP_FOLDERS,
		];
		$this->assertSame($expected, $this->getDocuments(self::ALICE));
		$this->assertSame([], $this->getDocuments(self::BOB));
	}

	public function testGroupFolderWithAclIsCrawledForEveryUser(): void {
		$this->createGroupFolderWithContent(acl: true);

		$this->assertSame(['/ffts_docs/a.txt', '/ffts_docs/sub'], $this->getChunks(self::ALICE));
		$this->assertSame(['/ffts_docs/a.txt', '/ffts_docs/sub'], $this->getChunks(self::BOB));

		$expected = [
			'ffts_docs' => ConfigLexicon::FILES_GROUP_FOLDERS,
			'ffts_docs/a.txt' => ConfigLexicon::FILES_GROUP_FOLDERS,
			'ffts_docs/sub' => ConfigLexicon::FILES_GROUP_FOLDERS,
			'ffts_docs/sub/b.txt' => ConfigLexicon::FILES_GROUP_FOLDERS,
		];
		$this->assertSame($expected, $this->getDocuments(self::ALICE));
		$this->assertSame($expected, $this->getDocuments(self::BOB));
	}

	public function testReleasedGroupFolderIsCrawledByNextUser(): void {
		$this->createGroupFolderWithContent();

		$this->getChunks(self::ALICE);
		$this->service->releaseCrawledGroupFolders(self::ALICE);

		$this->assertSame(['/ffts_docs/a.txt', '/ffts_docs/sub'], $this->getChunks(self::BOB));
		$this->assertSame([], $this->getChunks(self::ALICE));
	}
}
