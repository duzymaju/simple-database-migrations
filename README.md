# Simple database migrations

Simple PHP database migrations

## Testing

To run unit tests type `composer run test`.

## Implementation

To implement migrations into existed project add two classes into dependency injection container assuming that `db`
dependency is a database connection (`SimpleDatabase\Client\SqlConnectionInterface`):
```php
use SimpleDatabaseMigrations\Command\MigrationsCommand;
use SimpleDatabaseMigrations\Manager\MigrationsManager;

$container = $this->getContainer();
$container
    ->setObject('migrationsManager', MigrationsManager::class, ['db'], [
        $container->get('baseDir') . '/src/Migration', 'MyProject\\Migration',
    ])
    ->setObject('migrationsCommand', MigrationsCommand::class, ['migrationsManager'])
;
```
Then create `bin/migrations` file with the following content:
```php
#!/usr/bin/env php
<?php

use MyProject\Bootstrap;
use SimpleDatabaseMigrations\Command\MigrationsCommand;

$baseDir = realpath(dirname(__FILE__) . '/..');
require_once($baseDir . '/vendor/autoload.php');

$bootstrap = new Bootstrap($baseDir);

/** @var MigrationsCommand $command */
$command = $bootstrap
    ->getContainer()
    ->get('migrationsCommand')
;
$command->execute($argv);
```

## Commands

All migration commands works on a server on which application runs so on Docker container (access via Docker container's
bash) in case of local environment. There is a list of available commands:
* `bin/migrations status` - returns migrations status; supports the following flags:
    * `--full` - returns full information about migrations status,
* `bin/migrations create` - creates empty migration file to fill with queries,
* `bin/migrations migrate` - implements migrations; supports the following flags:
    * `--remove-unknown` - removes unknown migrations (record in database but no corresponding file),
* `bin/migrations migrate 1234567890` - implements or reverts migration to the given version (e.g. `1234567890`),
* `bin/migrations migrate empty` - reverts all migrations.
