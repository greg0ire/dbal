<?php

namespace Doctrine\Tests\DBAL\Functional\Schema;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

use function dirname;

class SqliteSchemaManagerTest extends SchemaManagerFunctionalTestCase
{
    /**
     * SQLITE does not support databases.
     */
    public function testListDatabases(): void
    {
        $this->expectException(DBALException::class);

        $this->schemaManager->listDatabases();
    }

    public function testCreateAndDropDatabase(): void
    {
        $path = dirname(__FILE__) . '/test_create_and_drop_sqlite_database.sqlite';

        $this->schemaManager->createDatabase($path);
        self::assertFileExists($path);
        $this->schemaManager->dropDatabase($path);
        self::assertFileNotExists($path);
    }

    public function testDropsDatabaseWithActiveConnections(): void
    {
        $this->schemaManager->dropAndCreateDatabase('test_drop_database');

        self::assertFileExists('test_drop_database');

        $params           = $this->connection->getParams();
        $params['dbname'] = 'test_drop_database';

        $user          = $params['user'] ?? null;
        $password      = $params['password'] ?? null;
        $driverOptions = $params['driverOptions'] ?? [];

        $connection = $this->connection->getDriver()->connect($params, $user, $password, $driverOptions);

        self::assertInstanceOf(Connection::class, $connection);

        $this->schemaManager->dropDatabase('test_drop_database');

        self::assertFileNotExists('test_drop_database');

        unset($connection);
    }

    public function testRenameTable(): void
    {
        $this->createTestTable('oldname');
        $this->schemaManager->renameTable('oldname', 'newname');

        $tables = $this->schemaManager->listTableNames();
        self::assertContains('newname', $tables);
        self::assertNotContains('oldname', $tables);
    }

    public function createListTableColumns(): Table
    {
        $table = parent::createListTableColumns();
        $table->getColumn('id')->setAutoincrement(true);

        return $table;
    }

    public function testListForeignKeysFromExistingDatabase(): void
    {
        $this->connection->exec(<<<EOS
CREATE TABLE user (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page INTEGER CONSTRAINT FK_1 REFERENCES page (key) DEFERRABLE INITIALLY DEFERRED,
    parent INTEGER REFERENCES user(id) ON DELETE CASCADE,
    log INTEGER,
    CONSTRAINT FK_3 FOREIGN KEY (log) REFERENCES log ON UPDATE SET NULL NOT DEFERRABLE
)
EOS
        );

        $expected = [
            new Schema\ForeignKeyConstraint(
                ['log'],
                'log',
                [''],
                'FK_3',
                ['onUpdate' => 'SET NULL', 'onDelete' => 'NO ACTION', 'deferrable' => false, 'deferred' => false]
            ),
            new Schema\ForeignKeyConstraint(
                ['parent'],
                'user',
                ['id'],
                null,
                ['onUpdate' => 'NO ACTION', 'onDelete' => 'CASCADE', 'deferrable' => false, 'deferred' => false]
            ),
            new Schema\ForeignKeyConstraint(
                ['page'],
                'page',
                ['key'],
                'FK_1',
                ['onUpdate' => 'NO ACTION', 'onDelete' => 'NO ACTION', 'deferrable' => true, 'deferred' => true]
            ),
        ];

        self::assertEquals($expected, $this->schemaManager->listTableForeignKeys('user'));
    }

    public function testColumnCollation(): void
    {
        $table = new Schema\Table('test_collation');
        $table->addColumn('id', 'integer');
        $table->addColumn('text', 'text');
        $table->addColumn('foo', 'text')->setPlatformOption('collation', 'BINARY');
        $table->addColumn('bar', 'text')->setPlatformOption('collation', 'NOCASE');
        $this->schemaManager->dropAndCreateTable($table);

        $columns = $this->schemaManager->listTableColumns('test_collation');

        self::assertArrayNotHasKey('collation', $columns['id']->getPlatformOptions());
        self::assertEquals('BINARY', $columns['text']->getPlatformOption('collation'));
        self::assertEquals('BINARY', $columns['foo']->getPlatformOption('collation'));
        self::assertEquals('NOCASE', $columns['bar']->getPlatformOption('collation'));
    }

    public function testListTableWithBinary(): void
    {
        $tableName = 'test_binary_table';

        $table = new Table($tableName);
        $table->addColumn('id', 'integer');
        $table->addColumn('column_varbinary', 'binary', []);
        $table->addColumn('column_binary', 'binary', ['fixed' => true]);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->createTable($table);

        $table = $this->schemaManager->listTableDetails($tableName);

        self::assertInstanceOf(BlobType::class, $table->getColumn('column_varbinary')->getType());
        self::assertFalse($table->getColumn('column_varbinary')->getFixed());

        self::assertInstanceOf(BlobType::class, $table->getColumn('column_binary')->getType());
        self::assertFalse($table->getColumn('column_binary')->getFixed());
    }

    public function testListTableColumnsWithWhitespacesInTypeDeclarations(): void
    {
        $sql = <<<SQL
CREATE TABLE dbal_1779 (
    foo VARCHAR (64) ,
    bar TEXT (100)
)
SQL;

        $this->connection->exec($sql);

        $columns = $this->schemaManager->listTableColumns('dbal_1779');

        self::assertCount(2, $columns);

        self::assertArrayHasKey('foo', $columns);
        self::assertArrayHasKey('bar', $columns);

        self::assertSame(Type::getType(Types::STRING), $columns['foo']->getType());
        self::assertSame(Type::getType(Types::TEXT), $columns['bar']->getType());

        self::assertSame(64, $columns['foo']->getLength());
        self::assertSame(100, $columns['bar']->getLength());
    }

    /**
     * @dataProvider getDiffListIntegerAutoincrementTableColumnsData
     */
    public function testDiffListIntegerAutoincrementTableColumns(
        string $integerType,
        bool $unsigned,
        bool $expectedComparatorDiff
    ): void {
        $tableName = 'test_int_autoincrement_table';

        $offlineTable = new Table($tableName);
        $offlineTable->addColumn('id', $integerType, ['autoincrement' => true, 'unsigned' => $unsigned]);
        $offlineTable->setPrimaryKey(['id']);

        $this->schemaManager->dropAndCreateTable($offlineTable);

        $onlineTable = $this->schemaManager->listTableDetails($tableName);
        $comparator  = new Schema\Comparator();
        $diff        = $comparator->diffTable($offlineTable, $onlineTable);

        if ($expectedComparatorDiff) {
            self::assertEmpty($this->schemaManager->getDatabasePlatform()->getAlterTableSQL($diff));
        } else {
            self::assertFalse($diff);
        }
    }

    /**
     * @return mixed[][]
     */
    public static function getDiffListIntegerAutoincrementTableColumnsData(): iterable
    {
        return [
            ['smallint', false, true],
            ['smallint', true, true],
            ['integer', false, false],
            ['integer', true, true],
            ['bigint', false, true],
            ['bigint', true, true],
        ];
    }

    public function testPrimaryKeyNoAutoIncrement(): void
    {
        $table = new Schema\Table('test_pk_auto_increment');
        $table->addColumn('id', 'integer');
        $table->addColumn('text', 'text');
        $table->setPrimaryKey(['id']);
        $this->schemaManager->dropAndCreateTable($table);

        $this->connection->insert('test_pk_auto_increment', ['text' => '1']);

        $this->connection->query('DELETE FROM test_pk_auto_increment');

        $this->connection->insert('test_pk_auto_increment', ['text' => '2']);

        $query = $this->connection->query('SELECT id FROM test_pk_auto_increment WHERE text = "2"');
        $query->execute();
        $lastUsedIdAfterDelete = (int) $query->fetchColumn();

        // with an empty table, non autoincrement rowid is always 1
        $this->assertEquals(1, $lastUsedIdAfterDelete);
    }

    public function testOnlyOwnCommentIsParsed(): void
    {
        $table = new Table('own_column_comment');
        $table->addColumn('col1', 'string', ['length' => 16]);
        $table->addColumn('col2', 'string', ['length' => 16, 'comment' => 'Column #2']);
        $table->addColumn('col3', 'string', ['length' => 16]);

        $sm = $this->connection->getSchemaManager();
        $sm->createTable($table);

        $this->assertNull($sm->listTableDetails('own_column_comment')
            ->getColumn('col1')
            ->getComment());
    }

    public function testUnnamedForeignKeyConstraintHandling(): void
    {
        $sql = <<<SQL
DROP TABLE IF EXISTS "unnamedfk_referenced1";
DROP TABLE IF EXISTS "unnamedfk_referenced2";
DROP TABLE IF EXISTS "unnamedfk_referencing";
CREATE TABLE "unnamedfk_referenced1" (
"id1" integer not null,
"id2" integer not null,
primary key("id1", "id2")
);
CREATE TABLE "unnamedfk_referenced2" (
"id" integer not null primary key autoincrement
);
CREATE TABLE "unnamedfk_referencing" (
    "id" integer not null primary key autoincrement,
    "reference1_id1" integer not null,
    "reference1_id2" integer not null,
    "reference2_id" integer not null,
    foreign key("reference1_id1", "reference1_id2") references "unnamedfk_referenced1"("id1", "id2") on delete cascade,
    foreign key("reference2_id") references "unnamedfk_referenced2"("id") on delete cascade
)
SQL;

        $this->connection->exec($sql);

        $sm          = $this->connection->getSchemaManager();
        $onlineTable = $sm->listTableDetails('unnamedfk_referencing');

        $offlineTable = new Table('unnamedfk_referencing');
        $offlineTable->addColumn('id', 'integer');
        $offlineTable->addColumn('reference1_id1', 'integer');
        $offlineTable->addColumn('reference1_id2', 'integer');
        $offlineTable->addColumn('reference2_id', 'integer');

        $comparator = new Schema\Comparator();
        $diff       = $comparator->diffTable($offlineTable, $onlineTable);

        $sqls = $this->connection->getDatabasePlatform()->getAlterTableSQL($diff);

        foreach ($sqls as $sql) {
            $this->connection->exec($sql);
        }

        $onlineTableAfter = $sm->listTableDetails('unnamedfk_referencing');
        $diff             = $comparator->diffTable($onlineTable, $onlineTableAfter);

        $this->assertFalse($diff);
    }
}
