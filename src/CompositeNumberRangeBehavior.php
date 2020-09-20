<?php

namespace Finanzcheck\CompositeNumberRange;

use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Unique;

class CompositeNumberRangeBehavior extends Behavior
{
    protected $compositeKeyColumnName;

    protected $parameters = array(
        'foreignTable' => null,
        'compositeKeyColumnName' => null,
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

    public function getCompositeKeyColumnName()
    {
        $name = $this->getParameter('compositeKeyColumnName');
        if ($name) {
            return $name;
        }

        $table = $this->getTable();
        $tableName = $table->getName();
        $foreignTableName = $this->getForeignTable();

        return $foreignTableName . '_' . $tableName . '_id';
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

        $this->compositeKeyColumnName = $this->getCompositeKeyColumnName();

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

        if ($table->hasColumn($this->compositeKeyColumnName)) {
            $compositeKeyColumn = $table->getColumn($this->compositeKeyColumnName);
        } else {
            $compositeKeyColumn = $table->addColumn(
                array(
                    'name' => $this->compositeKeyColumnName,
                    'type' => 'integer',
                    'required' => false,
                )
            );
        }

        $index = new Unique($tableName . '_UQ_' . $foreignIdColumnName . '_' . $this->compositeKeyColumnName);
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

    public function postUpdate()
    {
        if ($this->parentHasBehaviour()) {
            return null;
        }
        return "\$this->reloadAfterCompositeNumberUpdate();";
    }

    public function objectMethods($builder)
    {
        if ($this->parentHasBehaviour()) {
            return null;
        }

        $this->builder = $builder;
        $script = '';

        $this->addReloadAfterCompositeNumberUpdate($script);

        return $script;
    }

    private function addReloadAfterCompositeNumberUpdate(&$script)
    {
        $script .= <<<EOM
/**
 * Reload object after updated composite number field
 */
public function reloadAfterCompositeNumberUpdate()
{
    \$compositeNumberField = "{$this->compositeKeyColumnName}";
    
    if (null === \$this->\$compositeNumberField) {
        \$this->reload();
    }
}
EOM;
    }

    private function parentHasBehaviour()
    {
        $table = $this->getTable();
        if ($table->hasBehavior('concrete_inheritance')) {
            $parentTableName = $table->getBehavior('concrete_inheritance')->getParameter('extends');
            $parentTable = $table->getDatabase()->getTable($parentTableName);
            if ($parentTable->hasBehavior('\\' . __CLASS__)) {
                return true;
            }
        }
        return false;
    }

}
