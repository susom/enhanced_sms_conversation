<?php

namespace Stanford\EnhancedSMSConversation;

use REDCap;

class TwilioManager {
    /** @var EnhancedSMSConversation $module */

    private $module;
    private $sid;
    private $token;
    private $number;
    private $project_id;
    private $twilio_client;

    public function __construct($module, $project_id) {
        $this->module = $module;
        $this->project_id = $project_id;


        $this->sid = $module->getProjectSetting('twilio-sid');
        $this->token = $module->getProjectSetting('twilio-token');
        $this->number = $module->getProjectSetting('twilio-number');

        if (empty($this->sid) | empty( $this->token) | empty( $this->number)) throw new Exception ("Missing Twilio setup - see external module config");

        $this->twilio_client = new \Services_Twilio($this->sid , $this->token);


    }


    /**
     * Send a Twilio SMS Message
     * @param $to_number string
     * @param $message string
     * @return bool
     * @throws Exception
     */
    public function sendTwilioMessage($to_number, $message) {
        // Check to instantiate the client
        if (empty($this->twilio_client)) {
            $account_sid = $this->getProjectSetting('twilio-account-sid');
            $token = $this->getProjectSetting('twilio-token');
            if (empty($account_sid) | empty($token)) throw new Exception ("Missing Twilio setup - see external module config");
            $this->twilio_client = new \Services_Twilio($account_sid, $token);
        }
        if (empty($this->from_number)) {
            $from_number = $this->getProjectSetting('twilio-from-number');
            if (empty($from_number)) throw new Exception ("Missing Twilio setup - see external module config");
            $this->from_number = self::formatNumber($from_number);
        }

        $to = self::formatNumber($to_number);
        // $this->emDebug("Formatting to number from $to_number to $to");

        try {
            $sms = $this->twilio_client->account->messages->sendMessage(
                $this->from_number,
                $to,
                $message
            );
            if (!empty($sms->error_code) || !empty($sms->error_message)) {
                $error_message = "Error #" . $sms->error_code . " - " . $sms->error_message;
                $this->emError($error_message);
                throw new Exception ($error_message);
            }
        } catch (\Exception $e) {
            $this->emError("Exception when sending sms: " . $e->getMessage());
            $this->last_error_message = $e->getMessage();
            return false;
        }
        return true;
    }

}
