<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\FullTextSearch\Model\IIndex;
use OCP\Share\Events\ShareAcceptedEvent;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareTransferredEvent;
use Throwable;

/**
 * Class ShareCreated
 *
 * @package OCA\Files_FullTextSearch\Listeners
 */
class ShareCreated extends ListenersCore implements IEventListener {

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices()
			|| (!$event instanceof ShareCreatedEvent
				&& !$event instanceof ShareAcceptedEvent
				&& !$event instanceof ShareTransferredEvent)) {
			return;
		}

		$share = $event->getShare();
		try {
			$this->createIndexesForNode($share->getNode(), IIndex::INDEX_META);
		} catch (Throwable $e) {
			$this->logger->warning('Could not update indexes after a share was created', ['exception' => $e]);
		}
	}
}
