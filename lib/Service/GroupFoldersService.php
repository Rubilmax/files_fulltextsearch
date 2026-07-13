<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Service;

use OCA\Files_FullTextSearch\ConfigLexicon;
use OCA\Files_FullTextSearch\Exceptions\FileIsNotIndexableException;
use OCA\Files_FullTextSearch\Exceptions\GroupFolderNotFoundException;
use OCA\Files_FullTextSearch\Exceptions\KnownFileSourceException;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCA\Files_FullTextSearch\Model\MountPoint;
use OCA\Files_FullTextSearch\Tools\Traits\TArrayTools;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\Node;
use OCP\FullTextSearch\Model\IIndex;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class GroupFoldersService {
	use TArrayTools;

	private ?FolderManager $folderManager = null;
	/** @var MountPoint[] */
	private array $groupFolders = [];

	public function __construct(
		private IAppManager $appManager,
		private IGroupManager $groupManager,
		private LocalFilesService $localFilesService,
		IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		if ($appConfig->getAppValueBool(ConfigLexicon::FILES_GROUP_FOLDERS)
			&& $this->appManager->isEnabledForAnyone('groupfolders')) {
			try {
				$this->appManager->loadApp('groupfolders');
				$this->folderManager = \OCP\Server::get(FolderManager::class);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not load Team Folders integration', ['exception' => $e]);
			}
		}
	}

	/**
	 * @param string $userId
	 */
	public function initGroupSharesForUser(string $userId): void {
		if ($this->folderManager === null) {
			return;
		}

		$this->logger->debug('initGroupSharesForUser request', ['userId' => $userId]);
		$this->groupFolders = $this->getMountPoints($userId);
		$this->logger->debug('initGroupSharesForUser result', ['groupFolders' => $this->groupFolders]);
	}

	/**
	 * @param Node $file
	 * @param string $source
	 *
	 * @throws KnownFileSourceException
	 */
	public function getFileSource(Node $file, string &$source): void {
		if ($file->getMountPoint()
			->getMountType() !== 'group'
			|| $this->folderManager === null) {
			return;
		}

		try {
			$this->getMountPoint($file);
		} catch (FileIsNotIndexableException $e) {
			return;
		}

		$source = ConfigLexicon::FILES_GROUP_FOLDERS;
		throw new KnownFileSourceException();
	}

	/**
	 * @param FilesDocument $document
	 * @param Node $file
	 */
	public function updateDocumentAccess(FilesDocument $document, Node $file): void {
		if ($document->getSource() !== ConfigLexicon::FILES_GROUP_FOLDERS) {
			return;
		}

		try {
			$mount = $this->getMountPoint($file);
		} catch (FileIsNotIndexableException $e) {
			return;
		}

		$access = $document->getAccess();
		foreach ($mount->getGroups() as $group) {
			$access->addGroup($group);
		}
		foreach ($mount->getCircles() as $circle) {
			$access->addCircle($circle);
		}

		$document->getIndex()
			->addOptionInt('group_folder_id', $mount->getId());
		$document->setAccess($access);
	}

	/**
	 * @param FilesDocument $document
	 * @param array $users
	 */
	public function getShareUsers(FilesDocument $document, array &$users): void {
		if ($document->getSource() !== ConfigLexicon::FILES_GROUP_FOLDERS) {
			return;
		}

		$this->localFilesService->getSharedUsersFromAccess($document->getAccess(), $users);
	}

	/**
	 * @param Node $file
	 *
	 * @return MountPoint
	 * @throws FileIsNotIndexableException
	 */
	private function getMountPoint(Node $file): MountPoint {
		$mountPoint = $file->getMountPoint();
		$folderId = method_exists($mountPoint, 'getFolderId') ? $mountPoint->getFolderId() : null;
		if (is_int($folderId)) {
			foreach ($this->groupFolders as $mount) {
				if ($mount->getId() === $folderId) {
					return $mount;
				}
			}
		}

		$filePath = rtrim($file->getPath(), '/');
		foreach ($this->groupFolders as $mount) {
			$mountPath = rtrim($mount->getPath(), '/');
			if ($filePath === $mountPath || str_starts_with($filePath, $mountPath . '/')) {
				return $mount;
			}
		}

		throw new FileIsNotIndexableException();
	}

	/**
	 * @param string $userId
	 *
	 * @return MountPoint[]
	 */
	private function getMountPoints(string $userId): array {
		if ($this->folderManager === null) {
			return [];
		}

		$mountPoints = [];
		$mounts = $this->folderManager->getAllFolders();

		foreach ($mounts as $mount) {
			$mount = $this->normalizeFolder($mount);
			if ($mount === []) {
				continue;
			}

			$groups = [];
			$circles = [];
			foreach ($this->getArray('groups', $mount) as $id => $details) {
				if (!is_string($id)) {
					continue;
				}

				$type = is_array($details) ? $this->get('type', $details, 'group') : 'group';
				if ($type === 'circle') {
					$circles[] = $id;
				} else {
					$groups[] = $id;
				}
			}

			$mountPoint = new MountPoint();
			$mountPoint->setId($this->getInt('id', $mount, -1))
				->setPath('/' . $userId . '/files/' . $this->get('mount_point', $mount))
				->setGroups($groups)
				->setCircles($circles);
			$mountPoints[] = $mountPoint;
		}

		return $mountPoints;
	}

	/**
	 * @param IIndex $index
	 */
	public function impersonateOwner(IIndex $index): void {
		if ($index->getSource() !== ConfigLexicon::FILES_GROUP_FOLDERS) {
			return;
		}

		if ($this->folderManager === null) {
			return;
		}

		$groupFolderId = $index->getOptionInt('group_folder_id', 0);
		try {
			$mount = $this->getGroupFolderById($groupFolderId);
		} catch (GroupFolderNotFoundException $e) {
			return;
		}

		try {
			$users = $this->folderManager->searchUsers($groupFolderId, '', 1, 0);
			$ownerId = isset($users[0]) ? $this->get('uid', $users[0]) : '';
		} catch (\Throwable $e) {
			$this->logger->warning('Could not find a Team Folder viewer', [
				'folderId' => $groupFolderId,
				'exception' => $e,
			]);
			$ownerId = '';
		}

		if ($ownerId === '') {
			$groups = array_filter(
				$this->getArray('groups', $mount),
				static fn (mixed $details): bool => !is_array($details) || ($details['type'] ?? 'group') === 'group',
			);
			$ownerId = $this->getRandomUserFromGroups(array_keys($groups));
		}
		if ($ownerId !== '') {
			$index->setOwnerId($ownerId);
		}
	}

	/**
	 * @param int $groupFolderId
	 *
	 * @return array
	 * @throws GroupFolderNotFoundException
	 */
	private function getGroupFolderById(int $groupFolderId): array {
		if ($groupFolderId === 0 || $this->folderManager === null) {
			throw new GroupFolderNotFoundException();
		}

		$mounts = $this->folderManager->getAllFolders();
		foreach ($mounts as $mount) {
			$mount = $this->normalizeFolder($mount);
			if ($this->getInt('id', $mount) === $groupFolderId) {
				return $mount;
			}
		}

		throw new GroupFolderNotFoundException();
	}

	/**
	 * @param array $groups
	 *
	 * @return string
	 */
	private function getRandomUserFromGroups(array $groups): string {
		foreach ($groups as $groupName) {
			$group = $this->groupManager->get($groupName);
			if ($group === null) {
				continue;
			}

			$users = $group->getUsers();
			$user = reset($users);
			if ($user !== false) {
				return $user->getUID();
			}
		}

		return '';
	}

	private function normalizeFolder(mixed $folder): array {
		if (is_array($folder)) {
			return $folder;
		}

		if (is_object($folder) && is_callable([$folder, 'toArray'])) {
			$data = $folder->toArray();

			return is_array($data) ? $data : [];
		}

		return [];
	}
}
