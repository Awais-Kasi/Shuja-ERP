<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    public function test_backup_command_refuses_non_pgsql_connection(): void
    {
        // The suite runs on sqlite; the command must fail loudly rather than write a
        // useless/empty dump against an unsupported driver.
        $this->artisan('backup:database')
            ->expectsOutputToContain('pgsql')
            ->assertExitCode(1);
    }
}
