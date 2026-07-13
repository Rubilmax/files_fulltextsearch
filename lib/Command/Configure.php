<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch\Command;

use Exception;
use JsonException;
use OCA\Files_FullTextSearch\Service\ConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Configure
 *
 * @package OCA\Files_FullTextSearch\Command
 */
class Configure extends Command {
	public function __construct(
		private ConfigService $configService,
	) {
		parent::__construct();
	}

	/**
	 *
	 */
	protected function configure(): void {
		$this->setName('files_fulltextsearch:configure')
			->addArgument('json', InputArgument::REQUIRED, 'set config')
			->setDescription('Configure the installation');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$json = $input->getArgument('json');
		if (!is_string($json)) {
			throw new JsonException('Configuration must be a JSON object');
		}

		$config = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
		if (!$config instanceof \stdClass) {
			throw new JsonException('Configuration must be a JSON object');
		}

		$this->configService->setConfig(get_object_vars($config));
		$output->writeln(json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

		return self::SUCCESS;
	}
}
