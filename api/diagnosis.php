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
    
    // 4. API 端點檢測 - 改進版本
    $apiEndpoints = [
        'list' => 'api/weather.php?action=list',
        'latest' => 'api/weather.php?action=latest',
        'all_locations' => 'api/weather.php?action=all_locations',
        'test' => 'api/weather.php?action=test'
    ];
    
    $apiResults = [];
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    
    // SSL 檢測
    $sslInfo = [
        'https_enabled' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'ssl_protocol' => $_SERVER['SSL_PROTOCOL'] ?? 'Not Available',
        'ssl_cipher' => $_SERVER['SSL_CIPHER'] ?? 'Not Available'
    ];
    
    foreach ($apiEndpoints as $name => $endpoint) {
        $startTime = microtime(true);
        try {
            // 構建完整的 URL
            $baseUrl = rtrim(dirname(dirname($_SERVER['REQUEST_URI'])), '/');
            $url = "$protocol://$host$baseUrl/$endpoint";
            
            // 使用 cURL 並針對 SSL 問題進行配置
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                // SSL 相關設定
                CURLOPT_SSL_VERIFYPEER => false,  // 跳過 SSL 憑證驗證
                CURLOPT_SSL_VERIFYHOST => false,  // 跳過主機名驗證
                CURLOPT_SSLVERSION => CURL_SSLVERSION_DEFAULT,
                CURLOPT_USERAGENT => 'Weather System Diagnosis Tool',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Cache-Control: no-cache'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // 取得更多 cURL 資訊
            $curlInfo = curl_getinfo($ch);
            curl_close($ch);
            
            if ($response === false || !empty($curlError)) {
                // 分析錯誤類型
                $errorType = 'unknown';
                $errorSuggestion = '';
                
                if ($curlErrno === CURLE_SSL_CONNECT_ERROR || $curlErrno === CURLE_SSL_PEER_CERTIFICATE || $curlErrno === CURLE_SSL_CACERT) {
                    $errorType = 'ssl_error';
                    $errorSuggestion = 'SSL 憑證問題：建議檢查 SSL 憑證配置或使用 HTTP 協定';
                } elseif ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
                    $errorType = 'timeout';
                    $errorSuggestion = '連線逾時：檢查網路連線和伺服器回應時間';
                } elseif ($curlErrno === CURLE_COULDNT_CONNECT) {
                    $errorType = 'connection_failed';
                    $errorSuggestion = '無法連線：檢查伺服器是否正在運行';
                }
                
                $apiResults[$name] = [
                    'status' => 'error',
                    'response_code' => 'curl_error',
                    'response_time' => $responseTime . 'ms',
                    'success' => false,
                    'error' => $curlError ?: 'cURL request failed',
                    'error_code' => $curlErrno,
                    'error_type' => $errorType,
                    'suggestion' => $errorSuggestion,
                    'url' => $url,
                    'ssl_info' => $sslInfo
                ];
            } elseif ($httpCode !== 200) {
                $apiResults[$name] = [
                    'status' => 'error',
                    'response_code' => $httpCode,
                    'response_time' => $responseTime . 'ms',
                    'success' => false,
                    'error' => "HTTP $httpCode error",
                    'url' => $url,
                    'response_preview' => substr($response, 0, 200),
                    'ssl_info' => $sslInfo
                ];
            } else {
                $data = json_decode($response, true);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    $apiResults[$name] = [
                        'status' => 'warning',
                        'response_code' => 200,
                        'response_time' => $responseTime . 'ms',
                        'success' => false,
                        'error' => 'Invalid JSON response: ' . json_last_error_msg(),
                        'url' => $url,
                        'response_preview' => substr($response, 0, 200),
                        'ssl_info' => $sslInfo
                    ];
                } else {
                    $apiResults[$name] = [
                        'status' => isset($data['success']) && $data['success'] ? 'healthy' : 'warning',
                        'response_code' => 200,
                        'response_time' => $responseTime . 'ms',
                        'success' => isset($data['success']) ? $data['success'] : true,
                        'url' => $url,
                        'data_count' => isset($data['count']) ? $data['count'] : null,
                        'ssl_info' => $sslInfo
                    ];
                }
            }
        } catch (Exception $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            $apiResults[$name] = [
                'status' => 'error',
                'response_code' => 'exception',
                'response_time' => $responseTime . 'ms',
                'success' => false,
                'error' => $e->getMessage(),
                'url' => isset($url) ? $url : 'URL construction failed',
                'ssl_info' => $sslInfo
            ];
        }
    }
    
    // 分析 API 整體狀態
    $healthyApis = array_filter($apiResults, function($result) {
        return $result['status'] === 'healthy';
    });
    
    $warningApis = array_filter($apiResults, function($result) {
        return $result['status'] === 'warning';
    });
    
    $errorApis = array_filter($apiResults, function($result) {
        return $result['status'] === 'error';
    });
    
    $totalApis = count($apiResults);
    $healthyCount = count($healthyApis);
    $warningCount = count($warningApis);
    $errorCount = count($errorApis);
    
    // 計算平均回應時間
    $responseTimes = array_filter(array_map(function($result) {
        return isset($result['response_time']) ? floatval(str_replace('ms', '', $result['response_time'])) : null;
    }, $apiResults));
    
    $avgResponseTime = !empty($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes), 2) : 0;
    
    if ($errorCount === $totalApis) {
        $apiStatus = 'error';
        $apiMessage = '所有 API 端點都無法訪問';
        $diagnosis['overall_status'] = 'error';
    } elseif ($errorCount > 0) {
        $apiStatus = 'warning';
        $apiMessage = "有 $errorCount 個 API 端點異常，$healthyCount 個正常";
        if ($diagnosis['overall_status'] === 'healthy') {
            $diagnosis['overall_status'] = 'warning';
        }
    } elseif ($warningCount > 0) {
        $apiStatus = 'warning';
        $apiMessage = "有 $warningCount 個 API 端點有警告，$healthyCount 個正常";
        if ($diagnosis['overall_status'] === 'healthy') {
            $diagnosis['overall_status'] = 'warning';
        }
    } else {
        $apiStatus = 'healthy';
        $apiMessage = '所有 API 端點正常運作';
    }
    
    $diagnosis['components']['api_endpoints'] = [
        'status' => $apiStatus,
        'message' => $apiMessage,
        'details' => [
            'total_endpoints' => $totalApis,
            'healthy_endpoints' => $healthyCount,
            'warning_endpoints' => $warningCount,
            'error_endpoints' => $errorCount,
            'average_response_time' => $avgResponseTime . 'ms',
            'results' => $apiResults
        ]
    ];
    
    // 5. Web 伺服器檢測 - 新增
    $webServerInfo = [
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'php_version' => phpversion(),
        'php_sapi' => php_sapi_name(),
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'Unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
        'query_string' => $_SERVER['QUERY_STRING'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ];
    
    // 檢查重要的 PHP 擴展
    $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'curl'];
    $loadedExtensions = get_loaded_extensions();
    $missingExtensions = array_diff($requiredExtensions, $loadedExtensions);
    
    $webServerStatus = 'healthy';
    $webServerMessage = 'Web 伺服器配置正常';
    
    if (!empty($missingExtensions)) {
        $webServerStatus = 'warning';
        $webServerMessage = 'Web 伺服器缺少部分 PHP 擴展: ' . implode(', ', $missingExtensions);
        if ($diagnosis['overall_status'] === 'healthy') {
            $diagnosis['overall_status'] = 'warning';
        }
    }
    
    $diagnosis['components']['web_server'] = [
        'status' => $webServerStatus,
        'message' => $webServerMessage,
        'details' => [
            'server_info' => $webServerInfo,
            'required_extensions' => $requiredExtensions,
            'loaded_extensions_count' => count($loadedExtensions),
            'missing_extensions' => $missingExtensions,
            'php_ini_loaded' => php_ini_loaded_file() ?: 'None'
        ]
    ];
    
    // 6. 系統資源檢測
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
    
    // 6. SSL/HTTPS 檢測 - 新增
    $httpsStatus = 'healthy';
    $httpsMessage = 'HTTP 連線正常';
    
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        // 檢查 SSL 憑證資訊
        $sslDetails = [
            'protocol' => $_SERVER['SSL_PROTOCOL'] ?? 'Unknown',
            'cipher' => $_SERVER['SSL_CIPHER'] ?? 'Unknown',
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'Unknown'
        ];
        
        // 檢查是否有 SSL 相關錯誤
        $hasSSLErrors = false;
        foreach ($apiResults as $result) {
            if (isset($result['error_type']) && $result['error_type'] === 'ssl_error') {
                $hasSSLErrors = true;
                break;
            }
        }
        
        if ($hasSSLErrors) {
            $httpsStatus = 'warning';
            $httpsMessage = 'HTTPS 已啟用但發現 SSL 憑證問題';
        } else {
            $httpsStatus = 'healthy';
            $httpsMessage = 'HTTPS 已啟用且運作正常';
        }
        
        $sslDetails['errors_detected'] = $hasSSLErrors;
    } else {
        $httpsStatus = 'warning';
        $httpsMessage = '使用 HTTP 連線，建議啟用 HTTPS 以提高安全性';
        $sslDetails = [
            'enabled' => false,
            'recommendation' => 'Consider enabling HTTPS for security'
        ];
    }
    
    $diagnosis['components']['https_ssl'] = [
        'status' => $httpsStatus,
        'message' => $httpsMessage,
        'details' => [
            'https_enabled' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'ssl_details' => $sslDetails,
            'server_port' => $_SERVER['SERVER_PORT'] ?? 'Unknown',
            'request_scheme' => $_SERVER['REQUEST_SCHEME'] ?? 'Unknown'
        ]
    ];
    
    echo json_encode($diagnosis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'overall_status' => 'error',
        'message' => '系統診斷失敗: ' . $e->getMessage(),
        'components' => [],
        'debug_info' => [
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'error_trace' => $e->getTraceAsString()
        ]
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
