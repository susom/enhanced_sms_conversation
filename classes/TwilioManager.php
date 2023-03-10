<?php
namespace Stanford\EnhancedSMSConversation;

use REDCap;
use \Twilio\Rest\Client;
use \Exception;

// require_once APP_PATH_DOCROOT . "/Libraries/Twilio/Services/Twilio.php";

// require_once "../vendor/autoload.php";

/**
 * Helper module for sending messages
 */
class TwilioManager {
    /** @var EnhancedSMSConversation $module */
    private $module;

    private $sid;
    private $token;
    private $twilio_number;
    private $project_id;

    private $TwilioClient;

    public function __construct($module, $project_id) {
        $this->module = $module;
        $this->project_id = $project_id;

        $this->sid = $module->getProjectSetting('twilio-sid', $this->project_id);
        $this->token = $module->getProjectSetting('twilio-token',$this->project_id);
        $this->twilio_number = $module->formatNumber($module->getProjectSetting('twilio-number',$this->project_id));

        if (empty($this->sid) | empty( $this->token) | empty( $this->twilio_number)) throw new ConfigSetupException("Missing Twilio setup - see external module config");
    }


    /**
     * Helper to send multiple messages at once
     * @param string $to_number
     * @param array $messages
     * @return void
     * @throws Exception
     */
    public function sendBulkTwilioMessages($to_number, $messages) {
        // Make sure messages is an array
        if (!is_array($messages)) $messages = [ $messages ];

        $errors = [];
        foreach ($messages as $message) {
            // Do not send an empty message
            if (empty($message)) continue;

            // Send the message
            $result = $this->sendTwilioMessage($to_number, $message);
            if (!$result) $errors[] = $message;
        }
        if (!empty($errors)) {
            $this->module->emDebug("Bulk Twilio message send failed", $errors);
        }
    }


    /**
     * Send a Twilio SMS Message
     * @param $to_number string
     * @param $message string
     * @return bool
     * @throws Exception
     */
    public function sendTwilioMessage($to_number, $message) {
        $this->module->emDebug("sending to $to_number: ". $message);

        // Format to number
        $to = $this->module->formatNumber($to_number);
        if ($to !== $to_number) {
            $this->module->emDebug("Formatted to number from $to_number to $to");
        }

        try {
            $sms = $this->getTwilioClient()->messages->create(
                $to,
                [
                    'from' => $this->twilio_number,
                    'body' => $message
                ]
            );
            $this->module->emDebug("SEND SMS RESPONSE: ",$sms->sid);

            // Save a copy of the outgoing message
            $MH = new MessageHistory($this->module);
            $MH->setValues([
                'from_number' => $this->twilio_number,
                'to_number' => $to,
                'body' => $message,
                'response' => json_encode([
                    "errorMessage" => $sms->errorMessage,
                    "errorCode" => $sms->errorCode,
                    "sid"   => $sms->sid
                                          ])
            ]);
            $MH->save();


            if (!empty($sms->error_code) || !empty($sms->error_message)) {
                $error_message = "Error #" . $sms->errorCode . " - " . $sms->errorMessage;
                $this->module->emError($error_message);
                throw new Exception ($error_message);
            }


        } catch (\Exception $e) {
            REDCap::logEvent("Error sending Twilio message from number $to_number", $e->getMessage());
            $this->module->emError("Exception when sending sms: " . $e->getMessage());

            return false;
        }
        return true;
    }


    /**
     * @return Client TwilioClient
     * @throws \Twilio\Exceptions\ConfigurationException
     */
    public function getTwilioClient() {
        if (empty($this->TwilioClient)) {
            $this->TwilioClient = new Client($this->sid, $this->token);
        }
        return $this->TwilioClient;
    }

}
