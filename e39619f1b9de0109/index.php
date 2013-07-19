<?php

date_default_timezone_set('America/New_York');
    
// POST.LOG CSV FIELDS:
// time    author    data 

/**
 *
 * QUESTIONS
 *
 * (1) Set round start time?
 * (2) "Round over at X" text? Timer?
 *
 * TODO
 *
 * (3) **LOW PRIORITY** Ability to sort order order
 * (4) **LOW PRIORITY** Check for/escape bad syntax in order field (i.e. via regex)
 * (5) Use Duration?
 * (6) No orders allowed when market is closed
 * (480) 505-8859 <-- godaddy
 *
 **/

// get post history from file
$post_history = array();
$read_handle = fopen("../logs/post.log", "r");
while (!feof($read_handle)) $post_history[] = fgetcsv($read_handle, 1024, ',');
array_pop($post_history);
fclose($read_handle);

// get current post, if there is one, and add it to history and file
$admin_post = false;
$time = (String)time();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET)
    $admin_post = $_SERVER['QUERY_STRING'];
/* else if ($_SERVER['REQUEST_METHOD'] === 'POST') $admin_post = file_get_contents('php://input'); */
if ($admin_post){
    $admin_post_array = array($time, "Admin", $admin_post);
    $post_history[] = $admin_post_array;
}

// TODO: sort post_history by "sent" timestamps?

// parse post history
$market_round = 1;
$market_duration = 0;
$market_end_round_time = false;
$market_posts = array();
$market_teams = array();
$market_open = false;
$market_securities = array();
$market_orders = array();
$serials = array();
$initialized = false;
// $error_msg = false; // OLD
if (count($post_history) > 0){
    foreach($post_history as $post_array){

        $error_msg = false; // clear errors for previous messages
        $poster = $post_array[1];
        $posted = array();
        parse_str($post_array[2], $posted);
        $timestamp = $post_array[0];

        if (($poster == "Admin" && count($posted) > 0) || !$initialized){

            // this is an admin post
            foreach($posted as $name=>$value){
                $name = strtolower($name);

                if ($name == 'market' || $name == 'kill' || !$initialized){

                    // TODO: check that $value is a string to prevent error

                    if ($name == 'market' && strtolower($value) == 'initialize'){

                        if ($market_open){

                            // error
                            $error_msg = "ERROR: close existing market before initializing.";

                        }else{

                            // INITIALIZE!!!!
                            $market_round = 1;
                            $market_posts = array();
                            $market_teams = array();
                            // $error_msg = false; // If errors should only be cleared by initialize
                            $market_open = false;
                            $market_securities = array();
                            $market_orders = array();
                            $serials = array();
                            $initialized = $timestamp;

                        }

                    }else if (!$initialized){

                        // error
                        $error_msg = "ERROR: market is not initialized.";

                    }else if ($name == 'market' && strtolower($value) == 'open'){

                        if (!$market_open){
                        
                            // open market
                            // TODO: check for contiguous team and security numbers?
                            $market_open = true;
                            if ($market_duration && !$market_end_round_time){ // if duration is given before market=open
                                $market_end_round_time = $timestamp + $market_duration;
                            }

                        }else{

                            // error
                            $error_msg = "ERROR: market is already open.";

                        }

                    }else if (($name == 'market' && strtolower($value) == 'closed') || $name == "kill"){

                        if ($market_open){

                            // close market
                            $market_open = false;
                            $market_round++;
                            foreach($market_orders as $key=>$val){
                                if ($market_orders[$key]["status"] != "Error"){
                                    $market_orders[$key]["status"] = "Ended";
                                }
                            }

                        }else{

                            // error
                            $error_msg = "ERROR: market is already closed.";

                        }

                    }else{

                        // error
                        $error_msg = "ERROR: unrecognized value for 'market'.";

                    }

                }else if ($name == 'duration'){

                    // expected round duration in seconds
                    $market_duration = (int)$value;
                    if ($market_open){ // if the market is already open, update duration
                        $market_end_round_time = $timestamp + $market_duration;
                    }

                }else if ($name == 'team'){

                    // TODO: check for well-formedness
                    foreach($value as $num=>$pwd){
                       $market_teams[$num] = $pwd; 
                    }
                
                }else if ($name == 'security'){

                    // TODO: check for well-formedness
                    foreach($value as $num=>$name){
                       $market_securities[$num] = $name; 
                    }

                }else if ($name == 'showadmin'){

                    // do nothing...

                }else if (substr($name, 0, 5) == "order"){

                    if ($market_open){

                        // we're doing something that involves orders
                        $instruction_type = substr($name, 5);
                        foreach($value as $ordernum=>$val){

                            $market_posts[] = array($name, $val, $timestamp);
                            if (isset($serials[$ordernum])) $s = $serials[$ordernum];
                            if (!isset($serials[$ordernum]) && $instruction_type != "serial"){

                                // error
                                $error_msg = "ERROR: serial unknown for order " . $ordernum . ".";

                            }else{
                            
                                switch ($instruction_type){
                                    case "serial":
                                        $market_orders[$val]["ordernumber"] = $ordernum;
                                        $serials[$ordernum] = $val;
                                        break;

                                    case "status":
                                        $market_orders[$s]["status"] = $val;
                                        break;

                                    case "errortext":
                                        $market_orders[$s]["errortext"] = $val;
                                        break;

                                    case "fillquantity":
                                        if (!isset($market_orders[$s]["filled"]))
                                            $market_orders[$s]["filled"] = 0; // precaution
                                        $market_orders[$s]["filled"] += $val;
                                        $most_recent_fill_qty = $val;
                                        break;

                                    case "fillprice":
                                        if (isset($most_recent_fill_qty)){
                                            $verb = $most_recent_fill_qty > 0 ? "Bought " : "Sold ";
                                            $most_recent_fill_qty = abs($most_recent_fill_qty);
                                            $market_orders[$s]["prices"][] = $verb . $most_recent_fill_qty . " at " . $val; // TODO: 'bought'?
                                            unset($most_recent_fill_qty);
                                        }else{

                                            // error
                                            $error_msg = "ERROR: Must set fill quantity before setting fill price.";

                                        }
                                        break;

                                    // TODO: else throw error?
                                }
                            }

                        }

                    }else{

                        // error
                        $error_msg = "ERROR: orders cannot be placed or filled when the market is closed.";

                    }

                }else{

                    // error
                    $error_msg = "ERROR: unrecognized parameter: '" . $name . "'";

                }

            }

        }else if ($poster == "Team"){

            if(!!$market_securities){ // workaround that doesn't really make sense. TODO: make sure session is correct somewhere before this.

                // this is a team order, so put it in the system
                $team_num = (int)$posted['team'];
                // $diff = 100000 + ($timestamp - $initialized);
                // if ($team_num < 10) $serial = $diff . "-0" . $team_num;
                // else $serial = $diff . "-" . $team_num;
                $serial = count($market_orders) + 1;
                $ordersecurity = isset($posted['security']) ? (int)$posted['security'] : -1; // NOTE: params in team post are case sensitive
                $securitytext = ($ordersecurity > 0) ? " " . $market_securities[$ordersecurity] : ""; // NOTE: securities cannot be assigned #0
                $market_orders[$serial] = array(
                        "timestamp" => $timestamp,
                        "ordernumber" => "",
                        // "security" => $ordersecurity,
                        "orderteam" => $team_num,
                        "ordertext" => "Team " . $team_num . $securitytext . " " . $posted['text'],
                        "status" => "Sent",
                        "errortext" => "",
                        "filled" => 0,
                        "round" => $market_round
                    );
               
            } 
        }
    }
}

if (isset($admin_post_array[2])){
    $just_showing_admin = ($admin_post_array[2] == "showadmin" || $admin_post_array[2] == "showadmin=1" || $admin_post_array[2] == "showadmin=true");
}else{
    $just_showing_admin = false;
}

if ($admin_post && !$error_msg && !$just_showing_admin){

    // save this post to log
    $append_handle = fopen("../logs/post.log", "a");
    fputcsv($append_handle, $admin_post_array, ',');
    fclose($append_handle);

}else if ($error_msg){

    // save this post to error log
    $admin_post_array[] = urlencode($error_msg);
    $append_handle = fopen("../logs/error.log", "a");
    fputcsv($append_handle, $admin_post_array, ',');
    fclose($append_handle);
}

// TODO: write order statuses to order log?

$display_page = false;
if (count($post_history) > 0 && $initialized){

    // when was the market initialized
    $initialized_date = date('H:i:s \o\n F j, Y', $initialized);

    // market teams
    $teamtext = "";
    ksort($market_teams);
    foreach ($market_teams as $teamnum=>$teampwd){
        // TODO: check that team numbers are contiguous before market open?
        $teamtext .= "Team #" . $teamnum . ", pwd: <strong>" . $teampwd . "</strong><br />";
    }

    // market securities
    $sectext = "";
    ksort($market_securities);
    foreach ($market_securities as $secnum=>$secname){
        // TODO: check that security numbers are contiguous before market open?
        $sectext .= "Security #" . $secnum . ": <strong>\"". $secname ."\"</strong><br />";
    }

    // ksort($market_orders);
    $market_orders = array_reverse($market_orders, true);

    if (isset($_GET['showadmin'])){

        /* if (md5($_GET['showadmin']) == 'e39619f1b9de010969d611274e7397d7'){ */
            // show the admin page
            $display_page = true;
        /* }else{
            // bad login info
            echo "ERROR: authentication failed.";
        } */

    }else if (!$admin_post){

        // error
        echo "ERROR: no data received.";

    }else if ($error_msg){

        // error
        echo $error_msg;

    }else{

        // success
        echo "SUCCESS: " . $admin_post . "<br />";

    }
}else{

    // no market

}

if ($display_page):

    $open_or_closed = $market_open ? "open" : "closed";
    $open_or_closed = "<span class=\"" . $open_or_closed . "\">" . $open_or_closed . "</span>";
    if ($market_open){
        $round_text = "Current Round: <strong>" . $market_round . "</strong>";
    }else{
        $round_text = "Completed Rounds: <strong>" . ($market_round - 1) . "</strong>";
    }

?><!DOCTYPE html>
<!--[if lt IE 7]>      <html class="no-js lt-ie9 lt-ie8 lt-ie7"> <![endif]-->
<!--[if IE 7]>         <html class="no-js lt-ie9 lt-ie8"> <![endif]-->
<!--[if IE 8]>         <html class="no-js lt-ie9"> <![endif]-->
<!--[if gt IE 8]><!--> <html class="no-js"> <!--<![endif]-->
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
        <title></title>
        <meta name="description" content="">
        <meta name="viewport" content="width=device-width">

        <link rel="stylesheet" href="/css/bootstrap.min.css">
        <style>
            body {
                padding-top: 60px;
                padding-bottom: 40px;
            }
        </style>
        <link rel="stylesheet" href="/css/bootstrap-responsive.min.css">
        <link rel="stylesheet" href="/css/main.css?ver=5">

        <script src="/js/vendor/modernizr-2.6.2-respond-1.1.0.min.js"></script>
    </head>
    <body>
        <!--[if lt IE 7]>
            <p class="chromeframe">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
        <![endif]-->

        <div class="navbar navbar-inverse navbar-fixed-top">
            <div class="navbar-inner">
                <div class="container">
                    <!-- <a class="btn btn-navbar" data-toggle="collapse" data-target=".nav-collapse">
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </a> -->
                    <img src='/img/mslogo.png' id='mslogo' />
                    <a class="brand" href="/">GOSPEL Case Study</a>
                    <div class="nav-collapse collapse">
                        <!-- <ul class="nav">
                            <li><a href="/">Team Login</a></li>
                            <li><a href="/about">About</a></li>
                            <li class="active"><a href=".">Admin</a></li>
                        </ul> -->
                        <!-- <form class="navbar-form pull-right">
                            <input class="span2" type="text" placeholder="Email">
                            <input class="span2" type="password" placeholder="Password">
                            <button type="submit" class="btn">Sign in</button>
                        </form> -->
                    </div>
                </div>
            </div>
        </div>

        <div class="container">

            <div class="row">
                <div class="span4">
                    <h2>Market is <span id='open-or-closed'><?php echo $open_or_closed; ?></span></h2>
                    <p>Initialized at <strong><?php echo $initialized_date; ?></strong></p>
                    <p><?php echo $round_text; ?> <?php if ($market_end_round_time): ?><span class='endtime'>(ends at <?php echo date('g:i a', $market_end_round_time); ?>)</span><?php endif; ?></p>
                </div>
                <div class="span8">
                    <form class="ajax" id="killform" action="../ajax/adminpost.php" method="post">
                        <input type="hidden" name="kill" value="1" class="ajax" />
                        <input id="killswitch" type="submit" class="btn" value="Kill Round" <?php if (!$market_open): ?>disabled="disabled" <?php endif; ?>/>
                    </form>
                </div>
            </div>

            <hr>

            <div id="ajaxcontainer">
                <div id="ajaxdata" class="row" data-market-open="<?php echo $market_open; ?>" data-market-end-round-time="<?php echo $market_end_round_time; ?>">
                    <div class="span2" style="overflow:auto;">
                        <h3>Teams:</h3>
                        <p style="font-size:0.9em;"><?php echo $teamtext; ?></p>
                        <h3>Securities:</h3>
                        <p style="font-size:0.9em;"><?php echo $sectext; ?></p>
                    </div>
                    <div class="span5"  style='overflow:auto;'>
                        <h2>Orders</h2>
                        <table id='orders'>
                            <tr style='font-weight:bold;'>
                                <td>Time</td>
                                <td>Serial</td>
                                <td>O#</td>
                                <td>Text</td>
                                <td>Status</td>
                                <td>Filled</td>
                            </tr>
                            <?php $current_round = 0; $orderkeys = array(); ?>
                            <?php foreach($market_orders as $serial=>$order): ?>
                                <?php $orderkeys[$order["timestamp"] . "-" . $order["orderteam"]] = $serial; ?>
                                <?php if (!isset($order["round"])): ?>
                                    <tr class='error'>
                                        <td colspan='6'>ADMIN POST ERROR: Serial #<?php echo $serial; ?> does not exist.</td>
                                    </tr>
                                <?php elseif ($order["round"] > $current_round): ?>
                                    <?php $current_round = $order["round"]; ?>
                                    <tr class='newround'>
                                        <td colspan='6'>Round <?php echo $current_round; ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (isset($order["round"])): ?>
                                <tr class='<?php echo strtolower($order['status']); ?>'>
                                    <td><?php echo date('H:i:s', $order['timestamp']); ?></td>
                                    <td><?php echo $serial; ?></td>
                                    <td><?php echo $order['ordernumber']; ?></td>
                                    <td><?php echo $order['ordertext']; ?></td>
                                    <td><?php echo $order['status']; ?></td>
                                    <td><?php echo $order['filled'] == 0 ? "" : $order['filled']; ?></td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </table>
                        <!-- <br />
                        <h2>Admin Post Log</h2>
                        <table id='tabledata'>
                            <tr style='font-weight:bold;'>
                                <td>Time</td>
                                <td>Name</td>
                                <td>Value</td>
                            </tr>
                            <?php // foreach($market_posts as $row): ?>
                            <tr>
                                <td><?php // echo date('H:i:s', $row[2]); ?></td>
                                <td><?php // echo $row[0]; ?></td>
                                <td><?php // echo $row[1]; ?></td>
                            </tr>
                            <?php // endforeach; ?>
                        </table> -->
                    </div>
                    <div class="span5" style="overflow:auto;">
                        <h2>I/O Log</h2>
                        <table id='iolog'>
                            <tr style='font-weight:bold;'>
                                <td>Time</td>
                                <td>Author</td>
                                <td>Serial</td>
                                <td>Content</td>
                            </tr>
                            <?php $postcount = 0; ?>
                            <?php $post_history = array_reverse($post_history); ?>
                            <?php foreach($post_history as $post): ?>
                                <?php

                                $postcount++;
                                $ioserial = "";
                                $last = false;
                                $date = date('H:i:s', $post[0]);
                                $content = str_replace("&","<br />&",$post[2]);
                                if ($post[1] == "Admin"){

                                    $ioserial = "n/a";
                                    $rowclass = "admin";
                                    $author = "Admin";

                                }else{

                                    $posted = array();
                                    parse_str($post[2], $posted);
                                    $rowclass = "team";
                                    $author = "Team " . $posted['team'];
                                    $content = $posted['text'];
                                    $ioserial = $orderkeys[$post[0] . "-" . $posted['team']];
                                    if (isset($posted['security'])) $content = $market_securities[$posted['security']] . " " . $content;
                                }

                                if ($postcount == 1){
                                    $date .= "<br />(Current)";
                                    $last = true;
                                    $rowclass .= " last";
                                    if ($error_msg){
                                        $content .= "<br /><span class='error'>" . $error_msg . "</span>";
                                    }
                                }

                                ?>
                                <tr class='<?php  echo $rowclass; ?>'>
                                    <td><?php echo $date; ?></td>
                                    <td><?php echo $author; ?></td>
                                    <td><?php echo $ioserial; ?></td>
                                    <td><?php echo str_replace("%20", " ", urldecode($content)); // double escaping to cancel out bug on Excel end ?></td>
                                </tr>
                                <?php
                                // TODO: remove this once sorting is possible, and when old markets are saved in their own files
                                if ($author == "Admin" && stripos($content, "market=initialize") !== false){
                                    break;
                                }
                                ?>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>

            <hr>

            <footer>
                <p>&copy; Morgan Stanley 2013.</p>
            </footer>

        </div> <!-- /container -->



        <script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>
        <script>window.jQuery || document.write('<script src="/js/vendor/jquery-1.9.1.min.js"><\/script>')</script>

        <script src="/js/vendor/bootstrap.min.js"></script>

        <script src="/js/main.js"></script>
    </body>
</html>

<?php endif; // display page ?>
