<?php

namespace SimpleDatabaseMigrations\Migration;

use SimpleDatabase\Client\ConnectionInterface;
use SimpleDatabaseMigrations\Exception\MigrationException;

/**
 * Version
 */
abstract class Version
{
    /** @var ConnectionInterface */
    protected $connection;

    /**
     * Construct
     *
     * @param ConnectionInterface $connection connection
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Up
     */
    abstract public function up();

    /**
     * Down
     */
    abstract public function down();

    /**
     * Query
     *
     * @param string $statement statement
     *
     * @return self
     *
     * @throws MigrationException
     */
    protected function query($statement)
    {
        if (!method_exists($this->connection,'query')) {
            throw new MigrationException('There is no query method for this client.');
        }
        $this->connection->query($statement);

        return $this;
    }
}
