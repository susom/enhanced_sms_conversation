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
        // Immediate ASI works:
        [source] => ASI
        [source_id] => 177
        [project_id] => 81
        [record_id] => 17
        [event_id] => 167
        [instance] => 1
        [event_name] => event_1_arm_1
        [instrument] => survey_1
        [survey_id] => 177

        // immediate alert - instgrument isn't correct
        [source] => Alert
        [source_id] => 51
        [project_id] => 81
        [record_id] => 18
        [event_id] => 167
        [instance] => 1
        [event_name] => event_1_arm_1
        [instrument] => record_information
        [survey_id] =>
 *
 */
class MessageContext {
    private $module;

    public $source;
    public $source_id;
    public $project_id;
    public $record_id;
    public $event_id;
    public $instance;
    public $event_name;
    public $instrument;
    public $survey_id;

    const VALID_KEYS = [
        'source','source_id','project_id','record_id','event_id',
        'instance','event_name','instrument','survey_id'
    ];


    /**
     * You can pass in an array of known context to override as needed
     * @param EnhancedSMSConversation $module
     * @param $override
     * @throws \Exception
     */
    public function __construct($module, $override = []) {
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
                    [0] => 11       // Survey ID
                    [1] => 53       // Event ID
                    [2] => 21       // Record
                )

         */

        # Get Context From Backtrace
        $bt = debug_backtrace();
        //$module->emDebug(debug_backtrace(0));

        $context = "";
        while ($t = array_pop($bt)) {
            $function = $t['function'] ?? FALSE;
            $class = $t['class'] ?? FALSE;
            $args = $t['args'] ?? FALSE;

            // Skip EM calls as they don't have value
            if ($class == "ExternalModules\ExternalModules" || $class == 'Hooks') continue;

            if ($function == 'saveRecord' && $class == 'DataEntry') {
                if (!empty($args[0])) $this->record_id = $args[0];
                $module->emDebug("Setting record_id from saveRecord");
            }

            if ($function == 'SurveyInvitationEmailer' && $class = 'Jobs') {
                $module->emDebug("Setting source to ASI Cron");
                //xdebug_break();
                $this->source = "ASI Cron";
            }


            // remove whitespace and misc chars
            // if(is_array($args)) $args = preg_replace(['/\'/', '/"/', '/\s+/', '/_{6}/'], ['','','',''], $args);

            // If email is being sent from an Alert - get context from debug_backtrace using function:
            if ($function == 'sendNotification' && $class == 'Alerts') {
                $module->emDebug("Setting source to Alert");
                // sendNotification($alert_id, $project_id, $record, $event_id, $instrument, $instance=1, $data=array())
                $this->source = "Alert";
                $this->source_id = $args[0] ?? null;
                $this->project_id = $args[1] ?? null;
                $this->record_id = $args[2] ?? null;
                $this->event_id = $args[3] ?? null;
                $this->instance = $args[5] ?? 1;
            }

            if ($function == 'scheduleParticipantInvitation' && $class == 'SurveyScheduler') {
                $module->emDebug("Setting source to ASI"); //, $args, $t);
                // If email is from the SurveyScheduler
                $this->source = "ASI";  // Immediate
                $this->source_id = $args[0] ?? null;
                $this->event_id = $args[1] ?? null;
                $this->record_id = $args[2] ?? null;
                $this->instance = $args[3] ?? 1;
                $this->survey_id = $args[0] ?? null;
            }

            if ($function == 'send' && $class == 'Message' && is_object($t['object'])) {
                // Messsage object (properties we are interested in are private, so we need to get fancy to read values)
                $o = $t['object'];
                $r = new \ReflectionObject($o);
                // Map message properties to context valid keys
                $propertyMap = [
                    'project_id' => 'project_id',
                    'record'     => 'record_id',
                    'event_id'   => 'event_id',
                    'form'       => 'instrument',
                    'instance'   => 'instance'
                ];
                if ($this->source == "Alert") unset($propertyMap['form']);
//                $this->source = "Message";  // Message Object - delayed ASI is one use case...
                foreach ($propertyMap as $mProp => $cProp) {
                    if ($p = $r->getProperty($mProp)) {
                        $p->setAccessible(true);
                        $val = $p->getValue($o) ?? ($mProp == 'instance' ? 1 : null);
                        if (!empty($val) && $this->$cProp != $val) {
                            $module->emDebug("Setting $cProp to $val from Message Object");
                            $this->$cProp = $val;
                        }
                    }
                }
            }
        }

        // Next, lets fill in the gap if it is a survey scheduler source in sending mode - this is probably the best
        // hack for context if we have record and project
        if (!empty($this->record_id) && !empty($this->project_id)) {
            $sql = "select
                rssq.ssq_id as source_id,
                rss.survey_id as survey_id,
                rss.event_id as event_id,
                rssq.instance as instance,
                rssq.reminder_num,
                rs.form_name as instrument
            from
                redcap_surveys_scheduler_queue rssq
            join redcap_surveys_scheduler rss on rssq.ss_id = rss.ss_id
            join redcap_surveys rs on rss.survey_id = rs.survey_id
            where
                rssq.status = 'SENDING'
                and rssq.record = ?
                and rs.project_id = ?";
            $q = $module->query($sql,[$this->record_id, $this->project_id]);
            // $module->emDebug($sql);
            if ($row=db_fetch_assoc($q)) {
                foreach ($row as $k => $v) {
                    if (!empty($v) && property_exists($this, $k) && $this->$k != $v) {
                        $this->$k = $v;
                        $module->emDebug("Setting $k to $v from scheduler query");
                    }
                }
            }
        }

        // Get instrument and project from survey_id if possible
        if (!empty($this->survey_id)) {
            $sql = "select project_id, form_name from redcap_surveys rs where rs.survey_id = ?";
            $q = $module->query($sql,[$this->survey_id]);
            if ($row = db_fetch_assoc($q)) {
                $this->project_id = $row['project_id'];
                $this->instrument = $row['form_name'];
                $module->emDebug("Setting project to $this->project_id and instrument to $this->instrument from survey_id");
            }
        }

        $pid = $module->getProjectId();
        if (empty($this->project_id)) $this->project_id = $pid;
        // # Try to get project_id from url if not already set
        // if (empty($this->project_id)) {
        //     $pid = $_GET['pid'] ?? null;
        //     // Require an integer to prevent any kind of injection (and make Psalm happy)
        //     $pid = filter_var($pid, FILTER_VALIDATE_INT);
        //     if($pid !== false) $this->project_id = (string)$pid;
        // } else {
        //     $this->project_id = strval($this->project_id);
        // }

        # OVERRIDE CONTEXT BACKTRACE VALUES WITH CONSTRUCTOR SETTINGS
        foreach (self::VALID_KEYS as $key) {
            if (!empty($override[$key])) {
                // Context override
                if ($this->$key != $override[$key]) {
                    if (!empty($this->$key)) {
                        // Backtrace value found - still overriding
                        $this->module->emDebug("Overriding backtrace value for $key from " .
                            $this->source . " of " . $this->$key . " with " . $override[$key]);
                    }
                    $this->$key = $override[$key];
                }
            }
        }

        # Set event_name from event_id and visa vera
        if (!empty($this->project_id)) {
            // These methods got complicated to make it work when not in project context from cron
            global $Proj;
            $thisProj = (!empty($Proj->project_id) && $this->project_id == $Proj->project_id) ?
                $Proj :
                new Project($this->project_id);
            if (!empty($this->event_id) && empty($this->event_name)) {
                $this->event_name = $thisProj->getUniqueEventNames($this->event_id);
            }
            if (!empty($this->event_name) && empty($this->event_id)) {
                $this->event_id = $thisProj->getEventIdUsingUniqueEventName($this->event_name);
            }
        }
        // $module->emDebug("Types: Project_Id " . gettype($this->project_id) . " - event_id " . gettype($this->event_id));
    }


    /**
     * @return array
     */
    public function getContextAsArray() {
        $result = [];
        foreach (self::VALID_KEYS as $key) {
            $result[$key] = $this->getContext($key);
        }
        return $result;
    }


    /**
     * @param $key
     * @return null
     */
    public function getContext($key) {
        if (in_array($key, self::VALID_KEYS)) {
            return $this->$key;
        } else {
            $this->module->emError("Request for invalid key $key");
            return null;
        }
    }
}
