<?php

namespace SimpleDatabaseMigrations\Manager;

use SimpleDatabase\Client\ConnectionInterface;
use SimpleDatabase\Client\QueryInterface;
use SimpleDatabase\Client\SqlConnectionInterface;
use SimpleDatabase\Exception\DatabaseException;
use SimpleDatabaseMigrations\Exception\MigrationException;
use SimpleDatabaseMigrations\Migration\Version;

class MigrationsManager
{
    /** @const string */
    const VERSION_EMPTY = 'empty';

    /** @const string */
    const VERSION_PATTERN = '[0-9]{14}';

    /** @var ConnectionInterface */
    private $connection;

    /** @var string */
    private $dirPath;

    /** @var string */
    private $namespace;

    /** @var string */
    private $tableName;

    /** @var string */
    private $fileNamePrefix;

    /**
     * Construct
     *
     * @param ConnectionInterface $connection     connection
     * @param string              $dirPath        dir path
     * @param string              $namespace      namespace
     * @param string              $tableName      table name
     * @param string              $fileNamePrefix file name prefix
     */
    public function __construct(
        ConnectionInterface $connection, $dirPath, $namespace, $tableName = 'migrations', $fileNamePrefix = 'Version'
    ) {
        $this->connection = $connection;
        $this->dirPath = $dirPath;
        $this->namespace = $namespace;
        $this->tableName = $tableName;
        $this->fileNamePrefix = $fileNamePrefix;
    }

    /**
     * Status
     *
     * @return object
     */
    public function status()
    {
        $existedVersions = $this->getExistedVersions();
        $lastVersion = count($existedVersions) > 0 ? end($existedVersions) : null;

        $implementedVersions = $this->getImplementedVersions();
        $implementedExistedVersions = array_intersect($implementedVersions, $existedVersions);
        $implementedUnknownVersions = array_diff($implementedVersions, $existedVersions);
        $currentVersion = count($implementedExistedVersions) > 0 ? end($implementedExistedVersions) : null;

        $missedVersions = [];
        $newVersions = [];
        foreach (array_diff($existedVersions, $implementedVersions) as $version) {
            if ($version < $currentVersion) {
                $missedVersions[] = $version;
            } else {
                $newVersions[] = $version;
            }
        }

        return (object) [
            'current' => $currentVersion,
            'existed' => $existedVersions,
            'implemented' => $implementedExistedVersions,
            'last' => $lastVersion,
            'missed' => $missedVersions,
            'new' => $newVersions,
            'unknown' => $implementedUnknownVersions,
        ];
    }

    /**
     * Migrate
     *
     * @param string $outputVersion output version
     * @param bool   $removeUnknown remove unknown
     *
     * @return self
     *
     * @throws MigrationException
     */
    public function migrate($outputVersion = null, $removeUnknown = false)
    {
        $status = $this->status();
        if (!isset($outputVersion)) {
            $outputVersion = $status->last;
        }
        $hasUnknown = count($status->unknown) > 0;

        if ($outputVersion === $status->current) {
            return $this;
        } elseif (count($status->existed) === 0) {
            throw new MigrationException('There are no migration files.');
        } elseif ($outputVersion != self::VERSION_EMPTY && !in_array($outputVersion, $status->existed)) {
            throw new MigrationException(sprintf('There is no migration version %s.', $outputVersion));
        } elseif ($hasUnknown && !$removeUnknown) {
            throw new MigrationException('There are unknown migrations on list. You have to remove it first.');
        }

        $versionsToAdd = [];
        $versionsToRemove = [];
        if ($hasUnknown) {
            $versionsToRemove = $status->unknown;
        }

        $versions = [];
        if ($outputVersion === self::VERSION_EMPTY || $outputVersion < $status->current) {
            $direction = 'down';
            foreach ($status->implemented as $version) {
                if ($outputVersion === self::VERSION_EMPTY || $outputVersion < $version) {
                    $versions[] = $version;
                    $versionsToRemove[] = $version;
                }
            }
        } else {
            $direction = 'up';
            foreach ($status->new as $version) {
                if ($outputVersion >= $version) {
                    $versions[] = $version;
                    $versionsToAdd[] = $version;
                }
            }
        }

        if ($direction === 'down') {
            rsort($versions);
        }
        $this->connection->beginTransaction();
        try {
            foreach ($versions as $version) {
                $migrationClass = $this->namespace . '\\' . $this->getFileName($version);
                /** @var Version $migration */
                $migration = new $migrationClass($this->connection);
                $migration->$direction();
            }
            $this->connection->commit(false);
        } catch (MigrationException | DatabaseException $exception) {
            $this->connection->rollBack(false);
            throw new MigrationException(
                sprintf('Migrations have been rolled back because of an error: %s', $exception->getMessage()),
                $exception->getCode(), $exception
            );
        }
        $this->setImplementedVersions($versionsToAdd, $versionsToRemove);

        return $this;
    }

    /**
     * Create
     *
     * @return self
     */
    public function create()
    {
        $fileName = $this->getFileName(gmdate('YmdHis'));
        if (!is_dir($this->dirPath)) {
            mkdir($this->dirPath);
        }
        file_put_contents($this->dirPath . '/' . $fileName . '.php', '<?php

namespace ' . $this->namespace . ';

use SimpleDatabaseMigrations\Migration\Version;

/**
 * Version ' . gmdate('Y-m-d H:i:s') . '
 */
class ' . $fileName . ' extends Version
{
    /**
     * Up
     */
    public function up()
    {
        // add queries
    }

    /**
     * Down
     */
    public function down()
    {
        // add queries
    }
}
');

        return $this;
    }

    /**
     * Get existed versions
     *
     * @return array
     */
    private function getExistedVersions()
    {
        $versions = [];
        if (is_dir($this->dirPath) && $dir = opendir($this->dirPath)) {
            while (false !== $file = readdir($dir)) {
                if (preg_match('#^' . $this->fileNamePrefix . self::VERSION_PATTERN . '\.php$#', $file)) {
                    $fileParts = explode('.', $file);
                    $fileName = array_shift($fileParts);
                    $versions[] = $this->getVersion($fileName);
                }
            }
            closedir($dir);
        }
        sort($versions);

        return $versions;
    }

    /**
     * Get implemented versions
     *
     * @return array
     */
    private function getImplementedVersions()
    {
        $this->createTableIfNotExists();
        $results = $this->connection
            ->select('version', $this->tableName)
            ->execute()
        ;

        return array_map(function ($result) {
            return $result['version'];
        }, $results);
    }

    /**
     * Set implemented versions
     *
     * @param array $versionsToAdd    versions to add
     * @param array $versionsToRemove versions to remove
     *
     * @return self
     */
    private function setImplementedVersions(array $versionsToAdd, array $versionsToRemove)
    {
        $this->createTableIfNotExists();
        if (count($versionsToAdd) > 0) {
            $query = $this->connection
                ->insert($this->tableName)
                ->set(['version = :version'])
                ->bindParam('version', QueryInterface::PARAM_INT)
            ;
            foreach ($versionsToAdd as $version) {
                $query->execute(['version' => $version]);
            }
        }
        if (count($versionsToRemove) > 0) {
            $query = $this->connection
                ->delete($this->tableName)
                ->where(['version = :version'])
                ->bindParam('version', QueryInterface::PARAM_INT)
            ;
            foreach ($versionsToRemove as $version) {
                $query->execute(['version' => $version]);
            }
        }

        return $this;
    }

    /**
     * Create table if not exists
     *
     * @return self
     */
    private function createTableIfNotExists()
    {
        if ($this->connection instanceof SqlConnectionInterface) {
            $this->connection->rawQuery('CREATE TABLE IF NOT EXISTS `' . $this->tableName . '` (
    `version` char(14) NOT NULL,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE utf8_unicode_ci');
        }

        return $this;
    }

    /**
     * Get file name
     *
     * @param string $version version
     *
     * @return string
     */
    private function getFileName($version)
    {
        return $this->fileNamePrefix . $version;
    }

    /**
     * Get version
     *
     * @param string $fileName file name
     *
     * @return string
     */
    private function getVersion($fileName)
    {
        return substr($fileName, strlen($this->fileNamePrefix));
    }
}
