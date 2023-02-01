<?php
namespace Stanford\EnhancedSMSConversation;

/** @var EnhancedSMSConversation $module */

// Replace this with your module code
echo "Hello from $module->PREFIX";

// $CS = ConversationState::buildConversationStateFromId($module, 5);


$c = 3;


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

if (true) {
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

    $ids = SimpleEmLogObject::queryIds($module,
        "CS",
        "number = ? and record = ? and timestamp > ?",
        ["16503803405", 5, date("2023-01-27 16:34:46")]);

    $id = current($ids);
    $CS = new SimpleEmLogObject($module,"CS",$id);

    var_dump($ids, $id, $CS);

    $CS->setValue("record",null);

    $CS->save();

    var_dump($CS);



    // var_dump($CS);
}
