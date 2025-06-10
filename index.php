<?php
// src/index.php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';
session_start();

if (isset($_GET['logout'])) {
    unset($_SESSION['access_token'], $_SESSION['user_name']);
    header('Location: ' . strtok(GOOGLE_REDIRECT_URI, '?'));
    exit;
}

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
    <!DOCTYPE html>
    <html lang="zh-TW">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>點名系統 - 登入</title>
        <link rel="stylesheet" href="styles.css">
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>點名系統</h2>
                <p>請先登入以開始使用</p>
            </div>
            <div class="login-container">
                <a href="{$loginUrl}" class="btn btn-success btn-large">用 Google 帳號登入</a>
            </div>
        </div>
    </body>
    </html>
    HTML;
    exit;
}

// —— 新增：登录后、渲染任何页面前，先用 access_token 抓 userInfo 并存 session —— 
$client->setAccessToken($_SESSION['access_token']);
$oauth2      = new Google_Service_Oauth2($client);
$userInfo    = $oauth2->userinfo_v2_me->get();
$userName    = $userInfo->getName();
$_SESSION['user_name'] = $userName;

if (DEMO_MODE) {
    $courses = json_decode(file_get_contents(__DIR__ . '/src/demo_courses.json'), true);
} else {
    $courses = json_decode(file_get_contents(__DIR__ . '/src/demo_courses.json'), true);
}

// 以下是已登入、但還未選課的畫面
if (!isset($_GET['course'])) {
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="zh-TW">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>點名系統 - 選擇課程</title>
        <link rel="stylesheet" href="styles.css">
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>歡迎，{$userName}</h1>
                <p>請選擇要進行點名的課程</p>
            </div>
            <div class="content">
                <div class="navigation">
                    <a href="?logout=1" class="btn btn-secondary">重新登入</a>
                </div>
                
                <div class="course-selection">
                    <form method="GET">
                        <div class="form-group">
                            <label for="course">選擇課程：</label>
                            <select name="course" id="course" required>
                                <option value="">請選擇課程...</option>
    HTML;
    
    foreach ($courses as $c) {
        echo "<option value=\"{$c['id']}\">{$c['name']}</option>";
    }
    
    echo <<<HTML
                            </select>
                        </div>
                        <button type="submit" class="btn btn-success">開始點名</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>
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

// 開始HTML輸出
echo <<<HTML
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>點名系統 - {$courseName}</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$courseName}</h1>
            <p>課程點名系統</p>
        </div>
        <div class="content">
HTML;

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
    echo "<div class=\"success-message\">點名已更新成功！課程：{$courseName}</div>";

    // —— 新增：顯示目前該課程所有點名紀錄 —— 
    echo '<h2>《' . htmlspecialchars($courseName) . '》點名紀錄</h2>';
    // 讀出所有該 course 的記錄，按時間遞增
    $select = $pdo->prepare("
        SELECT student_id, present, timestamp, user_name
        FROM attendance
        WHERE course_id = ?
        ORDER BY timestamp DESC
    ");
    $select->execute([$courseId]);
    $records = $select->fetchAll(PDO::FETCH_ASSOC);

    if (count($records) > 0) {
        echo '<div class="table-container">';
        echo '<table>';
        echo '<thead><tr><th>時間</th><th>學生編號</th><th>出席狀態</th><th>操作人</th></tr></thead><tbody>';
        foreach ($records as $r) {
            $time    = htmlspecialchars($r['timestamp']);
            $sid     = htmlspecialchars($r['student_id']);
            $pres    = $r['present'] ? '<span class="attendance-status present">出席</span>' : '<span class="attendance-status absent">缺席</span>';
            $un      = htmlspecialchars($r['user_name']);
            echo "<tr>
                    <td>{$time}</td>
                    <td>{$sid}</td>
                    <td>{$pres}</td>
                    <td>{$un}</td>
                  </tr>";
        }
        echo '</tbody></table></div>';
    } else {
        echo '<p>目前尚無任何點名紀錄。</p>';
    }
}

// --- Step 8: 渲染點名表單 ---
echo '<div class="user-info">操作人：' . htmlspecialchars($userName) . '</div>';
echo '<div class="navigation">';
echo '<a href="' . strtok($_SERVER['PHP_SELF'], '?') . '" class="btn btn-secondary">回到選擇課程</a>';
echo '</div>';

echo '<form method="POST">';
echo '<div class="table-container">';
echo '<table>';
echo '<thead><tr><th>學生編號</th><th>學生姓名</th><th>出席狀況</th></tr></thead><tbody>';

foreach ($students as $stu) {
    $sid  = htmlspecialchars($stu['id']);
    $name = htmlspecialchars($stu['name']);
    echo <<<ROW
    <tr>
      <td>{$sid}</td>
      <td>{$name}</td>
      <td class="checkbox-container"><input type="checkbox" name="present_{$sid}" value="1"></td>
    </tr>
    ROW;
}

echo '</tbody></table></div>';
echo '<div class="text-center" style="margin-top: 20px;">';
echo '<button type="submit" class="btn btn-success btn-large">送出點名</button>';
echo '</div>';
echo '</form>';

echo <<<HTML
        </div>
    </div>
</body>
</html>
HTML;
?>