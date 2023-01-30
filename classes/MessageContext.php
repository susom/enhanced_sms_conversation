<?php
namespace Stanford\EnhancedSMSConversation;

use \REDCap;
use \Project;

/**
 * Class MessageContext
 *
 * Because the redcap_email hook doesn't have any context (e.g. record, project, etc) it can sometimes be hard
 * to know if any context applies.  Generally you can provide details via piping in the subject, but only to a
 * certain extent.  For example, a scheduled ASI email will have a record, but no event / project context
 *
 * This module does its best to set:
 * source, source_id, project_id, record_id, event_id, instance, and event_name
 *
 */
class MessageContext {
    private $module;

    private $source;
    private $source_id;
    private $project_id;
    private $record_id;
    private $event_id;
    private $instance;
    private $event_name;

    CONST CONTEXT_KEYS = ['source','source_id','project_id','record_id','event_id','instance','event_name'];

    /**
     * You can pass in an array of known context to override as needed
     * @param EnhancedSMSConversation $context
     * @param $context
     * @throws \Exception
     */
    public function __construct($module, $context = []) {
        /** @var EnhancedSMSConversation $module */
        $this->module = $module;
        /*
            [file] => /var/www/html/redcap_v10.8.2/DataEntry/index.php
            [line] => 345
            [function] => saveRecord
            [class] => DataEntry
            [type] => ::
            [args] => Array
                (
                    [0] => 21
                    [1] => 1
                    [2] =>
                    [3] =>
                    [4] =>
                    [5] => 1
                    [6] =>
                )


        // From an immediate ASI
        scheduleParticipantInvitation($survey_id, $event_id, $record)

            [file] => /var/www/html/redcap_v10.8.2/Classes/SurveyScheduler.php
            [line] => 1914
            [function] => scheduleParticipantInvitation
            [class] => SurveyScheduler
            [type] => ->
            [args] => Array
                (
                    [0] => 11
                    [1] => 53
                    [2] => 21
                )

         */

        # Get Context From Backtrace
        $bt = debug_backtrace(0);
        // $this->emDebug($bt);
        foreach ($bt as $t) {
            $function = $t['function'] ?? FALSE;
            $class = $t['class'] ?? FALSE;
            $args = $t['args'] ?? FALSE;
            // remove whitespace and misc chars
            // if(is_array($args)) $args = preg_replace(['/\'/', '/"/', '/\s+/', '/_{6}/'], ['','','',''], $args);

            // If email is being sent from an Alert - get context from debug_backtrace using function:
            if ($function == 'sendNotification' && $class == 'Alerts') {
                // sendNotification($alert_id, $project_id, $record, $event_id, $instrument, $instance=1, $data=array())
                $this->source     = "Alert";
                $this->source_id  = $args[0] ?? null;
                $this->project_id = $args[1] ?? null;
                $this->record_id  = $args[2] ?? null;
                $this->event_id   = $args[3] ?? null;
                $this->instance   = $args[5] ?? 1;
                break;
            } else if ($function == 'scheduleParticipantInvitation' && $class == 'SurveyScheduler') {
                // If email is from the SurveyScheduler
                $this->source     = "ASI (Immediate)";
                $this->source_id  = $args[0] ?? null;
                $this->event_id   = $args[1] ?? null;
                $this->record_id  = $args[2] ?? null;
                break;
            } else if ($function == 'SurveyInvitationEmailer' && $class == 'Jobs') {
                $this->source    = "ASI (Delayed)";
                $this->source_id = "";
                // Unable to get project_id in this case
                break;
            }
        }

        # Try to get project_id from url if not already set
        if (empty($this->project_id)) {
            $pid = $_GET['pid'] ?? null;
            // Require an integer to prevent any kind of injection (and make Psalm happy)
            $pid = filter_var($pid, FILTER_VALIDATE_INT);
            if($pid !== false) $this->project_id = (string)$pid;
        }

        # OVERRIDE CONTEXT BACKTRACE VALUES WITH CONSTRUCTOR SETTINGS
        foreach (self::CONTEXT_KEYS as $key) {
            if (!empty($context[$key])) {
                // Context override
                if ($this->$key != $context[$key]) {
                    if (!empty($this->$key)) {
                        // Backtrace value found - still overriding
                        $this->module->emDebug("Overriding backtrace value for $key from " .
                            $this->source . " of " . $this->$key . " with " . $context[$key]);
                    }
                    $this->$key = $context[$key];
                }
            }
        }

        # Set event_name from event_id and visa vera
        if (!empty($this->project_id)) {
            if (!empty($this->event_id) && empty($this->event_name)) {
                $this->event_name = REDCap::getEventNames(true, false, $this->event_id);
            }

            // This method got complicated to make it work when not in project context from cron
            if (!empty($this->event_name) && empty($this->event_id)) {
                global $Proj;
                $thisProj = (
                    !empty($Proj->project_id) && $this->project_id == $Proj->project_id) ?
                    $Proj :
                    new Project($this->project_id);
                $this->event_id = $thisProj->getEventIdUsingUniqueEventName($this->event_name);
            }
        }
    }


    /**
     * @return array
     */
    public function getContextAsArray() {
        $result = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $result[$key] = $this->getContext($key);
        }
        return $result;
        // return [
        //     "source"        => $this->getSource(),
        //     "source_id"     => $this->getSourceId(),
        //     "record_id"     => $this->getRecordId(),
        //     "event_id"      => $this->getEventId(),
        //     "event_name"    => $this->getEventName(),
        //     "instance"      => $this->getInstance(),
        //     "project_id"    => $this->getProjectId()
        // ];
    }

    /**
     * @param $key
     * @return null
     */
    public function getContext($key) {
        if (in_array($key, self::CONTEXT_KEYS)) {
            return $this->$key;
        } else {
            $this->module->emError("Request for invalid key $key");
            return null;
        }
    }


    // /**
    //  * @return mixed
    //  */
    // public function getSource()
    // {
    //     return $this->source;
    // }
    //
    // /**
    //  * @return mixed
    //  */
    // public function getSourceId()
    // {
    //     return strval($this->source_id);
    // }
    //
    // /**
    //  * @return mixed
    //  */
    // public function getProjectId()
    // {
    //     return $this->project_id;
    // }
    //
    // /**
    //  * @return string
    //  */
    // public function getRecordId()
    // {
    //     return strval($this->record_id);
    // }
    //
    // /**
    //  * @return mixed
    //  */
    // public function getEventId()
    // {
    //     return $this->event_id;
    // }
    //
    // /**
    //  * @return mixed
    //  */
    // public function getInstance()
    // {
    //     return strval($this->instance);
    // }
    //
    // /**
    //  * @return mixed
    //  */
    // public function getEventName()
    // {
    //     return $this->event_name;
    // }

}
