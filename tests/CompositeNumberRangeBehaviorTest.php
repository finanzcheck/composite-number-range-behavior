<?php

namespace Finanzcheck\CompositeNumberRange;

use Finanzcheck\CompositeNumberRange\Platform\MysqlPlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Adapter\Pdo\MysqlAdapter;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Propel;
use Propel\Tests\TestCase;

class CompositeNumberRangeBehaviorTest extends TestCase
{
    private $parent;
    private $parent2;

    public function setUp()
    {
        if (!class_exists('\ChildTable')) {
            $schema = <<<EOF
<database name="composite_number_range_test">
    <table name="parent_table">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="name" type="VARCHAR" required="false" />
    </table>
    <table name="child_table">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="name" type="VARCHAR" size="50" required="false" />
        <behavior name="\Finanzcheck\CompositeNumberRange\CompositeNumberRangeBehavior">
            <parameter name="foreignTable" value="parent_table"/>
        </behavior>
    </table>
</database>
EOF;

            $builder = new QuickBuilder();
            $builder->setPlatform(new MysqlPlatform());
            $builder->setSchema($schema);
            $builder->build(
                'mysql:host=127.0.0.1;dbname=' . getenv('DB_NAME'),
                getenv('DB_USER'),
                getenv('DB_PASS'),
                new MysqlAdapter()
            );
        }

        $this->parent = new \ParentTable();
        $this->parent->setName('test');
        $this->parent->save();

        $this->parent2 = new \ParentTable();
        $this->parent2->setName('test2');
        $this->parent2->save();


        \ChildTableQuery::create()->deleteAll();
        \ParentTableSequenceQuery::create()->deleteAll();
    }

    public function testClassesAreCreatedCorrectly()
    {
        $this->assertTrue(class_exists('\ChildTable'));
        $this->assertTrue(class_exists('\ChildTableQuery'));
        $this->assertTrue(class_exists('\ParentTable'));
        $this->assertTrue(class_exists('\ParentTableQuery'));
        $this->assertTrue(class_exists('\ParentTableSequence'));
        $this->assertTrue(class_exists('\ParentTableSequenceQuery'));
    }

    /**
     * @depends testClassesAreCreatedCorrectly
     */
    public function testColumnsAreCreatedCorrectly()
    {
        $entity = new \ChildTable();

        $this->assertTrue(method_exists($entity, 'setParentTable'));
        $this->assertTrue(method_exists($entity, 'setParentTableId'));
        $this->assertTrue(method_exists($entity, 'setParentTableChildTableId'));

        $sequenceEntity = new \ParentTableSequence();

        $this->assertTrue(method_exists($sequenceEntity, 'setTableName'));
        $this->assertTrue(method_exists($sequenceEntity, 'setParentTableId'));
        $this->assertTrue(method_exists($sequenceEntity, 'setParentTableMaxSequenceId'));
    }

    /**
     * @depends testColumnsAreCreatedCorrectly
     */
    public function testInsertOnChildTableCreatedParentTableSequenceRow()
    {
        $parentId = $this->parent->getId();

        $child = new \ChildTable();
        $child->setName('test');
        $child->setParentTableId($parentId);
        $child->save();

        /** @var ObjectCollection $sequences */
        $sequences = \ParentTableSequenceQuery::create()->find();
        $this->assertEquals(1, $sequences->count());
        $sequence = $sequences->getFirst();

        $this->assertNotNull($sequence);
        $this->assertEquals('child_table', $sequence->getTableName());
        $this->assertEquals($parentId, $sequence->getParentTableId());
        $this->assertEquals($child->getParentTableChildTableId(), $sequence->getParentTableMaxSequenceId());
    }

    /**
     * @depends testInsertOnChildTableCreatedParentTableSequenceRow
     */
    public function testInsertSecondRowUpdatesParentTableSequenceRow()
    {
        $parentId = $this->parent->getId();

        $child1 = new \ChildTable();
        $child1->setName('test1');
        $child1->setParentTableId($parentId);
        $child1->save();

        $child2 = new \ChildTable();
        $child2->setName('test2');
        $child2->setParentTableId($parentId);
        $child2->save();

        /** @var ObjectCollection $sequences */
        $sequences = \ParentTableSequenceQuery::create()->find();
        $this->assertEquals(1, $sequences->count());
        $sequence = $sequences->getFirst();

        $this->assertNotNull($sequence);
        $this->assertEquals('child_table', $sequence->getTableName());
        $this->assertEquals($parentId, $sequence->getParentTableId());
        $this->assertEquals($child2->getParentTableChildTableId(), $sequence->getParentTableMaxSequenceId());
    }

    /**
     * @depends testInsertSecondRowUpdatesParentTableSequenceRow
     */
    public function testInsertThirdRowWithDifferentParentTableIdCreatesAnotherParentTableSequenceRow()
    {
        $parentId = $this->parent->getId();
        $parent2Id = $this->parent2->getId();

        $child1 = new \ChildTable();
        $child1->setName('test1');
        $child1->setParentTableId($parentId);
        $child1->save();

        $child2 = new \ChildTable();
        $child2->setName('test2');
        $child2->setParentTableId($parent2Id);
        $child2->save();

        /** @var ObjectCollection $sequences */
        $sequences = \ParentTableSequenceQuery::create()->orderByParentTableId()->find();
        $this->assertEquals(2, $sequences->count());
        $sequence = $sequences->getLast();

        $this->assertNotNull($sequence);
        $this->assertEquals('child_table', $sequence->getTableName());
        $this->assertEquals($parent2Id, $sequence->getParentTableId());
        $this->assertEquals($child2->getParentTableChildTableId(), $sequence->getParentTableMaxSequenceId());
    }

    /**
     * @depends testColumnsAreCreatedCorrectly
     */
    public function testNullingParentTableIdForExistingRowCreatesNewParentTableId()
    {
        $tableMapClass = \ParentTable::TABLE_MAP;
        $con = Propel::getServiceContainer()->getWriteConnection($tableMapClass::DATABASE_NAME);
        $con->exec('ALTER TABLE child_table CHANGE parent_table_id parent_table_id INT(11) DEFAULT NULL');

        $parentId = $this->parent->getId();

        $child = new \ChildTable();
        $child->setName('test');
        $child->setParentTableId($parentId);
        $child->save();

        $firstParentTableId = $child->getParentTableId();
        $this->assertGreaterThan(0, $firstParentTableId);

        $child->setParentTableId(null);
        $child->save();
        $child->reload();

        $this->assertGreaterThan($firstParentTableId, $child->getParentTableId());
    }

    public function testUsesTheLocalTableAliasParameterWhenGiven()
    {
        $schema = <<<EOF
<database name="composite_number_range_test">
    <table name="foo">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
    </table>
    <table name="bar">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <behavior name="\Finanzcheck\CompositeNumberRange\CompositeNumberRangeBehavior">
            <parameter name="foreignTable" value="foo"/>
            <parameter name="localTableAlias" value="baz"/>
        </behavior>
    </table>
</database>
EOF;

        $builder = new QuickBuilder();
        $builder->setPlatform(new MysqlPlatform());
        $builder->setSchema($schema);
        $builder->build(
            'mysql:host=127.0.0.1;dbname=' . getenv('DB_NAME'),
            getenv('DB_USER'),
            getenv('DB_PASS'),
            new MysqlAdapter()
        );

        $this->assertTrue(class_exists('\Bar'));
        $this->assertTrue(class_exists('\BarQuery'));
        $this->assertTrue(class_exists('\Foo'));
        $this->assertTrue(class_exists('\FooQuery'));
        $this->assertTrue(class_exists('\FooSequence'));
        $this->assertTrue(class_exists('\FooSequenceQuery'));

        $entity = new \Bar();

        $this->assertTrue(method_exists($entity, 'setFoo'));
        $this->assertTrue(method_exists($entity, 'setFooId'));
        $this->assertTrue(method_exists($entity, 'setFooBazId'));

        $sequenceEntity = new \FooSequence();

        $this->assertTrue(method_exists($sequenceEntity, 'setTableName'));
        $this->assertTrue(method_exists($sequenceEntity, 'setFooId'));
        $this->assertTrue(method_exists($sequenceEntity, 'setFooMaxSequenceId'));

        $foo = new \Foo();
        $foo->save();

        $entity->setFoo($foo);
        $entity->save();

        $this->assertEquals($foo->getId(), $entity->getFooBazId());
    }
}
 
