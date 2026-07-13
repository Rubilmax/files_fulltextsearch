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
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\Events\ShareDeletedFromSelfEvent;
use Throwable;

/**
 * Class ShareDeleted
 *
 * @package OCA\Files_FullTextSearch\Listeners
 */
class ShareDeleted extends ListenersCore implements IEventListener {

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices()
			|| (!$event instanceof ShareDeletedEvent && !$event instanceof ShareDeletedFromSelfEvent)) {
			return;
		}

		$share = $event->getShare();
		try {
			$this->createIndexesForNode($share->getNode(), IIndex::INDEX_META);
		} catch (Throwable $e) {
			$this->logger->warning('Could not update indexes after a share was deleted', ['exception' => $e]);
		}
	}
}
