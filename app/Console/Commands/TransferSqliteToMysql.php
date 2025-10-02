<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

class TransferSqliteToMysql extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:transfer-sqlite-to-mysql
        {--chunk-size=500 : Number of records to move per batch}
        {--force : Skip confirmation prompts}
        {--allow-nonempty : Allow transferring into tables that already contain rows}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy data from the legacy SQLite connection into the default MySQL connection.';

    private const TABLE_ORDER = [
        'users' => 'id',
        'agent_profiles' => 'id',
        'password_reset_tokens' => null,
        'sessions' => null,
        'cache' => null,
        'cache_locks' => null,
        'jobs' => 'id',
        'job_batches' => 'id',
        'failed_jobs' => 'id',
        'migrations' => 'id',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!Config::has('database.connections.legacy_sqlite')) {
            $this->error('Configure the legacy SQLite connection before running the transfer.');
            return self::FAILURE;
        }

        $chunkSize = (int) $this->option('chunk-size');
        assert($chunkSize > 0, 'Chunk size must be greater than zero.');

        $sourceConnection = DB::connection('legacy_sqlite');
        $targetConnection = DB::connection();

        try {
            $sourceConnection->getPdo();
            $targetConnection->getPdo();
        } catch (Throwable $throwable) {
            $this->error('Unable to connect to one of the databases: '.$throwable->getMessage());
            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm('This will copy all tables from SQLite to MySQL. Continue?')) {
            $this->line('Transfer aborted by user.');
            return self::SUCCESS;
        }

        foreach (self::TABLE_ORDER as $tableName => $orderColumn) {
            $transferSucceeded = $this->transferTable(
                $sourceConnection,
                $targetConnection,
                $tableName,
                $orderColumn,
                $chunkSize
            );

            if (!$transferSucceeded) {
                return self::FAILURE;
            }
        }

        $this->line('Data transfer complete.');
        return self::SUCCESS;
    }

    private function transferTable(
        ConnectionInterface $sourceConnection,
        ConnectionInterface $targetConnection,
        string $tableName,
        ?string $orderColumn,
        int $chunkSize
    ): bool {
        if (!Schema::connection($sourceConnection->getName())->hasTable($tableName)) {
            $this->line("Skipping {$tableName}: table not found on legacy connection.");
            return true;
        }

        if (!Schema::connection($targetConnection->getName())->hasTable($tableName)) {
            $this->error("Target database is missing table {$tableName}.");
            return false;
        }

        if (!$this->option('allow-nonempty') && $this->targetTableHasData($targetConnection, $tableName)) {
            $this->error("Target table {$tableName} already has rows. Rerun with --allow-nonempty to continue.");
            return false;
        }

        $sourceRowCount = (int) $sourceConnection->table($tableName)->count();
        if ($sourceRowCount === 0) {
            $this->line("{$tableName}: no rows to transfer.");
            return true;
        }

        $this->line("{$tableName}: transferring {$sourceRowCount} rows...");
        $progressBar = $this->output->createProgressBar($sourceRowCount);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();

        try {
            $targetConnection->transaction(function () use (
                $sourceConnection,
                $targetConnection,
                $tableName,
                $orderColumn,
                $chunkSize,
                $progressBar
            ): void {
                $this->copyRows(
                    $sourceConnection,
                    $targetConnection,
                    $tableName,
                    $orderColumn,
                    $chunkSize,
                    $progressBar
                );
            });
        } catch (Throwable $throwable) {
            $progressBar->clear();
            $this->error("{$tableName}: transfer failed - {$throwable->getMessage()}");
            return false;
        }

        $progressBar->finish();
        $this->newLine();

        $transferredCount = (int) $targetConnection->table($tableName)->count();
        assert($transferredCount >= $sourceRowCount, 'Transferred row count is less than source row count.');

        return true;
    }

    private function copyRows(
        ConnectionInterface $sourceConnection,
        ConnectionInterface $targetConnection,
        string $tableName,
        ?string $orderColumn,
        int $chunkSize,
        ProgressBar $progressBar
    ): void {
        $batch = [];
        $processedRows = 0;

        $query = $sourceConnection->table($tableName);
        if ($orderColumn !== null) {
            $query->orderBy($orderColumn);
        }

        foreach ($query->lazy($chunkSize) as $row) {
            $batch[] = $this->convertRowToArray($row);

            if (count($batch) >= $chunkSize) {
                $this->insertBatch($targetConnection, $tableName, $batch);
                $processedRows += count($batch);
                $progressBar->advance(count($batch));
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->insertBatch($targetConnection, $tableName, $batch);
            $processedRows += count($batch);
            $progressBar->advance(count($batch));
        }

        assert($processedRows > 0, 'No rows processed during transfer despite non-empty source table.');
    }

    private function insertBatch(ConnectionInterface $connection, string $tableName, array $rows): void
    {
        assert(!empty($rows), 'Attempted to insert an empty batch.');
        $connection->table($tableName)->insert($rows);
    }

    private function convertRowToArray(object $row): array
    {
        $rowArray = (array) $row;
        assert(!empty($rowArray), 'Converted row array is empty.');
        return $rowArray;
    }

    private function targetTableHasData(ConnectionInterface $connection, string $tableName): bool
    {
        return $connection->table($tableName)->exists();
    }
}
