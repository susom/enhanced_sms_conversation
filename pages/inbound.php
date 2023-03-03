<?php
namespace Stanford\EnhancedSMSConversation;

/** @var EnhancedSMSConversation $module */

// This should handle inbound SMS/XML messages from Twilio

/*
ToCountry=US
ToState=MN
SmsMessageSid=SMdb090c884ec16cfde4b0a37c25d515e5
NumMedia=0
ToCity=
FromZip=94304
SmsSid=SMdb090c884ec16cfde4b0a37c25d515e5
FromState=CA
SmsStatus=received
FromCity=PALO+ALTO
Body=Test
FromCountry=US
To=%2B16124823490
MessagingServiceSid=MG11175259da4f5f52edcc2bb74b38bf18
ToZip=
NumSegments=1
MessageSid=SMdb090c884ec16cfde4b0a37c25d515e5
AccountSid=AC40b3884912172e03b4b9c2c0ad8d2ae8
From=%2B16503803405
ApiVersion=2010-04-01

 */


// $module->emDebug("Here!", $_POST);
// var_dump($_SERVER);

// Ignoring any non-POST hits to this endpoint
if ($_SERVER['REQUEST_METHOD'] == "POST") {

    // $module->emDebug("Inbound Post:" . json_encode($_POST));

    if ($module->getProjectSetting('disable-incoming-sms')) {
        $module->emDebug("Inbound processing disabled.");
        header('Content-Type: text/html');
        $response =  "The system is offline - please check with the study administrator";
        // TODO: Log this inbound message
    } else {
        // Log inbound message
        $MH = new MessageHistory($this->module);
        $MH->setValues([
            'from_number' => $_POST['From'],
            'to_number'   => $_POST['To'],
            'body'        => $_POST['Body'],
            'status'      => $_POST['SmsStatus'],
            'sid'         => $_POST['SmsMessageSid'],
            'post'        => $_POST
        ]);
        $MH->save();

        // Originally, we were thinking of using the inbound response to send the first reply, but due to the challenges
        // of sending sequential replies in the right order, we are just going to respond with nothing and if we have
        // messages to send back, we will probably send them as separate messages.
        $response = $module->processInboundMessage();
    }

    if (!empty($response)) {
        $module->emDebug("Inbound Message Response: " . $response);
        echo "<Response>$response</Response>";
    }

}
