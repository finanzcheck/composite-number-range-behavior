<?php

namespace Propel\Tests\Generator\Behavior\CompositeNumberRange;

use FelixWillmann\CompositeNumberRange\Platform\MysqlPlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Adapter\Pdo\MysqlAdapter;
use Propel\Runtime\Collection\ObjectCollection;
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
        <behavior name="\FelixWillmann\CompositeNumberRange\CompositeNumberRangeBehavior">
            <parameter name="foreignTable" value="parent_table"/>
        </behavior>
    </table>
</database>
EOF;

            $builder = new QuickBuilder();
            $builder->setPlatform(new MysqlPlatform());
            $builder->setSchema($schema);
            $builder->build('mysql:host=127.0.0.1;dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'), new MysqlAdapter());
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
}
 
