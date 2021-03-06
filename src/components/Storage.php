<?php

/*
 * Asset Packagist
 *
 * @link      https://github.com/hiqdev/asset-packagist
 * @package   asset-packagist
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2016, HiQDev (http://hiqdev.com/)
 */

namespace hiqdev\assetpackagist\components;

use hiqdev\assetpackagist\models\AssetPackage;
use Yii;
use yii\base\Component;
use yii\helpers\Json;
use hiqdev\assetpackagist\helpers\Locker;

class Storage extends Component
{
    protected $_path;
    protected $_locker;
    
    protected static $_instance;

    public static function getInstance()
    {
        if (static::$_instance === null) {
            static::$_instance = new static();
        }

        return static::$_instance;
    }

    public function init()
    {
        $this->_path = Yii::getAlias('@storage', false);
    }

    protected function getLocker()
    {
        if ($this->_locker === null) {
            $this->_locker = Locker::getInstance($this->buildPath('lock'));
        }

        return $this->_locker;
    }

    public function getNextID()
    {
        $this->getLocker()->lock();
        {
            $nextID = $this->readLastID() + 1;
            $this->writeLastID($nextID);
        }
        $this->getLocker()->release();

        return $nextID;
    }

    protected function readLastId()
    {
        $path = $this->getLastIDPath();

        return (file_exists($path) ? (int) file_get_contents($path) : 0) ?: 1000000;
    }

    protected function writeLastId($value)
    {
        file_put_contents($this->getLastIDPath(), $value);
    }

    protected function getLastIDPath()
    {
        return $this->buildPath('lastid');
    }

    public function writePackage(AssetPackage $package)
    {
        $name = $package->getNormalName();
        $data = [
            'packages' => [
                $name => $package->getReleases(),
            ],
        ];
        $json = Json::encode($data);
        $hash = hash('sha256', $json);
        $path = $this->buildHashedPath($name, $hash);
        if (!file_exists($path)) {
            $this->getLocker()->lock();
            {
                static::mkdir(dirname($path));
                file_put_contents($path, $json);
                file_put_contents($this->buildHashedPath($name), $json);
                $this->writeProviderLatest($name, $hash);
            }
            $this->getLocker()->release();
        }

        return $hash;
    }

    /**
     * Reads the $package information from the storage
     *
     * @param AssetPackage $package
     * @return array|null array of two elements:
     *  0 - string sha256 hash of the package
     *  1 - array[] releases
     *
     * Returns null, when package does not exist.
     */
    public function readPackage(AssetPackage $package)
    {
        $name = $package->getNormalName();
        $path = $this->buildHashedPath($name);
        if (!file_exists($path)) {
            return null;
        }
        $json = file_get_contents($path);
        $updateTime = filemtime($path);
        $hash = hash('sha256', $json);
        $data = Json::decode($json);
        $releases = isset($data['packages'][$name]) ? $data['packages'][$name] : [];

        return compact('hash', 'releases', 'updateTime');
    }

    public function buildPath()
    {
        $args = func_get_args();
        array_unshift($args, $this->_path);

        return implode(DIRECTORY_SEPARATOR, $args);
    }

    public function buildHashedPath($name, $hash = 'latest')
    {
        return $this->buildPath('p', $name, $hash . '.json');
    }

    protected function writeProviderLatest($name, $hash)
    {
        $latest_path = $this->buildHashedPath('provider-latest');
        if (file_exists($latest_path)) {
            $data = Json::decode(file_get_contents($latest_path) ?: '[]');
        }
        if (!isset($data)||!is_array($data)) {
            $data = [];
        }
        if (!isset($data['providers'])) {
            $data['providers'] = [];
        }
        $data['providers'][$name] = ['sha256' => $hash];
        $json = Json::encode($data);
        $hash = hash('sha256', $json);
        $path = $this->buildHashedPath('provider-latest', $hash);
        if (!file_exists($path)) {
            $this->getLocker()->lock();
            {
                static::mkdir(dirname($path));
                file_put_contents($path, $json);
                file_put_contents($latest_path, $json);
                $this->writePackagesJson($hash);
            }
            $this->getLocker()->release();
        }

        return $hash;
    }

    protected function writePackagesJson($hash)
    {
        $data = [
            'providers-url'     => '/p/%package%/%hash%.json',
            'provider-includes' => [
                'p/provider-latest/%hash%.json' => [
                    'sha256' => $hash,
                ],
            ],
        ];
        $this->getLocker()->lock();
        {
            file_put_contents($this->buildPath('packages.json'), Json::encode($data));
        }
        $this->getLocker()->release();
    }

    public static function mkdir($dir)
    {
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function readJson($path)
    {
        return Json::decode(file_get_contents($this->buildPath($path)));
    }

    protected function readPackagesJson()
    {
        $data = $this->readJson('packages.json');

        return $data['provider-includes'];
    }

    protected function readProvider($path)
    {
        $data = $this->readJson($path);

        return $data['providers'];
    }

    public function listPackages()
    {
        $packages = [];
        $providers = $this->readPackagesJson();
        foreach ($providers as $path => $data) {
            $path = strtr($path, ['%hash%' => $data['sha256']]);
            $packages = array_merge($packages, $this->readProvider($path));
        }

        return $packages;
    }
}
