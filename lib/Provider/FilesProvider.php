<?php
/**
 * Files_FullTextSearch - Index the content of your files
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Files_FullTextSearch\Provider;

use OCA\Files_FullTextSearch\AppInfo\Application;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCA\Files_FullTextSearch\Service\ConfigService;
use OCA\Files_FullTextSearch\Service\ElasticSearchService;
use OCA\Files_FullTextSearch\Service\FilesService;
use OCA\Files_FullTextSearch\Service\MiscService;
use OCA\Files_FullTextSearch\Service\SearchService;
use OCA\FullTextSearch\Exceptions\InterruptException;
use OCA\FullTextSearch\Exceptions\TickDoesNotExistException;
use OCA\FullTextSearch\IFullTextSearchPlatform;
use OCA\FullTextSearch\IFullTextSearchProvider;
use OCA\FullTextSearch\Model\Index;
use OCA\FullTextSearch\Model\IndexDocument;
use OCA\FullTextSearch\Model\IndexOptions;
use OCA\FullTextSearch\Model\Runner;
use OCA\FullTextSearch\Model\SearchRequest;
use OCA\FullTextSearch\Model\SearchResult;
use OCP\AppFramework\QueryException;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

class FilesProvider implements IFullTextSearchProvider {


	const FILES_PROVIDER_ID = 'files';

	/** @var ConfigService */
	private $configService;

	/** @var FilesService */
	private $filesService;

	/** @var SearchService */
	private $searchService;

	/** @var ElasticSearchService */
	private $elasticSearchService;

	/** @var MiscService */
	private $miscService;


	/** @var Runner */
	private $runner;

	/** @var IndexOptions */
	private $indexOptions = [];


	/**
	 * return unique id of the provider
	 */
	public function getId() {
		return self::FILES_PROVIDER_ID;
	}


	/**
	 * return name of the provider
	 */
	public function getName() {
		return 'Files';
	}


	/**
	 * @return string
	 */
	public function getVersion() {
		return $this->configService->getAppValue('installed_version');
	}


	/**
	 * @return array
	 */
	public function getConfiguration() {
		return $this->configService->getConfig();
	}


	/**
	 * @return string
	 */
	public function getAppId() {
		return Application::APP_NAME;
	}


	public function setRunner(Runner $runner) {
		$this->runner = $runner;
	}


	/**
	 * @param IndexOptions $options
	 */
	public function setIndexOptions($options) {
		$this->indexOptions = $options;
	}


	/**
	 * @return array
	 */
	public function getOptionsTemplate() {
//				'template' => 'options.panel',
		return [
			'panel'      => [
				'options' => [
					[
						'name'  => 'files_within_dir',
						'title' => 'Within current directory',
						'type'  => 'checkbox'
					],
					[
						'name'  => 'files_local',
						'title' => 'Within local files',
						'type'  => 'checkbox'
					],
					[
						'name'  => 'files_external',
						'title' => 'Within external files',
						'type'  => 'checkbox'
					],
					[
						'name'  => 'files_group_folders',
						'title' => 'Within group folders',
						'type'  => 'checkbox'
					],
					[
						'name'        => 'files_extension',
						'title'       => 'Filter by extension',
						'type'        => 'input',
						'size'        => 'small',
						'placeholder' => 'txt'
					]
				]
			],
			'navigation' => [
				'icon'    => 'icon-fts-files',
				'options' => [
					[
						'name'  => 'files_local',
						'title' => 'Local Files',
						'type'  => 'checkbox'
					],
					[
						'name'  => 'files_external',
						'title' => 'External Files',
						'type'  => 'checkbox'
					],
					[
						'name'  => 'files_group_folders',
						'title' => 'Group Folders',
						'type'  => 'checkbox'
					],
					[
						'name'        => 'files_extension',
						'title'       => 'Extension',
						'type'        => 'input',
						'size'        => 'small',
						'placeholder' => 'txt'
					]
				]
			]
		];
	}


	/**
	 * @deprecated
	 */
	public function getOptions() {
		return $this->getOptionsTemplate();
	}


	/**
	 * called when loading all providers.
	 *
	 * Loading some containers.
	 *
	 * @throws QueryException
	 */
	public function loadProvider() {
		$app = new Application();

		$container = $app->getContainer();
		$this->configService = $container->query(ConfigService::class);
		$this->filesService = $container->query(FilesService::class);
		$this->searchService = $container->query(SearchService::class);
		$this->elasticSearchService = $container->query(ElasticSearchService::class);
		$this->miscService = $container->query(MiscService::class);
	}


	/**
	 * returns all indexable document for a user.
	 * There is no need to fill the document with content at this point.
	 *
	 * $platform is provided if the mapping needs to be changed.
	 *
	 * @param string $userId
	 *
	 * @return IndexDocument[]
	 * @throws InterruptException
	 * @throws TickDoesNotExistException
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function generateIndexableDocuments($userId) {
		$files = $this->filesService->getFilesFromUser($this->runner, $userId, $this->indexOptions);

		return $files;
	}


	/**
	 * generate documents prior to the indexing.
	 * throw NoResultException if no more result
	 *
	 * @param IndexDocument[] $chunk
	 *
	 * @return IndexDocument[]
	 * @throws InterruptException
	 * @throws TickDoesNotExistException
	 */
	public function fillIndexDocuments($chunk) {

		/** @var FilesDocument[] $chunk */

		$index = [];
		foreach ($chunk as $document) {
			$this->updateRunnerAction('fillDocument', true);
			$this->updateRunnerInfoArray(
				[
					'documentId' => $document->getId(),
					'title'      => '',
					'content'    => ''
				]
			);

			if (!($document instanceof FilesDocument)) {
				continue;
			}

			$result = $this->filesService->generateDocument($document);
			$this->updateRunnerInfoArray(
				[
					'info' => $document->getMimetype(),
					'title'   => $document->getTitle(),
					'content' => $document->getContentSize()
				]
			);

			$index[] = $result;
		}

		return $index;
	}


	/**
	 * @param IndexDocument $document
	 *
	 * @return bool
	 */
	public function isDocumentUpToDate($document) {
		return $this->filesService->isDocumentUpToDate($document);
	}


	/**
	 * @param Index $index
	 *
	 * @return IndexDocument|null
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function updateDocument(Index $index) {
		return $this->filesService->updateDocument($index);
	}


	/**
	 * @param IFullTextSearchPlatform $platform
	 */
	public function onInitializingIndex(IFullTextSearchPlatform $platform) {
		$this->elasticSearchService->onInitializingIndex($platform);
	}


	/**
	 * @param IFullTextSearchPlatform $platform
	 */
	public function onResettingIndex(IFullTextSearchPlatform $platform) {
		$this->elasticSearchService->onResettingIndex($platform);
	}


	/**
	 * not used yet
	 */
	public function unloadProvider() {
	}


	/**
	 * before a search, improve the request
	 *
	 * @param SearchRequest $request
	 */
	public function improveSearchRequest(SearchRequest $request) {
		$this->searchService->improveSearchRequest($request);
	}


	/**
	 * after a search, improve results
	 *
	 * @param SearchResult $searchResult
	 */
	public function improveSearchResult(SearchResult $searchResult) {
		$this->searchService->improveSearchResult($searchResult);
	}


	/**
	 * @param $action
	 * @param bool $force
	 *
	 * @throws InterruptException
	 * @throws TickDoesNotExistException
	 */
	private function updateRunnerAction($action, $force = false) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->updateAction($action, $force);
	}

	/**
	 * @param array $data
	 */
	private function updateRunnerInfoArray($data) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->setInfoArray($data);
	}


}