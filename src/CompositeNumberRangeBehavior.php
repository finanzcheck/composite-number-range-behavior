<?php

namespace FelixWillmann\CompositeNumberRange;

use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Unique;

class CompositeNumberRangeBehavior extends Behavior
{
    protected $parameters = array(
        'foreignTable' => null,
        'refPhpName' => null,
        'phpName' => null,
    );

    /**
     * Gets the foreignTable parameter from config array.
     *
     * @return string
     */
    public function getForeignTable()
    {
        $table = $this->getParameter('foreignTable');

        if ($table == null) {
            $table = 'client';
        }

        return strtolower($table);
    }

    /**
     * Gets the refPhpName parameter from config array.
     *
     * @return string
     */
    protected function getRefPhpName()
    {
        $name = $this->getParameter('refPhpName');

        if ($name == null) {
            $name = Column::generatePhpName($this->getForeignTable());
        }

        return $name;
    }

    /**
     * Gets the phpName parameter from config array.
     * will only be set if !== null
     *
     * @return string
     */
    protected function getPhpName()
    {
        $name = $this->getParameter('phpName');
        return $name;
    }

    /**
     * Adds all columns, indexes, constraints and additional tables.
     */
    public function modifyTable()
    {
        $table = $this->getTable();
        $tableName = $table->getName();
        $foreignTableName = $this->getForeignTable();
        $phpName = $this->getPhpName();

        // enable reload on insert to force the model to load the trigger generated id(s)
        $table->setReloadOnInsert(true);

        $foreignIdColumnName = $foreignTableName . '_id';
        $compositeKeyColumnName = $foreignTableName . '_' . $tableName . '_id';

        if ($table->hasBehavior('concrete_inheritance')) {
            // we're a child in a concrete inheritance
            $parentTableName = $table->getBehavior('concrete_inheritance')->getParameter('extends');
            $parentTable = $table->getDatabase()->getTable($parentTableName);

            if ($parentTable->hasBehavior('\\' . __CLASS__)) {
                //we're a child of a concrete inheritance structure, so we're going to skip this
                //round here because this behavior has also been attached by the parent table.
                return;
            }
        }

        if ($table->hasColumn($foreignIdColumnName)) {
            $foreignIdColumn = $table->getColumn($foreignIdColumnName);
        } else {
            $foreignIdColumn = $table->addColumn(
                array(
                    'name' => $foreignIdColumnName,
                    'type' => 'integer',
                    'required' => true
                )
            );

            $compositeKeyForeignKeyName = $tableName . '_FK_' . $foreignIdColumnName;
            $foreignKey = new ForeignKey($compositeKeyForeignKeyName);
            $foreignKey->addReference($foreignIdColumnName, 'id');
            $foreignKey->setForeignTableCommonName($foreignTableName);
            $foreignKey->setOnUpdate(ForeignKey::CASCADE);
            $foreignKey->setOnDelete(ForeignKey::CASCADE);
            if (null !== $phpName) {
                $foreignKey->setPhpName($phpName);
            }
            $table->addForeignKey($foreignKey);

        }

        if ($table->hasColumn($compositeKeyColumnName)) {
            $compositeKeyColumn = $table->getColumn($compositeKeyColumnName);
        } else {
            $compositeKeyColumn = $table->addColumn(
                array(
                    'name' => $compositeKeyColumnName,
                    'type' => 'integer',
                    'required' => false,
                )
            );
        }

        $index = new Unique($tableName . '_UQ_' . $foreignIdColumnName . '_' . $compositeKeyColumnName);
        $index->addColumn($foreignIdColumn);
        $index->addColumn($compositeKeyColumn);
        $table->addUnique($index);

        $database = $table->getDatabase();
        $sequenceTableName = sprintf('%s_sequence', $foreignTableName);
        if (!$database->hasTable($sequenceTableName)) {
            $sequenceTable = $database->addTable(
                array(
                    'name' => $sequenceTableName,
                    'package' => $table->getPackage(),
                    'schema' => $table->getSchema(),
                    'namespace' => $table->getNamespace() ? '\\' . $table->getNamespace() : null,
                    'skipSql' => $table->isSkipSql()
                )
            );

            $sequenceTable->addColumn(
                array(
                    'name' => 'table_name',
                    'type' => 'varchar',
                    'size' => 32,
                    'required' => true,
                    'primaryKey' => true
                )
            );

            $sequenceTable->addColumn(
                array(
                    'name' => $foreignIdColumnName,
                    'type' => 'integer',
                    'required' => true,
                    'primaryKey' => true
                )
            );

            $sequenceTable->addColumn(
                array(
                    'name' => $foreignTableName . '_max_sequence_id',
                    'type' => 'integer',
                    'required' => false,
                    'default' => null
                )
            );
        }
    }
}
