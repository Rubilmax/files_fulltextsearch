<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Listeners;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;

/**
 * Class FileCreated
 *
 * @package OCA\Files_FullTextSearch\Listeners
 */
class FileCreated extends ListenersCore implements IEventListener {

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices()) {
			return;
		}

		if ($event instanceof NodeCreatedEvent) {
			$node = $event->getNode();
		} elseif ($event instanceof NodeCopiedEvent || $event instanceof NodeRestoredEvent) {
			$node = $event->getTarget();
		} else {
			return;
		}

		if ($node->getName() === '.noindex') {
			$this->createIndexesForNode($node->getParent());

			return;
		}

		$this->createIndexesForNode($node);
	}
}
