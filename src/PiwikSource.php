<?php
/**
 * This file is part of the browscap-helper-source-piwik package.
 *
 * Copyright (c) 2016-2017, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);
namespace BrowscapHelper\Source;

use BrowserDetector\Loader\NotFoundException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use UaDataMapper\BrowserNameMapper;
use UaDataMapper\BrowserTypeMapper;
use UaDataMapper\BrowserVersionMapper;
use UaDataMapper\DeviceMarketingnameMapper;
use UaDataMapper\DeviceNameMapper;
use UaDataMapper\DeviceTypeMapper;
use UaDataMapper\EngineNameMapper;
use UaDataMapper\PlatformNameMapper;
use UaDataMapper\PlatformVersionMapper;
use UaResult\Browser\Browser;
use UaResult\Company\CompanyLoader;
use UaResult\Device\Device;
use UaResult\Engine\Engine;
use UaResult\Os\Os;
use UaResult\Result\Result;
use Wurfl\Request\GenericRequestFactory;

/**
 * Class DirectorySource
 *
 * @author  Thomas Mueller <mimmi20@live.de>
 */
class PiwikSource implements SourceInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger = null;

    /**
     * @var \Psr\Cache\CacheItemPoolInterface|null
     */
    private $cache = null;

    /**
     * @param \Psr\Log\LoggerInterface          $logger
     * @param \Psr\Cache\CacheItemPoolInterface $cache
     */
    public function __construct(LoggerInterface $logger, CacheItemPoolInterface $cache)
    {
        $this->logger = $logger;
        $this->cache  = $cache;
    }

    /**
     * @param int $limit
     *
     * @return string[]
     */
    public function getUserAgents($limit = 0)
    {
        $counter = 0;

        foreach ($this->loadFromPath() as $row) {
            if ($limit && $counter >= $limit) {
                return;
            }

            $row = json_decode($row, false);
            yield trim($row->user_agent);
            ++$counter;
        }
    }

    /**
     * @return \UaResult\Result\Result[]
     */
    public function getTests()
    {
        foreach ($this->loadFromPath() as $row) {
            $row     = json_decode($row, false);
            $request = (new GenericRequestFactory())->createRequestForUserAgent($row->user_agent);

            $browserManufacturer = null;
            $browserVersion      = null;

            if (!empty($row->bot)) {
                $browserName = (new BrowserNameMapper())->mapBrowserName($row->bot->name);

                if (!empty($row->bot->producer->name)) {
                    try {
                        $browserManufacturer = (new CompanyLoader($this->cache))->loadByName($row->bot->producer->name);
                    } catch (NotFoundException $e) {
                        $this->logger->critical($e);
                        $browserManufacturer = null;
                    }
                }

                try {
                    $browserType = (new BrowserTypeMapper())->mapBrowserType($this->cache, 'robot');
                } catch (NotFoundException $e) {
                    $this->logger->critical($e);
                    $browserType = null;
                }
            } else {
                $browserName    = (new BrowserNameMapper())->mapBrowserName($row->client->name);
                $browserVersion = (new BrowserVersionMapper())->mapBrowserVersion(
                    $row->client->version,
                    $browserName
                );

                if (!empty($row['client']['type'])) {
                    try {
                        $browserType = (new BrowserTypeMapper())->mapBrowserType($this->cache, $row['client']['type']);
                    } catch (NotFoundException $e) {
                        $this->logger->critical($e);
                        $browserType = null;
                    }
                } else {
                    $browserType = null;
                }
            }

            $browser = new Browser(
                $browserName,
                $browserManufacturer,
                $browserVersion,
                $browserType
            );

            $deviceName  = (new DeviceNameMapper())->mapDeviceName($row->device->model);
            $deviceBrand = null;

            try {
                $deviceBrand = (new CompanyLoader($this->cache))->loadByBrandName($row->device->brand);
            } catch (NotFoundException $e) {
                $this->logger->critical($e);
                $deviceBrand = null;
            }

            try {
                $deviceType = (new DeviceTypeMapper())->mapDeviceType($this->cache, $row->device->type);
            } catch (NotFoundException $e) {
                $this->logger->critical($e);
                $deviceType = null;
            }

            $device = new Device(
                $deviceName,
                (new DeviceMarketingnameMapper())->mapDeviceMarketingName($deviceName),
                null,
                $deviceBrand,
                $deviceType
            );

            $os = new Os(null, null);

            if (!empty($row->os->name)) {
                $osName = (new PlatformNameMapper())->mapOsName($row->os->name);

                if (!in_array($osName, ['PlayStation'])) {
                    $osVersion = (new PlatformVersionMapper())->mapOsVersion($row->os->version, $row->os->name);
                    $os        = new Os($osName, null, null, $osVersion);
                }
            }

            if (!empty($row->client->engine)) {
                $engineName = (new EngineNameMapper())->mapEngineName($row->client->engine);

                $engine = new Engine($engineName);
            } else {
                $engine = new Engine(null);
            }

            yield trim($row->user_agent) => new Result($request, $device, $os, $browser, $engine);
        }
    }

    /**
     * @return string[]
     */
    private function loadFromPath()
    {
        $path = 'vendor/piwik/device-detector/Tests/fixtures';

        if (!file_exists($path)) {
            return;
        }

        $this->logger->info('    reading path ' . $path);

        $allTests = [];
        $finder   = new Finder();
        $finder->files();
        $finder->name('*.yml');
        $finder->ignoreDotFiles(true);
        $finder->ignoreVCS(true);
        $finder->sortByName();
        $finder->ignoreUnreadableDirs();
        $finder->in($path);

        foreach ($finder as $file) {
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            if ('yml' !== $file->getExtension()) {
                continue;
            }

            $filepath = $file->getPathname();

            $this->logger->info('    reading file ' . str_pad($filepath, 100, ' ', STR_PAD_RIGHT));
            $dataFile = \Spyc::YAMLLoad($filepath);

            if (!is_array($dataFile)) {
                continue;
            }

            foreach ($dataFile as $row) {
                if (empty($row['user_agent'])) {
                    continue;
                }

                $agent = trim($row['user_agent']);

                if (array_key_exists($agent, $allTests)) {
                    continue;
                }

                yield json_encode($row, JSON_FORCE_OBJECT);
                $allTests[$agent] = 1;
            }
        }
    }
}
