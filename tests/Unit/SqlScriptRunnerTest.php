<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SqlScriptRunner;
use PHPUnit\Framework\TestCase;

final class SqlScriptRunnerTest extends TestCase
{
    public function testSplitsStatementsAndHandlesDelimiterQuotesAndComments(): void
    {
        $sql = <<<'SQL'
-- commentaire avec ; point-virgule
CREATE TABLE a (x VARCHAR(10) DEFAULT 'a;b'); # autre commentaire
INSERT INTO a VALUES ('l''apostrophe ; ok'), ("double ; quote");
/* bloc ; commentaire */
DELIMITER $$
CREATE TRIGGER t BEFORE DELETE ON a FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'non; jamais';
END$$
DELIMITER ;
SELECT 1;
SQL;
        $statements = SqlScriptRunner::split($sql);
        $this->assertCount(4, $statements);
        $this->assertStringContainsString("DEFAULT 'a;b'", $statements[0]);
        $this->assertStringContainsString("'l''apostrophe ; ok'", $statements[1]);
        $this->assertStringStartsWith('CREATE TRIGGER', $statements[2]);
        $this->assertStringContainsString("'non; jamais';\nEND", $statements[2]);
        $this->assertSame('SELECT 1', $statements[3]);
    }

    public function testRealSchemaFileSplitsIntoExpectedStatements(): void
    {
        $statements = SqlScriptRunner::split((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $triggers = array_filter($statements, fn ($s) => str_starts_with($s, 'CREATE TRIGGER'));
        $tables = array_filter($statements, fn ($s) => str_starts_with($s, 'CREATE TABLE'));
        $this->assertCount(22, $triggers);
        $this->assertCount(92, $tables);
    }
}
