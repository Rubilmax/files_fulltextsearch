<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Service;

use InvalidArgumentException;
use OCA\Files_FullTextSearch\ConfigLexicon;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCP\AppFramework\Services\IAppConfig;
use OCP\FullTextSearch\Model\IIndex;

/**
 * Class ConfigService
 *
 * @package OCA\Files_FullTextSearch\Service
 */
class ConfigService {
	private const BOOL_KEYS = [
		ConfigLexicon::FILES_LOCAL,
		ConfigLexicon::FILES_GROUP_FOLDERS,
		ConfigLexicon::FILES_OFFICE,
		ConfigLexicon::FILES_PDF,
		ConfigLexicon::FILES_ZIP,
		ConfigLexicon::FILES_OPEN_RESULT_DIRECTLY,
	];

	private const INT_KEYS = [
		ConfigLexicon::FILES_EXTERNAL,
		ConfigLexicon::FILES_SIZE,
		ConfigLexicon::FILES_CHUNK_SIZE,
	];

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function getConfig(): array {
		return [
			ConfigLexicon::FILES_LOCAL => $this->appConfig->getAppValueBool(ConfigLexicon::FILES_LOCAL),
			ConfigLexicon::FILES_EXTERNAL => $this->appConfig->getAppValueInt(ConfigLexicon::FILES_EXTERNAL),
			ConfigLexicon::FILES_GROUP_FOLDERS => $this->appConfig->getAppValueBool(ConfigLexicon::FILES_GROUP_FOLDERS),
			ConfigLexicon::FILES_SIZE => $this->appConfig->getAppValueInt(ConfigLexicon::FILES_SIZE),
			ConfigLexicon::FILES_OFFICE => $this->appConfig->getAppValueBool(ConfigLexicon::FILES_OFFICE),
			ConfigLexicon::FILES_PDF => $this->appConfig->getAppValueBool(ConfigLexicon::FILES_PDF),
			ConfigLexicon::FILES_ZIP => $this->appConfig->getAppValueBool(ConfigLexicon::FILES_ZIP),
			ConfigLexicon::FILES_CHUNK_SIZE => $this->appConfig->getAppValueInt(ConfigLexicon::FILES_CHUNK_SIZE),
			ConfigLexicon::FILES_OPEN_RESULT_DIRECTLY => $this->appConfig->getAppValueBool(ConfigLexicon::FILES_OPEN_RESULT_DIRECTLY),
		];
	}

	public function setConfig(array $save): void {
		foreach ($save as $key => $value) {
			if (!is_string($key)) {
				throw new InvalidArgumentException('Configuration keys must be strings');
			}

			$this->setValue($key, $value);
		}
	}

	public function getValue(string $key): bool|int {
		if (in_array($key, self::BOOL_KEYS, true)) {
			return $this->appConfig->getAppValueBool($key);
		}

		if (in_array($key, self::INT_KEYS, true)) {
			return $this->appConfig->getAppValueInt($key);
		}

		throw new InvalidArgumentException('Unknown configuration key: ' . $key);
	}

	public function setValue(string $key, mixed $value): void {
		if (in_array($key, self::BOOL_KEYS, true)) {
			$this->appConfig->setAppValueBool($key, $this->normalizeBool($value));

			return;
		}

		if (in_array($key, self::INT_KEYS, true)) {
			$value = $this->normalizeInt($value);
			$this->validateInt($key, $value);
			$this->appConfig->setAppValueInt($key, $value);

			return;
		}

		throw new InvalidArgumentException('Unknown configuration key: ' . $key);
	}

	public function setDocumentIndexOption(FilesDocument $document, string $option): void {
		$document->getIndex()->addOption('_' . $option, $this->getCurrentIndexOptionStatus($option) ? '1' : '0');
	}

	/**
	 * @param IIndex $index
	 *
	 * @return bool
	 */
	public function compareIndexOptions(IIndex $index): bool {
		$options = $index->getOptions();

		$ak = array_keys($options);
		foreach ($ak as $k) {
			if (!str_starts_with($k, '_')) {
				continue;
			}

			$currentValue = $this->getCurrentIndexOptionStatus(substr($k, 1)) ? '1' : '0';
			if ($options[$k] !== $currentValue) {
				return false;
			}
		}

		return true;
	}

	public function getCurrentIndexOptionStatus(string $option): bool {
		if ($option === ConfigLexicon::FILES_EXTERNAL) {
			return $this->appConfig->getAppValueInt(ConfigLexicon::FILES_EXTERNAL) === 1;
		}

		return in_array($option, self::BOOL_KEYS, true)
			&& $this->appConfig->getAppValueBool($option);
	}

	private function normalizeBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value) && ($value === 0 || $value === 1)) {
			return $value === 1;
		}

		if (is_string($value)) {
			$normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
			if ($normalized !== null) {
				return $normalized;
			}
		}

		throw new InvalidArgumentException('Invalid boolean configuration value');
	}

	private function normalizeInt(mixed $value): int {
		if (is_int($value)) {
			return $value;
		}

		if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
			return (int)$value;
		}

		throw new InvalidArgumentException('Invalid integer configuration value');
	}

	private function validateInt(string $key, int $value): void {
		if ($key === ConfigLexicon::FILES_EXTERNAL && !in_array($value, [0, 1, 2], true)) {
			throw new InvalidArgumentException('External files mode must be 0, 1, or 2');
		}

		if ($key === ConfigLexicon::FILES_SIZE
			&& ($value < 0 || $value > intdiv(PHP_INT_MAX, 1024 * 1024))) {
			throw new InvalidArgumentException('Maximum file size is outside the supported range');
		}

		if ($key === ConfigLexicon::FILES_CHUNK_SIZE && $value < 1) {
			throw new InvalidArgumentException('Chunk size must be at least 1');
		}
	}
}
