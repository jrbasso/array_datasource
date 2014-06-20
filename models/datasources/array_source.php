<?php

App::import('Core', 'Set');

/**
 * ArraySource
 *
 * Datasource for Array
 */
class ArraySource extends Datasource {

/**
 * Version for this Data Source.
 *
 * @var string
 * @access public
 */
	var $version = '0.1';

/**
 * Description string for this Data Source.
 *
 * @var string
 * @access public
 */
	var $description = 'Array Datasource';

/**
 * List of requests ("queries")
 *
 * @var array
 * @access protected
 */
	var $_requestsLog = array();

/**
 * Base Config
 *
 * @var array
 * @access protected
 */
	var $_baseConfig = array(
		'driver' => '' // Just to avoid DebugKit warning
	);

/**
 * Default Constructor
 *
 * @param array $config options
 * @access public
 */
	function __construct($config = array()) {
		parent::__construct($config);
	}

/**
 * Returns a Model description (metadata) or null if none found.
 *
 * @param Model $model
 * @return array Show only id
 * @access public
 */
	function describe(&$model) {
		return array('id' => array());
	}

/**
 * List sources
 *
 * @param mixed $data
 * @return boolean Always false. It's not supported
 * @access public
 */
	function listSources($data = null) {
		return false;
	}

/**
 * Used to read records from the Datasource. The "R" in CRUD
 *
 * @param Model $model The model being read.
 * @param array $queryData An array of query data used to find the data you want
 * @return mixed
 * @access public
 */
	function read(&$model, $queryData = array()) {
		if (!isset($model->records) || !is_array($model->records) || empty($model->records)) {
			$this->_requestsLog[] = array(
				'query' => 'Model ' . $model->alias,
				'error' => __('No records found in model.', true),
				'affected' => 0,
				'numRows' => 0,
				'took' => 0
			);
			return array($model->alias => array());
		}
		$startTime = microtime(true);
		$data = array();
		$i = 0;
		$limit = false;
		if (is_integer($queryData['limit']) && $queryData['limit'] > 0) {
			$limit = $queryData['page'] * $queryData['limit'];
		}
		foreach ($model->records as $pos => $record) {
			// Tests whether the record will be chosen
			if (!empty($queryData['conditions'])) {
				$queryData['conditions'] = (array)$queryData['conditions'];
				foreach ($queryData['conditions'] as $field => $value) {
					if (is_string($field)) {
						if (strpos($field, ' ') === false) {
							$value = $field . ' = ' . $value;
						} else {
							// Can have LIKE, NOT, IN, ...
							$value = $field . ' ' . $value;
						}
					}
					if (preg_match('/^(\w+\.?\w+)\s+(=|!=|LIKE|IN)\s+(.*)$/', $value, $matches)) {
						$field = $matches[1];
						$value = $matches[3];
						if (strpos($field, '.') !== false) {
							list($alias, $field) = explode('.', $field, 2);
							if ($alias != $model->alias) {
								continue;
							}
						}
						switch ($matches[2]) {
							case '=':
								if (!isset($record[$field]) || $record[$field] != $value) {
									continue(3);
								}
								break;
							case '!=':
								if (isset($record[$field]) && $record[$field] == $value) {
									continue(3);
								}
								break;
							case 'LIKE':
								if (!isset($record[$field]) || strpos($record[$field], $value) === false) {
									continue(3);
								}
								break;
							case 'IN':
								$items = array();
								if (preg_match('/^\(\w+(,\s*\w+)*\)$/', $value)) {
									$items = explode(',', trim($value, '()'));
									$items = array_map('trim', $items);
								}
								if (!isset($record[$field]) || !in_array($record[$field], (array)$items)) {
									continue(3);
								}
								break;
						}
					}
				}
			}
			$data[$i] = $record;
			$i++;
			// Test limit
			if ($limit !== false && $i == $limit && empty($queryData['order'])) {
				break;
			}
		}
		if ($queryData['fields'] === 'COUNT') {
			$this->_registerLog($model, $queryData, getMicrotime() - $startTime, 1);
			if ($limit !== false) {
				$data = array_slice($data, ($queryData['page'] - 1) * $queryData['limit'], $queryData['limit'], false);
			}
			return array(array(array('count' => count($data))));
		}
		// Order
		if (!empty($queryData['order'])) {
			if (is_string($queryData['order'][0])) {
				$field = $queryData['order'][0];
				$alias = $model->alias;
				if (strpos($field, '.') !== false) {
					list($alias, $field) = explode('.', $field, 2);
				}
				if ($alias === $model->alias) {
					$sort = 'ASC';
					if (strpos($field, ' ') !== false) {
						list($field, $sort) = explode(' ', $field, 2);
					}
					$data = Set::sort($data, '{n}.' . $field, $sort);
				}
			}
		}
		// Limit
		if ($limit !== false) {
			$data = array_slice($data, ($queryData['page'] - 1) * $queryData['limit'], $queryData['limit'], false);
		}
		// Filter fields
		if (!empty($queryData['fields'])) {
			$listOfFields = array();
			foreach ($queryData['fields'] as $field) {
				if (strpos($field, '.') !== false) {
					list($alias, $field) = explode('.', $field, 2);
					if ($alias !== $model->alias) {
						continue;
					}
				}
				$listOfFields[] = $field;
			}
			foreach ($data as $id => $record) {
				foreach ($record as $field => $value) {
					if (!in_array($field, $listOfFields)) {
						unset($data[$id][$field]);
					}
				}
			}
		}
		$this->_registerLog($model, $queryData, microtime(true) - $startTime, count($data));
		if ($model->findQueryType === 'first') {
			if (!isset($data[0])) {
				return array();
			}
			return array(array($model->alias => $data[0]));
		} elseif ($model->findQueryType === 'list') {
			$newData = array();
			foreach ($data as $item) {
				$newData[] = array($model->alias => $item);
			}
			return $newData;
		}
		return array($model->alias => $data);
	}

/**
 * Returns an calculation
 *
 * @param model $model
 * @param string $type Lowercase name type, i.e. 'count' or 'max'
 * @param array $params Function parameters (any values must be quoted manually)
 * @return string Calculation method
 * @access public
 */
	function calculate(&$model, $type, $params = array()) {
		return 'COUNT';
	}

/**
 * Queries associations.  Used to fetch results on recursive models.
 *
 * @param Model $model Primary Model object
 * @param Model $linkModel Linked model that
 * @param string $type Association type, one of the model association types ie. hasMany
 * @param unknown_type $association
 * @param unknown_type $assocData
 * @param array $queryData
 * @param boolean $external Whether or not the association query is on an external datasource.
 * @param array $resultSet Existing results
 * @param integer $recursive Number of levels of association
 * @param array $stack
 */
	function queryAssociation(&$model, &$linkModel, $type, $association, $assocData, &$queryData, $external = false, &$resultSet, $recursive, $stack) {
		foreach ($resultSet as $id => $result) {
			if (!array_key_exists($model->alias, $result) || !array_key_exists($assocData['foreignKey'], $result[$model->alias])) {
				continue;
			}
			$find = $model->{$association}->find('first', array(
				'conditions' => array_merge((array)$assocData['conditions'], array($model->{$association}->primaryKey => $result[$model->alias][$assocData['foreignKey']])),
				'fields' => $assocData['fields'],
				'order' => $assocData['order']
			));
			if (empty($find)) {
				$resultSet[$id][$association] = array();
			} else {
				$resultSet[$id][$association] = $find[$association];
			}
		}
	}

/**
 * Get the query log as an array.
 *
 * @param boolean $sorted Get the queries sorted by time taken, defaults to false.
 * @param boolean $clear Clear after return logs
 * @return array Array of queries run as an array
 * @access public
 */
	function getLog($sorted = false, $clear = true) {
		if ($sorted) {
			$log = sortByKey($this->_requestsLog, 'took', 'desc', SORT_NUMERIC);
		} else {
			$log = $this->_requestsLog;
		}
		if ($clear) {
			$this->_requestsLog = array();
		}
		return array('log' => $log, 'count' => count($log), 'time' => array_sum(Set::extract('{n}.took', $log)));
	}

/**
 * Generate a log registry
 *
 * @param object $model
 * @param array $queryData
 * @param float $took
 * @param integer $numRows
 * @return void
 */
	function _registerLog(&$model, &$queryData, $took, $numRows) {
		if (!Configure::read()) {
			return;
		}
		$this->_requestsLog[] = array(
			'query' => $this->_pseudoSelect($model, $queryData),
			'error' => '',
			'affected' => 0,
			'numRows' => $numRows,
			'took' => round($took, 3)
		);
	}

/**
 * Generate a pseudo select to log
 *
 * @param object $model Model
 * @param array $queryData Query data sended by find
 * @return string Pseudo query
 * @access protected
 */
	function _pseudoSelect(&$model, &$queryData) {
		$out = '(symbolic) SELECT ';
		if (empty($queryData['fields'])) {
			$out .= '*';
		} elseif ($queryData['fields']) {
			$out .= 'COUNT(*)';
		} else {
			$out .= implode(', ', $queryData['fields']);
		}
		$out .= ' FROM ' . $model->alias;
		if (!empty($queryData['conditions'])) {
			$out .= ' WHERE';
			foreach ($queryData['conditions'] as $id => $condition) {
				if (empty($condition)) {
					continue;
				}
				if (is_string($id)) {
					if (strpos($id, ' ') !== false) {
						$condition = $id . ' ' . $condition;
					} else {
						$condition = $id . ' = ' . $condition;
					}
				}
				if (preg_match('/^(\w+\.)?\w+ /', $condition, $matches)) {
					if (!empty($matches[1]) && substr($matches[1], 0, -1) !== $model->alias) {
						continue;
					}
				}
				$out .= ' (' . $condition . ') &&';
			}
			$out = substr($out, 0, -3);
		}
		if (!empty($queryData['order'][0])) {
			$out .= ' ORDER BY ' . implode(', ', $queryData['order']);
		}
		if (!empty($queryData['limit'])) {
			$out .= ' LIMIT ' . (($queryData['page'] - 1) * $queryData['limit']) . ', ' .  $queryData['limit'];
		}
		return $out;
	}
}
?>