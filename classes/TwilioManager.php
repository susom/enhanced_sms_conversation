<?php

namespace Stanford\EnhancedSMSConversation;

use REDCap;
use \Twilio\TwiML\MessagingResponse;
use \Twilio\Rest\Client;
use \Exception;

require_once APP_PATH_DOCROOT . "/Libraries/Twilio/Services/Twilio.php";
class TwilioManager {
    /** @var EnhancedSMSConversation $module */

    private $module;
    private $sid;
    private $token;
    private $twilio_number;
    private $project_id;
    private $twilio_client;

    public function __construct($module, $project_id) {
        $this->module = $module;
        $this->project_id = $project_id;


        $this->sid = $module->getProjectSetting('twilio-sid');
        $this->token = $module->getProjectSetting('twilio-token');
        $this->twilio_number = $module->formatNumber($module->getProjectSetting('twilio-number'));

        if (empty($this->sid) | empty( $this->token) | empty( $this->twilio_number)) throw new Exception ("Missing Twilio setup - see external module config");

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
        $this->getTwilioClient();

        $to = $this->module->formatNumber($to_number);
        // $this->emDebug("Formatting to number from $to_number to $to");

        try {
            $sms = $this->twilio_client->account->messages->sendMessage(
                $this->twilio_number,
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
            //TODO: log event
            return false;
        }
        return true;
    }


    /**
     * @return Client TwilioClient
     * @throws \Twilio\Exceptions\ConfigurationException
     */
    public function getTwilioClient() {
        if (empty($this->twilio_client)) {
            $sid = $this->getProjectSetting('twilio-sid');
            $token = $this->getProjectSetting('twilio-token');
            $this->twilio_client = new Client($this->sid, $this->token);
        }
    }
}
