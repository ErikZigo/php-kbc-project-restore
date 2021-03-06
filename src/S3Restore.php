<?php
namespace Keboola\ProjectRestore;

use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Client AS StorageApi;
use Aws\S3\S3Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Exception as StorageApiException;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;


class S3Restore
{
    /**
     * @var StorageApi
     */
    private $sapiClient;

    /**
     * @var S3Client
     */
    private $s3Client;

    /**
     * @var NullLogger
     */
    private $logger;

    public function __construct(S3Client $s3Client, StorageApi $sapiClient, LoggerInterface $logger = null)
    {
        $this->s3Client = $s3Client;
        $this->sapiClient = $sapiClient;
        $this->logger = $logger?: new NullLogger();
    }

    //@FIXME check bucket compatibiliyty

    private function trimSourceBasePath(string $targetBasePath = null)
    {
        if (empty($targetBasePath) || $targetBasePath === '/') {
            return '';
        } else {
            return trim($targetBasePath, '/') . '/';
        }
    }

    /**
     * List of KBC components without api
     *
     * @see https://github.com/keboola/kbc-ui/blob/master/src/scripts/modules/components/utils/hasComponentApi.coffee
     * @return array
     */
    private function componentsWithoutApi(): array
    {
        return [
            'wr-dropbox', 'tde-exporter', 'geneea-topic-detection',
            'geneea-language-detection', 'geneea-lemmatization', 'geneea-sentiment-analysis',
            'geneea-text-correction', 'geneea-entity-recognition', 'ex-adform', 'geneea-nlp-analysis',
            'rcp-anomaly', 'rcp-basket', 'rcp-correlations', 'rcp-data-type-assistant',
            'rcp-distribution-groups', 'rcp-linear-dependency', 'rcp-linear-regression',
            'rcp-next-event', 'rcp-next-order-simple',
            'rcp-segmentation', 'rcp-var-characteristics', 'ex-sklik', 'ex-dropbox', 'wr-portal-sas', 'ag-geocoding',
            'keboola.ex-db-pgsql', 'keboola.ex-db-db2', 'keboola.ex-db-firebird',
        ];
    }

    /**
     * Check if component is obsolete
     *
     * @see https://github.com/keboola/kbc-ui/blob/master/src/scripts/modules/trash/utils.js
     * @param array $component component data
     * @return bool
     */
    private function isObsoleteComponent(array $component): bool
    {
        $componentId = $component['id'];
        if ($componentId === 'gooddata-writer') {
            return true;
        }

        if ($componentId === 'transformation') {
            return false;
        }

        $flags = $component['flags'];
        if (isset($component['uri']) &&
            !in_array($componentId, $this->componentsWithoutApi()) &&
            !in_array('genericUI', $flags) &&
            !in_array('genericDockerUI', $flags) &&
            !in_array('genericTemplatesUI', $flags)
        ) {
            return true;
        }

        return false;
    }

    public function restoreTableAliases(string $sourceBucket, string $sourceBasePath = null)
    {
        $sourceBasePath = $this->trimSourceBasePath($sourceBasePath);
        $this->logger->info('Downloading tables');

        $tmp = new Temp();
        $tmp->initRunFolder();

        $targetFile = $tmp->createFile("tables.json");
        $this->s3Client->getObject([
            'Bucket' => $sourceBucket,
            'Key' => $sourceBasePath . 'tables.json',
            'SaveAs' => (string) $targetFile
        ]);

        $tables = json_decode(file_get_contents($targetFile), true);
        $restoredBuckets = array_map(function($bucket) { return $bucket['id']; }, $this->sapiClient->listBuckets());
        $metadataClient = new Metadata($this->sapiClient);

        foreach ($tables as $tableInfo)
        {
            if ($tableInfo["isAlias"] !== true) {
                continue;
            }

            $tableId = $tableInfo["id"];
            $bucketId = $tableInfo["bucket"]["id"];

            if (!in_array($bucketId, $restoredBuckets)) {
                $this->logger->warning(sprintf('Skipping alias %s', $tableId));
                continue;
            }

            $this->logger->info(sprintf('Restoring alias %s', $tableId));

            $aliasOptions = [];
            if (isset($tableInfo["aliasFilter"])) {
                $aliasOptions["aliasFilter"] = $tableInfo["aliasFilter"];
            }
            if (isset($tableInfo["aliasColumnsAutoSync"]) && $tableInfo["aliasColumnsAutoSync"] === false) {
                $aliasOptions["aliasColumns"] = $tableInfo["columns"];
            }
            $this->sapiClient->createAliasTable(
                $bucketId,
                $tableInfo["sourceTable"]["id"],
                $tableInfo["name"],
                $aliasOptions
            );

            // Alias attributes
            if (isset($tableInfo["attributes"]) && count($tableInfo["attributes"])) {
                $this->sapiClient->replaceTableAttributes($tableId, $tableInfo["attributes"]);
            }
            if (isset($tableInfo["metadata"]) && count($tableInfo["metadata"])) {
                foreach ($this->prepareMetadata($tableInfo["metadata"]) as $provider => $metadata) {
                    $metadataClient->postTableMetadata($tableId, $provider, $metadata);
                }
            }
            if (isset($tableInfo["columnMetadata"]) && count($tableInfo["columnMetadata"])) {
                foreach ($tableInfo["columnMetadata"] as $column => $columnMetadata) {
                    foreach ($this->prepareMetadata($columnMetadata) as $provider => $metadata) {
                        $metadataClient->postColumnMetadata($tableId . "." . $column, $provider, $metadata);
                    }
                }
            }
        }
    }

    public function restoreTables(string $sourceBucket, string $sourceBasePath = null)
    {
        $sourceBasePath = $this->trimSourceBasePath($sourceBasePath);
        $this->logger->info('Downloading tables');

        $tmp = new Temp();
        $tmp->initRunFolder();

        $targetFile = $tmp->createFile("tables.json");
        $this->s3Client->getObject([
            'Bucket' => $sourceBucket,
            'Key' => $sourceBasePath . 'tables.json',
            'SaveAs' => (string) $targetFile
        ]);

        $tables = json_decode(file_get_contents($targetFile), true);
        $restoredBuckets = array_map(function($bucket) { return $bucket['id']; }, $this->sapiClient->listBuckets());
        $metadataClient = new Metadata($this->sapiClient);

        foreach ($tables as $tableInfo) {
            if ($tableInfo["isAlias"] === true) {
                continue;
            }

            $tableId = $tableInfo["id"];
            $bucketId = $tableInfo["bucket"]["id"];

            if (!in_array($bucketId, $restoredBuckets)) {
                $this->logger->warning(sprintf('Skipping table %s', $tableId));
                continue;
            }

            $this->logger->info(sprintf('Restoring table %s', $tableId));

            //@FIXME
            // create empty table
            $headerFile = $tmp->createFile(sprintf('%s.header.csv', $tableId));
            $headerFile = new CsvFile($headerFile->getPathname());
            $headerFile->writeRow($tableInfo["columns"]);

            $tableId = $this->sapiClient->createTable(
                $bucketId,
                $tableInfo["name"],
                $headerFile,
                [
                    "primaryKey" => join(",", $tableInfo["primaryKey"])
                ]
            );

            // Table attributes
            if (isset($tableInfo["attributes"]) && count($tableInfo["attributes"])) {
                $this->sapiClient->replaceTableAttributes($tableId, $tableInfo["attributes"]);
            }
            if (isset($tableInfo["metadata"]) && count($tableInfo["metadata"])) {
                foreach ($this->prepareMetadata($tableInfo["metadata"]) as $provider => $metadata) {
                    $metadataClient->postTableMetadata($tableId, $provider, $metadata);
                }
            }
            if (isset($tableInfo["columnMetadata"]) && count($tableInfo["columnMetadata"])) {
                foreach ($tableInfo["columnMetadata"] as $column => $columnMetadata) {
                    foreach ($this->prepareMetadata($columnMetadata) as $provider => $metadata) {
                        $metadataClient->postColumnMetadata($tableId . "." . $column, $provider, $metadata);
                    }
                }
            }

            // upload data
            $slices = $this->s3Client->listObjects(
                [
                    'Bucket' => $sourceBucket,
                    'Prefix' => $sourceBasePath . str_replace('.', '/', $tableId) . '.',
                ]
            );

            // no files for the table found, probably an empty table
            if (!isset($slices["Contents"])) {
                unset($headerFile);
                continue;
            }

            if (count($slices["Contents"]) === 1 && substr($slices["Contents"][0]["Key"], -14) !== '.part_0.csv.gz') {
                // one file and no slices => the file has header
                // no slices = file does not end with .part_0.csv.gz
                $targetFile = $tmp->createFile(sprintf('%s.csv.gz', $tableId));
                $this->s3Client->getObject(
                    [
                        'Bucket' => $sourceBucket,
                        'Key' => $slices["Contents"][0]["Key"],
                        'SaveAs' => (string) $targetFile,
                    ]
                );
                $fileUploadOptions = new FileUploadOptions();
                $fileUploadOptions
                    ->setFileName(sprintf('%s.csv.gz', $tableId));
                $fileId = $this->sapiClient->uploadFile((string) $targetFile, $fileUploadOptions);
                $this->sapiClient->writeTableAsyncDirect(
                    $tableId,
                    [
                        "name" => $tableInfo["name"],
                        "dataFileId" => $fileId,
                    ]
                );
            } else {
                // sliced file, requires some more work
                // prepare manifest and prepare upload params
                $manifest = [
                    "entries" => [],
                ];
                $fileUploadOptions = new FileUploadOptions();
                $fileUploadOptions
                    ->setFederationToken(true)
                    ->setFileName($tableId)
                    ->setIsSliced(true)
                ;
                $fileUploadInfo = $this->sapiClient->prepareFileUpload($fileUploadOptions);
                $uploadParams = $fileUploadInfo["uploadParams"];
                $s3FileClient = new S3Client(
                    [
                        "credentials" => [
                            "key" => $uploadParams["credentials"]["AccessKeyId"],
                            "secret" => $uploadParams["credentials"]["SecretAccessKey"],
                            "token" => $uploadParams["credentials"]["SessionToken"],
                        ],
                        "region" => $fileUploadInfo["region"],
                        "version" => "2006-03-01",
                    ]
                );
                //@FIXME better temps
                $fs = new Filesystem();
                $part = 0;

                // download and upload each slice
                foreach ($slices["Contents"] as $slice) {
                    $fileName = $tmp->getTmpFolder() . "/" . $tableId . $tableId . ".part_" . $part . ".csv.gz";
                    $this->s3Client->getObject(
                        [
                            'Bucket' => $sourceBucket,
                            'Key' => $slice["Key"],
                            'SaveAs' => $fileName,
                        ]
                    );

                    $manifest["entries"][] = [
                        "url" => "s3://" . $uploadParams["bucket"] . "/" . $uploadParams["key"] . ".part_" . $part . ".csv.gz",
                        "mandatory" => true,
                    ];

                    $handle = fopen($fileName, 'r+');
                    $s3FileClient->putObject(
                        [
                            'Bucket' => $uploadParams['bucket'],
                            'Key' => $uploadParams['key'] . ".part_" . $part . ".csv.gz",
                            'Body' => $handle,
                            'ServerSideEncryption' => $uploadParams['x-amz-server-side-encryption'],
                        ]
                    );

                    // remove the uploaded file
                    fclose($handle);
                    $fs->remove($fileName);
                    $part++;
                }

                // Upload manifest
                $s3FileClient->putObject(
                    array(
                        'Bucket' => $uploadParams['bucket'],
                        'Key' => $uploadParams['key'] . 'manifest',
                        'ServerSideEncryption' => $uploadParams['x-amz-server-side-encryption'],
                        'Body' => json_encode($manifest),
                    )
                );

                // Upload data to table
                $this->sapiClient->writeTableAsyncDirect(
                    $tableId,
                    array(
                        'dataFileId' => $fileUploadInfo['id'],
                        'columns' => $headerFile->getHeader()
                    )
                );
            }
            unset($headerFile);
        }

    }

    public function restoreBuckets(string $sourceBucket, string $sourceBasePath = null, bool $checkBackend = true): void
    {
        $sourceBasePath = $this->trimSourceBasePath($sourceBasePath);
        $this->logger->info('Downloading buckets');

        $tmp = new Temp();
        $tmp->initRunFolder();

        $targetFile = $tmp->createFile("buckets.json");
        $this->s3Client->getObject([
            'Bucket' => $sourceBucket,
            'Key' => $sourceBasePath . 'buckets.json',
            'SaveAs' => (string) $targetFile
        ]);

        $buckets = json_decode(file_get_contents($targetFile), true);

        if ($checkBackend) {
            $token = $this->sapiClient->verifyToken();
            foreach ($buckets as $bucketInfo) {
                switch ($bucketInfo["backend"]) {
                    case "mysql":
                        if (!isset($token["owner"]["hasMysql"]) || $token["owner"]["hasMysql"] === false) {
                            throw new StorageApiException('Missing MySQL backend');
                        }
                        break;
                    case "redshift":
                        if (!isset($token["owner"]["hasRedshift"]) || $token["owner"]["hasRedshift"] === false) {
                            throw new StorageApiException('Missing Redshift backend');
                        }
                        break;
                    case "snowflake":
                        if (!isset($token["owner"]["hasSnowflake"]) || $token["owner"]["hasSnowflake"] === false) {
                            throw new StorageApiException('Missing Snowflake backend');
                        }
                        break;
                }
            }
        }

        $restoredBuckets = [];
        $metadataClient = new Metadata($this->sapiClient);

        // buckets restore
        foreach ($buckets as $bucketInfo) {
            if (isset($bucketInfo['sourceBucket']) || substr($bucketInfo["name"], 0, 2) !== 'c-') {
                $this->logger->warning(sprintf('Skipping bucket %s - linked bucket', $bucketInfo["name"]));
                continue;
            }

            $this->logger->info(sprintf('Restoring bucket %s', $bucketInfo["name"]));


            $bucketName = substr($bucketInfo["name"], 2);
            $restoredBuckets[] = $bucketInfo["id"];

            if (!$checkBackend) {
                $this->sapiClient->createBucket($bucketName, $bucketInfo['stage'], $bucketInfo['description']);
            } else {
                $this->sapiClient->createBucket(
                    $bucketName,
                    $bucketInfo['stage'],
                    $bucketInfo['description'],
                    $bucketInfo['backend']
                );
            }

            // bucket attributes
            if (isset($bucketInfo["attributes"]) && count($bucketInfo["attributes"])) {
                $this->sapiClient->replaceBucketAttributes($bucketInfo["id"], $bucketInfo["attributes"]);
            }
            if (isset($bucketInfo["metadata"]) && count($bucketInfo["metadata"])) {
                foreach ($this->prepareMetadata($bucketInfo["metadata"]) as $provider => $metadata) {
                    $metadataClient->postBucketMetadata($bucketInfo["id"], $provider, $metadata);
                }
            }
        }

    }

    private function prepareMetadata(array $rawMetadata): array
    {
        $result = [];
        foreach ($rawMetadata as $item) {
            $result[$item["provider"]][] = [
                "key" => $item["key"],
                "value" => $item["value"],
            ];
        }
        return $result;
    }

    public function restoreConfigs($sourceBucket, $sourceBasePath = null): void
    {
        $sourceBasePath = $this->trimSourceBasePath($sourceBasePath);
        $this->logger->info('Downloading configurations');

        $tmp = new Temp();
        $tmp->initRunFolder();

        $targetFile = $tmp->createFile("configurations.json");
        $this->s3Client->getObject([
            'Bucket' => $sourceBucket,
            'Key' => $sourceBasePath . 'configurations.json',
            'SaveAs' => (string) $targetFile
        ]);

        $configurations = json_decode(file_get_contents($targetFile), true);

        $components = new Components($this->sapiClient);

        $componentList = [];
        foreach ($this->sapiClient->indexAction()['components'] as $component) {
            $componentList[$component['id']] = $component;
        }

        foreach ($configurations as $componentWithConfigurations) {

            // skip non-existing components
            if (!array_key_exists($componentWithConfigurations["id"], $componentList)) {
                $this->logger->warning(sprintf('Skipping %s configurations - component does not exists', $componentWithConfigurations["id"]));
                continue;
            }

            // skip obsolete components - orchestrator, old writers, etc.
            if ($this->isObsoleteComponent($componentList[$componentWithConfigurations["id"]])) {
                $this->logger->warning(sprintf('Skipping %s configurations - component has custom API', $componentWithConfigurations["id"]));
                continue;
            }

            $this->logger->info(sprintf('Restoring %s configurations', $componentWithConfigurations["id"]));

            foreach ($componentWithConfigurations["configurations"] as $componentConfiguration) {
                $targetFile = $tmp->createFile(sprintf("configurations-%s-%s.json", $componentWithConfigurations["id"], $componentConfiguration["id"]));
                $this->s3Client->getObject(
                    [
                        'Bucket' => $sourceBucket,
                        'Key' => sprintf("%sconfigurations/%s/%s.json", $sourceBasePath, $componentWithConfigurations["id"], $componentConfiguration["id"]),
                        'SaveAs' => (string) $targetFile,
                    ]
                );

                // configurations as objects to preserve empty arrays or empty objects
                $configurationData = json_decode(file_get_contents($targetFile));

                // create empty configuration
                $configuration = new Configuration();
                $configuration->setComponentId($componentWithConfigurations["id"]);
                $configuration->setConfigurationId($componentConfiguration["id"]);
                $configuration->setDescription($configurationData->description);
                $configuration->setName($configurationData->name);
                $components->addConfiguration($configuration);

                // update configuration and state
                $configuration->setChangeDescription(sprintf(
                    'Configuration %s restored from backup',
                    $componentConfiguration["id"]
                ));
                $configuration->setConfiguration($configurationData->configuration);
                if (isset($configurationData->state)) {
                    $configuration->setState($configurationData->state);
                }
                $components->updateConfiguration($configuration);

                // create configuration rows
                if (count($configurationData->rows)) {
                    foreach ($configurationData->rows as $row) {
                        // create empty row
                        $configurationRow = new ConfigurationRow($configuration);
                        $configurationRow->setRowId($row->id);
                        $components->addConfigurationRow($configurationRow);

                        // update row configuration and state
                        $configurationRow->setConfiguration($row->configuration);
                        $configurationRow->setChangeDescription(sprintf(
                            'Row %s restored from backup', $row->id

                        ));
                        if (isset($row->state)) {
                            $configurationRow->setState($row->state);
                        }
                        $components->updateConfigurationRow($configurationRow);
                    }
                }
            }

        }
    }
}