<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Composer\Autoload\ClassLoader;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$loader->setClassMapAuthoritative(false);
$loader->addPsr4('OCP\\', dirname(__DIR__) . '/vendor/nextcloud/ocp/OCP');
$serverRoot = getenv('NEXTCLOUD_SERVER_ROOT') ?: dirname(__DIR__, 3);
$loader->addPsr4('OC\\', $serverRoot . '/lib/private');
