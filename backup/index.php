<?php

session_start();
date_default_timezone_set('America/New_York');

// var_dump($_SESSION);

$team = false;
$team_logged_in = false;
if (isset($_POST['team_login']) && isset($_POST['team_select']) && isset($_POST['team_password'])){
    // trying to log in
    $team = $_POST['team_select'];
}else if (isset($_SESSION['team_logged_in']) && isset($_SESSION['team_number'])){
    // always set team number!
    $team = $_SESSION['team_number']; // TODO: really though? whatabout initial load?
    if (isset($_GET['logout'])){
        // logging out
        $_SESSION['team_logged_in'] = false;
        $_SESSION['initialized'] = false;
        $team_logged_in = false;
        if ($_GET['logout'] == "marketended"){
            $login_message = "The market has ended. You have been logged out.";
        }else{
            $login_message = "You have been logged out.";
        }
    }else if ($_SESSION['team_logged_in']){
        // already logged in
        $team_logged_in = true;
    }
}else{
    // log out as a precaution // TODO: keep?
    unset($_SESSION['team_logged_in']);
    unset($_SESSION['team_number']);
    unset($_SESSION['initialized']);
    $team_logged_in = false;
}

// get post history from file
$post_history = array();
$read_handle = fopen("logs/post.log", "r");
while (!feof($read_handle)) $post_history[] = fgetcsv($read_handle, 1024, ',');
array_pop($post_history);
fclose($read_handle);

// parse post history
$market_round = 1;
$market_duration = 0;
$market_end_round_time = false;
$market_posts = array();
$market_teams = array();
$market_open = false;
$market_securities = array();
$market_positions = array(); // team only
$market_orders = array();
$serials = array();
$initialized = false;
if (count($post_history) > 0){
    foreach($post_history as $post_array){

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

                    if ($name == 'market' && strtolower($value) == 'initialize' && !$market_open){

                        // INITIALIZE!!!!
                        $market_round = 1;
                        $market_posts = array();
                        $market_teams = array();
                        $market_open = false;
                        $market_securities = array();
                        $market_positions = array(); // team only
                        $market_orders = array();
                        $serials = array();
                        $initialized = $timestamp;

                    }else if ($name == 'market' && strtolower($value) == 'open' && $initialized && !$market_open){

                        // open market
                        $market_open = true;
                        // $market_orders = array(); // only on team page
                        if ($market_duration && !$market_end_round_time){ // if duration is given before market=open
                            $market_end_round_time = $timestamp + $market_duration;
                        }

                    }else if ((($name == 'market' && strtolower($value) == 'closed') || $name == 'kill') && $initialized && $market_open){

                        // close market
                        $market_open = false;
                        $market_round++;
                        foreach($market_orders as $key=>$val){
                            if ($market_orders[$key]["status"] != "Error"){
                                $market_orders[$key]["status"] = "Ended";
                            }
                        } 
                    }

                }else if ($name == 'duration'){

                    // expected round duration in seconds
                    $market_duration = (int)$value;
                    if ($market_open){ // if the market is already open, update duration
                        $market_end_round_time = $timestamp + $market_duration;
                    }

                }else if ($name == 'team'){

                    // team passwords
                    foreach($value as $num=>$pwd){
                       $market_teams[$num] = $pwd; 
                    }
                
                }else if ($name == 'security'){

                    // securities
                    foreach($value as $num=>$name){
                       $market_securities[$num] = $name; 
                       $market_positions[$num] = 0; // team only
                    }

                }else if (substr($name, 0, 5) == "order" && $market_open){

                    // we're doing something that involves orders
                    $instruction_type = substr($name, 5);
                    foreach($value as $ordernum=>$val){

                        $market_posts[] = array($name, $val, $timestamp);
                        if (isset($serials[$ordernum])) $s = $serials[$ordernum];
                        if (isset($serials[$ordernum]) || $instruction_type == "serial"){
                        
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
                                    $most_recent_fill_qty = $val; // team only
                                    if (isset($market_orders[$s]["security"])){ // precaution
                                        $security_num = $market_orders[$s]["security"];
                                        if ($market_orders[$s]["orderteam"] == $team) // team only
                                            $market_positions[$security_num] += $val;
                                    }
                                    break;

                                case "fillprice":
                                    // TODO: errors for these on admin page
                                    if (isset($market_orders[$s]["security"]) && isset($most_recent_fill_qty)){ // precaution
                                        $verb = $most_recent_fill_qty > 0 ? "Bought " : "Sold ";
                                        $most_recent_fill_qty = abs($most_recent_fill_qty);
                                        $market_orders[$s]["prices"][] = $verb . $most_recent_fill_qty . " at " . $val; // TODO: 'bought'?
                                        unset($most_recent_fill_qty); // team only
                                    }
                                    break;

                            }
                        }
                    }
                }
            }

        }else if ($poster == "Team"){

            // this is a team order, so put it in the system
            // $diff = 100000 + ($timestamp - $initialized);
            $team_num = (int)$posted['team'];
            // if ($team_num < 10) $serial = $diff . "-0" . $team_num;
            // else $serial = $diff . "-" . $team_num;
            $serial = count($market_orders) + 1;
            $ordersecurity = isset($posted['security']) ? (int)$posted['security'] : -1; // TODO: case insensitive
            $market_orders[$serial] = array(
                    "timestamp" => $timestamp,
                    "ordernumber" => "",
                    "security" => $ordersecurity,
                    "orderteam" => $team_num,
                    "ordertext" => $posted['text'],
                    "status" => "Sent",
                    "errortext" => "",
                    "filled" => "0",
                    "round" => $market_round,
                    "prices" => array()
                );
                
        }
    }
}

// log team in or out, if applicable
$market_ended = false;
if ($team && !$team_logged_in && isset($_POST['team_login'])){
    // TODO: make sure post.log is not visible from browser
    // TODO: md5 hash passwords here and in log anyway
    if ($market_teams[$team] == $_POST['team_password']){
        $_SESSION['team_logged_in'] = true;
        $_SESSION['team_number'] = $team;
        $_SESSION['initialized'] = $initialized;
        $team_logged_in = true;
    }else{
        $_SESSION['team_logged_in'] = false;
        unset($_SESSION['team_number']);
        unset($_SESSION['initialized']);
        $team_logged_in = false;
        $login_message = "Invalid login. Please try again.";
    }
}else if (isset($_SESSION['initialized']) && $_SESSION['initialized'] != $initialized){
    // this is not the correct market, log out
    $market_ended = true;
}


if (count($post_history) > 0 && $initialized){

    // when was the market initialized
    $initialized_date = date('g:i a \o\n F j, Y', $initialized);

    // teams and securities
    $teams_securities_text = count($market_teams) . " teams, " . count($market_securities) . " securities";

    // securities dropdown
    $secselect = "";
    ksort($market_securities);
    foreach ($market_securities as $secnum=>$secname){
        // TODO: check that security numbers are contiguous before market open?
        $secselect .= "<option value='" . $secnum . "'>". $secname ."</option>";
    }

    // ksort($market_orders);
    $market_orders = array_reverse($market_orders, true);

}else{

    // no market

}

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
                    <?php if ($team_logged_in): ?>
                    <a class="btn btn-navbar" data-toggle="collapse" data-target=".nav-collapse">
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </a>
                    <?php endif; ?>
                    <img src='/img/mslogo.png' id='mslogo' />
                    <a class="brand" href="/">GOSPEL Case Study</a>
                    <!-- <span id="market-status" class="brand">Market is <?php echo $open_or_closed; ?></span> -->
                    <div class="nav-collapse collapse">
                        <?php if ($team_logged_in): ?>
                        <ul class="nav">
                            <!-- <li class="active"><a href="/">Team Login</a></li> -->
                            <li><a href="/?logout">Log Out</a></li>
                        </ul>
                        <?php endif; ?>
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

<?php if ($team_logged_in): ?>

            <div class="row">
                <div class="span8">
                    <h2>Market is <span id='open-or-closed'><?php echo $open_or_closed; ?></span></h2>
                    <!-- <?php // if ($market_end_round_time): ?><h4>Round ends at <?php // echo date('g:i a', $market_end_round_time); ?></h4><?php // endif; ?> -->
                    <!-- <h4><?php // echo $teams_securities_text; ?></h4> -->
                    <!-- <h4><?php // echo $round_text; ?></h4> -->

                    <hr>

                    <div class="row">
                        <div class="span6">
                            <form id="orderform" class='ajax' action='ajax/teampost.php' method='post'>
                                <input class='ajax' type='hidden' name='team' value='<?php echo $team; ?>' />
                                <select class='ajax' style='width:100px' name='security'>
                                    <option selected='selected'>...</option>
                                    <?php echo $secselect; ?>
                                </select>
                                <input class='ajax' style='width:160px;' type='text' name='text' placeholder='Enter order here...' />
                                <input class='btn' style='width:80px;margin-bottom:10px' type='submit' value='Submit' />
                            </form>
                        </div>
                        <div class="span2" style='text-align:right'>
                            <form id="cancelallform" class='ajax' action='ajax/teampost.php' method='post'>
                                <input class='ajax' type='hidden' name='team' value='<?php echo $team; ?>' />
                                <input class='ajax' type='hidden' name='text' value='cancel all' />
                                <input type='submit' id='cancelall' class='btn' value='Cancel All' <?php if (!$market_open): ?>disabled="disabled" <?php endif; ?>/>
                            </form>
                        </div>
                    </div>
                    
                </div>
                <div class="span4">
                    <!-- BLANK FOR NOW -->
                </div>
            </div>

            <hr>

            <div id="ajaxcontainer">
                <div id="ajaxdata" class="row" data-market-open="<?php echo $market_open; ?>" data-market-ended="<?php echo $market_ended; ?>">
                    <div class="span8">
                        <table id='teamorders'>
                            <tr style='font-weight:bold;border-bottom:1px solid #ccc;'>
                                <td>Time</td>
                                <td>O#</td>
                                <td>Order</td>
                                <td>Status</td>
                                <td>Filled</td>
                                <td>Cancel</td>
                            </tr>
                            <?php foreach($market_orders as $serial=>$order): ?>
                                <?php if (!isset($order["orderteam"])): ?>
                                    <tr class='error'>
                                        <td colspan='6'>ADMIN POST ERROR: Serial #<?php echo $serial; ?> does not exist.</td>
                                    </tr>
                                <?php elseif (($market_open && ($order["round"] != $market_round)) || (!$market_open && (($order["round"] + 1) != $market_round))): ?>
                                    <!-- Hidden order from previous round -->
                                <?php elseif ($order["orderteam"] == $team): ?>
                                    <?php 
                                    $etext = "";
                                    $trtd = "<tr class='" . strtolower($order['status']) . " sub-order'><td colspan='2'></td><td colspan='4'>";

                                    // fill prices
                                    $ptext = "";
                                    if (count($order['prices']) > 0){
                                        $glue = "</td></tr>" . $trtd;
                                        $ptext = $trtd . implode($glue, $order['prices']) . "</td></tr>";
                                    }

                                    // order text
                                    $otext = $order['ordertext'];
                                    if ($order['security'] >= 0 && $order['security'] != "...") 
                                        $otext = $market_securities[$order['security']] . " " . $otext;

                                    // cancel text
                                    $canceltext = "";
                                    if ($order['status'] == "Working"){
                                        $canceltext = "<form class='ajax' action='ajax/teampost.php' method='post' style='margin:0' onsubmit='return Gospel.ajaxSubmit(this);'><input class='ajax' type='hidden' name='team' value='" . $team . "' /><input class='ajax' type='hidden' name='text' value='cancel order ".$order['ordernumber']."' /><input type='submit' class='cancel-order btn' value='Cancel' /></form>";

                                    // error text
                                    }else if ($order["errortext"]){
                                        $etext = $trtd . "<strong>ERROR:</strong> " . $order["errortext"] . "</td></tr>";
                                    }
                                    ?>
                                    <tr class='<?php echo strtolower($order['status']); ?>'>
                                        <td><?php echo date('H:i:s', $order['timestamp']); ?></td>
                                        <td><?php echo $order['ordernumber']; ?></td>
                                        <td><?php echo $otext; ?></td>
                                        <td><?php echo $order['status']; ?></td>
                                        <td><?php echo $order['filled'] == 0 ? "" : $order['filled']; ?></td>
                                        <td><?php echo $canceltext; ?></td>
                                    </tr>
                                    <?php echo $ptext; ?>
                                    <?php echo str_replace("%20", " ", $etext); ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <div class="span4"  style='overflow:auto;'>
                        <h2 id='teampositionheader'>Team <?php echo $team; ?> Position</h2>
                        <table id='teamposition'>
                            <!-- <tr style='font-weight:bold;'>
                                <td>#</td>
                                <td>Security Name</td>
                                <td>Position</td>
                            </tr> -->
                            <?php foreach($market_securities as $num=>$sec): ?>
                                <?php 

                                    $thisposition = $market_positions[$num];
                                    if ($thisposition > 0){
                                        $tdclass = "pos";
                                    }else if ($thisposition < 0){
                                        $tdclass = "neg";
                                    }else{
                                        $tdclass = "zero";
                                    }
                                    // if ($thisposition == 0) $thisposition = "";

                                ?>
                                <tr>
                                    <!-- TODO: allow sorting -->
                                    <!-- <td><?php echo $num; ?></td> -->
                                    <td><?php echo $sec; ?></td>
                                    <td class='<?php echo $tdclass; ?>'><?php echo $thisposition; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>

<?php else: ?>

            <div class="row">
                <div class="span4"></div>
                <div class="span4">

                    <h1>Team Login</h1>
                    <form id='loginform' action='/' method='post'>
                        <input type='hidden' name='team_login' value='1' />
                        <select name='team_select'>
                            <option selected='selected'>Select Team #</option>
                            <?php foreach($market_teams as $num=>$pwd): ?>
                            <option value='<?php echo $num; ?>'>Team <?php echo $num; ?></option>
                            <?php endforeach; ?>
                        </select><br />
                        <input type='password' name='team_password' placeholder='Your team password' /><br />
                        <input type='submit' class='btn' value='Login' />
                    </form>
                    <?php if (isset($login_message)): ?>
                    <p class='error'><?php echo $login_message; ?></p>
                    <?php endif; ?>

                </div>
                <div class="span4"></div>
            </div>

<?php endif; ?> 

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