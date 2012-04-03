<?php
/**
 * This file is part of
 * Kimai - Open Source Time Tracking // http://www.kimai.org
 * (c) 2006-2012 Kimai-Development-Team
 *
 * Kimai is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; Version 3, 29 June 2007
 *
 * Kimai is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Kimai; If not, see <http://www.gnu.org/licenses/>.
 */
require_once('mysqlDatabaseLayer.class.php');
/**
 * Provides the database layer for remote API calls.
 * This was implemented due to the bad maintainability of MySQL and PDO Classes.
 * This class serves as a bridge and currently ONLY for API calls.
 *
 * @author Kevin Papst
 * @author Alexander Bauer
 */
class ApiDatabase {
	private $kga = null;
	private $dbLayer = null;
	private $conn = null;
	
	public function __construct($kga) {
		$odlDatabaseLayer = new MySQLDatabaseLayer($kga);
		$odlDatabaseLayer->connect($kga['server_hostname'], $kga['server_database'], $kga['server_username'], $kga['server_password'], $kga['utf8'], $kga['server_type']);
		
		$this->kga = $kga;
		$this->dbLayer = $odlDatabaseLayer;
		$this->conn = $this->dbLayer->getConnection();
	}
	
	public function __call($fnName, $arguments) {
		return call_user_func_array(array($this->dbLayer, $fnName), $arguments);
	}
	
	
	/**********
	 * Timesheet
	 */
	
	/**
  	 * returns timesheet for specific user as multidimensional array
	 * @param integer $user ID of user in table usr
	 * @param integer $in start of timespace in unix seconds
	 * @param integer $out end of timespace in unix seconds
	 * @param integer $filterCleared where -1 (default) means no filtering, 0 means only not cleared entries, 1 means only cleared entries
	 * @param 
	 * @return array
	 * @author Alexander Bauer
	 */
	public function get_arr_zef($in, $out, $users = null, $customers = null, $projects = null, $events = null, $reverse_order = false, $filterCleared = null, $startRows = 0, $limitRows = 0, $countOnly = false) {
		if (!is_numeric($filterCleared)) {
			$filterCleared = $this->kga['conf']['hideClearedEntries']-1; // 0 gets -1 for disabled, 1 gets 0 for only not cleared entries
		}
		
		$in = MySQL::SQLValue($in, MySQL::SQLVALUE_NUMBER);
		$out = MySQL::SQLValue($out, MySQL::SQLVALUE_NUMBER);
		$filterCleared = MySQL::SQLValue($filterCleared , MySQL::SQLVALUE_NUMBER);
      
      	$table = $this->getZefTable();
		$projectTable = $this->getProjectTable();
		$customerTable = $this->getCustomerTable();
		$userTable = $this->getUserTable();
		$statusTable = $this->getStatusTable();
		$eventTable = $this->getEventTable();
      	$whereClauses = $this->zef_whereClausesFromFilters($users, $customers, $projects, $events);

		if (isset($this->kga['customer'])) {
			$whereClauses[] = "$projectTable.pct_internal = 0";
		}

		if (!empty($in)) {
        	$whereClauses[]="(zef_in >= $in)";
		}
		if (!empty($out)) {
        	$whereClauses[]="zef_out <= $out || zef_out = 0";
		}
	
		if ($filterCleared > -1) {
        	$whereClauses[] = "zef_cleared = $filterCleared";
		}
      
		if(!empty($limitRows)) {
			$startRows = (int)$startRows;
			$limit = "LIMIT $startRows, $limitRows";
		} else {
			$limit = '';
		}
      
		$select = "SELECT zef_ID, zef_in, zef_out, zef_time, zef_rate, zef_fixed_rate, zef_budget, zef_approved, status, zef_billable,
                       zef_pctID, zef_evtID, zef_usrID, pct_ID, knd_name, pct_kndID, evt_name, pct_comment, pct_name,
                       zef_location, zef_trackingnr, zef_description, zef_comment, zef_comment_type, usr_name, usr_alias, zef_cleared";
      
		if($countOnly) {
			$select = "SELECT COUNT(*) AS total";
      		$limit = "";
      	}
		                
		$query = "$select
                FROM $table
                JOIN $projectTable ON zef_pctID = pct_ID
                JOIN $customerTable ON pct_kndID = knd_ID
                JOIN $userTable ON zef_usrID = usr_ID
                JOIN $statusTable ON zef_status = status_id
                JOIN $eventTable ON evt_ID = zef_evtID "
                .(count($whereClauses)>0?" WHERE ":" ").implode(" AND ",$whereClauses).
                ' ORDER BY zef_in '.($reverse_order?'ASC ':'DESC ') . $limit.';';
      
		$this->conn->Query($query);
		
      
		if($countOnly) {
			$this->conn->MoveFirst();
      		$row = $this->conn->Row();
      		return $row->total;
      	}

      	$arr = array();
		$this->conn->MoveFirst();
		while (!$this->conn->EndOfSeek()) {
			$row = $this->conn->Row();
			$rowArray = (array)$row;
			if ($row->zef_out != 0) {
				// only calculate time after recording is complete
				$rowArray['zef_time'] = $rowArray['zef_out'] - $rowArray['zef_in'];
				$rowArray['zef_duration'] = Format::formatDuration($rowArray['zef_time']);
                $rowArray['wage_decimal'] = $rowArray['zef_time'] / 3600 * $row->zef_rate;
				$rowArray['wage'] = sprintf("%01.2f", $rowArray['wage_decimal']);
			}
			$arr[] = $rowArray;
		}
		
		return $arr;
	}
	
	/**
	 * @see zef_create_record 
	 * @param array $data
	 */
	public function zef_add_record(Array $data) {
		$data = $this->clean_data($data);
		$values = array();
		
		$values ['zef_in'] = MySQL::SQLValue($data['zef_in'], MySQL::SQLVALUE_NUMBER );
		$values ['zef_out'] = MySQL::SQLValue($data['zef_out'], MySQL::SQLVALUE_NUMBER );
		$values ['zef_time'] = MySQL::SQLValue($data['zef_time'], MySQL::SQLVALUE_NUMBER );
		$values ['zef_usrID'] = MySQL::SQLValue($data['zef_usrID'], MySQL::SQLVALUE_NUMBER );
      	$values ['zef_pctID'] = MySQL::SQLValue($data['zef_pctID'], MySQL::SQLVALUE_NUMBER );
      	$values ['zef_evtID'] = MySQL::SQLValue($data['zef_evtID'], MySQL::SQLVALUE_NUMBER );
		
		if(isset($data ['zef_description'])) {
			$values ['zef_description'] = MySQL::SQLValue($data ['zef_description']);	
		}
		
		if(isset($data ['zef_comment'])) {
      		$values ['zef_comment'] = MySQL::SQLValue($data ['zef_comment']);
		}
		if(isset($data ['zef_comment_type'])) {
			$values ['zef_comment_type'] = MySQL::SQLValue($data ['zef_comment_type'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['zef_cleared'])) {
			$values ['zef_cleared'] = MySQL::SQLValue($data ['zef_cleared'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['zef_location'])) {
      		$values ['zef_location'] = MySQL::SQLValue($data ['zef_location']);
		}
		if(isset($data ['zef_trackingnr'])) {
        	$values ['zef_trackingnr'] = MySQL::SQLValue($data ['zef_trackingnr']);
		}
		if(isset($data ['zef_rate'])) {
			$values ['zef_rate'] = MySQL::SQLValue($data ['zef_rate'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['zef_fixed_rate'])) {
			$values ['zef_fixed_rate'] = MySQL::SQLValue($data ['zef_fixed_rate'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['zef_budget'])) {
			$values ['zef_budget'] = MySQL::SQLValue($data ['zef_budget'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['zef_approved'])) {
			$values ['zef_approved'] = MySQL::SQLValue($data ['zef_approved'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['zef_status'])) {
			$values ['zef_status'] = MySQL::SQLValue($data ['zef_status'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['zef_billable'])) {
			$values ['zef_billable'] = MySQL::SQLValue($data ['zef_billable'], MySQL::SQLVALUE_NUMBER );
		}
	
		$table = $this->getZefTable();
		$success =  $this->conn->InsertRow($table, $values);
		if ($success) {
			return  $this->conn->GetLastInsertID();
		} else {
	        $this->logLastError('zef_add_record');
	        return false;
		}
	}
	
	/***************************************************************************************************************
	 * Expenses
	 *********************************************/
	
	/**
	 * returns single expense entry as array
	 *
	 * @param integer $id ID of entry in table exp
	 * @global array $kga kimai-global-array
	 * @return array
	 * @author sl
	 */
	public function get_entry_exp($id) {
	    $id    = MySQL::SQLValue($id   , MySQL::SQLVALUE_NUMBER);
		
	    $table = $this->getExpenseTable();
		$projectTable = $this->getProjectTable();
		$customerTable = $this->getCustomerTable();
	  	
	    $query = "SELECT * FROM $table 
	              LEFT JOIN $projectTable ON $table.exp_pctID = $projectTable.pct_ID 
	              LEFT JOIN $customerTable ON $projectTable.pct_kndID = $customerTable.knd_ID 
	              WHERE $table.exp_ID = $id LIMIT 1;";
	
	    $this->conn->Query($query);
	    return $this->conn->RowArray(0, MYSQL_ASSOC);
	}
	
	/**
	 * Returns the data of a certain expense record
	 *
	 * @param array $exp_id exp_id of the record
	 * @return array the record's data as array, false on failure
	 * @author ob
	 */
	public function exp_get_data($expId) {
		$kga = $this->kga;
	    $conn = $this->conn;
	    
	    $table = $this->getExpenseTable();
	    
	    $expId = MySQL::SQLValue($expId, MySQL::SQLVALUE_NUMBER);
	
	    if ($expId) {
	        $result = $conn->Query("SELECT * FROM $table WHERE exp_ID = " . $expId);
	    } else {
	        $result = $conn->Query("SELECT * FROM $table WHERE exp_usrID = ".$kga['usr']['usr_ID']." ORDER BY exp_ID DESC LIMIT 1");
	    }
	    
	    if (! $result) {
	      return false;
	    } else {
	        return $conn->RowArray(0,MYSQL_ASSOC);
	    }
	}
	
	/**
	 * returns expenses for specific user as multidimensional array
	 * @param integer $user ID of user in table usr
	 * @return array
	 * @author th
	 * @author Alexander Bauer
	 */
	public function get_arr_exp($start, $end, $users = null, $customers = null, $projects = null, $reverse_order=false, $filter_refundable = -1, $filterCleared = null, $startRows = 0, $limitRows = 0, $countOnly = false) {
	  	$conn = $this->conn;
	  	$kga = $this->kga;
	  
	  	if (!is_numeric($filterCleared)) {
	  		$filterCleared = $kga['conf']['hideClearedEntries']-1; // 0 gets -1 for disabled, 1 gets 0 for only not cleared entries
	  	}
  
	  	$start  = MySQL::SQLValue($start    , MySQL::SQLVALUE_NUMBER);
	  	$end = MySQL::SQLValue($end   , MySQL::SQLVALUE_NUMBER);
	  
	  	$p     = $kga['server_prefix'];
  
	  	$whereClauses = $this->exp_whereClausesFromFilters($users, $customers, $projects);
	  
	  	if (isset($kga['customer']))
	  		$whereClauses[] = "${p}pct.pct_internal = 0";
	
	  	if (!empty($start)) {
	  		$whereClauses[]="exp_timestamp >= $start";
		}
	  	if (!empty($end)) {
	  		$whereClauses[]="exp_timestamp <= $end";
		}
	  	if ($filterCleared > -1) {
	  		$whereClauses[] = "exp_cleared = $filterCleared";
		}

	  	switch ($filter_refundable) {
	  		case 0:
	  			$whereClauses[] = "exp_refundable > 0";
	  			break;
	  		case 1:
	  			$whereClauses[] = "exp_refundable <= 0";
	  			break;
	  		case -1:
	  		default:
	  			// return all expenses - refundable and non refundable
	  	}
  	
	  	if(!empty($limitRows)) {
	  		$startRows = (int)$startRows;
			$limit = "LIMIT $startRows, $limitRows";
	  	} else {
	  		$limit="";
	  	}
  	
  		$select = "SELECT exp_ID, exp_timestamp, exp_multiplier, exp_value, exp_pctID, exp_designation, exp_usrID, pct_ID,
  					knd_name, pct_kndID, pct_name, exp_comment, exp_refundable,
  					exp_comment_type, usr_name, exp_cleared";
				
  		$where = empty($whereClauses) ? '' : "WHERE ".implode(" AND ",$whereClauses);
  		$orderDirection = $reverse_order ? 'ASC' : 'DESC';
  	
	  	if($countOnly) {
	  		$select = "SELECT COUNT(*) AS total";
	  		$limit = "";
	  	}
  	 
  		$query = "$select
  			FROM ${p}exp
	  		Join ${p}pct ON exp_pctID = pct_ID
	  		Join ${p}knd ON pct_kndID = knd_ID
	  		Join ${p}usr ON exp_usrID = usr_ID 
	  		$where
	  		ORDER BY exp_timestamp $orderDirection $limit";
  	
  		$conn->Query($query);
  	
	  	// return only the number of rows, ignoring LIMIT
	  	if($countOnly) {
	  		$this->conn->MoveFirst();
	  		$row = $this->conn->Row();
	  		return $row->total;
	  	}
  	
  	
	  	$i=0;
	  	$arr = array();
	  	$conn->MoveFirst();
		// toArray();
	  	while (! $conn->EndOfSeek()) {
	  		$row = $conn->Row();
			$arr[$i] = (array)$row;
	  		$i++;
	  	}
	  
		return $arr;
	}
  
  /**
   *  Creates an array of clauses which can be joined together in the WHERE part
   *  of a sql query. The clauses describe whether a line should be included
   *  depending on the filters set.
   *
   *  This method also makes the values SQL-secure.
   *
   * @param Array list of IDs of users to include
   * @param Array list of IDs of customers to include
   * @param Array list of IDs of projects to include
   * @param Array list of IDs of events to include
   * @return Array list of where clauses to include in the query
   */
  public function exp_whereClausesFromFilters($users, $customers, $projects ) {
  
  	if (!is_array($users)) $users = array();
  	if (!is_array($customers)) $customers = array();
  	if (!is_array($projects)) $projects = array();
  
  	for ($i = 0;$i<count($users);$i++)
  		$users[$i] = MySQL::SQLValue($users[$i], MySQL::SQLVALUE_NUMBER);
  		for ($i = 0;$i<count($customers);$i++)
  			$customers[$i] = MySQL::SQLValue($customers[$i], MySQL::SQLVALUE_NUMBER);
  			for ($i = 0;$i<count($projects);$i++)
  			$projects[$i] = MySQL::SQLValue($projects[$i], MySQL::SQLVALUE_NUMBER);
  
  			$whereClauses = array();
  
  			if (count($users) > 0) {
  			$whereClauses[] = "exp_usrID in (".implode(',',$users).")";
  		}
  
  		if (count($customers) > 0) {
  		$whereClauses[] = "knd_ID in (".implode(',',$customers).")";
  		}
  
  				if (count($projects) > 0) {
  		$whereClauses[] = "pct_ID in (".implode(',',$projects).")";
  		}
  
  		return $whereClauses;
  
	}
  
	/**
	 * create exp entry 
	 *
	 * @param integer $userId
	 * @param Array $data
	 * @author sl
	 * @author Alexander Bauer
	 */
	function exp_create_record(Array $data) {
	    $conn = $this->conn;
	    $data = $this->dbLayer->clean_data($data);
	    
		
		if(isset($data ['exp_timestamp'])) {
	    	$values ['exp_timestamp']    =   MySQL::SQLValue($data['exp_timestamp'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['exp_usrID'])) {
	    	$values ['exp_usrID']        =   MySQL::SQLValue($data['exp_usrID'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['exp_pctID'])) {
	    	$values ['exp_pctID']        =   MySQL::SQLValue($data['exp_pctID'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['exp_designation'])) {
	    	$values ['exp_designation']  =   MySQL::SQLValue($data['exp_designation']);
		}
		if(isset($data ['exp_comment'])) {
	    	$values ['exp_comment']      =   MySQL::SQLValue($data['exp_comment']);
		}
		if(isset($data ['exp_comment_type'])) {
	    	$values ['exp_comment_type'] =   MySQL::SQLValue($data['exp_comment_type'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['exp_refundable'])) {
	    	$values ['exp_refundable']   =   MySQL::SQLValue($data['exp_refundable'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['exp_cleared'])) {
	    	$values ['exp_cleared']   =   MySQL::SQLValue($data['exp_cleared'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['exp_multiplier'])) {
	    	$values ['exp_multiplier']   =   MySQL::SQLValue($data['exp_multiplier'], MySQL::SQLVALUE_NUMBER );
		}
		if(isset($data ['exp_value'])) {
	    	$values ['exp_value']        =   MySQL::SQLValue($data['exp_value'], MySQL::SQLVALUE_NUMBER );
		}
		
	    $table = $this->getExpenseTable();
	    return $conn->InsertRow($table, $values);    
	} 

	/**
	 * edit exp entry 
	 *
	 * @param integer $id
	 * @param array $data
	 * @author th
	 * @author Alexander Bauer
	 */
	function exp_edit_record($id, Array $data) {
	    $conn = $this->conn;
	    $data = $this->dbLayer->clean_data($data);
	   
	    $original_array = $this->exp_get_data($id);
	    $new_array = array();
	    
	    foreach ($original_array as $key => $value) {
	        if (isset($data[$key]) == true) {
	            $new_array[$key] = $data[$key];
	        } else {
	            $new_array[$key] = $original_array[$key];
	        }
	    }
	
	    $values ['exp_pctID']        = MySQL::SQLValue($new_array ['exp_pctID']       , MySQL::SQLVALUE_NUMBER );
	    $values ['exp_designation']  = MySQL::SQLValue($new_array ['exp_designation']                          );
	    $values ['exp_comment']      = MySQL::SQLValue($new_array ['exp_comment']                              );
	    $values ['exp_comment_type'] = MySQL::SQLValue($new_array ['exp_comment_type'], MySQL::SQLVALUE_NUMBER );
	    $values ['exp_timestamp']    = MySQL::SQLValue($new_array ['exp_timestamp']   , MySQL::SQLVALUE_NUMBER );
	    $values ['exp_multiplier']   = MySQL::SQLValue($new_array ['exp_multiplier']  , MySQL::SQLVALUE_NUMBER );
	    $values ['exp_value']        = MySQL::SQLValue($new_array ['exp_value']       , MySQL::SQLVALUE_NUMBER );
	    $values ['exp_refundable']   = MySQL::SQLValue($new_array ['exp_refundable']  , MySQL::SQLVALUE_NUMBER );
		$values ['exp_cleared']   	 = MySQL::SQLValue($new_array ['exp_cleared']  , MySQL::SQLVALUE_NUMBER );
	                                   
	    $filter ['exp_ID']           = MySQL::SQLValue($id, MySQL::SQLVALUE_NUMBER);
	    $table = $this->getExpenseTable();
	    $query = MySQL::BuildSQLUpdate($table, $values, $filter);
	
	    return $conn->Query($query);
	}
  
  
  	/**
	 * delete exp entry 
	 *
	 * @param integer $usr_ID 
	 * @param integer $id -> ID of record
	 * @global array  $kga kimai-global-array
	 * @author th
	 */
	function exp_delete_record($id) {
	    $filter["exp_ID"] = MySQL::SQLValue($id, MySQL::SQLVALUE_NUMBER);
		
	    $table = $this->getExpenseTable();
	    $query = MySQL::BuildSQLDelete($table, $filter);
	    return $this->conn->Query($query);    
	} 
	
	
}
