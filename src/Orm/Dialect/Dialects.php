<?php

declare(strict_types=1);

namespace Keel\Orm\Dialect;

/** Picks the dialect for a PDO driver; `database.dialect` overrides it (e.g. for PDO_ODBC). */
final class Dialects
{
    public static function for(string $driver, string $override = ''): Dialect
    {
        return match ($override !== '' ? $override : $driver) {
            'sqlite' => new SqliteDialect(),
            'pgsql', 'postgres', 'postgresql', 'cockroach' => new PostgresDialect(),
            'mysql', 'mariadb' => new MySqlDialect(),
            'sqlsrv', 'dblib', 'mssql' => new SqlServerDialect(),
            'oci', 'oracle' => new OracleDialect(),
            default => new AnsiDialect(), // firebird, ibm (DB2), informix, odbc, ...
        };
    }
}
