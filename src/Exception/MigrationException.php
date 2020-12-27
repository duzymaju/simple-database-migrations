<?php

namespace SimpleDatabaseMigrations\Exception;

use SimpleStructure\Exception\ExceptionInterface;
use SimpleStructure\Exception\RuntimeException;

/**
 * Migration exception
 */
class MigrationException extends RuntimeException implements ExceptionInterface
{
}
