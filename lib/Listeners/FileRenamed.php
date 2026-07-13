<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\FullTextSearch\Model\IIndex;

/**
 * Class FileRenamed
 *
 * @package OCA\Files_FullTextSearch\Listeners
 */
class FileRenamed extends ListenersCore implements IEventListener {

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices() || !($event instanceof NodeRenamedEvent)) {
			return;
		}

		$source = $event->getSource();
		$target = $event->getTarget();
		if ($source->getName() === '.noindex') {
			$this->createIndexesForNode($source->getParent());
		}
		if ($target->getName() === '.noindex') {
			$this->createIndexesForNode($target->getParent(), IIndex::INDEX_META);

			return;
		}

		$this->createIndexesForNode($target, IIndex::INDEX_META);
	}
}
