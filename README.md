<!--
  - SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Full text search - Files

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/files_fulltextsearch)](https://api.reuse.software/info/github.com/nextcloud/files_fulltextsearch)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/nextcloud/files_fulltextsearch/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/nextcloud/files_fulltextsearch/?branch=master)

_Full text search - Files_ is an extension app to the _Full text search_ framework

It allows you to index the content of your users' files.

### Requirements

- Nextcloud 34 or later
- PHP 8.2 or later
- A `fulltextsearch` framework app version compatible with the installed Nextcloud major

### Install from Git

The production Composer autoloader is committed in `vendor/`, so the repository can be checked out directly in Nextcloud's `apps/` directory without running Composer:

```shell
git submodule add https://github.com/Rubilmax/files_fulltextsearch.git apps/files_fulltextsearch
php occ app:enable files_fulltextsearch
```

### Documentation

[Can be found on the Wiki](https://github.com/nextcloud/files_fulltextsearch/wiki)
