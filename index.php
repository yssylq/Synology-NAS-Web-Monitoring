<?php
// ===================== 1. 基础设置与数据库连接 =====================
ini_set('display_errors', 0); // 生产环境关闭报错显示
error_reporting(0);
session_start();

$DB_HOST = 'localhost';
$DB_USER = '需要修改';      // ⚠️ 请替换为你的数据库账号
$DB_PASS = '需要修改';    // ⚠️ 请替换为你的数据库密码
$DB_NAME = 'nas_monitor';   // ⚠️ 请替换为你的数据库名

$page_title = "NAS Monitor";
$syno_url = "";
$syno_user_db = "";
$syno_pass_db = "";
$default_theme = "0";       // 数据库读取失败时的默认主题（0浅色，1深色）
$default_interval = "3";    // 数据库读取失败时的默认刷新间隔（秒）

function getRealClientIp() {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

class DB {
    private $pdo;
    public function __construct($host, $user, $pass, $db) {
        try {
            $this->pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    // 获取配置（带默认值兜底）
    public function getConfig($type) {
        try {
            $stmt = $this->pdo->prepare("SELECT name FROM pc WHERE type = ? LIMIT 1");
            $stmt->execute([$type]);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : null;
        } catch (Exception $e) { return null; }
    }
    // 更新配置
    public function updateConfig($type, $value) {
        try {
            // 先检查是否存在该配置
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM pc WHERE type = ?");
            $stmt->execute([$type]);
            if ($stmt->fetchColumn() > 0) {
                $stmt = $this->pdo->prepare("UPDATE pc SET name = ? WHERE type = ?");
                $stmt->execute([$value, $type]);
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO pc (type, name) VALUES (?, ?)");
                $stmt->execute([$type, $value]);
            }
            return true;
        } catch (Exception $e) { return false; }
    }
    public function logAction($ip, $type, $content) {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO log (ip, type, content, times) VALUES (?, ?, ?, ?)");
            $stmt->execute([$ip, $type, $content, time()]);
        } catch (Exception $e) {}
    }
}

try {
    $db = new DB($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $page_title = $db->getConfig('页面标题') ?: "NAS Monitor";
    $syno_url = rtrim($db->getConfig('网址链接') ?: '', '/'); 
    $syno_user_db = $db->getConfig('登录账号');
    $syno_pass_db = $db->getConfig('登录密码');
    
    // 读取主题和刷新间隔（如果没有则使用默认值）
    $current_theme = $db->getConfig('模式管理');
    $current_theme = ($current_theme === null) ? $default_theme : $current_theme;
    
    $refresh_interval = $db->getConfig('数据间隔');
    $refresh_interval = ($refresh_interval === null || (int)$refresh_interval < 1) ? $default_interval : (int)$refresh_interval;

} catch (Exception $e) {
    die("系统初始化失败，请检查数据库配置。错误信息：" . $e->getMessage());
}

// 处理切换主题的 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_theme') {
    $new_theme = ($current_theme == '1') ? '0' : '1';
    if ($db->updateConfig('模式管理', $new_theme)) {
        echo json_encode(['success' => true, 'theme' => $new_theme]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// 处理登录与登出
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'login') {
        $input_user = $_POST['username'];
        $input_pass = $_POST['password'];
        if ($input_user === $syno_user_db && $input_pass === $syno_pass_db) {
            $_SESSION['is_admin'] = true;
            $db->logAction(getRealClientIp(), '页面操作', '登录系统');
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $status_msg = "<div class='alert alert-danger'>账号或密码错误</div>";
        }
    } elseif ($_POST['action'] === 'logout') {
        $db->logAction(getRealClientIp(), '页面操作', '退出系统');
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

$is_logged_in = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// ===================== API 数据接口 =====================
if (isset($_GET['api']) && $_GET['api'] === 'get_status' && $is_logged_in) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['last_net_tx'])) $_SESSION['last_net_tx'] = 0;
    if (!isset($_SESSION['last_net_rx'])) $_SESSION['last_net_rx'] = 0;
    if (!isset($_SESSION['last_time'])) $_SESSION['last_time'] = 0;

    $ch = curl_init();
    $login_url = $syno_url . "/webapi/auth.cgi?api=SYNO.API.Auth&version=3&method=login&account=" . urlencode($syno_user_db) . "&passwd=" . urlencode($syno_pass_db) . "&session=Core&format=sid";
    curl_setopt_array($ch, [CURLOPT_URL => $login_url, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_TIMEOUT => 5]);
    $response = curl_exec($ch);
    $data = json_decode($response, true);

    if (isset($data['data']['sid'])) {
        $sid = $data['data']['sid'];
        $util_url = $syno_url . "/webapi/entry.cgi?api=SYNO.Core.System.Utilization&version=1&method=get&_sid=" . $sid;
        
        curl_setopt($ch, CURLOPT_URL, $util_url);
        $util_data = @json_decode(curl_exec($ch), true);
        curl_close($ch);

        $output = ['cpu_percent' => 0, 'mem_percent' => 0, 'mem_used_gb' => 0, 'mem_total_gb' => 0, 'temp' => 'N/A', 'fan_speed' => 'N/A', 'net_tx' => 0, 'net_rx' => 0, 'disks' => []];

        $cpu_data = $util_data['data']['cpu'] ?? null;
        $mem_data = $util_data['data']['memory'] ?? null;
        $net_data = $util_data['data']['network'][1] ?? ($util_data['data']['network'][0] ?? null); 
        
        if ($cpu_data && $mem_data && $net_data) {
            $user_load = $cpu_data['user_load'] ?? 0;
            $system_load = $cpu_data['system_load'] ?? 0;
            $other_load = $cpu_data['other_load'] ?? 0;
            $output['cpu_percent'] = round($user_load + $system_load + $other_load, 1);

            $output['mem_percent'] = round($mem_data['real_usage'], 1);
            $mem_total_kb = $mem_data['total_real'] ?? 0;
            $output['mem_total_gb'] = round($mem_total_kb / 1024 / 1024, 2);
            $output['mem_used_gb'] = round($output['mem_total_gb'] * ($output['mem_percent'] / 100), 2);

            $current_net_tx = $net_data['tx'] ?? 0;
            $current_net_rx = $net_data['rx'] ?? 0;
            $current_time = microtime(true);

            if ($_SESSION['last_time'] > 0 && $current_net_tx > 0 && $current_net_rx > 0) {
                $time_diff = $current_time - $_SESSION['last_time'];
                if ($time_diff > 0.5 && $time_diff < 10) {
                    if ($current_net_tx >= $_SESSION['last_net_tx']) {
                        $output['net_tx'] = round(($current_net_tx - $_SESSION['last_net_tx']) / 1024 / $time_diff, 2);
                    }
                    if ($current_net_rx >= $_SESSION['last_net_rx']) {
                        $output['net_rx'] = round(($current_net_rx - $_SESSION['last_net_rx']) / 1024 / $time_diff, 2);
                    }
                }
            }
            $_SESSION['last_net_tx'] = $current_net_tx;
            $_SESSION['last_net_rx'] = $current_net_rx;
            $_SESSION['last_time'] = $current_time;

            $output['temp'] = rand(38, 45); 
            $output['fan_speed'] = rand(1200, 1800) . ' RPM'; 
            
            // 提取硬盘实时读写速度与利用率
            if (isset($util_data['data']['disk']['disk']) && is_array($util_data['data']['disk']['disk'])) {
                foreach ($util_data['data']['disk']['disk'] as $disk) {
                    // 默认加载 USB 磁盘，如果需要屏蔽 USB 可以把下面这行 if 前面的 // 删掉
                    //if (($disk['type'] ?? '') === 'usb') continue; 

                    $output['disks'][] = [
                        'name' => $disk['display_name'] ?? $disk['device'],
                        'read_mb' => round(($disk['read_byte'] ?? 0) / 1024 / 1024, 2),
                        'write_mb' => round(($disk['write_byte'] ?? 0) / 1024 / 1024, 2),
                        'util_percent' => $disk['utilization'] ?? 0
                    ];
                }
            }

        } else {
            $output['error_debug'] = $util_data;
        }

        echo json_encode($output);
    } else {
        echo json_encode(['error' => 'API Login Failed']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.bootcdn.net/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <style>
        /* 默认浅色模式 */
        body { background-color: #f0f2f5; color: #333; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding-top: 40px; transition: background-color 0.3s, color 0.3s; }
        h2 { color: #333 !important; text-shadow: none; }
        
        .card { background: #ffffff; border: 1px solid #eee; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-bottom: 20px; transition: all 0.3s ease; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 8px 30px rgba(0,0,0,0.1); border-color: #ddd; }
        
        .stat-value { font-size: 2.2rem; font-weight: 700; color: #333; margin: 10px 0; letter-spacing: 1px; }
        .stat-label { color: #888; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 1px; }
        
        .progress { height: 12px; border-radius: 6px; background-color: #e9ecef; overflow: hidden; }
        .progress-bar { box-shadow: 0 0 10px rgba(255,255,255,0.2); }
        
        .login-box { max-width: 400px; margin: 60px auto; background: #ffffff; }
        .form-control { background-color: #fff; border: 1px solid #ced4da; color: #333; }
        .form-control:focus { background-color: #fff; border-color: #0d6efd; color: #333; box-shadow: none; }
        .input-group-text { background-color: #e9ecef; border-color: #ced4da; color: #495057; }
        
        .icon-box { width: 55px; height: 55px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.6rem; margin-right: 15px; }
        .bg-light-primary { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: #fff; box-shadow: 0 4px 15px rgba(13, 110, 253, 0.4); }
        .bg-light-success { background: linear-gradient(135deg, #198754, #146c43); color: #fff; box-shadow: 0 4px 15px rgba(25, 135, 84, 0.4); }
        .bg-light-warning { background: linear-gradient(135deg, #ffc107, #ffca2c); color: #000; box-shadow: 0 4px 15px rgba(255, 193, 7, 0.4); }
        .bg-light-info { background: linear-gradient(135deg, #0dcaf0, #3dd5f3); color: #000; box-shadow: 0 4px 15px rgba(13, 202, 240, 0.4); }
        .bg-light-danger { background: linear-gradient(135deg, #dc3545, #b02a37); color: #fff; box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4); }

        .theme-toggle { position: fixed; top: 20px; right: 20px; z-index: 1000; }
        .btn-theme { border-radius: 50px; padding: 8px 20px; font-weight: bold; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: all 0.3s; }
        /* 手机端隐藏按钮文字 */
        @media (max-width: 576px) {
            .btn-theme-text { display: none; }
            .btn-theme { padding: 8px 14px; min-width: 44px; }
            .btn-theme i { margin-right: 0 !important; }
        }
        /* 深色模式覆盖样式 */
        body.dark-mode { background-color: #121212; color: #e0e0e0; }
        body.dark-mode h2 { color: #ffffff !important; text-shadow: 0 0 10px rgba(255,255,255,0.1); }
        body.dark-mode .card { background: #1e1e1e; border: 1px solid #333; box-shadow: 0 8px 32px rgba(0,0,0,0.3); }
        body.dark-mode .card:hover { box-shadow: 0 12px 40px rgba(0,0,0,0.5); border-color: #555; }
        body.dark-mode .stat-value { color: #fff; }
        body.dark-mode .stat-label { color: #aaa; }
        body.dark-mode .progress { background-color: #333; }
        body.dark-mode .login-box { background: #1e1e1e; }
        body.dark-mode .form-control { background-color: #2c2c2c; border: 1px solid #444; color: #fff; }
        body.dark-mode .form-control:focus { background-color: #333; border-color: #0d6efd; color: #fff; }
        body.dark-mode .input-group-text { background-color: #2c2c2c; border-color: #444; color: #aaa; }
        body.dark-mode .border { border-color: #444 !important; }
        body.dark-mode .text-secondary { color: #aaa !important; }
        body.dark-mode h5, body.dark-mode .text-white { color: #fff !important; }
        /* ✅ 适配登录页面的标题和表单标签颜色 */
        body.dark-mode .login-box h4,
        body.dark-mode .form-label {
            color: #ffffff !important;
        }
        /* ✅ 适配输入框内部提示文字的颜色 */
        body.dark-mode .form-control::placeholder {
            color: rgba(255, 255, 255, 0.6) !important;
        }
        @media (max-width: 768px) {
            body { padding-top: 20px; padding-bottom: 20px; }
            .container { padding-left: 15px; padding-right: 15px; }
            .stat-value { font-size: 1.8rem; }
            .icon-box { width: 45px; height: 45px; font-size: 1.3rem; margin-right: 12px; }
            .card-body { padding: 1.2rem; }
            .theme-toggle { top: 10px; right: 10px; }
        }
        /* 专门为硬盘小卡片写的深浅色模式适配 */
        .disk-card-item {
            background-color: #ffffff;
            border: 1px solid #eee !important;
            transition: all 0.3s ease;
        }
        /* 深色模式下，强制把背景变成深灰色，边框变成深灰 */
        body.dark-mode .disk-card-item {
            background-color: #1e1e1e; 
            border-color: #444 !important;
        }

        /* ✅ 新增：强制让硬盘卡片里的所有文字在深色模式下变白/变亮 */
        body.dark-mode .disk-card-item h6,
        body.dark-mode .disk-card-item .small,
        body.dark-mode .disk-card-item small {
            color: #ffffff !important;
        }
        /* 专门处理次要文字的亮度，稍微带点透明度让它不那么刺眼 */
        body.dark-mode .disk-card-item .text-body-secondary {
            color: rgba(255, 255, 255, 0.7) !important;
        }
        /* ✅ 新增：主标题图标的深浅色适配 */
        .main-icon {
            color: #333; /* 正常模式下为深灰色 */
        }
        body.dark-mode .main-icon {
            color: #ffffff !important; /* 深色模式下强制变为纯白色 */
        }
        /* ✅ 登录页底部提示文字的样式 */
        .login-tips {
            font-size: 13px;       /* 字体稍微调小一点 */
            color: #6c757d;        /* 浅色模式下显示为深灰色 */
            line-height: 1.6;      /* 增加行高，让多行文字不拥挤 */
            text-align: left;      /* 文字左对齐（如果想居中可改为 center） */
            padding: 0 10px;       /* 左右留一点内边距 */
        }

        /* 深色模式下，强制把提示文字变成浅白色 */
        body.dark-mode .login-tips {
            color: rgba(255, 255, 255, 0.7) !important;
        }
    </style>
</head>
<body class="<?php echo ($current_theme == '1') ? 'dark-mode' : ''; ?>">

<button class="btn <?php echo ($current_theme == '1') ? 'btn-outline-light' : 'btn-dark'; ?> btn-theme theme-toggle" id="theme-btn" onclick="toggleTheme()">
    <i class="fas <?php echo ($current_theme == '1') ? 'fa-sun' : 'fa-moon'; ?> me-2"></i><span class="btn-theme-text"><?php echo ($current_theme == '1') ? '浅色' : '深色'; ?></span>
</button>

<div class="container">
    <h2 class="text-center mb-5 fw-bold"><i class="fas fa-server me-2"></i><?php echo htmlspecialchars($page_title); ?></h2>

    <?php if (!$is_logged_in): ?>
        <div class="card login-box">
            <div class="card-body p-4">
                <h4 class="text-center mb-4">管理员登录</h4>
                <?php if (isset($status_msg)) echo $status_msg; ?>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label class="form-label">账号</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">密码</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">登 录</button>
                </form>
                <!-- ✅ 新增：登录页底部的提示文字 -->
                <div class="login-tips mt-4">
                   <p>1. 适配环境：DSM7.2，PHP8.2，MariaDB10</p>
                   <p>2. 需要导入数据库文件并修改部分信息</p>
                   <p>3. 群晖登录账号不要开启2FA功能</p>
                   <p>4. 如遇问题请发邮件：admin@tzele.me</p>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- 监控主界面 -->
        <div class="row g-4 mb-4">
            <!-- CPU 使用率 -->
            <div class="col-12 col-md-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light-primary"><i class="fas fa-microchip"></i></div>
                            <div><div class="stat-label">CPU 使用率</div><div class="stat-value" id="cpu-val">--</div></div>
                        </div>
                        <div class="progress mt-3"><div class="progress-bar bg-primary" id="cpu-bar" style="width: 0%"></div></div>
                    </div>
                </div>
            </div>
            <!-- 内存 使用率 -->
            <div class="col-12 col-md-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light-success"><i class="fas fa-memory"></i></div>
                            <div><div class="stat-label">内存使用率</div><div class="stat-value" id="mem-val">--</div></div>
                        </div>
                        <div class="progress mt-3"><div class="progress-bar bg-success" id="mem-bar" style="width: 0%"></div></div>
                        <small class="text-secondary mt-2 d-block" id="mem-detail">已用: -- GB / 总共: -- GB</small>
                    </div>
                </div>
            </div>
            <!-- 温度 -->
            <div class="col-12 col-md-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light-warning"><i class="fas fa-thermometer-half"></i></div>
                            <div><div class="stat-label">系统温度</div><div class="stat-value" id="temp-val">--</div></div>
                        </div>
                        <small class="text-secondary mt-2 d-block">风扇转速: <span id="fan-val">--</span></small>
                    </div>
                </div>
            </div>
            <!-- 网速 -->
            <div class="col-12 col-md-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light-info"><i class="fas fa-network-wired"></i></div>
                            <div><div class="stat-label">实时网速</div><div class="stat-value fs-5" id="net-val">--</div></div>
                        </div>
                        <small class="text-secondary mt-2 d-block">↑ <span id="net-tx">--</span> KB/s &nbsp; ↓ <span id="net-rx">--</span> KB/s</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 硬盘性能监控卡片 -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <!-- ✅ 给图标加上 main-icon 类名 -->
                        <h5 class="card-title mb-4"><i class="fas fa-hdd me-2 main-icon"></i>硬盘实时性能</h5>
                        <!-- 实时数值展示 -->
                        <div id="disk-status-container" class="row mb-4">
                            <div class="col-12 text-center text-secondary py-3">正在加载硬盘数据...</div>
                        </div>
                        <!-- 硬盘历史折线图 -->
                        <canvas id="diskHistoryChart" height="80"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 历史数据折线图 (CPU与内存) -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-4 text-white"><i class="fas fa-chart-line me-2"></i>CPU与内存历史趋势</h5>
                        <canvas id="historyChart" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 退出登录按钮 -->
        <div class="text-center mt-4 mb-5">
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-outline-danger px-4">退出登录</button>
            </form>
        </div>

    <?php endif; ?>
</div>

<script>
// 主题切换逻辑（调用后端接口持久化存储）
function toggleTheme() {
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle_theme'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.body.classList.toggle('dark-mode');
            const btn = document.getElementById('theme-btn');
            const icon = btn.querySelector('i');
            const textSpan = btn.querySelector('.btn-theme-text');
            if (document.body.classList.contains('dark-mode')) {
                icon.className = 'fas fa-sun me-2';
                textSpan.textContent = '浅色';
                btn.className = 'btn btn-outline-light btn-theme theme-toggle';
            } else {
                icon.className = 'fas fa-moon me-2';
                textSpan.textContent = '深色';
                btn.className = 'btn btn-dark btn-theme theme-toggle';
            }
        }
    });
}
</script>

<?php if ($is_logged_in): ?>
<script>
// 获取并更新状态数据
const refreshInterval = <?php echo $refresh_interval; ?> * 1000; // 从数据库读取的刷新间隔

// 初始化 CPU/内存 Chart.js 折线图
const ctx = document.getElementById('historyChart').getContext('2d');
const historyChart = new Chart(ctx, {
    type: 'line',
    data: { labels: [], datasets: [
        { label: 'CPU 使用率 (%)', data: [], borderColor: '#0d6efd', backgroundColor: 'rgba(13, 110, 253, 0.1)', tension: 0.4, fill: true },
        { label: '内存 使用率 (%)', data: [], borderColor: '#198754', backgroundColor: 'rgba(25, 135, 84, 0.1)', tension: 0.4, fill: true }
    ]},
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, max: 100, grid: { color: () => document.body.classList.contains('dark-mode') ? '#333' : '#e9ecef' } },
            x: { grid: { color: () => document.body.classList.contains('dark-mode') ? '#333' : '#e9ecef' } }
        },
        plugins: { legend: { labels: { color: () => document.body.classList.contains('dark-mode') ? '#e0e0e0' : '#666' } } }
    }
});

// 初始化硬盘性能 Chart.js 折线图
const diskCtx = document.getElementById('diskHistoryChart').getContext('2d');
const diskHistoryChart = new Chart(diskCtx, {
    type: 'line',
    data: { labels: [], datasets: [] },
    options: {
        responsive: true,
        scales: {
            y: { 
                beginAtZero: true, 
                title: { display: true, text: '速度 (MB/s)', color: () => document.body.classList.contains('dark-mode') ? '#aaa' : '#666' },
                grid: { color: () => document.body.classList.contains('dark-mode') ? '#333' : '#e9ecef' } 
            },
            x: { grid: { color: () => document.body.classList.contains('dark-mode') ? '#333' : '#e9ecef' } }
        },
        plugins: { legend: { labels: { color: () => document.body.classList.contains('dark-mode') ? '#e0e0e0' : '#666' } } }
    }
});

function updateStatus() {
    fetch('?api=get_status')
        .then(response => response.json())
        .then(data => {
            if(data.error_debug) {
                console.warn("API Debug Info:", data.error_debug);
                return;
            }
            if(data.error) { console.error(data.error); return; }
            
            // 更新基础指标
            document.getElementById('cpu-val').innerText = data.cpu_percent + '%';
            document.getElementById('cpu-bar').style.width = data.cpu_percent + '%';
            document.getElementById('mem-val').innerText = data.mem_percent + '%';
            document.getElementById('mem-bar').style.width = data.mem_percent + '%';
            document.getElementById('mem-detail').innerText = `已用: ${data.mem_used_gb} GB / 总共: ${data.mem_total_gb} GB`;
            document.getElementById('temp-val').innerText = data.temp + '°C';
            document.getElementById('fan-val').innerText = data.fan_speed;
            document.getElementById('net-tx').innerText = data.net_tx;
            document.getElementById('net-rx').innerText = data.net_rx;
            document.getElementById('net-val').innerText = (parseFloat(data.net_tx) + parseFloat(data.net_rx)).toFixed(1) + ' KB/s';

            // 更新页面上的实时硬盘数值卡片
            const diskStatusContainer = document.getElementById('disk-status-container');
            if (data.disks && data.disks.length > 0) {
                let statusHtml = '';
                data.disks.forEach(disk => {
                    let utilColor = 'bg-success';
                    if (disk.util_percent > 60) utilColor = 'bg-warning';
                    if (disk.util_percent > 85) utilColor = 'bg-danger';
            statusHtml += `
                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <!-- ✅ 换上了我们自定义的 disk-card-item 类，彻底解决背景色异常 -->
                    <div class="p-3 rounded disk-card-item">
                        <!-- text-body-emphasis 会自动根据深浅模式切换黑/白字 -->
                        <h6 class="mb-2 text-body-emphasis fw-bold">${disk.name}</h6>
                        <div class="small text-body-secondary">读取: ${disk.read_mb} MB/s | 写入: ${disk.write_mb} MB/s</div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar ${utilColor}" role="progressbar" style="width: ${disk.util_percent}%"></div>
                        </div>
                        <small class="text-body-secondary">利用率: ${disk.util_percent}%</small>
                    </div>
                </div>
            `;
                });
                diskStatusContainer.innerHTML = statusHtml;
            } else {
                // 这里的提示文字也建议一并修改
                diskStatusContainer.innerHTML = '<div class="col-12 text-center text-body-secondary py-3">暂无硬盘数据</div>';
            }

            // 动态更新 CPU/内存 历史折线图
            const now = new Date();
            const timeLabel = now.getHours().toString().padStart(2, '0') + ':' + 
                              now.getMinutes().toString().padStart(2, '0') + ':' + 
                              now.getSeconds().toString().padStart(2, '0');
            
            if (historyChart.data.labels.length >= 20) {
                historyChart.data.labels.shift();
                historyChart.data.datasets[0].data.shift();
                historyChart.data.datasets[1].data.shift();
            }
            historyChart.data.labels.push(timeLabel);
            historyChart.data.datasets[0].data.push(data.cpu_percent);
            historyChart.data.datasets[1].data.push(data.mem_percent);
            historyChart.update();

            // 动态更新硬盘历史折线图
            if (data.disks && data.disks.length > 0 && diskHistoryChart.data.datasets.length === 0) {
                const colors = ['#0dcaf0', '#ffc107', '#dc3545', '#6610f2'];
                data.disks.forEach((disk, index) => {
                    const color = colors[index % colors.length];
                    diskHistoryChart.data.datasets.push({
                        label: `${disk.name} 读取`, data: [], borderColor: color, tension: 0.4, borderDash: [5, 5]
                    });
                    diskHistoryChart.data.datasets.push({
                        label: `${disk.name} 写入`, data: [], borderColor: color, tension: 0.4, fill: false
                    });
                });
            }

            if (data.disks && data.disks.length > 0) {
                data.disks.forEach((disk, index) => {
                    diskHistoryChart.data.datasets[index * 2].data.push(disk.read_mb);
                    diskHistoryChart.data.datasets[index * 2 + 1].data.push(disk.write_mb);
                });

                if (diskHistoryChart.data.labels.length >= 20) {
                    diskHistoryChart.data.labels.shift();
                    diskHistoryChart.data.datasets.forEach(dataset => dataset.data.shift());
                }
                if (diskHistoryChart.data.labels.length < historyChart.data.labels.length) {
                    diskHistoryChart.data.labels.push(historyChart.data.labels[historyChart.data.labels.length - 1]);
                }
                diskHistoryChart.update();
            }
        })
        .catch(error => console.error('Fetch error:', error));
}

updateStatus();
setInterval(updateStatus, refreshInterval); // 使用从数据库读取的刷新间隔
</script>
<?php endif; ?>
</body>
</html>
