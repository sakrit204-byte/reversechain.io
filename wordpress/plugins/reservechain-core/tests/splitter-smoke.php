<?php
declare(strict_types=1);
require '/app/src/Database/Migrator.php';
use ReserveChain\Core\Database\Migrator;

$fail = 0;
function check(string $name, bool $cond): void {
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $name . "\n";
    if (!$cond) { $fail++; }
}

// 1. semicolon inside a string literal must not split
$sql = "INSERT INTO t VALUES ('a;b'); SELECT 1;";
$s = Migrator::split_statements($sql);
check('semicolon inside string literal', count($s) === 2 && str_contains($s[0], "'a;b'"));

// 2. line comments stripped, not counted
$sql = "-- comment; with semicolon\nSELECT 1;\n# another; one\nSELECT 2;";
$s = Migrator::split_statements($sql);
check('line comments ignored', count($s) === 2);

// 3. block comment
$sql = "/* multi;\n line */ SELECT 1;";
$s = Migrator::split_statements($sql);
check('block comment ignored', count($s) === 1 && trim($s[0]) === 'SELECT 1');

// 4. backtick identifier containing a semicolon
$sql = "SELECT `we;ird` FROM t; SELECT 2;";
$s = Migrator::split_statements($sql);
check('backtick identifier', count($s) === 2);

// 5. escaped quote
$sql = "INSERT INTO t VALUES ('it\'s; fine'); SELECT 1;";
$s = Migrator::split_statements($sql);
check('backslash-escaped quote', count($s) === 2);

// 6. trailing statement without semicolon
$s = Migrator::split_statements("SELECT 1");
check('trailing statement without semicolon', count($s) === 1);

// 7. real migration files parse into plausible statement counts
foreach (glob('/app/migrations/*.sql') as $f) {
    $stmts = Migrator::split_statements(file_get_contents($f));
    $empty = array_filter($stmts, fn($x) => trim($x) === '');
    printf("  %-34s %2d statements\n", basename($f), count($stmts));
    check(basename($f) . ' has no empty statements', count($empty) === 0);
}

// 8. trigger bodies survive intact
$trig = Migrator::split_statements(file_get_contents('/app/migrations/0005_audit_immutability.sql'));
$creates = array_filter($trig, fn($s) => str_starts_with(strtoupper(trim($s)), 'CREATE TRIGGER'));
check('7 CREATE TRIGGER statements parsed', count($creates) === 7);
$intact = array_filter($creates, fn($s) => str_contains($s, 'SIGNAL SQLSTATE') && str_contains($s, 'MESSAGE_TEXT'));
check('every trigger kept its SIGNAL body', count($intact) === count($creates));

echo $fail === 0 ? "\nALL CHECKS PASSED\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
