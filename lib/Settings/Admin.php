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
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * Nextcloud-native, automatically saved administration settings.
 */
class Admin implements IDeclarativeSettingsFormWithHandlers {
	public function __construct(
		private readonly IL10N $l10n,
		private readonly ConfigService $configService,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'files',
			'priority' => 51,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'fulltextsearch',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l10n->t('Files'),
			'doc_url' => 'https://github.com/nextcloud/files_fulltextsearch/wiki',
			'fields' => [
				[
					'id' => ConfigLexicon::FILES_LOCAL,
					'title' => $this->l10n->t('Local Files'),
					'description' => $this->l10n->t('Index the content of local files.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => ConfigLexicon::FILES_EXTERNAL,
					'title' => $this->l10n->t('External Files'),
					'type' => DeclarativeSettingsTypes::RADIO,
					'default' => 0,
					'options' => [
						[
							'name' => $this->l10n->t('Index path only'),
							'value' => 0,
						],
						[
							'name' => $this->l10n->t('Index path and content'),
							'value' => 1,
						],
						[
							'name' => $this->l10n->t('Do not index path nor content'),
							'value' => 2,
						],
					],
				],
				[
					'id' => ConfigLexicon::FILES_GROUP_FOLDERS,
					'title' => $this->l10n->t('Team Folders'),
					'description' => $this->l10n->t('Index the content of Team Folders.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
				[
					'id' => ConfigLexicon::FILES_SIZE,
					'title' => $this->l10n->t('Maximum file size'),
					'description' => $this->l10n->t('Maximum file size to index (in MB).'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 20,
				],
				[
					'id' => ConfigLexicon::FILES_PDF,
					'title' => $this->l10n->t('Extract PDF'),
					'description' => $this->l10n->t('Index the content of PDF files.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => ConfigLexicon::FILES_OFFICE,
					'title' => $this->l10n->t('Extract Office'),
					'description' => $this->l10n->t('Index the content of office files.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => ConfigLexicon::FILES_OPEN_RESULT_DIRECTLY,
					'title' => $this->l10n->t('Open Files'),
					'description' => $this->l10n->t('Directly from search results.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
			],
		];
	}

	public function getValue(string $fieldId, IUser $user): bool|int {
		return $this->configService->getValue($fieldId);
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		if (!is_bool($value) && !is_int($value) && !is_string($value)) {
			throw new InvalidArgumentException('Unsupported settings value');
		}

		$this->configService->setValue($fieldId, $value);
	}
}
