<?php
// config/db.php

$host = "localhost";
$user = "root";
$pass = "4002@Jose";
$db   = "jolly-micro-loans";

$conn = mysqli_connect($host, $user, $pass, $db);

if ($conn->connect_error) {
    // Log the real error, show generic message to user
    error_log("DB connection failed: " . $conn->connect_error);
    die("Service temporarily unavailable. Please try again later.");
}

$conn->set_charset("utf8mb4");