<?php
namespace Stanford\EnhancedSMSConversation;

/** @var EnhancedSMSConversation $module */

include_once 'classes/FormManager.php';

use REDCap;
echo "TESTING : Hello from $module->PREFIX";

$project_id = $module->getProjectId();

$url = $module->getUrl('pages/inbound.php', true, true);
echo "<br><br>This is the TRAM innbound link: <br>".$url;

$module->emDebug("INBOUND: $url");
/**
 * TEST11; FormManager: what is in choices?
 *
 * TEST10: TwilioManager: send
 *
 * TEST8: TEST NONSENSE ? MISSING INSTRUCTIONS
 *
 * TEST6: CHECK TWILIO SENDING
 */

if (true) {
    $body = '0';
    if (empty($body)) {
        $module->emDebug("$body is empty");
    }

    if (isset($body)) {
        $module->emDebug("$body is isset");
    }


}

if (false) {
    $module->cronScanConversationState([]);
}

//TEST11: ValidateResponse
if (false) {
    $record_id = 1;
    $form = "thursday";
    $event = "week_1_sms_arm_1";
    $current_field = 'desd';
    $event_id = REDCap::getEventIdFromUniqueEvent($event);
    //$current_field = 'wplan';

    $cell_number = '+16505295666';
    $record_id = 1;

    $fm = new FormManager($module, $form, $current_field, $record_id, $event, $project_id);
    $tm = $module->getTwilioManager($project_id);

    $response = $fm->validateResponse(0);

    if (false !== $response) {
        $module->emDebug("not false");
    } else {
        $module->emDebug(" false");
    }

    $result = $module->saveResponseToREDCap($project_id,$record_id, $current_field, $event_id, $response);
    if ($result['errors'] || $result['warnings']) {
        $module->emDebug("There were errors while saving $response to $record_id", $result['errors'], $result['warnings'], );

        $module->emDebug("getting instructions for $current_field");

        $nonsense_test_warning = $module->getProjectSetting('nonsense-text-warning', $project_id);
        //$instructions = $fm->getFieldInstruction($current_field);
        $invalid_response = $fm->getInvalidResponse();

        //$label = $fm->getFieldLabel($current_field);

        //$active_variable = $fm->getActiveQuestion($current_step);
        $outbound_sms = implode("\n", array_filter([$invalid_response, $fm->getQuestionLabel()]));
        $tm->sendTwilioMessage($cell_number, $outbound_sms);

        //\REDCap::logEvent("Nonsense warning  for $current_field", $result['errors'],$record_id,$event_id, $project_id);

    }




}

//TEST9
if (false) {

    /**
     * looking for 1677088959
     * record_id = 4
     * [expiry_ts] => 1677088564
     * [reminder_ts] => 1677088444
     *
     *
     */

    $timestamp = time();
    $module->emDebug("TIMESTAMP: $timestamp");

    foreach (ConversationState::getActiveConversationsNeedingAttention($module, $project_id, $timestamp) as $CS) {
        /** @var $CS ConversationState **/
        $module->emDebug("working on ID: ". $CS->getId());
        if ($CS->getExpiryTs() > $timestamp ) {
            // Is expired?
            if ($timestamp - $CS->getLastResponseTs() <= EnhancedSMSConversation::LAST_RESPONSE_EXPIRY_DELAY_SEC) {
                // Participant responded recently, let's not expire the conversation yet
                $this->emDebug("Skipping expiration due to recent response", $timestamp, $CS->getLastResponseTs());
            } else {
                // Expire it!
                $CS->expireConversation();

                $result = $module->getTwilioManager($project_id)->sendTwilioMessage($CS->getCellNumber(),$expiration_message);
                $module->emDebug("Send expiration message", $result);

                \REDCap::logEvent("Expired Conversation " . $CS->getId(), "","",$CS->getRecordId(),$CS->getEventId(), $project_id);
            }
        } elseif($CS->getReminderTs() > $timestamp) {
            // Send a reminder
            $reminder_test_warning = $module->getProjectSetting('reminder-text-warning', $project_id);
            $current_field = $CS->getCurrentField();
            $FM = new FormManager($module,$CS->getInstrument(),$CS->getEventId(),$project_id);
            $current_step = $FM->getCurrentFormStep($current_field,$CS->getRecordId());
            $active_label = $FM->getActiveQuestion($current_step, true);
            $active_variable = $FM->getActiveQuestion($current_step);
            $module->emDebug("getting instrudtions for $active_variable", $active_label);

            $instructions = $FM->getFieldInstruction($active_variable);

            $outbound_sms = implode("\n", array_filter([$reminder_test_warning, $active_label, $instructions]));
            $result = $module->getTwilioManager($project_id)->sendTwilioMessage($CS->getCellNumber(),$outbound_sms);
            $module->emDebug("Send reminder message", $result);
            \REDCap::logEvent("Reminder sent for $current_field (#" . $CS->getId() . ")", "","",$CS->getRecordId(),$CS->getEventId(), $project_id);
            $CS->setReminderTs($CS->getExpiryTs());
            $CS->save();

        } else {
            $module->emDebug("This shouldn't happen", $timestamp, $CS);
        }
    }
}




//TEST0
if (false) {
    $record_id = 1;
    $form = "thursday";
    $event = "week_1_sms_arm_1";
    $cell_number = "+14152357577";
    $current_step = 'wplan_ctrl';

    $fm = new FormManager($module, $form, $event, $project_id);


    $all_steps = $fm->getCurrentFormStep($current_step, $record_id,$event);
    $module->emDebug("from $current_step", $all_steps);

    //$all_steps = $fm->getNextSMS($current_step, $record_id,$event);
    //$module->emDebug("from $current_step", $all_steps);

}

//TEST1:  TEST OF ALL DESCRIPTIVE FIELDS
if (false) {

    $record_id = 1;
    $form = "welcome_sms";
    $event = "baseline_arm_1";

    $fm = new FormManager($module, $form, $event, $project_id);

    //expect welcome_3
    $all_steps = $fm->getNextSMS('welcome_3', $record_id,$event);
    $module->emDebug("from welcome_3", $all_steps);

    //expect welcome_1, welcome_2, welcome_3
    $all_steps = $fm->getNextSMS('', $record_id,$event);
    $module->emDebug("from 0", $all_steps);

    //expect welcome_2, welcome_3
    $all_steps = $fm->getNextSMS('welcome_1', $record_id,$event);
    $module->emDebug("from welcome 1", $all_steps);

    //expect welcome_3
    $all_steps = $fm->getNextSMS('welcome_2', $record_id,$event);
    $module->emDebug("from welcome_2", $all_steps);

    //expect welcome_3
    $all_steps = $fm->getNextSMS('welcome_3', $record_id,$event);
    $module->emDebug("from welcome_3", $all_steps);

    /**
    //expect welcome_1, welcome_2, welcome_3
    $andy_steps = $fm->getNextQuestion('');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id, $event);
    $module->emDebug("a: from 0", $foo);

    //expect welcome_3
    $andy_steps = $fm->getNextQuestion('welcome_2');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id, $event);
    $module->emDebug("a: from welcome_2", $foo);
     */
}

//TEST1.1: TEST last field
if (false) {

    $record_id_control = 1; //CONTROL
    $record_id_goal_support = 2; //GOAL SUPPORT
    $record_id_coaching = 3;  //COACHING

    $form = "thursday";
    $event = "week_1_sms_arm_1";
    $current_sms = 'desd_ctrl';

    $fm = new FormManager($module, $form, $event, $project_id);

    $all_steps = $fm->getNextSMS($current_sms, $record_id_control, $event);
    $module->emDebug("for control", $all_steps);


    $andy_steps = $fm->getNextQuestion($current_sms);
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_control, $event);
    $module->emDebug("a: control", $foo);
}

//TEST3:  TEST BRANCHING OF EVENT
if (false) {

    $record_id_control = 1; //CONTROL
    $record_id_goal_support = 2; //GOAL SUPPORT
    $record_id_coaching = 3;  //COACHING

    $form = "thursday";
    $event = "week_1_sms_arm_1";

    $current_sms = 'wplan';

    //set record 3: wplan=0


    $fm = new FormManager($module, $form, $event, $project_id);

    //expect "your body will reward you"
    $all_steps = $fm->getNextSMS($current_sms, $record_id_coaching,$event);
    $module->emDebug("for $current_sms", $all_steps);


    $andy_steps = $fm->getNextQuestion($current_sms);
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_coaching, $event);
    $module->emDebug("a: for $current_sms", $foo);



    $event = "week_2_sms_arm_1";

//expect "Don't be surprise...
    $all_steps = $fm->getNextSMS($current_sms, $record_id_coaching,$event);
    $module->emDebug("for $current_sms", $all_steps);


    $andy_steps = $fm->getNextQuestion($current_sms);
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_coaching, $event);
    $module->emDebug("a: for $current_sms", $foo);

}

//TEST2:   TEST FORM_MANAGER BRANCHING OF RANDOMIZED GROUP
if (false) {

    $record_id_control = 1; //CONTROL
    $record_id_goal_support = 2; //GOAL SUPPORT
    $record_id_coaching = 3;  //COACHING

    $form = "thursday";
    $event = "week_1_sms_arm_1";
    $event_id = REDCap::getEventIdFromUniqueEvent($event);

    //in project set record 1: desd = 0
    //in project set record 2: desd = 0

    $fm = new FormManager($module, $form, $event, $project_id);

    //expecting desd_ctrol
    $all_steps = $fm->getNextSMS('desd', $record_id_control,$event_id);
    $module->emDebug("for control", $all_steps);

    //expecting gset
    $all_steps = $fm->getNextSMS('desd', $record_id_goal_support,$event_id);
    $module->emDebug("for goal support", $all_steps);

/**
//expecting desd_ctrol
    $andy_steps = $fm->getNextQuestion('desd');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_control, $event_id);
    $module->emDebug("a: for control", $foo);

    //expecting gconf
    $andy_steps = $fm->getNextQuestion('desd');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_goal_support, $event_id);
    $module->emDebug("a: for goal support", $foo);
 */
}

//TEST7: TEST FORM_MANAGER: validateResponse
if (false) {
    $form = "thursday";
    $event = "week_1_sms_arm_1";
    $record_id = '1';
    $current_field = 'wplan';


    $fm = new FormManager($module, $form, $event, $project_id);
//    $fm = new FormManager($this, $found_cs->getInstrument(), $found_cs->getEventId(), $found_cs->module->getProjectId());

    $meta = $fm->getCurrentFormStep($current_field, $record_id,$event);
    $module->emDebug("list for $current_field is", $meta);

    $meta = $fm->getCurrentFormStep('desd', $record_id,$event);
    $module->emDebug("list for desd is", $meta);

    $msg = "ha";  //expected response is false: got false
    $return = $fm->validateResponse($current_field, $msg);
    $module->emDebug("return  for $msg: ", $return);

    //TODO: need to be case insensitive
    $msg = "yes"; //TODO: FAIL: expected response is 1: got false
    $return = $fm->validateResponse($current_field, $msg);
    $module->emDebug("return  for $msg: ", $return);

    $msg = "Yes";  //expected response is 1: got 1
    $return = $fm->validateResponse($current_field, $msg);
    $module->emDebug("return  for $msg: ", $return);

    $msg = "no";  //TODO: FAIL: expected response is 0: got false
    $return = $fm->validateResponse($current_field, $msg);
    $module->emDebug("return  for $msg: ", $return);

    $msg = "No";  //expected response is 0: got 0
    $return = $fm->validateResponse($current_field, $msg);
    $module->emDebug("return  for $msg: ", $return);

    $msg = 0; //TODO: FAIL: expected response is false: got 0
    $return = $fm->validateResponse($current_field, $msg);
    $module->emDebug("return  for $msg: ", $return);

    return;

    $data = array(
        REDCap::getRecordIdField() => $record_id,
        //'redcap_event_name' => REDCap::getEventNames(true, false, $found_cs->getEventId()),
        'redcap_event_name'          => $event,
        //$found_cs->getCurrentField() => $msg,
        $current_field     => $msg
    );

    $this->emDebug("saving incoming data", $data);
    $response = REDCap::saveData('json', json_encode(array($data)));
    $this->emDebug("saved opt out", $response['errors']);
}


//TEST4: CHECK CONVERSATION PERSISTENCE
if (false) {

    $record_id = "1";
    $instrument = "thursday";
    $event = "week_1_sms_arm_1";
    $event_id= REDCap::getEventIdFromUniqueEvent($event);
    $cell_number = '+16505295666';

    //5. Clear out any existing states for this record in the state table
    //   If it comes through the email, then we should start from blank state.
    if ($found_cs = ConversationState::getActiveConversationByNumber($module, $cell_number)) {
        $id = $found_cs->getId();
        $module->emDebug("Found record $id. Closing this conversation..." );
        $found_cs->expireConversation();
        $found_cs->save();
    }

    //6. get the first sms to send
    $fm = new FormManager($module, $instrument, $event_id, $module->getProjectId());
    $sms_to_send_list = $fm->getNextSMS('', $record_id, $event_id);
    $active_field     = $fm->getActiveQuestion($sms_to_send_list);

    //7. Set the state table
    // Create a new Conversation State
    $CS = new ConversationState($module);
    $CS->setValues([
                       "instrument"    => $instrument,
                       "event_id"      => $event_id,
                       "instance"      => $mc_context['instance'] ?? 1,
                       "cell_number"   => $cell_number,
                       "current_field" => $active_field
                   ]);
    $CS->setState("ACTIVE");
    $CS->setExpiryTs();
    $CS->setReminderTs();
    $CS->save();

}

//TEST5: CHECK CONVERSATION FIND AND CLOSE
if (false) {

    $my_num = "+16505295666";
    if ($found_cs = ConversationState::getActiveConversationByNumber($module, $my_num)) {

        var_dump($found_cs);
        $state = $found_cs->getValue('state');
        $id = $found_cs->getId();
        $module->emDebug("Found record " . $id);
        $found_cs->expireConversation();
        $found_cs->save();
    } else {
        $module->emDebug("no conversation found for $my_num");
    }

    //$found_cs->closeExistingConversations(); //this one searches by number again??
}

//TEST6: CHECK TWILIO SENDING
if (false) {
    $tm = $module->getTwilioManager($module->getProjectId());

    $tm->sendTwilioMessage('+16505295666', "hello there");
}


//TEST8: TEST NONSENSE ? MISSING INSTRUCTIONS
if (false) {
    $record_id = 1;
    $form = "thursday";
    $event = "week_1_sms_arm_1";
    $cell_number = "+14152357577";


    $fm = new FormManager($module, $form, $event, $project_id);
    $tm = $module->getTwilioManager($module->getProjectId());

    //expecting text yes or no
    $current_step = "wplan";
    $module->emDebug("Instructions for $current_step are: ".$fm->getFieldInstruction($current_step));


    $module->handleReply($record_id, $cell_number, 'blah');

    $module->handleReply($record_id, $cell_number, 'yes');




}

if (false) {

    $record_id = 1;
    $form = "thursday";
    $event = "week_1_sms_arm_1";

    $record_id = 1;
    $form = "welcome_sms";
    $event = "baseline_arm_1";

    $fm = new FormManager($module, $form, $event, $project_id);

    $all_steps = $fm->getNextSMS('', $record_id, $event);
    $module->emDebug("from 0", $all_steps);
    $all_steps = $fm->getNextSMS('welcome_2', $record_id,$event);
    $module->emDebug("from sms_start", $all_steps);


    $andy_steps = $fm->getNextQuestion('');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id, $event);
    $module->emDebug("a: from 0", $foo);
    $andy_steps = $fm->getNextQuestion('welcome_2');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id, $event);
    $module->emDebug("a: from sms_start", $foo);

}

if (false) {
    $fm = new FormManager($module, $form, $event, $project_id);

    //check public method to get next texts given current text
    $all_steps = $fm->getNextSMS('', 1, 'week_1_sms_arm_1' );
    $all_steps = $fm->getNextSMS('sms_start', 1, 'week_1_sms_arm_1' );
    //$module->emDebug("these are the steps: ", $all_steps);

}

if (false) {
    $fm = new FormManager($module, $form, $event, $project_id);


    $start = 'wplan';
    //check public method to get next texts given current text
    $all_steps = $fm->getNextSMS($start, 1, 'week_1_sms_arm_1');
    $module->emDebug("these are the  SMS for starting at $start : ", $all_steps);
    return;

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('sms_start', 1, 'week_1_sms_arm_1');
    $module->emDebug("these are the steps: ", $all_steps);

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('desd_ctrl', 1, 'week_1_sms_arm_1');
    $module->emDebug("these are the steps: ", $all_steps);

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('gset', 1, 'week_1_sms_arm_1');
    $module->emDebug("these are the steps: ", $all_steps);
}

if (false) {
    $fm = new FormManager($module, $form, $event);

    $all_steps = $fm->getCurrentFormStep('desd_ctrl', 1, 'week_1_sms_arm_1' );
    $module->emDebug("these are the steps: ", $all_steps);
}

//set up the test CS
/**

These are the supported parameters
 * 'instrument',
 * 'event_id',
 * 'instance',
 * 'number',  - telephone number
 * 'start_ts',
 * 'current_question', - variable name?
 * 'reminder_ts',
 * 'expiry_ts',
 * 'state' - ACTIVE
 */

if (false) {

    $my_num = $module->formatNumber('6505295666');

    if ($found_cs = ConversationState::getActiveConversationByNumber($module, $my_num)) {

        var_dump($found_cs);
        $state = $found_cs->getValue('state');
        $id = $found_cs->getId();
        $module->emDebug("Found record " . $id);
    }

    $event_id = $found_cs->getValue("event_id");
    $current_question = $found_cs->getValue("current_question");
    $instrument = $found_cs->getValue("instrument");
    $record_id = $module->getRecordByNumber('16505295666');


}






/// OLD TESTING
if (false) {

    $c = 3;

    ?>
    <style>
        pre {
            white-space: pre-wrap;       /* css-3 */
        }
    </style>
    <?php

    // Testing the metadata parser functions:
    /** @set \Project $proj */
    global $proj;

    $event_id = 167;
    $FM = new FormManager($module, 'survey_1', 167,81, $event_id, $project_id);
    //var_dump($FM);

    $record = "34";
    $q = $FM->setStartingField('',$record);
    var_dump($q);

    $q = $FM->setStartingField($q['current_field'], $record);
    var_dump($q);

    $nf = $FM->getNextField($q['current_field']);
    $q = $FM->setStartingField($nf, $record);
    var_dump($nf,$q);

    $q = $FM->setStartingField($q['current_field'], $record);
    var_dump($q['current_field'],$q);

    $nf = $FM->getNextField($q['current_field']);
    $q = $FM->setStartingField($nf, $record);
    var_dump($nf,$q);

    die();
    $field = $q['current_field'];

    echo "\n------\n";
    $response = " nope e ";
    $field = "yn_2";
    $r = $FM->validateResponse($field, $response);
    var_dump("Answer $field with $response", $r);

    if (false) {
        $CS = new ConversationState($module, "CS");

        //$CS->setValue("foo","bar");

        $vals = [
            "fruit" => "apple",
            "number" => 6503803405
        ];

        $CS->setValues($vals);

        //$CS->setValue("timestamp",date("Y-m-d H:i:s") );

        $r = $CS->save();
        var_dump($r);
        var_dump($CS);
    }

    if (false) {
        // 1782 and 1783
        $CS = new ConversationState($module, "CS", 1783);
        var_dump($CS);
        var_dump($CS->delete());
    }

    if (false) {
        $CS = new ConversationState($module, "CS");
        $CS->setValue("state", "ACTIVE");
        $CS->setValue("number", "16503803405");
        $CS->save();
        var_dump($CS);
    }

    if (false) {
        $o = ConversationState::getActiveConversationByNumber($module, "16503803405");

        // var_dump($o);

        $o->setValue('foo','bar3');
        $o->save();
        var_dump($o);

        ConversationState::purgeChangeLogs($module,"CS",0);
        echo "Purged";
    }
}
