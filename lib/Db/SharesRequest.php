<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Db;

use DateTimeImmutable;
use Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

class SharesRequest {
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getFromFile(Node $file): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'parent', 'share_type', 'share_with', 'token')
			->from('share');

		$shares = [];
		try {
			$qb->where($qb->expr()->eq('file_source', $qb->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in(
					'item_type',
					$qb->createNamedParameter(['file', 'folder'], IQueryBuilder::PARAM_STR_ARRAY),
				))
				->andWhere($qb->expr()->orX(
					$qb->expr()->andX(
						$qb->expr()->neq('share_type', $qb->createNamedParameter(IShare::TYPE_USER)),
						$qb->expr()->neq('share_type', $qb->createNamedParameter(IShare::TYPE_USERGROUP)),
					),
					$qb->expr()->eq(
						'accepted',
						$qb->createNamedParameter(IShare::STATUS_ACCEPTED, IQueryBuilder::PARAM_INT),
					),
				))
				->andWhere($qb->expr()->orX(
					$qb->expr()->isNull('expiration'),
					$qb->expr()->gte(
						'expiration',
						$qb->createNamedParameter(
							new DateTimeImmutable('today'),
							IQueryBuilder::PARAM_DATETIME_IMMUTABLE,
						),
					),
				));
			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$shares[] = $data;
			}
			$cursor->closeCursor();
		} catch (Exception $e) {
			$this->logger->warning('could not get shares about file', ['exception' => $e]);
		}

		return $shares;
	}
}
