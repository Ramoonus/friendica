<?php

// Copyright (C) 2010-2026, the Friendica project
// SPDX-FileCopyrightText: 2010-2026 the Friendica project
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Friendica\Module\Admin;

use Friendica\App;
use Friendica\Core\Addon\Exception\InvalidAddonException;
use Friendica\Core\Cache\Exception\CachePersistenceException;
use Friendica\Core\Cache\Exception\InvalidCacheDriverException;
use Friendica\Core\Cache\Type\APCuCache;
use Friendica\Core\Cache\Type\RedisCache;
use Friendica\Core\Config\ValueObject\Cache;
use Friendica\Core\Renderer;
use Friendica\Core\Update;
use Friendica\Database\DBA;
use Friendica\Database\DBStructure;
use Friendica\DI;
use Friendica\Core\Config\Factory\Config;
use Friendica\Module\BaseAdmin;
use Friendica\Network\HTTPClient\Client\HttpClientAccept;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Strings;

class Summary extends BaseAdmin
{
	protected function content(array $request = []): string
	{
		parent::content();

		$basePath  = DI::appHelper()->getBasePath();
		$addonPath = DI::addonHelper()->getAddonPath();

		// are there MyISAM tables in the DB? If so, trigger a warning message
		$warningtext = [];

		$templateEngine = Renderer::getTemplateEngine();
		$errors         = [];
		$templateEngine->testInstall($errors);
		foreach ($errors as $error) {
			$warningtext[] = DI::l10n()->t('Template engine (%s) error: %s', $templateEngine::$name, $error);
		}

		if (DBA::count('information_schema.tables', ['engine' => 'myisam', 'table_schema' => DBA::databaseName()])) {
			$warningtext[] = DI::l10n()->t('Your DB still runs with MyISAM tables. You should change the engine type to InnoDB. As Friendica will use InnoDB only features in the future, you should change this! See <a href="%s">here</a> for a guide that may be helpful converting the table engines. You may also use the command <tt>php bin/console.php dbstructure toinnodb</tt> of your Friendica installation for an automatic conversion.<br />', 'https://dev.mysql.com/doc/refman/5.7/en/converting-tables-to-innodb.html');
		}

		// are there InnoDB tables in Antelope in the DB? If so, trigger a warning message
		if (DBA::count('information_schema.tables', ['ENGINE' => 'InnoDB', 'ROW_FORMAT' => ['COMPACT', 'REDUNDANT'], 'table_schema' => DBA::databaseName()])) {
			$warningtext[] = DI::l10n()->t('Your DB still runs with InnoDB tables in the Antelope file format. You should change the file format to Barracuda. Friendica is using features that are not provided by the Antelope format. See <a href="%s">here</a> for a guide that may be helpful converting the table engines. You may also use the command <tt>php bin/console.php dbstructure toinnodb</tt> of your Friendica installation for an automatic conversion.<br />', 'https://dev.mysql.com/doc/refman/5.7/en/innodb-file-format.html');
		}

		// Avoid the database error 1615 "Prepared statement needs to be re-prepared", see https://github.com/friendica/friendica/issues/8550
		if (!DI::config()->get('database', 'pdo_emulate_prepares')) {
			$table_definition_cache = DBA::getVariable('table_definition_cache');
			$table_open_cache       = DBA::getVariable('table_open_cache');
			if (!empty($table_definition_cache) && !empty($table_open_cache)) {
				$suggested_definition_cache = min(400 + round((int) $table_open_cache / 2, 1), 2000);
				if ($suggested_definition_cache > $table_definition_cache) {
					$warningtext[] = DI::l10n()->t('Your table_definition_cache is too low (%d). This can lead to the database error "Prepared statement needs to be re-prepared". Please set it at least to %d. See <a href="%s">here</a> for more information.<br />', $table_definition_cache, $suggested_definition_cache, 'https://dev.mysql.com/doc/refman/5.7/en/server-system-variables.html#sysvar_table_definition_cache');
				}
			}
		}

		// Check if github.com/friendica/stable/VERSION is higher then
		// the local version of Friendica. Check is opt-in, source may be stable or develop branch
		if (Update::isAvailable()) {
			$warningtext[] = DI::l10n()->t('There is a new version of Friendica available for download. Your current version is %1$s, upstream version is %2$s', App::VERSION, Update::getAvailableVersion());
		}

		if (DBStructure::getUpdateStatus() == DBStructure::UPDATE_NOT_CHECKED) {
			DBStructure::performUpdate();
		}

		if (DBStructure::getUpdateStatus() == DBStructure::UPDATE_FAILED) {
			$warningtext[] = DI::l10n()->t('The database update failed. Please run "php bin/console.php dbstructure update" from the command line and have a look at the errors that might appear.');
		}

		if (Update::getStatus() == Update::FAILED) {
			$warningtext[] = DI::l10n()->t('The last update failed. Please run "php bin/console.php dbstructure update" from the command line and have a look at the errors that might appear. (Some of the errors are possibly inside the logfile.)');
		}

		if (empty(DI::config()->get('system', 'url'))) {
			$warningtext[] = DI::l10n()->t('The system.url entry is missing. This is a low level setting and can lead to unexpected behavior. Please add a valid entry as soon as possible in the config file or per console command!');
		}

		$last_worker_call = DI::keyValue()->get('last_worker_execution');
		if (!$last_worker_call) {
			$warningtext[] = DI::l10n()->t('The worker was never executed. Please check your database structure!');
		} elseif ((strtotime(DateTimeFormat::utcNow()) - strtotime((string) $last_worker_call)) > 60 * 60) {
			$warningtext[] = DI::l10n()->t('The last worker execution was on %s UTC. This is older than one hour. Please check your crontab settings.', $last_worker_call);
		}

		// Legacy config file warning
		if (file_exists('.htconfig.php')) {
			$warningtext[] = DI::l10n()->t('Friendica\'s configuration now is stored in config/local.config.php, please copy config/local-sample.config.php and move your config from <code>.htconfig.php</code>. See <a href="%s">the Config help page</a> for help with the transition.', DI::baseUrl() . '/help/admin/config');
		}

		if (file_exists('config/local.ini.php')) {
			$warningtext[] = DI::l10n()->t('Friendica\'s configuration now is stored in config/local.config.php, please copy config/local-sample.config.php and move your config from <code>config/local.ini.php</code>. See <a href="%s">the Config help page</a> for help with the transition.', DI::baseUrl() . '/help/admin/config');
		}

		// Check server vitality
		if (!self::checkSelfNodeinfo()) {
			$well_known    = DI::baseUrl() . '/.well-known/nodeinfo';
			$warningtext[] = DI::l10n()->t(
				'<a href="%s">%s</a> is not reachable on your system. This is a severe configuration issue that prevents server to server communication. See <a href="%s">the installation page</a> for help.',
				$well_known,
				$well_known,
				DI::baseUrl() . '/help/admin/install',
			);
		}

		// Check logfile permission
		if (($return = DI::logCheck()->checkLogfile()) !== null) {
			$warningtext[] = $return;
		}
		if (($return = DI::logCheck()->checkDebugLogfile()) !== null) {
			$warningtext[] = $return;
		}

		// check legacy basepath settings
		$configLoader = (new Config())->createConfigFileManager($basePath, $addonPath, $_SERVER);
		$configCache  = new Cache();
		$configLoader->setupCache($configCache);
		$confBasepath = $configCache->get('system', 'basepath');
		$currBasepath = DI::config()->get('system', 'basepath');
		if ($confBasepath !== $currBasepath || !is_dir($currBasepath)) {
			if (is_dir($confBasepath) && DI::config()->set('system', 'basepath', $confBasepath)) {
				DI::logger()->info('Friendica\'s system.basepath was updated successfully.', [
					'from' => $currBasepath,
					'to'   => $confBasepath,
				]);
				$warningtext[] = DI::l10n()->t(
					'Friendica\'s system.basepath was updated from \'%s\' to \'%s\'. Please remove the system.basepath from your db to avoid differences.',
					$currBasepath,
					$confBasepath,
				);
			} elseif (!is_dir($currBasepath)) {
				DI::logger()->alert('Friendica\'s system.basepath is wrong.', [
					'from' => $currBasepath,
					'to'   => $confBasepath,
				]);
				$warningtext[] = DI::l10n()->t(
					'Friendica\'s current system.basepath \'%s\' is wrong and the config file \'%s\' isn\'t used.',
					$currBasepath,
					$confBasepath,
				);
			} else {
				DI::logger()->alert('Friendica\'s system.basepath is wrong.', [
					'from' => $currBasepath,
					'to'   => $confBasepath,
				]);
				$warningtext[] = DI::l10n()->t(
					'Friendica\'s current system.basepath \'%s\' is not equal to the config file \'%s\'. Please fix your configuration.',
					$currBasepath,
					$confBasepath,
				);
			}
		}

		$deferred = DBA::count('workerqueue', ['NOT `done` AND `retrial` > ?', 0]);

		$workerqueue = DBA::count('workerqueue', ['NOT `done` AND `retrial` = ?', 0]);

		// We can do better, but this is a quick queue status
		$queues = ['label' => DI::l10n()->t('Message queues'), 'deferred' => $deferred, 'workerq' => $workerqueue];

		// Table statistics approximate the space allocated to data and indexes.
		$db_size = DBA::fetchFirst(
			'SELECT COALESCE(SUM(`data_length` + `index_length`), 0) AS `size` FROM `information_schema`.`tables` WHERE `table_schema` = ?',
			DBA::databaseName(),
		);

		// Include table defaults and column-specific collations, without duplicates.
		$collations = DBA::p(
			'SELECT `table_collation` AS `collation` FROM `information_schema`.`tables` WHERE `table_schema` = ? AND `table_collation` IS NOT NULL
			 UNION SELECT `collation_name` AS `collation` FROM `information_schema`.`columns` WHERE `table_schema` = ? AND `collation_name` IS NOT NULL
			 ORDER BY `collation`',
			DBA::databaseName(),
			DBA::databaseName(),
		);
		$db_collations = $collations ? implode(', ', array_column(DBA::toArray($collations), 'collation')) : DI::l10n()->t('Unavailable');

		$db_version = (string) DBA::getVariable('version');
		$curl       = curl_version();

		// A cache backend can be enabled for local caching, distributed caching, or both.
		$cache_drivers = [
			DI::config()->get('system', 'cache_driver'),
			DI::config()->get('system', 'distributed_cache_driver'),
		];

		$max_execution_time = (int) ini_get('max_execution_time');

		$server_settings = [
			'label' => DI::l10n()->t('Server Settings'),
			'php'   => [
				'version'             => phpversion(),
				'php.ini'             => php_ini_loaded_file(),
				'upload_max_filesize' => ini_get('upload_max_filesize'),
				'post_max_size'       => ini_get('post_max_size'),
				'memory_limit'        => ini_get('memory_limit'),
				'max_execution_time'  => $max_execution_time === 0 ? DI::l10n()->t('Unlimited') : DI::l10n()->t('%d seconds', $max_execution_time),
			],
			'mysql' => [
				'type'               => str_contains($db_version, 'MariaDB') ? 'MariaDB' : 'MySQL',
				'version'            => preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $db_version),
				'max_allowed_packet' => Strings::formatBytes((int) DBA::getVariable('max_allowed_packet')),
				DI::l10n()->t('Size (data and indexes)') => isset($db_size['size']) ? Strings::formatBytes((int) $db_size['size']) : DI::l10n()->t('Unavailable'),
				DI::l10n()->t('Collations') => $db_collations,
			],
			'cache_label' => DI::l10n()->t('Cache'),
			'cache' => array_merge(
				$this->getRedisSettings(in_array(RedisCache::NAME, $cache_drivers, true)),
				$this->getAPCuSettings(in_array(APCuCache::NAME, $cache_drivers, true)),
			),
			'curl' => [
				'version' => $curl['version'],
				'HTTP/2'  => ($curl['features'] & CURL_VERSION_HTTP2) ? DI::l10n()->t('Yes') : DI::l10n()->t('No'),
			],
		];

		$addons = [];

		$addonHelper = DI::addonHelper();
		foreach ($addonHelper->getEnabledAddons() as $addonId) {
			try {
				$addonInfo = $addonHelper->getAddonInfo($addonId);
			} catch (InvalidAddonException $th) {
				$this->logger->error('Invalid addon found: ' . $addonId, ['exception' => $th]);
				continue;
			}

			$info = [
				'name'        => $addonInfo->getName(),
				'description' => $addonInfo->getDescription(),
				'version'     => $addonInfo->getVersion(),
			];

			$addons[] = [
				$addonId,
				$info,
			];
		}

		$t = Renderer::getMarkupTemplate('admin/summary.tpl');
		return Renderer::replaceMacros($t, [
			'$title'              => DI::l10n()->t('Administration'),
			'$page'               => DI::l10n()->t('Summary'),
			'$queues'             => $queues,
			'$version_label'      => DI::l10n()->t('Version'),
			'$platform'           => App::PLATFORM,
			'$codename'           => App::CODENAME,
			'$build'              => DI::config()->get('system', 'build'),
			'$addons'             => [DI::l10n()->t('Active addons'), $addons],
			'$serversettings'     => $server_settings,
			'$warningtext'        => $warningtext,
			'$link_enable_addons' => DI::l10n()->t('Enable new addons'),
		]);
	}

	private function getRedisSettings(bool $enabled): array
	{
		$status  = DI::l10n()->t('Disabled');
		$version = DI::l10n()->t('Unavailable');

		if ($enabled) {
			try {
				$redis = new RedisCache(DI::baseUrl()->getHost(), DI::config());
				$stats = $redis->getStats();

				$status  = DI::l10n()->t('Enabled and reachable');
				$version = $stats['version'] ?? DI::l10n()->t('Unavailable');
			} catch (CachePersistenceException | InvalidCacheDriverException | \RedisException $exception) {
				$status = DI::l10n()->t('Enabled but unavailable');
			}
		}

		return [
			DI::l10n()->t('Redis status')         => $status,
			DI::l10n()->t('Redis version')        => $version,
			DI::l10n()->t('Redis database index') => (int) DI::config()->get('system', 'redis_db', 0),
		];
	}

	private function getAPCuSettings(bool $enabled): array
	{
		$status = DI::l10n()->t('Disabled');

		if ($enabled) {
			$status = APCuCache::isAvailable() ? DI::l10n()->t('Enabled and available') : DI::l10n()->t('Enabled but unavailable');
		}

		return [
			DI::l10n()->t('APCu status')  => $status,
			DI::l10n()->t('APCu version') => phpversion('apcu') ?: DI::l10n()->t('Unavailable'),
		];
	}

	private static function checkSelfNodeinfo()
	{
		// Fetch the webfinger to check if this really is a vital server
		return DI::httpClient()->get(DI::baseUrl() . '/.well-known/nodeinfo', HttpClientAccept::JSON)->isSuccess();
	}

}
