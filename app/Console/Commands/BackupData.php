<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Portable logical backup that does not shell out. Hostinger disables
 * proc_open, so mysqldump is not an option there.
 *
 *   php artisan smackchat:backup
 *
 * Writes gzip SQL plus (when ZipArchive is available and not skipped) the
 * uploaded files. Copy storage/app/backups off the server with your normal
 * off-site job; the command only keeps local copies.
 */
class BackupData extends Command
{
    protected $signature = 'smackchat:backup {--keep=14 : Daily copies to keep} {--skip-files : Only dump the database}';

    protected $description = 'Write a database dump (and uploaded files) to storage/app/backups';

    public function handle(): int
    {
        $directory = storage_path('app/backups');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error('Could not create '.$directory);

            return self::FAILURE;
        }

        $stamp = now()->format('Ymd-His');

        try {
            $sql = $this->dumpDatabase($directory, $stamp);
            $this->info('Database: '.$sql);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Database backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('skip-files')) {
            try {
                $files = $this->archiveFiles($directory, $stamp);

                if ($files) {
                    $this->info('Files: '.$files);
                } else {
                    $this->warn('File archive skipped (ZipArchive not available).');
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->warn('File archive failed: '.$exception->getMessage());
            }
        }

        $this->prune($directory, max(1, (int) $this->option('keep')));

        return self::SUCCESS;
    }

    private function dumpDatabase(string $directory, string $stamp): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $tables = match ($driver) {
            'mysql', 'mariadb' => $this->mysqlTables(),
            'sqlite' => $this->sqliteTables(),
            default => throw new RuntimeException('Unsupported database driver: '.$driver),
        };

        $temporary = $directory.'/.dump-'.$stamp.'.sql';
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not open '.$temporary);
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");

        foreach ($tables as $table) {
            fwrite($handle, "\n-- {$table}\n");
            fwrite($handle, $this->createStatement($driver, $table).";\n");
            $this->writeRows($handle, $connection, $table);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        fclose($handle);

        $target = $directory.'/smackchat-'.$stamp.'.sql.gz';
        $this->gzip($temporary, $target);
        @unlink($temporary);

        return $target;
    }

    /** @return list<string> */
    private function mysqlTables(): array
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $key = 'Tables_in_'.$database;

        return array_map(fn ($row) => (string) (is_array($row) ? $row[$key] : $row->{$key}), $rows);
    }

    /** @return list<string> */
    private function sqliteTables(): array
    {
        return array_map(
            fn ($row) => (string) $row->name,
            DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name")
        );
    }

    private function createStatement(string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            $row = DB::selectOne('select sql from sqlite_master where name = ?', [$table]);
            $sql = (string) ($row->sql ?? '');

            return "DROP TABLE IF EXISTS \"{$table}\";\n".$sql;
        }

        $row = (array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
        $sql = (string) ($row['Create Table'] ?? array_values($row)[1] ?? '');

        return "DROP TABLE IF EXISTS `{$table}`;\n".$sql;
    }

    /**
     * @param  resource  $handle
     */
    private function writeRows($handle, $connection, string $table): void
    {
        $columns = $connection->getSchemaBuilder()->getColumnListing($table);
        if ($columns === []) {
            return;
        }

        $quoted = implode(', ', array_map(fn ($column) => '`'.$column.'`', $columns));
        $pdo = $connection->getPdo();

        $write = function (iterable $rows) use ($handle, $table, $quoted, $pdo): void {
            $batch = [];

            foreach ($rows as $row) {
                $values = array_map(function ($value) use ($pdo) {
                    if ($value === null) {
                        return 'NULL';
                    }

                    if (is_bool($value)) {
                        return $value ? '1' : '0';
                    }

                    if (is_int($value) || is_float($value)) {
                        return (string) $value;
                    }

                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value);
                    }

                    return $pdo->quote((string) $value);
                }, (array) $row);

                $batch[] = '('.implode(', ', $values).')';

                if (count($batch) >= 200) {
                    fwrite($handle, "INSERT INTO `{$table}` ({$quoted}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }

            if ($batch !== []) {
                fwrite($handle, "INSERT INTO `{$table}` ({$quoted}) VALUES\n".implode(",\n", $batch).";\n");
            }
        };

        if (in_array('id', $columns, true)) {
            $connection->table($table)->orderBy('id')->chunkById(500, fn ($rows) => $write($rows), 'id');

            return;
        }

        $write($connection->table($table)->limit(5000)->get());
    }

    private function gzip(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb9');

        if ($in === false || $out === false) {
            throw new RuntimeException('Could not compress the dump.');
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 512));
        }

        fclose($in);
        gzclose($out);
    }

    private function archiveFiles(string $directory, string $stamp): ?string
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }

        $root = storage_path('app/private');
        $target = $directory.'/files-'.$stamp.'.zip';
        $zip = new \ZipArchive;

        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the file archive.');
        }

        $added = 0;

        foreach (['attachments', 'avatars', 'logos'] as $folder) {
            $base = $root.'/'.$folder;

            if (! is_dir($base)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $zip->addFile($file->getPathname(), $relative);
                $added++;
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($target);

            return null;
        }

        return $target;
    }

    private function prune(string $directory, int $keep): void
    {
        foreach (['smackchat-', 'files-'] as $prefix) {
            $files = glob($directory.'/'.$prefix.'*.gz') ?: [];
            $files = array_merge($files, glob($directory.'/'.$prefix.'*.zip') ?: []);
            rsort($files);

            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
            }
        }
    }
}
