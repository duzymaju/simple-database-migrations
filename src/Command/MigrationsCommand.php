<?php

namespace SimpleDatabaseMigrations\Command;

use SimpleDatabaseMigrations\Exception\MigrationException;
use SimpleDatabaseMigrations\Manager\MigrationsManager;

class MigrationsCommand
{
    /** @var MigrationsManager */
    private $manager;

    /**
     * Construct
     *
     * @param MigrationsManager $manager migrations manager
     */
    public function __construct(MigrationsManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Execute
     *
     * @param array $argv argv
     *
     * @return self
     */
    public function execute(array $argv)
    {
        array_shift($argv);
        try {
            switch (array_shift($argv)) {
                case 'create':
                    $this->manager->create();
                    echo 'New migration file created.' . "\r\n";
                    break;

                case 'migrate':
                    $removeUnknownFlag = '--remove-unknown';
                    $removeUnknown = in_array($removeUnknownFlag, $argv);
                    $outputVersion = array_filter($argv, function ($argument) use ($removeUnknownFlag) {
                        return $argument !== $removeUnknownFlag;
                    })[0];
                    $this->manager->migrate($outputVersion, $removeUnknown);
                    echo 'All migrations implemented.' . "\r\n";
                    break;

                case 'status':
                    $status = $this->manager->status();
                    $fullStatus = in_array('--full', $argv);
                    echo sprintf(
                        'Migrations status
Current version:      %s
Last version:         %s
%sUnknown versions:     %s' . "\r\n", self::one($status->current) .
                        ($status->current === $status->last ? ' [LAST]' : ''), self::one($status->last),
                        $fullStatus ? sprintf(
                            'Existed versions:     %s
New versions:         %s
Implemented versions: %s
Missed versions:      %s' . "\r\n", self::many($status->existed), self::many($status->new),
                            self::many($status->implemented), self::many($status->missed)
                        ) : '', self::many($status->unknown)
                    );
                    break;

                default:
                    throw new MigrationException('There is no such method.');
            }
        } catch (MigrationException $exception) {
            echo sprintf('Error: %s' . "\r\n", $exception->getMessage());
        }

        return $this;
    }

    /**
     * One
     *
     * @param string|null $version version
     *
     * @return string
     */
    private static function one($version)
    {
        return isset($version) ? $version : '---';
    }

    /**
     * Many
     *
     * @param string[] $versions versions
     *
     * @return string
     */
    private static function many(array $versions)
    {
        return count($versions) > 0 ? implode(', ', $versions) : '---';
    }
}
