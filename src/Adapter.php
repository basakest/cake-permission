<?php

namespace CasbinAdapter\Cake;

use Casbin\Exceptions\CasbinException;
use Casbin\Persist\Adapter as AdapterContract;
use Casbin\Persist\BatchAdapter as BatchAdapterContract;
use Casbin\Persist\UpdatableAdapter as UpdatableAdapterContract;
use Casbin\Persist\FilteredAdapter as FilteredAdapterContract;
use Casbin\Model\Model;
use Casbin\Persist\Adapters\Filter;
use Casbin\Exceptions\InvalidFilterTypeException;
use Casbin\Persist\AdapterHelper;
use Cake\ORM\TableRegistry;
use CasbinAdapter\Cake\Model\Table\CasbinRuleTable;

/**
 * Adapter.
 *
 * @author techlee@qq.com
 */
class Adapter implements AdapterContract, BatchAdapterContract, UpdatableAdapterContract, FilteredAdapterContract
{
    use AdapterHelper;

    protected $table;

    /**
     * @var bool
     */
    private $filtered = false;

    public function __construct()
    {
        $this->table = $this->getTable();
    }

    public function savePolicyLine($ptype, array $rule)
    {
        $col['ptype'] = $ptype;
        foreach ($rule as $key => $value) {
            $col['v' . strval($key)] = $value;
        }

        $entity = $this->table->newEntity($col);
        $this->table->save($entity);
    }

    public function loadPolicy($model): void
    {
        $rows = $this->table->find();

        foreach ($rows as $row) {
            $array = $row->toArray();
            $array = array_filter($array, function ($value) {
                return !is_null($value) && $value !== '';
            });
            $line = implode(', ', array_slice(array_values($array), 1));
            $this->loadPolicyLine(trim($line), $model);
        }
    }

    public function savePolicy($model): void
    {
        foreach ($model['p'] as $ptype => $ast) {
            foreach ($ast->policy as $rule) {
                $this->savePolicyLine($ptype, $rule);
            }
        }

        foreach ($model['g'] as $ptype => $ast) {
            foreach ($ast->policy as $rule) {
                $this->savePolicyLine($ptype, $rule);
            }
        }
    }

    public function addPolicy($sec, $ptype, $rule): void
    {
        $this->savePolicyLine($ptype, $rule);
    }

    public function removePolicy($sec, $ptype, $rule): void
    {
        $entity = $this->table->newEmptyEntity();

        foreach ($rule as $key => $value) {
            $entity->set('v'.strval($key), $value);
        }

        $this->table->delete($entity);
    }

    public function removeFilteredPolicy($sec, $ptype, $fieldIndex, ...$fieldValues) :void
    {
        throw new CasbinException('not implemented');
    }

    /**
     * Adds a policy rules to the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $rules
     */
    public function addPolicies(string $sec, string $ptype, array $rules): void
    {
        $cols = [];

        foreach ($rules as $rule) {
            $temp['ptype'] = $ptype;
            foreach ($rule as $key => $value) {
                $temp['v' . strval($key)] = $value;
            }
            $cols[] = $temp;
            $temp = [];
        }
        $entities = $this->table->newEntities($cols);
        $this->table->saveMany($entities);
    }

    /**
     * Removes policy rules from the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $rules
     */
    public function removePolicies(string $sec, string $ptype, array $rules): void
    {
        $this->table->getConnection()->transactional(function () use ($ptype, $rules) {
            foreach ($rules as $rule) {
                $entity = $this->table->find();

                foreach ($rule as $key => $value) {
                    $entity->where(['v' . strval($key) => $value]);
                }
                $entity = $entity->first();
                $this->table->delete($entity, ['atomic' => false]);
            }
        });
    }

    /**
     * Updates a policy rule from storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[] $oldRule
     * @param string[] $newPolicy
     */
    public function updatePolicy(string $sec, string $ptype, array $oldRule, array $newPolicy): void
    {
        $entity = $this->table->find()->where(['ptype' => $ptype]);
        foreach ($oldRule as $k => $v) {
            $entity->where(['v' . $k => $v]);
        }
        $first = $entity->first();

        foreach ($newPolicy as $k => $v) {
            $key = 'v' . $k;
            $first->$key = $v;
        }
        $this->table->save($first);
    }

    /**
     * UpdatePolicies updates some policy rules to storage, like db, redis.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $oldRules
     * @param string[][] $newRules
     * @return void
     */
    public function updatePolicies(string $sec, string $ptype, array $oldRules, array $newRules): void
    {
        $this->table->getConnection()->transactional(function () use ($sec, $ptype, $oldRules, $newRules) {
            foreach ($oldRules as $i => $oldRule) {
                $this->updatePolicy($sec, $ptype, $oldRule, $newRules[$i]);
            }
        });
    }

    /**
     * UpdateFilteredPolicies deletes old rules and adds new rules.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $newPolicies
     * @param integer $fieldIndex
     * @param string ...$fieldValues
     * @return array
     */
    public function updateFilteredPolicies(string $sec, string $ptype, array $newPolicies, int $fieldIndex, string ...$fieldValues): array
    {
        $where['ptype'] = $ptype;

        foreach ($fieldValues as $fieldValue) {
            $suffix = $fieldIndex++;
            if (!is_null($fieldValue) && $fieldValue !== '') {
                $where['v' . $suffix] = $fieldValue;
            }
        }

        $newP = [];
        $oldP = [];
        foreach ($newPolicies as $newRule) {
            $col['ptype'] = $ptype;
            foreach ($newRule as $key => $value) {
                $col['v' . strval($key)] = $value;
            }
            $newP[] = $col;
        }

        $this->table->getConnection()->transactional(function () use ($where, $newP, &$oldP) {
            $oldRules = $this->table->find()->where($where);
            $oldP = $oldRules->all()->toArray();

            foreach ($oldP as &$item) {
                unset($item->id);
                $item = $item->toArray();
                $item = array_filter($item, function ($value) {
                    return !is_null($value) && $value !== '';
                });
                unset($item['ptype']);
            }
            
            $oldRules->delete()->execute();

            $entities = $this->table->newEntities($newP);
            $this->table->saveMany($entities);
        });

        // return deleted rules
        return $oldP;
    }

    /**
     * Loads only policy rules that match the filter.
     *
     * @param Model $model
     * @param mixed $filter
     */
    public function loadFilteredPolicy(Model $model, $filter): void
    {
        $entity = $this->table->find();
        
        if (is_string($filter)) {
            $entity = $entity->epilog('WHERE ' . $filter);
        } elseif ($filter instanceof Filter) {
            foreach ($filter->p as $k => $v) {
                $where[$v] = $filter->g[$k];
                $entity = $entity->where([$v => $filter->g[$k]]);
            }
        } elseif ($filter instanceof \Closure) {
            $entity = $entity->where($filter);
        } else {
            throw new InvalidFilterTypeException('invalid filter type');
        }
        $rows = $entity->all();
        
        foreach ($rows as $row) {
            unset($row->id);
            $row = $row->toArray();
            $row = array_filter($row, function ($value) {
                return !is_null($value) && $value !== '';
            });
            $line = implode(', ', array_filter($row, function ($val) {
                return '' != $val && !is_null($val);
            }));
            $this->loadPolicyLine(trim($line), $model);
        }
        $this->setFiltered(true);
    }

    /**
     * Returns true if the loaded policy has been filtered.
     *
     * @return bool
     */
    public function isFiltered(): bool
    {
        return $this->filtered;
    }

    /**
     * Sets filtered parameter.
     *
     * @param bool $filtered
     */
    public function setFiltered(bool $filtered): void
    {
        $this->filtered = $filtered;
    }

    protected function getTable()
    {
        return  TableRegistry::getTableLocator()->get('CasbinRule');
    }
}
