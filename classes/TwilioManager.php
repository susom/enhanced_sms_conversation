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

    private $account_sid;
    private $account_token;
    private $twilio_number;
    private $project_id;

    // Per message attributes
    public $response;
    public $to;
    public $body;
    public $sid;    // Message sid

    private $TwilioClient;

    public function __construct($module, $project_id) {
        $this->module = $module;
        $this->project_id = $project_id;

        $this->account_sid = $module->getProjectSetting('twilio-sid', $this->project_id);
        $this->account_token = $module->getProjectSetting('twilio-token',$this->project_id);
        $this->twilio_number = $module->formatNumber($module->getProjectSetting('twilio-number',$this->project_id));

        if (empty($this->account_sid) | empty( $this->account_token) | empty( $this->twilio_number)) throw new ConfigSetupException("Missing Twilio setup - see external module config");
    }


    /**
     * Get the project id for context checking
     * @return mixed
     */
    public function getProjectId() {
        return $this->project_id;
    }


    /**
     * Get a Number Object (useful for checking the endpoint configuration
     * @return \Twilio\Rest\Api\V2010\Account\IncomingPhoneNumberInstance|null
     */
    public function getNumberDetails() {
        /*  EXAMPLE OUTPUT (as object)
              'accountSid' => string 'AC40b3884912172e03b4b9c2c0ad8d2ae8' (length=34)
              'addressSid' => null
              'addressRequirements' => string 'none' (length=4)
              'apiVersion' => string '2010-04-01' (length=10)
              'beta' => boolean false
              'capabilities' =>
                array (size=3)
                  'voice' => boolean true
                  'sms' => boolean true
                  'mms' => boolean true
              'dateCreated' =>
                object(DateTime)[62]
                  public 'date' => string '2023-02-02 22:15:49.000000' (length=26)
                  public 'timezone_type' => int 1
                  public 'timezone' => string '+00:00' (length=6)
              'dateUpdated' =>
                object(DateTime)[61]
                  public 'date' => string '2023-03-02 22:10:33.000000' (length=26)
                  public 'timezone_type' => int 1
                  public 'timezone' => string '+00:00' (length=6)
              'friendlyName' => string '(612) 482-3490 (ANDY LOCAL DEV - #28)' (length=37)
              'identitySid' => null
              'phoneNumber' => string '+16124823490' (length=12)
              'origin' => string 'twilio' (length=6)
              'sid' => string 'PN4b4c81b26c0e9dd1c4a0356cf9989709' (length=34)
              'smsApplicationSid' => string '' (length=0)
              'smsFallbackMethod' => string 'POST' (length=4)
              'smsFallbackUrl' => string '' (length=0)
              'smsMethod' => string 'POST' (length=4)
              'smsUrl' => string 'https://redcap.stanford.edu/api/?type=module&prefix=enhanced_sms&page=pages%2Finbound&pid=27832&NOAUTH' (length=102)
              'statusCallback' => string '' (length=0)
              'statusCallbackMethod' => string 'POST' (length=4)
              'trunkSid' => null
              'uri' => string '/2010-04-01/Accounts/AC40b3884912172e03b4b9c2c0ad8d2ae8/IncomingPhoneNumbers/PN4b4c81b26c0e9dd1c4a0356cf9989709.json' (length=116)
              'voiceReceiveMode' => null
              'voiceApplicationSid' => string '' (length=0)
              'voiceCallerIdLookup' => boolean false
              'voiceFallbackMethod' => string 'POST' (length=4)
              'voiceFallbackUrl' => string '' (length=0)
              'voiceMethod' => string 'POST' (length=4)
              'voiceUrl' => string 'https://demo.twilio.com/welcome/voice/' (length=38)
              'emergencyStatus' => string 'Active' (length=6)
              'emergencyAddressSid' => null
              'emergencyAddressStatus' => string 'unregistered' (length=12)
              'bundleSid' => null
              'status' => string 'in-use' (length=6)
        */
        try {
            $pn = $this->getTwilioClient()->incomingPhoneNumbers->read(["phoneNumber" => $this->twilio_number],20);

            $result = null;
            if (empty($pn)) {
                $this->module->emError("Number: $this->twilio_number not found");
            } else {
                $result = $pn[0];
            }
            return $result;
        } catch (\Exception $e) {
            $this->module->emError("Exception in getNumberDetails: " . $e->getMessage());
            return null;
        }
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
            $this->to = $to;
            $this->body = $message;

            $response = $this->getTwilioClient()->messages->create(
                $to,
                [
                    'from' => $this->twilio_number,
                    'body' => $message
                ]
            );
            $this->module->emDebug("Outbound SID: ",$response->sid);
            $this->response = $response;

            // Save a copy of the outgoing message
            $MH = new MessageHistory($this->module);
            $MH->setValues([
                'from_number' => $this->twilio_number,
                'to_number' => $to,
                'body' => $message,
                'response' => json_encode($response->toArray()),
                'sid' => $response->sid
            ]);
            $MH->save();


            if (!empty($response->error_code) || !empty($response->error_message)) {
                $error_message = "Error #" . $response->errorCode . " - " . $response->errorMessage;
                $this->module->emError($error_message);
                throw new Exception ($error_message);
            }


        } catch (\Exception $e) {
            REDCap::logEvent("Error sending Twilio message from number $to_number", $e->getMessage());
            $this->module->emError("Exception when sending sms: " . $e->getMessage());
            return false;
        }
    }


    /**
     * @return Client TwilioClient
     * @throws \Twilio\Exceptions\ConfigurationException
     */
    public function getTwilioClient() {
        if (empty($this->TwilioClient)) {
            $this->TwilioClient = new Client($this->account_sid, $this->account_token);
        }
        return $this->TwilioClient;
    }

}
