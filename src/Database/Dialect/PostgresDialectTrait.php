<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Dialect;

use Cake\Database\Expression\FunctionExpression;
use Cake\Database\Schema\PostgresSchema;
use Cake\Database\SqlDialectTrait;

/**
 * Contains functions that encapsulates the SQL dialect used by Postgres,
 * including query translators and schema introspection.
 *
 * @internal
 */
trait PostgresDialectTrait
{

    use SqlDialectTrait;

    /**
     * String used to start a database identifier quoting to make it safe
     *
     * @var string
     */
    protected $_startQuote = '"';

    /**
     * String used to end a database identifier quoting to make it safe
     *
     * @var string
     */
    protected $_endQuote = '"';

    /**
     * The schema dialect class for this driver
     *
     * @var \Cake\Database\Schema\PostgresSchema
     */
    protected $_schemaDialect;

    /**
     * Distinct clause needs no transformation
     *
     * @param \Cake\Database\Query $query The query to be transformed
     * @return \Cake\Database\Query
     */
    protected function _transformDistinct($query)
    {
        return $query;
    }

    /**
     * Modifies the original insert query to append a "RETURNING *" epilogue
     * so that the latest insert id can be retrieved
     *
     * @param \Cake\Database\Query $query The query to translate.
     * @return \Cake\Database\Query
     */
    protected function _insertQueryTranslator($query)
    {
        if (!$query->clause('epilog')) {
            $query->epilog('RETURNING *');
        }

        return $query;
    }

    /**
     * Returns a dictionary of expressions to be transformed when compiling a Query
     * to SQL. Array keys are method names to be called in this class
     *
     * @return array
     */
    protected function _expressionTranslators()
    {
        $namespace = 'Cake\Database\Expression';

        return [
            $namespace . '\FunctionExpression' => '_transformFunctionExpression'
        ];
    }

    /**
     * Receives a FunctionExpression and changes it so that it conforms to this
     * SQL dialect.
     *
     * @param \Cake\Database\Expression\FunctionExpression $expression The function expression to convert
     *   to postgres SQL.
     * @return void
     */
    protected function _transformFunctionExpression(FunctionExpression $expression)
    {
        switch ($expression->getName()) {
            case 'CONCAT':
                // CONCAT function is expressed as exp1 || exp2
                $expression->setName('')->setConjunction(' ||');
                break;
            case 'DATEDIFF':
                $expression
                    ->setName('')
                    ->setConjunction('-')
                    ->iterateParts(function ($p) {
                        if (is_string($p)) {
                            $p = ['value' => [$p => 'literal'], 'type' => null];
                        } else {
                            $p['value'] = [$p['value']];
                        }

                        return new FunctionExpression('DATE', $p['value'], [$p['type']]);
                    });
                break;
            case 'CURRENT_DATE':
                $time = new FunctionExpression('LOCALTIMESTAMP', [' 0 ' => 'literal']);
                $expression->setName('CAST')->setConjunction(' AS ')->add([$time, 'date' => 'literal']);
                break;
            case 'CURRENT_TIME':
                $time = new FunctionExpression('LOCALTIMESTAMP', [' 0 ' => 'literal']);
                $expression->setName('CAST')->setConjunction(' AS ')->add([$time, 'time' => 'literal']);
                break;
            case 'NOW':
                $expression->setName('LOCALTIMESTAMP')->add([' 0 ' => 'literal']);
                break;
            case 'RAND':
                $expression->setName('RANDOM');
                break;
            case 'DATE_ADD':
                $expression
                    ->setName('')
                    ->setConjunction(' + INTERVAL')
                    ->iterateParts(function ($p, $key) {
                        if ($key === 1) {
                            $p = sprintf("'%s'", $p);
                        }

                        return $p;
                    });
                break;
            case 'DAYOFWEEK':
                $expression
                    ->setName('EXTRACT')
                    ->setConjunction(' ')
                    ->add(['DOW FROM' => 'literal'], [], true)
                    ->add([') + (1' => 'literal']); // Postgres starts on index 0 but Sunday should be 1
                break;
        }
    }

    /**
     * Get the schema dialect.
     *
     * Used by Cake\Database\Schema package to reflect schema and
     * generate schema.
     *
     * @return \Cake\Database\Schema\PostgresSchema
     */
    public function schemaDialect()
    {
        if (!$this->_schemaDialect) {
            $this->_schemaDialect = new PostgresSchema($this);
        }

        return $this->_schemaDialect;
    }

    /**
     * {@inheritDoc}
     */
    public function disableForeignKeySQL()
    {
        return 'SET CONSTRAINTS ALL DEFERRED';
    }

    /**
     * {@inheritDoc}
     */
    public function enableForeignKeySQL()
    {
        return 'SET CONSTRAINTS ALL IMMEDIATE';
    }
}
