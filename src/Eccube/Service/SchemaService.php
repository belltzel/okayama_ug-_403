<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Service;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Eccube\Doctrine\ORM\Mapping\Driver\ReloadSafeAnnotationDriver;
use Eccube\Util\StringUtil;

class SchemaService
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * SchemaService constructor.
     *
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Doctrine Metadata を生成してコールバック関数を実行する.
     *
     * コールバック関数は主に SchemaTool が利用されます.
     * Metadata を出力する一時ディレクトリを指定しない場合は内部で生成し, コールバック関数実行後に削除されます.
     *
     * @param callable $callback Metadata を生成した後に実行されるコールバック関数
     * @param array $generatedFiles Proxy ファイルパスの配列
     * @param string $proxiesDirectory Proxy ファイルを格納したディレクトリ
     * @param bool $saveMode UpdateSchema を即時実行する場合 true
     * @param string $outputDir Metadata の出力先ディレクトリ
     */
    public function executeCallback(callable $callback, $generatedFiles, $proxiesDirectory, $outputDir = null)
    {
        $createOutputDir = false;
        if (is_null($outputDir)) {
            $outputDir = sys_get_temp_dir().'/proxy_'.StringUtil::random(12);
            mkdir($outputDir);
            $createOutputDir = true;
        }

        try {
            $chain = $this->entityManager->getConfiguration()->getMetadataDriverImpl();
            $drivers = $chain->getDrivers();
            foreach ($drivers as $namespace => $oldDriver) {
                if ('Eccube\Entity' === $namespace || preg_match('/^Plugin\\\\.*\\\\Entity$/', $namespace)) {
                    // Setup to AnnotationDriver
                    $newDriver = new ReloadSafeAnnotationDriver(
                        new AnnotationReader(),
                        $oldDriver->getPaths()
                    );
                    $newDriver->setFileExtension($oldDriver->getFileExtension());
                    $newDriver->addExcludePaths($oldDriver->getExcludePaths());
                    $newDriver->setTraitProxiesDirectory($proxiesDirectory);
                    $newDriver->setNewProxyFiles($generatedFiles);
                    $newDriver->setOutputDir($outputDir);
                    $chain->addDriver($newDriver, $namespace);
                }
            }

            $tool = new SchemaTool($this->entityManager);
            $metaData = $this->entityManager->getMetadataFactory()->getAllMetadata();

            call_user_func($callback, $tool, $metaData);
        } finally {
            if ($createOutputDir) {
                foreach (glob("${outputDir}/*") as $f) {
                    unlink($f);
                }
                rmdir($outputDir);
            }
        }
    }

    /**
     * Doctrine Metadata を生成して UpdateSchema を実行する.
     *
     * @param array $generatedFiles Proxy ファイルパスの配列
     * @param string $proxiesDirectory Proxy ファイルを格納したディレクトリ
     * @param bool $saveMode UpdateSchema を即時実行する場合 true
     */
    public function updateSchema($generatedFiles, $proxiesDirectory, $saveMode = false)
    {
        $this->executeCallback(function (SchemaTool $tool, array $metaData) use ($saveMode) {
            $tool->updateSchema($metaData, $saveMode);
        }, $generatedFiles, $proxiesDirectory);
    }

    /**
     * ネームスペースに含まれるEntityのテーブルを削除する
     *
     * @param $targetNamespace string 削除対象のネームスペース
     */
    public function dropTable($targetNamespace)
    {
        $chain = $this->entityManager->getConfiguration()->getMetadataDriverImpl();
        $drivers = $chain->getDrivers();

        $dropMetas = [];
        foreach ($drivers as $namespace => $driver) {
            if ($targetNamespace === $namespace) {
                $allClassNames = $driver->getAllClassNames();

                foreach ($allClassNames as $className) {
                    $dropMetas[] = $this->entityManager->getMetadataFactory()->getMetadataFor($className);
                }
            }
        }
        $tool = new SchemaTool($this->entityManager);
        $tool->dropSchema($dropMetas);
    }
}
