<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Model;

use JsonSerializable;

/**
 * Class MountPoint
 *
 * @package OCA\Files_FullTextSearch\Model
 */
class MountPoint implements JsonSerializable {

	private int $id = 0;

	private string $path = '';

	private bool $global = false;

	private array $groups = [];

	private array $circles = [];

	private array $users = [];

	/**
	 * @return int
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * @param int $id
	 *
	 * @return $this
	 */
	public function setId(int $id): MountPoint {
		$this->id = $id;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPath(): string {
		return $this->path;
	}

	/**
	 * @param string $path
	 *
	 * @return $this
	 */
	public function setPath(string $path): MountPoint {
		$this->path = $path;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isGlobal(): bool {
		return $this->global;
	}

	/**
	 * @param bool $global
	 *
	 * @return $this
	 */
	public function setGlobal(bool $global): MountPoint {
		$this->global = $global;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getGroups(): array {
		return $this->groups;
	}

	/**
	 * @param array $groups
	 *
	 * @return $this
	 */
	public function setGroups(array $groups): MountPoint {
		$this->groups = $groups;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getCircles(): array {
		return $this->circles;
	}

	/**
	 * @param array $circles
	 *
	 * @return $this
	 */
	public function setCircles(array $circles): MountPoint {
		$this->circles = $circles;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getUsers(): array {
		return $this->users;
	}

	/**
	 * @param array $users
	 *
	 * @return $this
	 */
	public function setUsers(array $users): MountPoint {
		$this->users = $users;

		return $this;
	}

	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'path' => $this->getPath(),
			'global' => $this->isGlobal(),
			'groups' => $this->getGroups(),
			'circles' => $this->getCircles(),
			'users' => $this->getUsers()
		];
	}
}
