<?php

include '../database/connect.php';

$fullname = "CSS Administrator";
$username = "admin";
$email = "admin@css.edu.ph";
$password = "admin123";
$role = "admin";

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO accounts
        (fullname, username, email, password, role)
        VALUES (?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "sssss",
    $fullname,
    $username,
    $email,
    $hashed_password,
    $role
);

if ($stmt->execute()) {
    echo "Admin account created successfully.<br>";
    echo "Username: admin<br>";
    echo "Password: admin123";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();

?>