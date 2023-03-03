<?php

namespace Stanford\EnhancedSMSConversation;

use \Exception;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

/**
 *

 * Change logs:
        select reml.*,
        remlp1.value as 'parent_log_id',
        remlp2.value as 'type',
        remlp3.value as 'activity'
        from
        redcap_external_modules_log reml
        left join redcap_external_modules_log_parameters remlp1 on reml.log_id = remlp1.log_id
        left join redcap_external_modules_log_parameters remlp2 on reml.log_id = remlp2.log_id
        left join redcap_external_modules_log_parameters remlp3 on reml.log_id = remlp3.log_id
        where
        reml.message = 'SELO_CHANGE_LOG'
        and  remlp1.name = 'parent_log_id'
        and  remlp2.name = 'type'
        and  remlp3.name = 'activity'
        ;

 *
 *
 *
 */
class SimpleEmLogObject
{
    /** @var EnhancedSMSConversation $module */
    public $module;

    private $log_id;
    private $type;                      // type of object (stored in the message column)
    private $message;
    private $timestamp;
    private $ui_id;
    private $ip;
    private $project_id;
    private $record;

    private $object_parameters = [];    // This array stores the parameters for the object
    private $dirty_parameters = [];     // This array tells us if any of the object needs updating

    private $dirty_columns = [];        // This allows you to update UPDATABLE COLUMNS but isn't supported yet

    public $last_error;

    const LOG_CHANGES_TYPE = 'SELO_CHANGE_LOG'; // This will be the 'message' attribute for change logs related to this object
    const LOG_CHANGES = true;
    public $change_log = [];

    const MAIN_COLUMNS = ['log_id', 'timestamp', 'ui_id','ip','project_id','record_id', 'message'];
    protected const UPDATABLE_COLUMNS = ['record', 'project_id', 'timestamp', 'message'];


    /**
     * @param AbstractExternalModule $module
     * @param string $type Name of Object
     * @param integer $log_id
     * @param array $limit_params Leave blank for ALL parameters, otherwise specific array of desired
     * @throws Exception
     */
    public function __construct($module, $type, $log_id = null, $limit_params = []) {
        // Other code to run when object is instantiated
        $this->module = $module;
        $this->type = $type;
        // $this->module->emDebug("Constructor for $type!");

        if($log_id) {
            // Try to get all available EAV parameter entries for log_id
            if (empty($limit_params)) {
                // Get all params for the log_id
                $sql = "select distinct name from redcap_external_modules_log_parameters where log_id=?";
                $result = $module->query($sql, $log_id);
                while ($row = $result->fetch_assoc()) {
                    $limit_params[] = $row['name'];
                }
            }
            $columns = array_merge(static::MAIN_COLUMNS, $limit_params);

            // Query all data
            $sql = "select " . implode(", ", $columns) . " where log_id=? and message=?";
            // $module->emDebug("Load Sql: " . $sql);
            $q = $module->queryLogs($sql, [$log_id, $type]);
            if ($row = $q->fetch_assoc()) {
                foreach ($row as $key=>$val) {
                    if (property_exists($this, $key)) {
//                        $this->module->emDebug("Setting property $key to $val");
                        $this->$key = $val;
                    } else {
//                        $this->module->emDebug("Setting object_parameter $key to $val");
                        $this->object_parameters[$key] = $val;
                    }
                }
            } else {
                $this->last_error = "Requested log_id $log_id not found for type $type";
                $this->module->emDebug($this->last_error);
                throw new Exception ($this->last_error);
            }
        } else {
            // Create a new object - not yet saved
            $this->module->emDebug("Creating new $type");
        }
    }


    /**
     * Set object value by key pair
     * If null, remove from object_properties
     * If unchanged, do not mark as dirty
     * @param string $name
     * @param $val
     * @return void
     */
    public function setValue($name, $val) {
        if(property_exists($this,$name)) {
            // Is object property
            if (in_array($name, self::UPDATABLE_COLUMNS)) {
                if ($this->$name != $val) {
                    $this->module->emDebug("Setting property $name value " . ($this->$name ? "" : "from $this->$name ") . "to $val");
                    $this->$name = $val;
                    $this->dirty_columns[$name] = $val;
                } else {
                    // No change in property value
                    // $this->module->emDebug("Property $name remains unchanged as $val");
                }
            } else {
                $this->last_error = "The property $name is not updatable.";
                $this->module->emDebug($this->last_error);
                // Could throw and exception here but going to just swallow this for now
            }
        } else {
            if (isset($this->object_parameters[$name])) {
                // Existing parameter
                if (is_null($val) or $val = '') {
                    // Null or empty parameter values are not supported - skip and mark for removal
                    $this->dirty_parameters[] = $name;
                    unset($this->object_parameters[$name]);
                } else if ($this->object_parameters[$name] == $val) {
                    // Skip - no change to value
                    // $this->module->emDebug("The parameter $name remains unchanged as $val");
                } else {
                    // Update
                    // $this->module->emDebug("Updating parameter $name from " . $this->object_parameters[$name] . " to $val");
                    $this->object_parameters[$name] = $val;
                    $this->dirty_parameters[] = $name;
                }
            } else {
                if (is_null($val) or $val = '') {
                    // Null or empty parameter values are not supported
                    $this->module->emDebug("Skipping $name -- null/empty parameters are not allowed");
                } else {
                    // Create
                    // $this->module->emDebug("Creating parameter $name as $val");
                    $this->object_parameters[$name] = $val;
                    $this->dirty_parameters[] = $name;
                }
            }
        }
    }


    /**
     * Set object values by an associative array
     * @param array $arr
     * @return bool
     */
    public function setValues($arr) {
        if (!is_array($arr)) {
            $this->module->emDebug("Input is not an array");
            return false;
        }
        foreach ($arr as $k => $v) {
            $this->setValue($k, $v);
        }
        return true;
    }


    /**
     * Get a value by a key
     * If key doesn't exist, return null
     * @param string $k
     * @return mixed
     */
    public function getValue($k) {
        if(property_exists($this,$k)) {
            // if($k=="project_id") $this->module->emDebug("$k PROPERTY EXISTS");
            $value = $this->$k;
        } else if (isset($this->object_parameters[$k])) {
            // if($k=="project_id") $this->module->emDebug("$k PARAMETER EXISTS");
            $value = $this->object_parameters[$k];
        } else {
            $this->module->emDebug("Unable to identify requested value by key $k");
            $value = null;
        }
        return $value;
    }


    /**
     * Get the log_id for the object
     * @return mixed
     */
    public function getId() {
        return $this->log_id;
    }


    /**
     * Save the object, only modifying the object_parameters
     * @return void
     * @throws Exception
     */
    public function save() {
        if ($this->log_id) {
            // For saving existing log_ids
            $this->module->emDebug("DIRTY IN SAVE: ", $this->dirty_parameters, $this->dirty_columns);
            // We only update dirty parameters
            $this->dirty_parameters = array_unique($this->dirty_parameters);

            // Loop through all parameters
            foreach ($this->object_parameters as $k => $v) {
                // Only update dirty parameters
                if (in_array($k, $this->dirty_parameters)) {
                    // Update/Insert parameter
                    if ($this->validateParameter($k, $v)) {
                        $this->module->emDebug("Updating parameter $k to $v");
                        // UPSERT THE VALUE
                        $sql = "INSERT INTO redcap_external_modules_log_parameters (log_id,name,value) " .
                            " VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=?";
                        $this->module->emDebug($sql);
                        $result = $this->module->query($sql, [$this->log_id, $k, $v, $v]);

                        $this->module->emDebug("RESULT OF QUERY: ", $result);

                        $this->logChange(['update', $k, $v]);
                        // Remove from dirty parameters
                        $this->dirty_parameters = array_diff($this->dirty_parameters, [$k]);
                    } else {
                        // Invalid key or value
                    }
                } else {
                    // Skip parameter - wasn't dirty
                }
            }

            // To remove a parameter from an object, you setValue to null which makes it dirty but unsets it
            // from the object.  Therefore, any parameters left as dirty should be deleted.
            foreach ($this->dirty_parameters as $name) {
                $sql = "delete from redcap_external_modules_log_parameters where log_id=? and name=? limit 1";
                $result = $this->module->query($sql, [$this->log_id, $name]);
                $this->logChange(["delete parameter", $name]);
                $this->module->emDebug("Deleted parameter $name for log id $this->log_id", $result);
            }

            if (!empty($this->dirty_columns)) {
                foreach ($this->dirty_columns as $col => $val) {
                    if (in_array($col, self::UPDATABLE_COLUMNS)) {
                        $sql = "update redcap_external_modules_log set " . $col . "=? where log_id=?";
                        $result = $this->module->query($sql, [$this->$col, $this->log_id]);
                        $this->module->emDebug("Updated $col to " . $this->$col);
                        $this->logChange(["update", $col, $this->$col]);
                    } else {
                        $this->module->emError("You cannot update column $col on a previously saved object");
                    }
                }
                // You cannot update these columns on an already saved log_id
                $this->module->emError("You cannot update column values on an already saved object $this->log_id", $this->dirty_columns);
            }
        } else {
            // Create New Log Entry (merging columns and parameters)
            $parameters = array_merge($this->dirty_columns, $this->object_parameters);
            // $this->module->emDebug("About to save: " , $parameters);
            $this->log_id = $this->module->log($this->type, $parameters);
        }

        // Clear object
        $this->dirty_parameters=[];

        // Write to change logs
        $this->saveChangeLog();
    }


    /**
     * Delete from database
     * @return bool
     */
    public function delete() {
        // Remove this log_id
        if ($this->log_id) {
            $result = $this->module->removeLogs("log_id = ?", [$this->log_id]);
            $this->module->emDebug("Removed log $this->log_id with result: " . json_encode($result));
            $this->logChange(["delete", $this->log_id]);
            $this->saveChangeLog();
            return true;
        } else {
            $this->module->emDebug("This object hasn't been saved.  Cannot delete.");
            return false;
        }
    }


    /**
     * Modified from Framework function
     * @param string $name
     * @param mixed $value
     * @return bool
     * @throws Exception
     */
    private function validateParameter($name, $value)
    {
        $type = gettype($value);
        if(!in_array($type, ['boolean', 'integer', 'double', 'string', 'NULL'])){
            throw new Exception("The type '$type' for the '$name' parameter is not supported.");
        }
        else if (isset(AbstractExternalModule::$RESERVED_LOG_PARAMETER_NAMES_FLIPPED[$name])) {
            throw new Exception("The '$name' parameter name is set automatically and cannot be overridden.");
        }
        else if($value === null){
            // There's no point in storing null values in the database.
            // If a parameter is missing, queries will return null for it anyway.
            // unset($parameters[$name]);
            return false;
        }
        else if(strpos($name, "'") !== false){
            throw new Exception("Single quotes are not allowed in parameter names.");
        }
        else if(mb_strlen($name, '8bit') > ExternalModules::LOG_PARAM_NAME_SIZE_LIMIT){
            throw new Exception(ExternalModules::tt('em_errors_160', ExternalModules::LOG_PARAM_NAME_SIZE_LIMIT));
        }
        else if(mb_strlen($value, '8bit') > ExternalModules::LOG_PARAM_VALUE_SIZE_LIMIT){
            throw new Exception(ExternalModules::tt('em_errors_161', ExternalModules::LOG_PARAM_VALUE_SIZE_LIMIT));
        }
        return true;
    }


    /**
     * Log a change to the SEMLO
     * @param $change
     * @return void
     */
    private function logChange($change) {
        $this->change_log[] = $change;
    }


    /**
     * Save Change Log
     * @return void
     */
    private function saveChangeLog() {
        if (!empty($this->change_log) && static::LOG_CHANGES) {
            $params = [
                "parent_log_id" => $this->log_id,
                "type" => $this->type,
                "activity" => json_encode($this->change_log)
            ];
            if (!empty($this->project_id)) $params['project_id'] = $this->project_id;
            $this->module->log(static::LOG_CHANGES_TYPE, $params);
            $this->change_log = [];
        }
    }


    #### STATIC METHODS ####

    /**
     * This method will purge the redcap_external_module_logs table of all of the change_event
     * logs for this object that are older than the $age_in_days
     * @param string $object_type  The type of object, e.g. "CS"
     * @param integer $age_in_days
     * @return void
     */
    public static function purgeChangeLogs($module, $object_type, $age_in_days = 30) {
        $dt = new \DateTime();
        $interval = new \DateInterval("P" . $age_in_days . "D");
        $timestamp = $dt->sub($interval)->format("Y-m-d H:i:s");
        $filter_clause = "timestamp < ? and type = ? and message = ?";
        $module->removeLogs($filter_clause, [$timestamp, $object_type, static::LOG_CHANGES_TYPE]);
        $module->emDebug("Change logs for objects of type $object_type older than $age_in_days were purged");
    }


    /**
     * Get all of the matching log ids for the object
     * @param $module
     * @param $object_type
     * @param $filter_clause
     * @param $parameters
     * @return array
     * @throws Exception
     */
    public static function queryIds($module, $object_type, $filter_clause = "", $parameters = []) {
        $framework = new \ExternalModules\Framework($module);

        // Trim leading where if it exists
        if (substr(trim(mb_strtolower($filter_clause)),0,5) === "where") {
            $filter_clause = substr(trim($filter_clause),5);
        }

        $question_mark_count = count_chars($filter_clause)[ord("?")];
        if (count($parameters) != $question_mark_count) {
            throw Exception ("query filter must have parameter for each question mark");
        }

        // Add type filter
        $sql = "select log_id where message = ?" . (empty($filter_clause) ? "" : " and " . $filter_clause);
        $params = array_merge([$object_type], $parameters);
        // $module->emDebug($sql, $params);
        $result = $framework->queryLogs($sql,$params);
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = $row['log_id'];
        }
        return $ids;
    }

    /**
     * Return an array of objects instead of ids for the matching results
     * @param $module
     * @param $object_type
     * @param $filter_clause
     * @param $parameters
     * @return array
     * @throws Exception
     */
    public static function queryObjects($module, $object_type, $filter_clause = "", $parameters = []) {
        $ids = static::queryIds($module,$object_type,$filter_clause,$parameters);
        $results = [];
        foreach ($ids as $id) {
            $obj = new static($module, $object_type, $id);
            $results[] = $obj;
        }
        return $results;
    }


}
