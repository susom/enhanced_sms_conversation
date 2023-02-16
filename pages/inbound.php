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
$module->emDebug("Inbound", $_POST);

if ($module->getProjectSetting('disable-incoming-sms')) {
    $module->emDebug("Inbound processing disabled.");
    header('Content-Type: text/html');
    echo "<Response></Response>";
} else {
    $response = $module->processInboundMessage();
    print $response;
    $module->emDebug("Response", $response."");
}

