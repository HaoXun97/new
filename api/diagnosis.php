<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

date_default_timezone_set('Asia/Taipei');
ob_clean();

try {
    include_once '../config/database.php';
    
    $database = new Database();
    
    // 系統診斷結果
    $diagnosis = [
        'timestamp' => date('Y-m-d H:i:s'),
        'overall_status' => 'healthy',
        'components' => []
    ];
    
    // 1. 資料庫連線檢測
    try {
        $db = $database->getConnection();
        if ($db) {
            $diagnosis['components']['database'] = [
                'status' => 'healthy',
                'message' => '資料庫連線正常',
                'details' => [
                    'connection' => 'success',
                    'response_time' => measureDatabaseResponseTime($db)
                ]
            ];
        }
    } catch (Exception $e) {
        $diagnosis['components']['database'] = [
            'status' => 'error',
            'message' => '資料庫連線失敗: ' . $e->getMessage(),
            'details' => ['connection' => 'failed']
        ];
        $diagnosis['overall_status'] = 'error';
    }
    
    // 2. 資料表檢測
    if (isset($db)) {
        try {
            $query = "SHOW TABLES LIKE 'weather_data'";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $tableExists = $stmt->fetch();
            
            if ($tableExists) {
                // 檢查資料表結構
                $query = "DESCRIBE weather_data";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $requiredColumns = ['id', 'location', 'weather_condition', 'rainfall_probability', 
                                  'min_temperature', 'max_temperature', 'comfort_level', 'update_time'];
                $existingColumns = array_column($columns, 'Field');
                $missingColumns = array_diff($requiredColumns, $existingColumns);
                
                if (empty($missingColumns)) {
                    $diagnosis['components']['table_structure'] = [
                        'status' => 'healthy',
                        'message' => '資料表結構正常',
                        'details' => [
                            'table_exists' => true,
                            'columns_count' => count($columns),
                            'required_columns' => 'all_present'
                        ]
                    ];
                } else {
                    $diagnosis['components']['table_structure'] = [
                        'status' => 'warning',
                        'message' => '資料表缺少必要欄位: ' . implode(', ', $missingColumns),
                        'details' => [
                            'table_exists' => true,
                            'missing_columns' => $missingColumns
                        ]
                    ];
                    $diagnosis['overall_status'] = 'warning';
                }
            } else {
                $diagnosis['components']['table_structure'] = [
                    'status' => 'error',
                    'message' => 'weather_data 資料表不存在',
                    'details' => ['table_exists' => false]
                ];
                $diagnosis['overall_status'] = 'error';
            }
        } catch (Exception $e) {
            $diagnosis['components']['table_structure'] = [
                'status' => 'error',
                'message' => '資料表檢測失敗: ' . $e->getMessage(),
                'details' => ['error' => true]
            ];
            $diagnosis['overall_status'] = 'error';
        }
    }
    
    // 3. 資料完整性檢測
    if (isset($db)) {
        try {
            // 檢查資料數量
            $query = "SELECT COUNT(*) as total FROM weather_data";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            $totalRecords = $result['total'];
            
            // 檢查最新資料
            $query = "SELECT MAX(update_time) as latest_update FROM weather_data";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            $latestUpdate = $result['latest_update'];
            
            // 檢查資料新鮮度（24小時內）
            $hoursOld = null;
            $dataFreshness = 'unknown';
            if ($latestUpdate) {
                $latestTime = new DateTime($latestUpdate);
                $now = new DateTime();
                $diff = $now->diff($latestTime);
                $hoursOld = $diff->h + ($diff->days * 24);
                
                if ($hoursOld <= 1) {
                    $dataFreshness = 'fresh';
                } elseif ($hoursOld <= 6) {
                    $dataFreshness = 'recent';
                } elseif ($hoursOld <= 24) {
                    $dataFreshness = 'old';
                } else {
                    $dataFreshness = 'stale';
                }
            }
            
            $status = 'healthy';
            $message = '資料完整性正常';
            
            if ($totalRecords == 0) {
                $status = 'warning';
                $message = '無氣象資料';
            } elseif ($dataFreshness === 'stale') {
                $status = 'warning';
                $message = '資料過舊，超過24小時未更新';
            }
            
            $diagnosis['components']['data_integrity'] = [
                'status' => $status,
                'message' => $message,
                'details' => [
                    'total_records' => $totalRecords,
                    'latest_update' => $latestUpdate,
                    'hours_old' => $hoursOld,
                    'freshness' => $dataFreshness
                ]
            ];
            
            if ($status === 'warning' && $diagnosis['overall_status'] === 'healthy') {
                $diagnosis['overall_status'] = 'warning';
            }
            
        } catch (Exception $e) {
            $diagnosis['components']['data_integrity'] = [
                'status' => 'error',
                'message' => '資料完整性檢測失敗: ' . $e->getMessage(),
                'details' => ['error' => true]
            ];
            $diagnosis['overall_status'] = 'error';
        }
    }
    
    // 4. API 端點檢測
    $apiEndpoints = [
        'list' => 'api/weather.php?action=list',
        'latest' => 'api/weather.php?action=latest',
        'all_locations' => 'api/weather.php?action=all_locations'
    ];
    
    $apiResults = [];
    foreach ($apiEndpoints as $name => $endpoint) {
        try {
            $url = "http://" . $_SERVER['HTTP_HOST'] . "/" . $endpoint;
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'method' => 'GET'
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            if ($response !== false) {
                $data = json_decode($response, true);
                if ($data && isset($data['success'])) {
                    $apiResults[$name] = [
                        'status' => 'healthy',
                        'response_code' => 200,
                        'success' => $data['success']
                    ];
                } else {
                    $apiResults[$name] = [
                        'status' => 'warning',
                        'response_code' => 200,
                        'success' => false,
                        'error' => 'Invalid JSON response'
                    ];
                }
            } else {
                $apiResults[$name] = [
                    'status' => 'error',
                    'response_code' => 'timeout',
                    'success' => false,
                    'error' => 'Request timeout'
                ];
            }
        } catch (Exception $e) {
            $apiResults[$name] = [
                'status' => 'error',
                'response_code' => 'error',
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    $healthyApis = array_filter($apiResults, function($result) {
        return $result['status'] === 'healthy';
    });
    
    $apiStatus = 'healthy';
    $apiMessage = '所有 API 端點正常';
    
    if (count($healthyApis) === 0) {
        $apiStatus = 'error';
        $apiMessage = '所有 API 端點異常';
        $diagnosis['overall_status'] = 'error';
    } elseif (count($healthyApis) < count($apiResults)) {
        $apiStatus = 'warning';
        $apiMessage = '部分 API 端點異常';
        if ($diagnosis['overall_status'] === 'healthy') {
            $diagnosis['overall_status'] = 'warning';
        }
    }
    
    $diagnosis['components']['api_endpoints'] = [
        'status' => $apiStatus,
        'message' => $apiMessage,
        'details' => [
            'total_endpoints' => count($apiResults),
            'healthy_endpoints' => count($healthyApis),
            'results' => $apiResults
        ]
    ];
    
    // 5. 系統資源檢測
    $diagnosis['components']['system_resources'] = [
        'status' => 'healthy',
        'message' => '系統資源正常',
        'details' => [
            'php_version' => phpversion(),
            'memory_usage' => formatBytes(memory_get_usage(true)),
            'memory_peak' => formatBytes(memory_get_peak_usage(true)),
            'disk_free_space' => formatBytes(disk_free_space('.')),
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ]
    ];
    
    echo json_encode($diagnosis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'overall_status' => 'error',
        'message' => '系統診斷失敗: ' . $e->getMessage(),
        'components' => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function measureDatabaseResponseTime($db) {
    $start = microtime(true);
    try {
        $stmt = $db->prepare("SELECT 1");
        $stmt->execute();
        $end = microtime(true);
        return round(($end - $start) * 1000, 2) . 'ms';
    } catch (Exception $e) {
        return 'error';
    }
}

function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}
?>
