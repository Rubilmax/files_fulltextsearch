<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Settings;

use InvalidArgumentException;
use OCA\Files_FullTextSearch\ConfigLexicon;
use OCA\Files_FullTextSearch\Service\ConfigService;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * Class Admin
 *
 * @package OCA\Files_FullTextSearch\Settings
 */
class Admin implements IDeclarativeSettingsFormWithHandlers {
	private const FIELDS = [
		ConfigLexicon::FILES_LOCAL,
		ConfigLexicon::FILES_EXTERNAL,
		ConfigLexicon::FILES_GROUP_FOLDERS,
		ConfigLexicon::FILES_SIZE,
		ConfigLexicon::FILES_PDF,
		ConfigLexicon::FILES_OFFICE,
		ConfigLexicon::FILES_OPEN_RESULT_DIRECTLY,
	];

	private const BOOLEAN_FIELDS = [
		ConfigLexicon::FILES_LOCAL,
		ConfigLexicon::FILES_GROUP_FOLDERS,
		ConfigLexicon::FILES_PDF,
		ConfigLexicon::FILES_OFFICE,
		ConfigLexicon::FILES_OPEN_RESULT_DIRECTLY,
	];

	public function __construct(
		private ConfigService $configService,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'files',
			'priority' => 51,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'fulltextsearch',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => 'Files',
			'fields' => [
				[
					'id' => ConfigLexicon::FILES_LOCAL,
					'title' => 'Local Files',
					'description' => 'Index the content of local files.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => ConfigLexicon::FILES_EXTERNAL,
					'title' => 'External Files',
					'description' => 'Index the content of external files.',
					'type' => DeclarativeSettingsTypes::RADIO,
					'options' => [
						['name' => 'Index path only', 'value' => 0],
						['name' => 'Index path and content', 'value' => 1],
						['name' => 'Do not index path nor content', 'value' => 2],
					],
					'default' => 0,
				],
				[
					'id' => ConfigLexicon::FILES_GROUP_FOLDERS,
					'title' => 'Group Folders',
					'description' => 'Index the content of group folders.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
				[
					'id' => ConfigLexicon::FILES_SIZE,
					'title' => 'Maximum file size',
					'description' => 'Maximum file size to index (in Mb).',
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 20,
				],
				[
					'id' => ConfigLexicon::FILES_PDF,
					'title' => 'Extract PDF',
					'description' => 'Index the content of PDF files.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => ConfigLexicon::FILES_OFFICE,
					'title' => 'Extract Office',
					'description' => 'Index the content of office files.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => ConfigLexicon::FILES_OPEN_RESULT_DIRECTLY,
					'title' => 'Open Files',
					'description' => 'Directly from search results.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
			],
		];
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		$this->assertKnownField($fieldId);

		return $this->configService->getConfig()[$fieldId];
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		$this->assertKnownField($fieldId);

		if (in_array($fieldId, self::BOOLEAN_FIELDS, true)) {
			$value = $this->normalizeBoolean($value);
		} else {
			$value = $this->normalizeInteger($value);
		}

		$this->configService->setConfig([$fieldId => $value]);
	}

	private function assertKnownField(string $fieldId): void {
		if (!in_array($fieldId, self::FIELDS, true)) {
			throw new InvalidArgumentException('Unknown settings field: ' . $fieldId);
		}
	}

	private function normalizeBoolean(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return $value !== 0;
		}

		if (is_string($value)) {
			return in_array(strtolower($value), ['1', 'yes', 'on', 'true'], true);
		}

		throw new InvalidArgumentException('Invalid boolean settings value');
	}

	private function normalizeInteger(mixed $value): int {
		if (is_int($value)) {
			return $value;
		}

		if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
			return (int)$value;
		}

		throw new InvalidArgumentException('Invalid integer settings value');
	}
}
