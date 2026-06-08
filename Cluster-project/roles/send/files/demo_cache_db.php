<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');
echo "<h1>数据库读写 + 缓存链路演示</h1>";

// ==================== 配置 ====================
$sentinels = [
    '192.168.1.51:26379',
    '192.168.1.52:26379',
    '192.168.1.53:26379',
];
$redis_password = '123456';
$master_name = 'mymaster';

$proxysql_host = '192.168.1.100';
$proxysql_port = 6033;
$proxy_user = 'app_user';
$proxy_pass = '123456';

$db_name = 'test_db';
$table_name = 'test_data';

// 获取 Redis 主库
function getRedisMaster($sentinels, $master_name) {
    foreach ($sentinels as $sentinel) {
        list($host, $port) = explode(':', $sentinel);
        try {
            $redis = new Redis();
            if ($redis->connect($host, $port, 2)) {
                $reply = $redis->rawCommand('SENTINEL', 'get-master-addr-by-name', $master_name);
                if (is_array($reply) && count($reply) == 2) {
                    return [$reply[0], (int)$reply[1]];
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }
    return false;
}

// 显示链路信息
function showLinkInfo($pdo, $redis_master_ip) {
    echo "<h3>当前链路状态</h3>";
    echo "<ul>";
    echo "<li><strong>Redis 主库:</strong> {$redis_master_ip}:6379</li>";
    $stmt = $pdo->query("SELECT @@hostname as host, @@server_id as sid");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<li><strong>ProxySQL 后端 MySQL:</strong> host={$row['host']}, server_id={$row['sid']}</li>";
    echo "</ul>";
}

// 处理表单提交
$message = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 连接 ProxySQL
    $pdo = new PDO("mysql:host=$proxysql_host;port=$proxysql_port;dbname=$db_name", $proxy_user, $proxy_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'insert') {
        $name = trim($_POST['name']);
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO $table_name (name) VALUES (?)");
            $stmt->execute([$name]);
            $insertId = $pdo->lastInsertId();
            $message = "✅ 插入成功，ID: $insertId，数据已写入主库";

            // 插入后删除所有缓存（简单起见，删除整个表的相关缓存）
            $redisMaster = getRedisMaster($sentinels, $master_name);
            if ($redisMaster) {
                $redis = new Redis();
                $redis->connect($redisMaster[0], $redisMaster[1]);
                $redis->auth('123456');
                // 删除所有以 "test_data:" 开头的 key（实际项目中可按需删除）
                $keys = $redis->keys('test_data:*');
                foreach ($keys as $key) {
                    $redis->del($key);
                }
                $message .= "，已清理 Redis 缓存";
            }
        } else {
            $message = "❌ 名称不能为空";
        }
    } elseif ($action === 'select') {
        $id = intval($_POST['id']);
        if ($id > 0) {
            // 1. 查 Redis 缓存
            $redisMaster = getRedisMaster($sentinels, $master_name);
            $cacheKey = "test_data:$id";
            $cached = null;
            if ($redisMaster) {
                $redis = new Redis();
                $redis->connect($redisMaster[0], $redisMaster[1]);
                $redis->auth('123456');
                $cached = $redis->get($cacheKey);
                if ($cached !== false) {
                    $result = unserialize($cached);
                    $message = "✅ 缓存命中，数据来自 Redis";
                }
            }
            // 2. 缓存未命中，查从库（ProxySQL 自动路由 SELECT 到从库）
            if (!$result) {
                $stmt = $pdo->prepare("SELECT id, name, created_at FROM $table_name WHERE id = ?");
                $stmt->execute([$id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $message = "🔍 缓存未命中，数据来自 MySQL 从库";
                    // 写入缓存
                    if ($redisMaster) {
                        $redis->setex($cacheKey, 60, serialize($result));
                        $message .= "，已写入 Redis 缓存（TTL 60秒）";
                    }
                } else {
                    $message = "❌ 未找到 ID 为 $id 的记录";
                }
            }
        } else {
            $message = "❌ 请输入有效的 ID";
        }
    }
}

// 获取当前链路信息（用于页面顶部展示）
try {
    $pdo = new PDO("mysql:host=$proxysql_host;port=$proxysql_port;dbname=$db_name", $proxy_user, $proxy_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $redisMaster = getRedisMaster($sentinels, $master_name);
    $redis_master_ip = $redisMaster ? $redisMaster[0] : 'unavailable';
} catch (Exception $e) {
    $redis_master_ip = 'unavailable';
}

// 显示链路信息
showLinkInfo($pdo, $redis_master_ip);

// 显示消息
if ($message) {
    echo "<div style='padding:10px; background:#f0f0f0; margin:10px 0'>$message</div>";
}

// 显示查询结果
if ($result) {
    echo "<h3>查询结果</h3>";
    echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Name</th><th>Created At</th></tr>";
    echo "<tr><td>{$result['id']}</td><td>{$result['name']}</td><td>{$result['created_at']}</td></tr>";
    echo "</table>";
}
?>

<h3>操作</h3>
<form method="post">
    <h4>插入数据（写操作 → 主库）</h4>
    <input type="hidden" name="action" value="insert">
    <label>名称: <input type="text" name="name" required></label>
    <button type="submit">插入</button>
</form>

<form method="post" style="margin-top:20px">
    <h4>查询数据（读操作 → 缓存 → 从库）</h4>
    <input type="hidden" name="action" value="select">
    <label>ID: <input type="number" name="id" required></label>
    <button type="submit">查询</button>
</form>

<hr>
<p><strong>说明：</strong></p>
<ul>
    <li>插入数据：直接通过 ProxySQL 写入主库（ProxySQL 自动识别写操作），并清除 Redis 中相关缓存。</li>
    <li>查询数据：先查 Redis 缓存；未命中则通过 ProxySQL 读取从库，并将结果缓存 60 秒。</li>
    <li>页面顶部显示当前 Redis 主库地址和 ProxySQL 后端 MySQL 的 hostname/server_id，直观展示读写分离效果。</li>
</ul>
