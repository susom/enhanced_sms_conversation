<?php

namespace Stanford\EnhancedSMSConversation;

use \Exception;

require_once "SimpleEmLogObject.php";


/**
 * The Conversation State extends the Simple EM Log Object to provide a data store for all conversations
 *
 */
class MessageHistory extends SimpleEmLogObject
{
    /** @var EnhancedSMSConversation $this->module */

    /**
     * The object holds Message History in the EM
     * EM LOG table already has: record, timestamp
     */
    CONST VALID_OBJECT_PARAMETERS = [
        'cs_id',
        'from_number',
        'to_number',
        'body',
        'direction',    // IN / OUT
        'error_id',     // An error number
        'note'          // A place to log any notes about the CS
    ];

    CONST OBJECT_NAME = 'MessageHistory';   // This is the 'name' of the object and stored in the message column

    public function __construct($module, $type = self::OBJECT_NAME, $log_id = null, $limit_params = [])
    {
        parent::__construct($module, $type, $log_id, $limit_params);
    }

    public function setCsId($id) { $this->setValue('cs_id', $id); }
    public function setToNumber($number) { $this->setValue('to_number', $number); }
    public function setFromNumber($number) { $this->setValue('from_number', $number); }
    public function setBody($body) { $this->setValue('body', $body); }
    public function setDirection($d) { $this->setValue('direction', $d); }
    public function setProjectId($pid) { $this->setValue('project_id', $pid); }
    public function setRecord($id) { $this->setValue('record', $id); }


    /**
     * Create a new log message
     * @param $module
     * @param $to_number
     * @param $from_number
     * @param $body
     * @param $direction
     * @param $cs_id
     * @param $record
     * @param $project_id
     * @return int
     * @throws Exception
     */
    public static function logNewMessage($module, $to_number, $from_number, $body, $direction, $cs_id, $record = null, $project_id)
    {
        $MH = new MessageHistory($module);
        $MH->setValues([
            'to_number'   => $to_number,
            'from_number' => $from_number,
            'body'        => $body,
            'direction'   => $direction,
            'cs_id'       => $cs_id,
            'record'      => $record,
            'project_id'  => $project_id
        ]);
        $MH->save();
        return $MH->getId();
    }


    /**
     * Load the active conversation by phone number
     * If there is more than one active conversation for a given cell number, it marks the older ones as ERROR
     * @param EnhancedSMSConversation $module
     * @param string $record
     * @return array{MessageHistory}
     * @throws Exception
     */
    public static function getMessagesByRecord($module, $record) {
        $type = self::OBJECT_NAME;
        $filter_clause = "record = ? order by timestamp desc";
        $objs = self::queryObjects($module, $type, $filter_clause, [$record]);
        $count = count($objs);
        $module->emDebug("Found $count hits with $filter_clause");
        return $objs;
    }

}
