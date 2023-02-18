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
    $module->emDebug("list is", $meta);

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
if (true) {
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


