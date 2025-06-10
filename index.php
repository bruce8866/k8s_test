<?php
// src/index.php
require __DIR__ . '/vendor/autoload.php';
session_start();
require __DIR__ . '/config.php';

$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES_READONLY,
    Google_Service_Classroom::CLASSROOM_ROSTERS_READONLY,
]);

if (!isset($_GET['code'])) {
    header('Location: ' . $client->createAuthUrl());
    exit;
} else {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $_SESSION['access_token'] = $token;
    header('Location: ' . GOOGLE_REDIRECT_URI);
    exit;
}

if (isset($_SESSION['access_token'])) {
    $client->setAccessToken($_SESSION['access_token']);
    $service = new Google_Service_Classroom($client);
    $courses = $service->courses->listCourses(['pageSize' => 1]);
    $courseId = $courses->getCourses()[0]->getId();
    $students = $service->courses_students->listCoursesStudents($courseId)->getStudents();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $stmt = $pdo->prepare("INSERT INTO attendance (student_id, course_id, present, timestamp) VALUES (?, ?, ?, NOW())");
        foreach ($students as $stu) {
            $present = isset($_POST['present_' . $stu->getProfile()->getId()]) ? 1 : 0;
            $stmt->execute([
                $stu->getProfile()->getId(),
                $courseId,
                $present,
            ]);
        }
        echo "<p>點名已記錄！</p>";
    }

    echo '<h1>課程點名系統</h1><form method="POST"><ul>';
    foreach ($students as $stu) {
        $id = $stu->getProfile()->getId();
        $name = htmlspecialchars($stu->getProfile()->getName()->getFullName());
        echo "<li><label><input type=\"checkbox\" name=\"present_$id\" value=\"1\"> $name</label></li>";
    }
    echo '</ul><button type="submit">送出點名</button></form>';
}


  
