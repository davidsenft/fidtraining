<?php

/**
 *
 * TEAM POST
 *
 **/

// TODO: rigor
$team_post = false;
$time = (String)time();
if ($_SERVER['REQUEST_METHOD'] === 'POST') $team_post = file_get_contents('php://input');

if ($team_post){
    // parse the post
    /* $posted = array();
	parse_str($team_post, $posted);
	var_dump($team_post);
	var_dump($posted); */

	$team_post_array = array($time, "Team", $team_post);

    // save to log
	$append_handle = fopen("../logs/post.log", "a");
	fputcsv($append_handle, $team_post_array, ',');
	fclose($append_handle);
}

?>