<?php
// src/index.php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';
session_start();



// --- Step 1: 設定 Google Client for OAuth2 only (openid+profile) ---
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);
$client->addScope(['openid', 'profile', 'email']);
$client->setAccessType('offline');
$client->setPrompt('select_account');

// OAuth 回呼
if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $_SESSION['access_token'] = $token;
    header('Location: ' . strtok(GOOGLE_REDIRECT_URI, '?')); // 乾淨的 URL
    exit;
}

// --- Step 2: 如果尚未登入，顯示「Google 登入」按鈕 ---
if (empty($_SESSION['access_token'])) {
    $loginUrl = $client->createAuthUrl();
    echo <<<HTML
    <h2>請先登入</h2>
    <a href="{$loginUrl}"><button>用 Google 帳號登入</button></a>
    HTML;
    exit;
}

// --- Step 3: 已登入，取得使用者名稱並存入 session ---
$client->setAccessToken($_SESSION['access_token']);
$oauth2 = new Google_Service_Oauth2($client);
$userInfo = $oauth2->userinfo_v2_me->get();
$userName = $userInfo->getName();
$_SESSION['user_name'] = $userName;

// --- Step 4: Demo 模式讀假資料 ---
if (DEMO_MODE) {
    $courses = json_decode(file_get_contents(__DIR__ . '/src/demo_courses.json'), true);
} else {
    // 真實模式可接 Classroom API，這裡暫時一律用 Demo
    $courses = json_decode(file_get_contents(__DIR__ . '/src/demo_courses.json'), true);
}

// --- Step 5: 如果還沒選課，顯示課程選單 ---
if (!isset($_GET['course'])) {
    echo "<h1>歡迎，{$userName}</h1>";
    echo '<form method="GET"><label>選擇課程：</label><select name="course">';
    foreach ($courses as $c) {
        echo "<option value=\"{$c['id']}\">{$c['name']}</option>";
    }
    echo '</select><button type="submit">開始點名</button></form>';
    exit;
}

$courseId = $_GET['course'];
$courseName = '';
foreach ($courses as $c) {
    if ($c['id'] === $courseId) {
        $courseName = $c['name'];
        break;
    }
}

// --- Step 6: 讀學生清單 ---
$allStudents = json_decode(file_get_contents(__DIR__ . '/src/demo_students.json'), true);
$students = $allStudents[$courseId] ?? [];

// --- Step 7: 處理 POST (寫入 attendance 並帶上使用者名稱) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 建立 PDO
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );

    // 自動建表
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(255) NOT NULL,
        course_id VARCHAR(255) NOT NULL,
        present TINYINT(1) NOT NULL,
        timestamp DATETIME NOT NULL,
        user_name VARCHAR(255) DEFAULT NULL
      )
    ");

    // 插入
    $stmt = $pdo->prepare("
        INSERT INTO attendance
          (student_id, course_id, present, timestamp, user_name)
        VALUES (?, ?, ?, NOW(), ?)
    ");
    foreach ($students as $stu) {
        $sid     = $stu['id'];
        $present = isset($_POST['present_' . $sid]) ? 1 : 0;
        $stmt->execute([
            $sid,
            $courseId,
            $present,
            $_SESSION['user_name']
        ]);
    }
    echo "<p style=\"color:green;\">點名已更新！({$courseName})</p>";

    // —— 新增：顯示目前該課程所有點名紀錄 —— 
    echo '<h2>目前《' . htmlspecialchars($courseName) . '》點名紀錄</h2>';
    // 讀出所有該 course 的記錄，按時間遞增
    $select = $pdo->prepare("
        SELECT student_id, present, timestamp, user_name
        FROM attendance
        WHERE course_id = ?
        ORDER BY timestamp ASC
    ");
    $select->execute([$courseId]);
    $records = $select->fetchAll(PDO::FETCH_ASSOC);

    if (count($records) > 0) {
        echo '<table border="1" cellpadding="6">';
        echo '<tr><th>時間</th><th>學生編號</th><th>出席</th><th>操作人</th></tr>';
        foreach ($records as $r) {
            $time    = htmlspecialchars($r['timestamp']);
            $sid     = htmlspecialchars($r['student_id']);
            $pres    = $r['present'] ? '✓' : '✗';
            $un      = htmlspecialchars($r['user_name']);
            echo "<tr>
                    <td>{$time}</td>
                    <td>{$sid}</td>
                    <td style=\"text-align:center;\">{$pres}</td>
                    <td>{$un}</td>
                  </tr>";
        }
        echo '</table>';
    } else {
        echo '<p>目前尚無任何點名紀錄。</p>';
    }
}


// --- Step 8: 渲染點名表單 ---
echo "<h1>課程：《{$courseName}》點名</h1>";
echo "<p>操作人：{$userName}</p>";
echo '<form method="POST"><table border="1" cellpadding="8">';
echo '<tr><th>學生編號</th><th>學生姓名</th><th>出席</th></tr>';
foreach ($students as $stu) {
    $sid  = htmlspecialchars($stu['id']);
    $name = htmlspecialchars($stu['name']);
    echo <<<ROW
    <tr>
      <td>{$sid}</td>
      <td>{$name}</td>
      <td><input type="checkbox" name="present_{$sid}" value="1"></td>
    </tr>
    ROW;
}
echo '</table><button type="submit">送出點名</button></form>';
