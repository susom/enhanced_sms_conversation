<?php
namespace Stanford\EnhancedSMSConversation;

use REDCap;

/** @var EnhancedSMSConversation $module */
$config = $module->getConfig();

/** @set \Project $Proj */
global $Proj;

// Replace this with your module code
?>
    <!doctype html>
    <html lang="en">
    <head>
        <!-- Required meta tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- Bootstrap CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">

        <title><?= $config['name'] ?>Test Page</title>
        <meta name="description" content="<?= $config['name'] ?>Test Page">
        <meta name="author" content="
            <?php
                $authors = [];
                foreach ($config['authors'] as $author) {
                    $authors[] = $author['name'];
                }
                echo implode(", ", $authors);
            ?>
        ">
        <link rel="icon" href="<?= APP_PATH_WEBROOT ?>Resources/images/favicon.ico">
    </head>

    <style>
        pre {
            white-space: pre-wrap;       /* css-3 */
            font-size:small;
        }
    </style>

    <body>
        <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js" integrity="sha384-IQsoLXl5PILFhosVNubq5LC7Qb9DXgDA9i+tQ8Zj3iwWAwPtgFTxbJ8NT4GN1R8p" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js" integrity="sha384-cVKIPhGWiC2Al4u+LWgxfKTRIcfu0JTxR+EQDz/bgldoEyl4H0zUF0QKbrJ0EcQF" crossorigin="anonymous"></script>


        <nav class="navbar navbar-expand-lg navbar-light bg-light">
            <div class="container-fluid">
                <a class="navbar-brand" href="<?= APP_PATH_WEBROOT . "index.php?pid=" . $module->getProjectId() ?>"><?= $config['name'] ?></a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item">
                            <a class="nav-link active" aria-current="page" href="#">Home</a>
                        </li>
<!--                        <li class="nav-item">-->
<!--                            <a class="nav-link" href="#">Link</a>-->
<!--                        </li>-->
<!--                        <li class="nav-item dropdown">-->
<!--                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">-->
<!--                                Dropdown-->
<!--                            </a>-->
<!--                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">-->
<!--                                <li><a class="dropdown-item" href="#">Action</a></li>-->
<!--                                <li><a class="dropdown-item" href="#">Another action</a></li>-->
<!--                                <li><hr class="dropdown-divider"></li>-->
<!--                                <li><a class="dropdown-item" href="#">Something else here</a></li>-->
<!--                            </ul>-->
<!--                        </li>-->
<!--                        <li class="nav-item">-->
<!--                            <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true">Disabled</a>-->
<!--                        </li>-->
                    </ul>
<!--                    <form class="d-flex">-->
<!--                        <input class="form-control me-2" type="search" placeholder="Search" aria-label="Search">-->
<!--                        <button class="btn btn-outline-success" type="submit">Search</button>-->
<!--                    </form>-->
                </div>
            </div>
        </nav>


        <main role="main" class="container">
            <div class="jumbotron">
                <h1>Welcome to <?= $config['name'] ?> Admin Testpage</h1>
                <p class="lead">This page is designed to help you do tests!</p>
            </div>
        </main>

        <div class="card" style="">
            <div class="card-body">
                <h5 class="card-title">Twilio Inbound URLs</h5>
<!--                <h6 class="card-subtitle mb-2 text-muted">Card subtitle</h6>-->
                <p class="card-text"><code><?= $module->getUrl("pages/inbound.php",true,true) ?></code></p>
                <p class="card-text"><code><?= $module->getUrl("pages/inbound.php",true,false) ?></code></p>
<!--                <a href="#" class="card-link">--><?php //= $module->getUrl("pages/inbound.php",true,true) ?><!--</a>-->
            </div>
        </div>
        <div class="card" style="">
            <div class="card-body">
                <h5 class="card-title">Select Record Context</h5>
                <div class="input-group mb-3">
                    <div class="input-group-prepend">
                        <span class="input-group-text" id="basic-addon3">Record ID</span>
                    </div>
                    <select class="custom-select" id="selected_record_id">
                        <?php
                            $params = [
                                'fields' => [ REDCap::getRecordIdField() ]
                            ];
                            $result = REDCap::getData($params);
                            $record_ids=[];
                            foreach ($result as $id => $data) {
                                $record_ids[] = $id;
                            }
                            foreach($record_ids as $record) {
                                $selected = ($record == $_GET['selected_record_id']) ? "selected" : "";
                                echo "\n<option $selected value='$record'>$record</option>";
                            }
                        ?>
                    </select>
                </div>
                <div class="input-group mb-3">
                    <div class="input-group-prepend">
                        <span class="input-group-text" id="basic-addon3">Event ID</span>
                    </div>
                    <select class="custom-select" id="selected_event_id">
                        <?php
                            foreach ($Proj->events as $arm_num => $arm_data) {
                                foreach ($arm_data['events'] as $event_id => $event_data) {
                                    $selected = ($event_id == $_GET['selected_event_id']) ? "selected" : "";
                                    echo "\n<option $selected value='$event_id'>$event_id: " . $arm_data['name'] . " " . $event_data['descrip'] . "</option>";
                                }
                            }
                        ?>
                    </select>
                </div>
                <div class="input-group mb-3">
                    <div class="input-group-prepend">
                        <span class="input-group-text" id="basic-addon3">Instance ID</span>
                    </div>
                    <input type="text" class="form-control" id="selected_instance_id" aria-describedby="basic-addon3">
                </div>
                <button type="button" class="btn btn-primary" id="set_context">Set Context</button>
            </div>
        </div>
<?php



// Testing the metadata parser functions:
$project_id = $module->getProjectId();
$first_event_id = $Proj->getFirstEventIdArm(1);

$branching_logic1 = "([step_prompt]>0) AND ([step_prompt] <= 2000) AND ([current-instance]='1')";
$branching_logic2 = "([step_prompt]>0) AND ([step_prompt] <= 2000) AND ([current-instance]='1')";

$event_id = $first_event_id;
$record_id = 2;
$repeat_instance=1;
$repeat_form="daily";
$result1 = REDCap::evaluateLogic($branching_logic1, $project_id, $record_id, $event_id, $repeat_instance, $repeat_form, $repeat_form);
$result2 = REDCap::evaluateLogic($branching_logic2, $project_id, $record_id, $event_id, $repeat_instance, $repeat_form);

echo "<pre>" . "\nEvent: $event_id\nRecord: $record_id\nInstance: $repeat_instance\n";

echo "\nLogic: $branching_logic1\nResult: " . json_encode($result1);
echo "\nLogic: $branching_logic2\nResult: " . json_encode($result2);
echo "</pre>";

exit();




var_dump($project_id, $first_event_id, $Proj);
exit();

$project_id = 81;
$event_id = 167;
$record = "34";


$FM = new FormManager($module, 'survey_1', $next_field, $record, $event_id,81);
var_dump("Started at [" . $FM->getStartField() . "], AT [" .  $FM->getCurrentField() . "] next is [" . $FM->getNextField() . "]",
    $FM->getQuestionLabel(),$FM->getInstructions(),
    "Messages",$FM->getMessages(), $FM->getChoices());

$next_field = $FM->getNextField();
$FM = new FormManager($module, 'survey_1', $next_field, $record, $event_id,81);
var_dump("Started at [" . $FM->getStartField() . "], AT [" .  $FM->getCurrentField() . "] next is [" . $FM->getNextField() . "]",
    $FM->getQuestionLabel(),$FM->getInstructions(),
    "Messages",$FM->getMessages(), $FM->getChoices());

$next_field = $FM->getNextField();
$FM = new FormManager($module, 'survey_1', $next_field, $record, $event_id,81);
var_dump("Started at [" . $FM->getStartField() . "], AT [" .  $FM->getCurrentField() . "] next is [" . $FM->getNextField() . "]",
    $FM->getQuestionLabel(),$FM->getInstructions(),
    "Messages",$FM->getMessages(), $FM->getChoices());

$next_field = $FM->getNextField();
$FM = new FormManager($module, 'survey_1', $next_field, $record, $event_id,81);
var_dump("Started at [" . $FM->getStartField() . "], AT [" .  $FM->getCurrentField() . "] next is [" . $FM->getNextField() . "]",
    $FM->getQuestionLabel(),$FM->getInstructions(),
    "Messages",$FM->getMessages(), $FM->getChoices());

$next_field = $FM->getNextField();
$FM = new FormManager($module, 'survey_1', $next_field, $record, $event_id,81);
var_dump("Started at [" . $FM->getStartField() . "], AT [" .  $FM->getCurrentField() . "] next is [" . $FM->getNextField() . "]",
    $FM->getQuestionLabel(),$FM->getInstructions(),
    "Messages",$FM->getMessages(), $FM->getChoices());

$next_field = $FM->getNextField();
$FM = new FormManager($module, 'survey_1', $next_field, $record, $event_id,81);
var_dump("Started at [" . $FM->getStartField() . "], AT [" .  $FM->getCurrentField() . "] next is [" . $FM->getNextField() . "]",
    $FM->getQuestionLabel(),$FM->getInstructions(),
    "Messages",$FM->getMessages(), $FM->getChoices());

$next_field = $FM->getNextField();
$FM = new FormManager($module, 'survey_1', $next_field, $record, $event_id,81);
var_dump("Started at [" . $FM->getStartField() . "], AT [" .  $FM->getCurrentField() . "] next is [" . $FM->getNextField() . "]",
    $FM->getQuestionLabel(),$FM->getInstructions(),
    "Messages",$FM->getMessages(), $FM->getChoices());

$next_field = $FM->getNextField();
$FM = new FormManager($module, 'survey_1', $next_field, $record, $event_id,81);
var_dump("Started at [" . $FM->getStartField() . "], AT [" .  $FM->getCurrentField() . "] next is [" . $FM->getNextField() . "]",
    $FM->getQuestionLabel(),$FM->getInstructions(),
    "Messages",$FM->getMessages(), $FM->getChoices());

exit();

$q = $FM->setStartingField($start_field,$record);
echo "1-----\n[ $start_field ]\n";
$current_field = $FM->getCurrentField();
var_dump($current_field, $FM->getPreLabels(), $FM->getQuestionLabel(), $FM->getChoices(), $FM->getInstructions(), $FM->getInvalidResponseMessage());

echo "2-----\n[ $current_field ]\n";
$q = $FM->setStartingField($current_field,$record);
$current_field = $FM->getCurrentField();
var_dump($current_field, $FM->getPreLabels(), $FM->getQuestionLabel(), $FM->getChoices(), $FM->getInstructions(), $FM->getInvalidResponseMessage());

echo "2b-----\n[ $current_field ]\n";
$q = $FM->setStartingField($current_field,$record);
$current_field = $FM->getCurrentField();
var_dump($current_field, $FM->getPreLabels(), $FM->getQuestionLabel(), $FM->getChoices(), $FM->getInstructions(), $FM->getInvalidResponseMessage());

$current_field = $FM->getNextField($current_field);
echo "3-----\n[ $current_field ]\n";
$q = $FM->setStartingField($current_field,$record);
$current_field = $FM->getCurrentField();
var_dump($current_field, $FM->getPreLabels(), $FM->getQuestionLabel(), $FM->getChoices(), $FM->getInstructions(), $FM->getInvalidResponseMessage());





// $q = $FM->setStartingField($q['current_field'], $record);
// var_dump($q);
//
// $nf = $FM->getNextField($q['current_field']);
// $q = $FM->setStartingField($nf, $record);
// var_dump($nf,$q);
//
// $q = $FM->setStartingField($q['current_field'], $record);
// var_dump($q['current_field'],$q);
//
// $nf = $FM->getNextField($q['current_field']);
// $q = $FM->setStartingField($nf, $record);
// var_dump($nf,$q);


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







        ?>
    </body>
</html>

