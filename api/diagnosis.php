<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

date_default_timezone_set('Asia/Taipei');
ob_clean();

// 輔助函數
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

function generateFixCommands($missingExtensions, $osType) {
    if (empty($missingExtensions)) {
        return [];
    }
    
    $commands = [];
    
    switch ($osType) {
        case 'Linux':
            // Ubuntu/Debian
            $commands['ubuntu_debian'] = [
                'title' => 'Ubuntu/Debian 系統修復指令',
                'commands' => [
                    '# 更新套件清單',
                    'sudo apt update',
                    '',
                    '# 安裝缺失的 PHP 擴展'
                ]
            ];
            
            foreach ($missingExtensions as $ext) {
                switch ($ext) {
                    case 'pdo':
                    case 'pdo_mysql':
                        $commands['ubuntu_debian']['commands'][] = 'sudo apt install php-mysql php-pdo';
                        break;
                    case 'curl':
                        $commands['ubuntu_debian']['commands'][] = 'sudo apt install php-curl';
                        break;
                    case 'json':
                        $commands['ubuntu_debian']['commands'][] = 'sudo apt install php-json';
                        break;
                    case 'mbstring':
                        $commands['ubuntu_debian']['commands'][] = 'sudo apt install php-mbstring';
                        break;
                    case 'openssl':
                        $commands['ubuntu_debian']['commands'][] = 'sudo apt install php-openssl';
                        break;
                }
            }
            
            $commands['ubuntu_debian']['commands'] = array_merge(
                $commands['ubuntu_debian']['commands'],
                [
                    '',
                    '# 重啟 Apache',
                    'sudo systemctl restart apache2',
                    '',
                    '# 檢查 PHP 擴展是否成功安裝',
                    'php -m | grep -E "' . implode('|', $missingExtensions) . '"'
                ]
            );
            
            // CentOS/RHEL
            $commands['centos_rhel'] = [
                'title' => 'CentOS/RHEL 系統修復指令',
                'commands' => [
                    '# 更新套件',
                    'sudo yum update -y',
                    '',
                    '# 安裝缺失的 PHP 擴展'
                ]
            ];
            
            foreach ($missingExtensions as $ext) {
                switch ($ext) {
                    case 'pdo':
                    case 'pdo_mysql':
                        $commands['centos_rhel']['commands'][] = 'sudo yum install php-pdo php-mysql';
                        break;
                    case 'curl':
                        $commands['centos_rhel']['commands'][] = 'sudo yum install php-curl';
                        break;
                    case 'json':
                        $commands['centos_rhel']['commands'][] = 'sudo yum install php-json';
                        break;
                    case 'mbstring':
                        $commands['centos_rhel']['commands'][] = 'sudo yum install php-mbstring';
                        break;
                    case 'openssl':
                        $commands['centos_rhel']['commands'][] = 'sudo yum install php-openssl';
                        break;
                }
            }
            
            $commands['centos_rhel']['commands'] = array_merge(
                $commands['centos_rhel']['commands'],
                [
                    '',
                    '# 重啟 Apache',
                    'sudo systemctl restart httpd',
                    '',
                    '# 檢查 PHP 擴展',
                    'php -m | grep -E "' . implode('|', $missingExtensions) . '"'
                ]
            );
            break;
            
        case 'Windows':
            $commands['windows'] = [
                'title' => 'Windows 系統修復指令',
                'commands' => [
                    '1. 開啟 php.ini 檔案 (通常位於 PHP 安裝目錄)',
                    '2. 找到以下行並移除前面的分號 (;) 來啟用擴展：'
                ]
            ];
            
            foreach ($missingExtensions as $ext) {
                switch ($ext) {
                    case 'pdo':
                        $commands['windows']['commands'][] = '   ;extension=pdo  → extension=pdo';
                        break;
                    case 'pdo_mysql':
                        $commands['windows']['commands'][] = '   ;extension=pdo_mysql  → extension=pdo_mysql';
                        break;
                    case 'curl':
                        $commands['windows']['commands'][] = '   ;extension=curl  → extension=curl';
                        break;
                    case 'mbstring':
                        $commands['windows']['commands'][] = '   ;extension=mbstring  → extension=mbstring';
                        break;
                    case 'openssl':
                        $commands['windows']['commands'][] = '   ;extension=openssl  → extension=openssl';
                        break;
                }
            }
            
            $commands['windows']['commands'] = array_merge(
                $commands['windows']['commands'],
                [
                    '',
                    '3. 儲存 php.ini 檔案',
                    '4. 重啟 Apache 或 IIS 服務',
                    '5. 檢查擴展是否啟用：建立 phpinfo.php 檔案包含 <?php phpinfo(); ?>'
                ]
            );
            break;
            
        default:
            $commands['generic'] = [
                'title' => '通用修復指令',
                'commands' => [
                    '1. 檢查您的作業系統類型',
                    '2. 使用套件管理員安裝對應的 PHP 擴展',
                    '3. 重啟 Web 伺服器',
                    '4. 執行 php -m 檢查擴展是否成功載入'
                ]
            ];
    }
    
    return $commands;
}

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
    
    // 4. Web 伺服器檢測
    $webServerInfo = [
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'php_version' => phpversion(),
        'php_sapi' => php_sapi_name(),
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'
    ];
    
    // 檢查重要的 PHP 擴展
    $requiredExtensions = [
        'pdo' => 'PDO (PHP Data Objects) - 資料庫連線必需',
        'pdo_mysql' => 'PDO MySQL Driver - MySQL 資料庫連線必需',
        'json' => 'JSON - API 回應處理必需',
        'curl' => 'cURL - HTTP 請求處理必需',
        'mbstring' => 'Multibyte String - 中文字串處理建議',
        'openssl' => 'OpenSSL - HTTPS 連線必需'
    ];
    
    $loadedExtensions = get_loaded_extensions();
    $extensionStatus = [];
    $missingExtensions = [];
    
    foreach ($requiredExtensions as $ext => $description) {
        $isLoaded = extension_loaded($ext);
        $extensionStatus[$ext] = [
            'loaded' => $isLoaded,
            'description' => $description,
            'required' => in_array($ext, ['pdo', 'pdo_mysql', 'json', 'curl'])
        ];
        
        if (!$isLoaded) {
            $missingExtensions[] = $ext;
        }
    }
    
    // 檢查 PHP 配置
    $phpConfig = [
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'allow_url_fopen' => ini_get('allow_url_fopen') ? 'On' : 'Off'
    ];
    
    // 生成修復指令
    $osType = PHP_OS_FAMILY;
    $fixCommands = generateFixCommands($missingExtensions, $osType);
    
    $webServerStatus = 'healthy';
    $webServerMessage = 'Web 伺服器配置正常';
    
    if (!empty($missingExtensions)) {
        $criticalMissing = array_filter($missingExtensions, function($ext) use ($extensionStatus) {
            return $extensionStatus[$ext]['required'];
        });
        
        if (!empty($criticalMissing)) {
            $webServerStatus = 'error';
            $webServerMessage = 'Web 伺服器缺少關鍵 PHP 擴展: ' . implode(', ', $criticalMissing);
            $diagnosis['overall_status'] = 'error';
        } else {
            $webServerStatus = 'warning';
            $webServerMessage = 'Web 伺服器缺少部分 PHP 擴展: ' . implode(', ', $missingExtensions);
            if ($diagnosis['overall_status'] === 'healthy') {
                $diagnosis['overall_status'] = 'warning';
            }
        }
    }
    
    $diagnosis['components']['web_server'] = [
        'status' => $webServerStatus,
        'message' => $webServerMessage,
        'details' => [
            'server_info' => $webServerInfo,
            'php_config' => $phpConfig,
            'extension_status' => $extensionStatus,
            'missing_extensions' => $missingExtensions,
            'loaded_extensions_count' => count($loadedExtensions),
            'php_ini_loaded' => php_ini_loaded_file() ?: 'None',
            'os_type' => $osType,
            'fix_commands' => $fixCommands
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
        'components' => [],
        'debug_info' => [
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine()
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
