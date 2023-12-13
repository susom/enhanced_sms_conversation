<?php
namespace Stanford\EnhancedSMSConversation;

/** @var EnhancedSMSConversation $module */

// Replace this with your module code
echo "Hello from $module->PREFIX";

var_dump($module->getUrl("pages/inbound.php",true,true));

// $CS = ConversationState::buildConversationStateFromId($module, 5);


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


    // $CS->t();
    // $CS->setValue("FOO", "bar");
    // $CS->setValue("instrument", "TEST1");
    // $CS->save();

    // var_dump($CS);




}

if (false) {
    $CS = new ConversationState($module, "CS");
    $CS->setValue("state", "ACTIVE");
    $CS->setValue("number", "16503803405");
    $CS->save();

    var_dump($CS);
}

if (false) {
    // $CS = ConversationState::getObjectsByType($module, "CS");
    // $ids = ConversationState::getObjectIdsByQuery($module, "CS");
    // var_dump($ids);
    //
    // $objs = ConversationState::getObjectsByQuery($module, "CS");
    // var_dump($objs);

    // $ids = ConversationState::getObjectIdsByQuery($module,
    //     "CS",
    //     "number = ? and record = ? and timestamp > ?",
    //     ["16503803405", 5, date("2023-01-27 16:34:46")]);
    // var_dump($ids);
    //

    // $ids = SimpleEmLogObject::queryIds($module,
    //     "CS",
    //     "number = ? and record = ? and timestamp > ?",
    //     ["16503803405", 5, date("2023-01-27 16:34:46")]);
    //
    // $id = current($ids);
    // $CS = new SimpleEmLogObject($module,"CS",$id);
    //
    // var_dump($ids, $id, $CS);
    // $CS->setValue("record",null);
    // $CS->save();
    // var_dump($CS);


    // $n = ConversationState::queryObjects($module,"CS","number=?", ["16503803405"]);
    // foreach ($n as $o) {
    //     $o->setValue("state","ACTIVE");
    //     $o->save();
    //     var_dump($o->getId(), $o->getValue('state'));
    // }



    $o = ConversationState::getActiveConversationByNumber($module, "16503803405");

    // var_dump($o);

    $o->setValue('foo','bar3');
    $o->save();
    var_dump($o);


    ConversationState::purgeChangeLogs($module,"CS",0);
    echo "Purged";


    // var_dump($CS);
}



if (false) {
    // Make a conversation
    // $CS = new ConversationState($module);
    // $CS->setValues([
    //     'state' => 'ACTIVE',
    //     'number' => '+16503803411',
    //     'instrument' => 'survey_1'
    // ]);
    // $CS->save();
    // var_dump($CS, $CS->getId());

    var_dump($module->getRecordIdByCellNumber("+16503803405"));

    // 'instrument', 'event_id', 'instance','number',
    // 'start_ts','current_question','reminder_ts','expiry_ts','state'
}
if (false) {
    $CS = new ConversationState($module);
    $CS->setValues([
        "day" => "light"
    ]);
    $CS->save();
    var_dump($CS);
}


// $sql = "select log_id where timestamp > date_sub(now(), interval ? day) and ui_id = ?";
//
// $results = $module->queryLogs($sql,[1,2]);
// $rows=[];
// while ($row = db_fetch_assoc($results)) {
//     $rows[] = $row;
// }
// var_dump($sql,$rows);

