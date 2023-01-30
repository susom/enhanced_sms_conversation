<?php

namespace Stanford\EnhancedSMSConversation;

use \Exception;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

abstract class EmLogObject
{
    /** @var EnhancedSMSConversation $module */
    public $module;

    private $log_id;
    private $type;  // type of object (stored in the message column)
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

    const MAIN_COLUMNS = ['log_id', 'timestamp', 'ui_id','ip','project_id','record', 'message'];
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
        $this->module->emDebug("Constructor for $type!");

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
            $module->emDebug("Load Sql: " . $sql);
            $q = $module->queryLogs($sql, [$log_id, $type]);
            if ($row = $q->fetch_assoc()) {
                foreach ($row as $key=>$val) {
                    if (property_exists($this, $key)) {
                        $this->module->emDebug("Setting property $key to $val");
                        $this->$key = $val;
                    } else {
                        $this->module->emDebug("Setting object_parameter $key to $val");
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
            $this->module->emDebug("Making new object of type $type");
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
                    $this->module->emDebug("Updated property $name from " . $this->$name . " to $val");
                    $this->$name = $val;
                    $this->dirty_columns[] = $name;
                } else {
                    // No change in value
                    $this->module->emDebug("Property $name remains unchanged as $val");
                }
            } else {
                $this->last_error = "The property $name is not updatable.";
                $this->module->emDebug($this->last_error);
                // Could throw and exception here but going to just swallow this for now
            }
        } else {
            if (isset($this->object_parameters[$name])) {
                // Existing parameter
                if (is_null($val)) {
                    // Null parameter values are not supported - skip and mark for removal
                    $this->dirty_parameters[] = $name;
                    unset($this->object_parameters[$name]);
                } else if ($this->object_parameters[$name] == $val) {
                    // Skip - no change to value
                    $this->module->emDebug("The parameter $name remains unchanged as $val");
                } else {
                    // Update
                    $this->module->emDebug("Updated property $name from " . $this->$name . " to $val");
                    $this->object_parameters[$name] = $val;
                    $this->dirty_parameters[] = $name;
                }
            } else {
                // Create
                $this->module->emDebug("Created property $name from " . $this->$name . " to $val");
                $this->object_parameters[$name] = $val;
                $this->dirty_parameters[] = $name;
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
            $value = $this[$k];
        } else if (isset($this->object_parameters[$k])) {
            $value = $this->object_parameters[$k];
        } else {
            $this->module->emDebug("Unable to identify requested value by key $k");
            $value = null;
        }
        return $value;
    }


    /**
     * Save the object, only modifying the object_parameters
     * @return void
     */
    public function save() {
        if ($this->log_id) {
            // For saving existing log_ids

            // We only update dirty parameters
            $this->dirty_parameters = array_unique($this->dirty_parameters);

            // Upsert dirty parameters
            foreach ($this->object_parameters as $k => $v) {
                if (in_array($k, $this->dirty_parameters)) {
                    // Update/Insert parameter
                    if ($this->validateParameter($k, $v)) {
                        $this->module->emDebug("Updating parameter $k to $v");
                        // UPSERT THE VALUE
                        $sql = "INSERT INTO redcap_external_modules_log_parameters (log_id,name,value) " .
                            " VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=?";
                        $this->module->emDebug($sql);
                        $this->module->query($sql, [$this->log_id, $k, $v, $v]);

                        // Remove from dirty parameters
                        $this->dirty_parameters = array_diff($this->dirty_parameters, [$k]);
                    } else {
                        // Invalid key or value
                    }
                } else {
                    // Skip parameter - wasn't dirty
                }
            }

            // Are there any dirty parameters remaining - if so, delete them
            foreach ($this->dirty_parameters as $name) {
                // $this->module->emDebug("Dirty $name is till here!", $this->dirty_parameters);
                // continue;
                // Delete parameter
                $sql = "delete from redcap_external_modules_log_parameters where log_id=? and name=? limit 1";
                $result = $this->module->query($sql, [$this->log_id, $name]);
                $this->module->emDebug("Deleted parameter $name for log id $this->log_id", $result);
            }

            if (!empty($this->dirty_columns)) {
                // You cannot update these columns on an already saved log_id
                $this->module->emError("You cannot update column values on an already saved object $this->log_id", $this->dirty_columns);
            }
        } else {
            // Create New Log Entry (merging columns and parameters)
            $parameters = array_merge($this->dirty_columns, $this->object_parameters);
            $this->log_id = $this->module->log($this->type, $parameters);
            $this->module->emDebug("Created new log entry as $this->log_id", $this->object_parameters);
        }

        // Clear object
        $this->dirty_parameters=[];

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

}
