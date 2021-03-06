<?php

/*
 * Asset Packagist
 *
 * @link      https://github.com/hiqdev/asset-packagist
 * @package   asset-packagist
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2016, HiQDev (http://hiqdev.com/)
 */

namespace hiqdev\assetpackagist\registry;

class NpmRegistry extends \Fxp\Composer\AssetPlugin\Repository\NpmRepository
{
    use RegistryTrait;

    public $siteUrl = 'https://www.npmjs.com/';

    public function buildPackageUrl($name)
    {
        return $this->siteUrl . 'package/' . $name;
    }
}
