<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Service;

use Exception;
use OCA\Files_FullTextSearch\ConfigLexicon;
use OCA\Files_FullTextSearch\Exceptions\EmptyUserException;
use OCA\Files_FullTextSearch\Exceptions\FileIsNotIndexableException;
use OCA\Files_FullTextSearch\Exceptions\FilesNotFoundException;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\FileInfo;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\Node;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IConfig;
use OCP\ITagManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class SearchService
 *
 * @package OCA\Files_FullTextSearch\Service
 */
class SearchService {
	private string $userId;
	/** @var array<int, true>|null */
	private ?array $favoriteIds = null;

	public function __construct(
		private IUserSession $userSession,
		private IMimeTypeDetector $mimeTypeDetector,
		private IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private FilesService $filesService,
		private IConfig $config,
		private ITagManager $tagManager,
		private ExtensionService $extensionService,
		private LoggerInterface $logger,
	) {
		$this->userId = '';
	}

	/**
	 * @param ISearchRequest $request
	 */
	public function improveSearchRequest(ISearchRequest $request) {
		$request->addWildcardField('title');

		$this->searchQueryInOptions($request);
		$this->searchQueryFiltersExtension($request);
		$this->searchQueryFiltersSource($request);
		$this->userId = $this->userSession->getUser()?->getUID() ?? $request->getAuthor();
		$request->addPart('comments');
		$this->extensionService->searchRequest($request);
	}

	/**
	 * @param ISearchRequest $request
	 */
	private function searchQueryFiltersExtension(ISearchRequest $request) {
		$extension = $request->getOption('files_extension');
		if ($extension === '') {
			return;
		}

		$request->addRegexFilters(
			[
				['title' => '.*\.' . preg_quote(ltrim($extension, '.'), '/') . '$']
			]
		);
	}

	/**
	 * @param ISearchRequest $request
	 */
	private function searchQueryFiltersSource(ISearchRequest $request) {
		$local = $request->getOption('files_local');
		$external = $request->getOption('files_external');
		$groupFolders = $request->getOption('files_group_folders');
		if (count(array_unique([$local, $external, $groupFolders])) === 1) {
			return;
		}

		$this->addMetaTagToSearchRequest($request, 'files_local', (int)$local);
		$this->addMetaTagToSearchRequest($request, 'files_external', (int)$external);
		$this->addMetaTagToSearchRequest($request, 'files_group_folders', (int)$groupFolders);
	}

	/**
	 * @param ISearchRequest $request
	 */
	private function searchQueryInOptions(ISearchRequest $request) {
		$in = $request->getOptionArray('in', []);

		if (in_array('filename', $in)) {
			$request->addLimitField('title');
		}

		if (in_array('content', $in)) {
			$request->addLimitField('content');
		}
	}

	/**
	 * @param ISearchRequest $request
	 * @param string $tag
	 * @param int $cond
	 */
	private function addMetaTagToSearchRequest(ISearchRequest $request, string $tag, int $cond) {
		if ($cond === 1) {
			$request->addMetaTag($tag);
		}
	}

	/**
	 * @param ISearchResult $searchResult
	 */
	public function improveSearchResult(ISearchResult $searchResult) {
		$this->userId = $this->userSession->getUser()?->getUID()
			?? $searchResult->getRequest()->getAuthor();
		$this->favoriteIds = null;

		$indexDocuments = $searchResult->getDocuments();
		$filesDocuments = [];
		foreach ($indexDocuments as $indexDocument) {
			try {
				$filesDocument = FilesDocument::fromIndexDocument($indexDocument);
				$this->setDocumentInfo($filesDocument);
				$this->setDocumentTitle($filesDocument);
				$this->setDocumentLink($filesDocument);

				if ($filesDocument->getType() === FileInfo::TYPE_FOLDER) {
					$icon = 'icon-folder';
				} else {
					$icon = $this->mimeTypeDetector->mimeTypeIcon($filesDocument->getInfo('mime'));
				}

				$filesDocument->setInfoArray(
					'unified',
					[
						'thumbUrl' => '',
						'icon' => $icon
					]
				);

				$filesDocuments[] = $filesDocument;
			} catch (FilesNotFoundException|EmptyUserException|FileIsNotIndexableException $e) {
				// The viewer no longer has access to this stale search result.
				continue;
			} catch (Throwable $e) {
				$this->logger->warning('Exception while improving searchresult', ['exception' => $e]);
			}
		}

		$searchResult->setDocuments($filesDocuments);
		$this->extensionService->searchResult($searchResult);
	}

	/**
	 * @param FilesDocument $document
	 *
	 * @throws Exception
	 */
	private function setDocumentInfo(FilesDocument $document) {
		$document->setInfo('webdav', $this->getWebdavId((int)$document->getId()));

		$file = $this->filesService->getFileFromId($this->userId, (int)$document->getId());

		$this->setDocumentInfoFromFile($document, $file);
	}

	/**
	 * @param FilesDocument $document
	 * @param Node $file
	 */
	private function setDocumentInfoFromFile(FilesDocument $document, Node $file) {
		if ($this->userId === '') {
			return;
		}

		$path = $this->filesService->getRelativePath($this->userId, $file);
		$pathInfo = pathinfo($path);

		$document->setPath($path);
		$document->setInfo('path', $path)
			->setInfo('type', $file->getType())
			->setInfo('file', $pathInfo['basename'])
			->setInfo('dir', $pathInfo['dirname'] ?? '')
			->setInfo('mime', $file->getMimetype())
			->setInfoBool('favorite', $this->isFavorite((int)$file->getId()));

		try {
			$fileSize = $file->getSize();
			if (is_float($fileSize)) {
				$fileSize = $fileSize >= PHP_INT_MAX ? PHP_INT_MAX : (int)$fileSize;
			}

			$document->setInfoInt('size', $fileSize)
				->setInfoInt('mtime', $file->getMTime())
				->setInfo('etag', $file->getEtag())
				->setInfoInt('permissions', $file->getPermissions());
		} catch (Exception $e) {
		}
	}

	/**
	 * @param FilesDocument $document
	 */
	private function setDocumentTitle(FilesDocument $document) {
		if ($document->getPath() !== '') {
			$document->setTitle(ltrim(str_replace('//', '/', $document->getPath()), '/'));
		}
	}

	/**
	 * @param FilesDocument $document
	 */
	private function setDocumentLink(FilesDocument $document) {
		$params = ['fileid' => $document->getId()];
		if ($document->getInfo('type') === FileInfo::TYPE_FILE
			&& !$this->appConfig->getAppValueBool(ConfigLexicon::FILES_OPEN_RESULT_DIRECTLY)) {
			$params['openfile'] = 'false';
		}

		$link = $this->urlGenerator->linkToRoute('files.View.showFile', $params);
		$document->setLink($this->urlGenerator->getAbsoluteURL($link));
	}

	private function isFavorite(int $fileId): bool {
		if ($this->favoriteIds === null) {
			$this->favoriteIds = [];
			try {
				$tags = $this->tagManager->load('files', [], false, $this->userId);
				foreach ($tags?->getFavorites() ?? [] as $favoriteId) {
					$this->favoriteIds[(int)$favoriteId] = true;
				}
			} catch (Throwable $e) {
				$this->logger->warning('Could not load favorite files for search results', ['exception' => $e]);
			}
		}

		return isset($this->favoriteIds[$fileId]);
	}

	/**
	 * @param int $fileId
	 *
	 * @return string
	 */
	private function getWebdavId(int $fileId): string {
		return sprintf('%08s', $fileId) . $this->config->getSystemValueString('instanceid');
	}
}
