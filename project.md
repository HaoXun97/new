# EC2 + RDS + S3 + Lambda 氣象資料專案架構

## 各服務分工

### EC2 (Apache 網站伺服器)

- 運行主要的 Web 應用程式
- 處理用戶請求和頁面渲染
- 執行 PHP/Python 等後端程式碼
- 從 RDS 查詢氣象資料
- 上傳檔案到 S3
- 提供 API 接口

**Apache2 部署需求：**

- PHP 7.4+ 與 PDO MySQL 擴展
- mod_rewrite 模組啟用
- 適當的檔案權限設定
- SSL 憑證配置（建議）

### RDS (資料庫)

- 儲存結構化氣象資料
  - **資料庫名稱：** `weather_data`
  - **資料庫欄位結構：**
    - `id`: 記錄識別碼 (PRIMARY KEY, AUTO_INCREMENT)
    - `location`: 地點資訊 (VARCHAR)
    - `weather_condition`: 天氣狀況描述 (VARCHAR)
    - `rainfall_probability`: 降雨機率 (%) (INT)
    - `min_temperature`: 最低溫度 (°C) (DECIMAL)
    - `max_temperature`: 最高溫度 (°C) (DECIMAL)
    - `comfort_level`: 舒適度等級 (VARCHAR)
    - `update_time`: 資料更新時間 (DATETIME)
    - `created_at`: 記錄建立時間 (TIMESTAMP)
- 執行複雜的資料查詢和分析
- 提供資料的一致性和備份

### S3 (檔案儲存)

- 儲存氣象相關檔案
  - 衛星雲圖
  - 雷達圖
  - 天氣圖表
  - 歷史資料 CSV/JSON 檔案
- 提供靜態網站資源 (CSS, JS, 圖片)
- 備份和歷史資料歸檔

### Lambda (無伺服器運算)

- 定期從外部氣象 API 獲取最新資料
- 資料處理和格式化
- 自動上傳處理後的資料到 RDS 和 S3
- 觸發條件：CloudWatch Events (每小時/每日執行)
- 成本效益：只在執行時計費

## 資料流程

1. **Lambda** 定期從氣象 API 獲取資料
2. **Lambda** 處理和清理資料
3. 結構化資料存入 **RDS**
4. 圖片/檔案上傳至 **S3**
5. 用戶透過 **EC2 Apache2** 查看整合後的氣象資訊

## API 端點說明

### 可用的 API 操作

1. **list** - 獲取最近 10 筆氣象資料

   - URL: `/api/weather.php?action=list`

2. **latest** - 獲取最新一筆氣象資料

   - URL: `/api/weather.php?action=latest`

3. **location** - 根據地點搜尋氣象資料

   - URL: `/api/weather.php?action=location&location=臺北`

4. **all_locations** - 獲取所有可用地點列表

   - URL: `/api/weather.php?action=all_locations`

5. **recent_by_location** - 獲取每個地點的最新資料
   - URL: `/api/weather.php?action=recent_by_location`

### 即時天氣 API 回應範例

```json
{
  "success": true,
  "data": {
    "id": 1,
    "location": "臺北市",
    "weather_condition": "多雲時晴",
    "rainfall_probability": 20,
    "min_temperature": 18.5,
    "max_temperature": 28.2,
    "comfort_level": "舒適",
    "update_time": "2024-01-15 14:30:00",
    "last_updated": "2024-01-15 14:30:00",
    "created_at": "2024-01-15 14:30:00"
  },
  "count": 1,
  "timestamp": "2024-01-15 14:30:00"
}
```

## EC2 Apache2 部署指南

### 1. 系統需求

```bash
# 更新系統
sudo apt update && sudo apt upgrade -y

# 安裝 Apache2
sudo apt install apache2 -y

# 安裝 PHP 和必要模組
sudo apt install php php-mysql php-curl php-json php-mbstring -y

# 啟用 Apache2 模組
sudo a2enmod rewrite
sudo a2enmod ssl
sudo systemctl restart apache2
```

### 2. 虛擬主機配置

```apache
# /etc/apache2/sites-available/weather.conf
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/weather

    <Directory /var/www/weather>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/weather_error.log
    CustomLog ${APACHE_LOG_DIR}/weather_access.log combined
</VirtualHost>
```

### 3. 檔案部署

```bash
# 建立網站目錄
sudo mkdir -p /var/www/weather

# 複製檔案到網站目錄
sudo cp -r /path/to/web/* /var/www/weather/

# 設定檔案權限
sudo chown -R www-data:www-data /var/www/weather
sudo chmod -R 755 /var/www/weather
sudo chmod -R 644 /var/www/weather/*.php

# 啟用網站
sudo a2ensite weather.conf
sudo systemctl reload apache2
```

### 4. 資料庫建立 SQL

```sql
CREATE DATABASE IF NOT EXISTS weather_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE weather_db;

CREATE TABLE IF NOT EXISTS weather_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(100) NOT NULL,
    weather_condition VARCHAR(50) NOT NULL,
    rainfall_probability INT DEFAULT 0,
    min_temperature DECIMAL(5,2) DEFAULT NULL,
    max_temperature DECIMAL(5,2) DEFAULT NULL,
    comfort_level VARCHAR(20) DEFAULT NULL,
    update_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_location (location),
    INDEX idx_update_time (update_time)
);
```

## Lambda 觸發方式

- CloudWatch Events：每小時自動執行
- S3 事件：當新檔案上傳時觸發
- API Gateway：手動觸發資料更新

## 安全性建議

1. **資料庫連線安全**

   - 使用環境變數儲存敏感資訊
   - 限制資料庫存取來源 IP
   - 定期更新密碼

2. **網站安全**

   - 啟用 HTTPS
   - 設定適當的 CORS 政策
   - 定期更新系統和套件

3. **檔案權限**
   - 配置檔案不可被網頁直接存取
   - 設定適當的目錄權限

## 時間處理說明

### 資料庫時間格式

- **儲存格式**: UTC+0 (協調世界時)
- **顯示格式**: UTC+8 (台灣標準時間)
- **轉換方式**: 在 SQL 查詢中使用 `DATE_ADD(time_column, INTERVAL 8 HOUR)` 轉換

### API 時間回應

- 所有時間欄位已轉換為台灣時間
- 格式: `YYYY-MM-DD HH:MM:SS`
- 時區: UTC+8 (Asia/Taipei)

### 時間欄位說明

- `update_time`: 氣象資料更新時間 (台灣時間)
- `created_at`: 記錄建立時間 (台灣時間)
- `timestamp`: API 回應時間戳記 (台灣時間)

這樣的架構實現了自動化資料獲取，減輕 EC2 負擔並提高系統效率。
