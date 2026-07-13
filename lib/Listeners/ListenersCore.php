<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Listeners;

use OCA\Files_FullTextSearch\Service\ConfigService;
use OCA\Files_FullTextSearch\Service\FilesService;
use OCA\Files_FullTextSearch\Tools\Traits\TArrayTools;
use OCP\App\IAppManager;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndex;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class CoreFileEvents
 *
 * @package OCA\Files_FullTextSearch\Listeners
 */
class ListenersCore {
	use TArrayTools;

	public function __construct(
		protected IAppManager $appManager,
		protected IUserSession $userSession,
		protected IFullTextSearchManager $fullTextSearchManager,
		protected FilesService $filesService,
		protected ConfigService $configService,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * @return bool
	 */
	protected function registerFullTextSearchServices(): bool {
		try {
			$this->appManager->loadApp('fulltextsearch');

			return $this->fullTextSearchManager->isAvailable();
		} catch (Throwable $e) {
			$this->logger->warning('Could not initialize full-text search services', ['exception' => $e]);

			return false;
		}
	}

	protected function createIndexesForNode(Node $node, int $status = IIndex::INDEX_FULL): void {
		foreach ($this->getNodeTree($node) as $entry) {
			$this->createIndexForNode($entry, $status);
		}
	}

	protected function createIndexForNode(Node $node, int $status = IIndex::INDEX_FULL): void {
		$fileId = $node->getId();
		$userId = $node->getOwner()?->getUID() ?? $this->userSession->getUser()?->getUID() ?? '';
		if ($fileId < 0 || $userId === '') {
			return;
		}

		try {
			$this->fullTextSearchManager->createIndex(
				'files', (string)$fileId, $userId, $status,
			);
		} catch (Throwable $e) {
			$this->logger->warning('Could not update the file index status', [
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Capture IDs before a folder is deleted, while its children are still available.
	 *
	 * @return string[]
	 */
	protected function getNodeTreeIds(Node $node): array {
		$ids = [];
		foreach ($this->getNodeTree($node) as $entry) {
			if ($entry->getId() >= 0) {
				$ids[] = (string)$entry->getId();
			}
		}

		return $ids;
	}

	/**
	 * @return iterable<Node>
	 */
	private function getNodeTree(Node $node): iterable {
		yield $node;
		if ($node->getType() !== FileInfo::TYPE_FOLDER) {
			return;
		}

		try {
			/** @var Folder $node */
			$children = $node->getDirectoryListing();
		} catch (Throwable $e) {
			$this->logger->warning('Could not traverse a folder while updating file indexes', [
				'path' => $node->getPath(),
				'exception' => $e,
			]);

			return;
		}

		foreach ($children as $child) {
			yield from $this->getNodeTree($child);
		}
	}
}
