<?php
// 關閉錯誤顯示，避免HTML錯誤訊息混入JSON回應
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// 設定時區為台北時間
date_default_timezone_set('Asia/Taipei');

// 確保輸出緩衝區清空
ob_clean();

try {
    include_once '../config/database.php';
    
    $database = new Database();
    $db = $database->getConnection();

    if(!$db) {
        throw new Exception("資料庫連線失敗");
    }

    $action = $_GET['action'] ?? 'list';
    $location = $_GET['location'] ?? '';

    switch($action) {
        case 'test':
            // 測試端點
            echo json_encode([
                "success" => true,
                "message" => "API 正常運作",
                "timestamp" => date('Y-m-d H:i:s'),
                "database_status" => $database->testConnection() ? "connected" : "disconnected"
            ]);
            exit;
            
        case 'list':
            $query = "SELECT id, location, weather_condition, rainfall_probability, min_temperature, max_temperature, comfort_level, 
                     DATE_FORMAT(DATE_ADD(update_time, INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') as update_time,
                     DATE_FORMAT(DATE_ADD(created_at, INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') as created_at 
                     FROM weather_data ORDER BY update_time DESC LIMIT 10";
            $stmt = $db->prepare($query);
            break;
            
        case 'location':
            if(empty($location)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "地點參數為必填"]);
                exit;
            }
            
            $query = "SELECT id, location, weather_condition, rainfall_probability, min_temperature, max_temperature, comfort_level, 
                     DATE_FORMAT(DATE_ADD(update_time, INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') as update_time,
                     DATE_FORMAT(DATE_ADD(created_at, INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') as created_at 
                     FROM weather_data WHERE location LIKE ? ORDER BY update_time DESC LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->bindValue(1, "%{$location}%");
            break;
            
        case 'latest':
            $query = "SELECT id, location, weather_condition, rainfall_probability, min_temperature, max_temperature, comfort_level, 
                     DATE_FORMAT(DATE_ADD(update_time, INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') as update_time,
                     DATE_FORMAT(DATE_ADD(created_at, INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') as created_at 
                     FROM weather_data ORDER BY update_time DESC LIMIT 1";
            $stmt = $db->prepare($query);
            break;
            
        case 'all_locations':
            $query = "SELECT DISTINCT location FROM weather_data ORDER BY location";
            $stmt = $db->prepare($query);
            break;
            
        case 'recent_by_location':
            $query = "SELECT id, location, weather_condition, rainfall_probability, min_temperature, max_temperature, comfort_level, 
                     DATE_FORMAT(DATE_ADD(update_time, INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') as update_time,
                     DATE_FORMAT(DATE_ADD(created_at, INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') as created_at 
                     FROM weather_data WHERE location IN (SELECT DISTINCT location FROM weather_data) 
                     GROUP BY location ORDER BY update_time DESC";
            $stmt = $db->prepare($query);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "無效的操作"]);
            exit;
    }
    
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if($result) {
        // 格式化數據
        foreach($result as &$row) {
            if(isset($row['rainfall_probability'])) {
                $row['rainfall_probability'] = (int)$row['rainfall_probability'];
            }
            if(isset($row['min_temperature'])) {
                $row['min_temperature'] = (float)$row['min_temperature'];
            }
            if(isset($row['max_temperature'])) {
                $row['max_temperature'] = (float)$row['max_temperature'];
            }
            if(isset($row['update_time'])) {
                $row['last_updated'] = $row['update_time']; // 保持兼容性
            }
        }
        
        echo json_encode([
            "success" => true,
            "data" => ($action === 'list' || $action === 'all_locations' || $action === 'recent_by_location') ? $result : $result[0],
            "count" => count($result),
            "timestamp" => date('Y-m-d H:i:s') // 台灣時間戳記
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "查無資料",
            "timestamp" => date('Y-m-d H:i:s') // 台灣時間戳記
        ]);
    }
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "伺服器錯誤: " . $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s') // 台灣時間戳記
    ]);
}
?>
