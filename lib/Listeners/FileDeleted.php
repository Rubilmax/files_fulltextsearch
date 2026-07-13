<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\FullTextSearch\Model\IIndex;
use Throwable;

/**
 * Class FileDeleted
 *
 * @package OCA\Files_FullTextSearch\Listeners
 */
class FileDeleted extends ListenersCore implements IEventListener {
	/** @var array<string, array{ids: string[], noindexParent: ?\OCP\Files\Node}> */
	private static array $pendingDeletes = [];

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeDeletedEvent && !$event instanceof NodeDeletedEvent) {
			return;
		}

		$node = $event->getNode();
		$key = $this->getDeleteKey($node);
		if ($event instanceof BeforeNodeDeletedEvent) {
			self::$pendingDeletes[$key] = [
				'ids' => $this->getNodeTreeIds($node),
				'noindexParent' => $node->getName() === '.noindex' ? $node->getParent() : null,
			];

			return;
		}

		if (!$this->registerFullTextSearchServices()) {
			unset(self::$pendingDeletes[$key]);

			return;
		}

		$pending = self::$pendingDeletes[$key] ?? [
			'ids' => $this->getNodeTreeIds($node),
			'noindexParent' => null,
		];
		unset(self::$pendingDeletes[$key]);

		try {
			$this->fullTextSearchManager->updateIndexesStatus(
				'files', $pending['ids'], IIndex::INDEX_REMOVE, true,
			);
		} catch (Throwable $e) {
			$this->logger->warning('Could not remove deleted file indexes', ['exception' => $e]);
		}

		if ($pending['noindexParent'] !== null) {
			$this->createIndexesForNode($pending['noindexParent']);
		}
	}

	private function getDeleteKey(\OCP\Files\Node $node): string {
		return $node->getId() . ':' . $node->getPath();
	}
}
