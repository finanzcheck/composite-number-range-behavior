<?php

namespace Finanzcheck\CompositeNumberRange\Platform;

use Finanzcheck\CompositeNumberRange\CompositeNumberRangeBehavior;
use Propel\Generator\Model\Diff\TableDiff;
use Propel\Generator\Model\Table;
use Propel\Generator\Platform\MysqlPlatform as BaseMysqlPlatform;

class MysqlPlatform extends BaseMysqlPlatform
{
    const BEHAVIOR_NAME = '\Finanzcheck\CompositeNumberRange\CompositeNumberRangeBehavior';

    /**
     * Returns the actual trigger name in the database. Handy if you don't have the behavior definition
     * on a table.
     *
     * @param Table $table
     *
     * @return string|null
     */
    protected function getExistingTriggerNames(Table $table)
    {
        $con = $this->getConnection();

echo "Checking the {$table->getName()} \n";

        $sql = "SHOW TRIGGERS WHERE `Table` = ? AND `Trigger` LIKE 'set%Id'";
        $stmt = $con->prepare($sql);
        $stmt->execute([$table->getName()]);
        $ret = $stmt->fetchAll();

        $names = [];
        foreach($ret as $trigger) {
            $names []= $trigger['Trigger'];
        }

        return $names;
    }

    /**
     * {@inheritdoc}
     */
    public function getModifyTableDDL(TableDiff $tableDiff)
    {
        $ret = parent::getModifyTableDDL($tableDiff);

        $fromTable = $tableDiff->getFromTable();
        $toTable = $tableDiff->getToTable();

        $hasTrigger = $this->hasTriggers($fromTable);

        // if from-table has a trigger but to-table don't need it anymore, then drop it
        $needDrop = $hasTrigger && !$this->hasCompositeNumberRangeBehavior($toTable);

        // if from-table has no trigger but to-table wants one, create it
        $needCreate = !$hasTrigger && $this->hasCompositeNumberRangeBehavior($toTable);

        switch (true) {
            case $needCreate:
                $ret .= $this->createDropTriggersDDL($toTable);
                $ret .= $this->createTriggersDDL($toTable);
                break;
            case $needDrop:
                $ret .= $this->createDropTriggersDDL($toTable);
                break;
        }

        return $ret;
    }


    /**
     * Returns true if our trigger for given table exists.
     *
     * @param Table $table
     *
     * @return bool
     */
    protected function hasTriggers(Table $table)
    {
        return count($this->getExistingTriggerNames($table)) == 2;
    }

    /**
     * @param Table $table
     *
     * @return bool
     */
    protected function hasCompositeNumberRangeBehavior(Table $table)
    {
        if ($table->hasBehavior(self::BEHAVIOR_NAME)) {

            if ($table->hasBehavior('concrete_inheritance')) {
                // we're a child in a concrete inheritance
                $parentTableName = $table->getBehavior('concrete_inheritance')->getParameter('extends');
                $parentTable = $table->getDatabase()->getTable($parentTableName);

                if ($parentTable->hasBehavior(self::BEHAVIOR_NAME)) {
                    //we're a child of a concrete inheritance structure, so we're going to skip this
                    //round here because this behavior has also been attached by the parent table.
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getAddTableDDL(Table $table)
    {
        $ret = parent::getAddTableDDL($table);

        if ($this->hasCompositeNumberRangeBehavior($table)) {
            $ret .= $this->createTriggersDDL($table);
        }

        return $ret;
    }

    /**
     * Returns the DQL to remove a trigger.
     *
     * @param Table $table
     *
     * @return string
     */
    protected function createDropTriggersDDL(Table $table)
    {
        $triggerNames = $this->getExistingTriggerNames($table);

        $sql = "";
        foreach($triggerNames as $triggerName) {
            $sql .= "DROP TRIGGER IF EXISTS $triggerName;\n";
        }
        return $sql;
    }

    /**
     * Returns the trigger name.
     *
     * @param Table $table
     *
     * @return string
     */
    protected function getTriggerNames(Table $table)
    {
        /** @var CompositeNumberRangeBehavior $behavior */
        $behavior = $table->getBehavior(self::BEHAVIOR_NAME);
        $compositeKeyColumnName = $behavior->getCompositeKeyColumnName();
        $triggerColumnName = ucwords($compositeKeyColumnName, '_');

        $insertTrigger = str_replace(' ', '', ucwords(str_replace('_', ' ', 'set' . $triggerColumnName)));
        $updateTrigger = str_replace(' ', '', ucwords(str_replace('_', ' ', 'setOnUpdate' . $triggerColumnName)));

        return [ $insertTrigger, $updateTrigger ];
    }

    /**
     * Returns the DQL to create a new trigger.
     *
     * @param Table $table
     *
     * @return string
     */
    protected function createTriggersDDL(Table $table)
    {

        /** @var CompositeNumberRangeBehavior $behavior */
        $behavior = $table->getBehavior(self::BEHAVIOR_NAME);
        $foreignTableName = $behavior->getForeignTable();
        $triggerNames = $this->getTriggerNames($table);
        $tableName = $table->getName();
        $tableNameOrAlias = $behavior->getLocalTableName();
        $compositeKeyColumnName = $behavior->getCompositeKeyColumnName();

        $sql = "
DELIMITER $;

CREATE TRIGGER {$triggerNames[0]}
BEFORE INSERT ON ${tableName}
FOR EACH ROW
BEGIN
    INSERT INTO ${foreignTableName}_sequence (
        table_name, ${foreignTableName}_id, ${foreignTableName}_max_sequence_id
    ) VALUES (
        '${tableNameOrAlias}', NEW.${foreignTableName}_id, LAST_INSERT_ID(1)
    ) ON DUPLICATE KEY
        UPDATE ${foreignTableName}_max_sequence_id = LAST_INSERT_ID(${foreignTableName}_max_sequence_id +1);

    SET NEW.${compositeKeyColumnName} = LAST_INSERT_ID();
END

DELIMITER ;

DELIMITER $;

CREATE TRIGGER {$triggerNames[1]}
BEFORE UPDATE ON ${tableName}
FOR EACH ROW
BEGIN
    IF NEW.${compositeKeyColumnName} IS NULL OR NEW.${compositeKeyColumnName} = 0 THEN
        INSERT INTO ${foreignTableName}_sequence (
            table_name, ${foreignTableName}_id, ${foreignTableName}_max_sequence_id
        ) VALUES (
            '${tableNameOrAlias}', NEW.${foreignTableName}_id, LAST_INSERT_ID(1)
        ) ON DUPLICATE KEY
            UPDATE ${foreignTableName}_max_sequence_id = LAST_INSERT_ID(${foreignTableName}_max_sequence_id +1);
    
        SET NEW.${compositeKeyColumnName} = LAST_INSERT_ID();
    END IF;
END

DELIMITER ;
";

        return $sql;
    }

} 
