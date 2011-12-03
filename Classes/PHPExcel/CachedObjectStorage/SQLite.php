<?php
/**
 * PHPExcel
 *
 * Copyright (c) 2006 - 2010 PHPExcel
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category   PHPExcel
 * @package    PHPExcel_CachedObjectStorage
 * @copyright  Copyright (c) 2006 - 2010 PHPExcel (http://www.codeplex.com/PHPExcel)
 * @license    http://www.gnu.org/licenses/old-licenses/lgpl-2.1.txt	LGPL
 * @version    ##VERSION##, ##DATE##
 */


/**
 * PHPExcel_CachedObjectStorage_SQLite
 *
 * @category   PHPExcel
 * @package    PHPExcel_CachedObjectStorage
 * @copyright  Copyright (c) 2006 - 2010 PHPExcel (http://www.codeplex.com/PHPExcel)
 */
class PHPExcel_CachedObjectStorage_SQLite extends PHPExcel_CachedObjectStorage_CacheBase implements PHPExcel_CachedObjectStorage_ICache {

	private $_TableName = null;
	private $_DBHandle = null;

	private function _storeData() {
		if ($this->_currentCellIsDirty) {
			$this->_currentObject->detach();

			$this->_DBHandle->queryExec("INSERT OR REPLACE INTO kvp_".$this->_TableName." VALUES('".$this->_currentObjectID."','".sqlite_escape_string(serialize($this->_currentObject))."')");
			$this->_currentCellIsDirty = false;
		}
		$this->_currentObjectID = $this->_currentObject = null;
	}	//	function _storeData()


    /**
     *	Add or Update a cell in cache identified by coordinate address
     *
     *	@param	string			$pCoord		Coordinate address of the cell to update
     *	@param	PHPExcel_Cell	$cell		Cell to update
	 *	@return	void
     *	@throws	Exception
     */
	public function addCacheData($pCoord, PHPExcel_Cell $cell) {
		if (($pCoord !== $this->_currentObjectID) && ($this->_currentObjectID !== null)) {
			$this->_storeData();
		}

		$this->_currentObjectID = $pCoord;
		$this->_currentObject = $cell;
		$this->_currentCellIsDirty = true;

		return $cell;
	}	//	function addCacheData()


    /**
     * Get cell at a specific coordinate
     *
     * @param 	string 			$pCoord		Coordinate of the cell
     * @throws 	Exception
     * @return 	PHPExcel_Cell 	Cell that was found, or null if not found
     */
	public function getCacheData($pCoord) {
		if ($pCoord === $this->_currentObjectID) {
			return $this->_currentObject;
		}
		$this->_storeData();

		$query = "SELECT value FROM kvp_".$this->_TableName." WHERE id='".$pCoord."'";
		$cellResultSet = $this->_DBHandle->query($query,SQLITE_ASSOC);
		if ($cellResultSet->numRows() == 0) {
			//	Return null if requested entry doesn't exist in cache
			return null;
		}

		//	Set current entry to the requested entry
		$this->_currentObjectID = $pCoord;

		$cellResult = $cellResultSet->fetchSingle();
		$this->_currentObject = unserialize($cellResult);
		//	Re-attach the parent worksheet
		$this->_currentObject->attach($this->_parent);

		//	Return requested entry
		return $this->_currentObject;
	}	//	function getCacheData()


	/**
	 *	Is a value set for an indexed cell?
	 *
	 *	@param	string		$pCoord		Coordinate address of the cell to check
	 *	@return	boolean
	 */
	public function isDataSet($pCoord) {
		if ($pCoord === $this->_currentObjectID) {
			return true;
		}

		//	Check if the requested entry exists in the cache
		$query = "SELECT id FROM kvp_".$this->_TableName." WHERE id='".$pCoord."'";
		$cellResultSet = $this->_DBHandle->query($query,SQLITE_ASSOC);
		if ($cellResultSet->numRows() == 0) {
			//	Return null if requested entry doesn't exist in cache
			return false;
		}
		return true;
	}	//	function isDataSet()


    /**
     *	Delete a cell in cache identified by coordinate address
     *
     *	@param	string			$pCoord		Coordinate address of the cell to delete
     *	@throws	Exception
     */
	public function deleteCacheData($pCoord) {
		if ($pCoord === $this->_currentObjectID) {
			$this->_currentObject->detach();
			$this->_currentObjectID = $this->_currentObject = null;
		}

		//	Check if the requested entry exists in the cache
		$query = "DELETE FROM kvp_".$this->_TableName." WHERE id='".$pCoord."'";
		$this->_DBHandle->queryExec($query);

		$this->_currentCellIsDirty = false;
	}	//	function deleteCacheData()


	/**
	 *	Get a list of all cell addresses currently held in cache
	 *
	 *	@return	array of string
	 */
	public function getCellList() {
		$query = "SELECT id FROM kvp_".$this->_TableName;
		$cellIdsResult = $this->_DBHandle->unbufferedQuery($query,SQLITE_ASSOC);

		$cellKeys = array();
		foreach($cellIdsResult as $row) {
			$cellKeys[] = $row['id'];
		}

		return $cellKeys;
	}	//	function getCellList()


	/**
	 *	Clone the cell collection
	 *
	 *	@return	void
	 */
	public function copyCellCollection(PHPExcel_Worksheet $parent) {
		//	Get a new id for the new table name
		$tableName = str_replace('.','_',$this->_getUniqueID());
		$this->_DBHandle->queryExec('CREATE TABLE kvp_'.$tableName.' (id VARCHAR(12) PRIMARY KEY, value BLOB)
		                                       AS SELECT * FROM kvp_'.$this->_TableName);

		//	Copy the existing cell cache file
		$this->_TableName = $tableName;
	}	//	function copyCellCollection()


	public function unsetWorksheetCells() {
		if(!is_null($this->_currentObject)) {
			$this->_currentObject->detach();
			$this->_currentObject = $this->_currentObjectID = null;
		}
		//	detach ourself from the worksheet, so that it can then delete this object successfully
		$this->_parent = null;

		//	Close down the temporary cache file
		$this->__destruct();
	}	//	function unsetWorksheetCells()


	public function __construct(PHPExcel_Worksheet $parent) {
		parent::__construct($parent);
		if (is_null($this->_DBHandle)) {
			$this->_TableName = str_replace('.','_',$this->_getUniqueID());
			$_DBName = ':memory:';

			$this->_DBHandle = new SQLiteDatabase($_DBName);
			$this->_DBHandle->queryExec('CREATE TABLE kvp_'.$this->_TableName.' (id VARCHAR(12) PRIMARY KEY, value BLOB)');
		}
	}	//	function __construct()


	public function __destruct() {
		$this->_DBHandle = null;
	}	//	function __destruct()

}