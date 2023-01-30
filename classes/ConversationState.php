<?php

namespace Stanford\EnhancedSMSConversation;

require_once "EmLogObject.php";

class ConversationState extends EmLogObject
{
    // /** @var EnhancedSMSConversation $module */
    // private $module;

    // CONST VALID_OBJECT_PARAMETERS = ['foo','instrument', 'event_id', 'instance','number',
    //     'start_ts','current_question','reminder_ts','expiry_ts','state'];

    /**
     * @return mixed
     */
    public function getStartTs()
    {
        return $this->getValue('start_ts');
    }

    /**
     * @return mixed
     */
    public function getState()
    {
        // active, closed, expired, completed
        return $this->getValue('state');
    }

    public static function buildConversationStateFromId($module, $id)
    {
        /** @var EnhancedSMSConversation $module */
        return new ConversationState($module, __CLASS__, $id);
    }

    public static function getActiveConversationStateByNumber($module, $number) {
        $framework = new \ExternalModules\Framework($module);
        $sql = "select log_id where message=? and number=? and state=? order by timestamp desc";
        $result = $framework->queryLogs($sql,[__CLASS__, $number, 'ACTIVE']);
        // $sql = "select log_id from redcap_external_modules_log reml
        //       join redcap_external_modules_log_parameters remlp on reml.log_id = remlp.log_id
        //       where reml.message = ? and remlp.name='number' and remlp.number = ? and state = ? order by timestamp desc";
        // ExternalModules::query()
        return $result;
    }

}
