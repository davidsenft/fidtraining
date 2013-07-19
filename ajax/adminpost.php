<?php

/**
 *
 * ADMIN POST
 *
 **/

// TODO: rigor
$admin_post = false;
$time = (String)time();
if ($_SERVER['REQUEST_METHOD'] === 'POST') $admin_post = file_get_contents('php://input');

if ($admin_post){
    // parse the post
    /* $posted = array();
	parse_str($admin_post, $posted);
	var_dump($admin_post);
	var_dump($posted); */

	$admin_post_array = array($time, "Admin", $admin_post);

    // save to log
	$append_handle = fopen("../logs/post.log", "a");
	fputcsv($append_handle, $admin_post_array, ',');
	fclose($append_handle);
}

?>