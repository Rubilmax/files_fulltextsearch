<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\FullTextSearch\Model\IIndex;

/**
 * Class FileChanged
 *
 * @package OCA\Files_FullTextSearch\Listeners
 */
class FileChanged extends ListenersCore implements IEventListener {

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices()
			|| (!$event instanceof NodeWrittenEvent && !$event instanceof NodeTouchedEvent)) {
			return;
		}

		$this->createIndexForNode(
			$event->getNode(),
			$event instanceof NodeWrittenEvent ? IIndex::INDEX_CONTENT : IIndex::INDEX_META,
		);
	}
}
