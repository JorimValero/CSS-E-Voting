<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_host = "localhost";
$db_username = "root";
$db_password = "";
$db_name = "css_voting_system";

$conn = mysqli_connect($db_host, $db_username, $db_password, $db_name);

if (!$conn) {
    die("Connection Failed: " . mysqli_connect_error());
}
