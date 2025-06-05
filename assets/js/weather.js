class WeatherApp {
  constructor() {
    this.apiBase = "api/weather.php";
    this.init();
  }

  init() {
    this.loadTaipeiWeather(); // 改為載入臺北市天氣
    this.loadWeatherList();

    // 設定搜尋事件
    document
      .getElementById("location-input")
      .addEventListener("keypress", (e) => {
        if (e.key === "Enter") {
          this.searchWeather();
        }
      });
  }

  async testAPI() {
    try {
      const response = await fetch(`${this.apiBase}?action=test`);
      const data = await response.json();
      console.log("API測試結果:", data);
      return data;
    } catch (error) {
      console.error("API測試錯誤:", error);
      throw error;
    }
  }

  async fetchWeatherData(action, params = {}) {
    try {
      const queryParams = new URLSearchParams({
        action,
        ...params,
      });

      console.log("請求URL:", `${this.apiBase}?${queryParams}`);

      const response = await fetch(`${this.apiBase}?${queryParams}`);

      // 檢查回應的內容類型
      const contentType = response.headers.get("content-type");
      if (!contentType || !contentType.includes("application/json")) {
        const text = await response.text();
        console.error("非JSON回應:", text);
        throw new Error("伺服器回應格式錯誤");
      }

      const data = await response.json();
      console.log("API回應:", data);

      if (!response.ok) {
        throw new Error(data.message || "請求失敗");
      }

      return data;
    } catch (error) {
      console.error("API 請求錯誤:", error);
      throw error;
    }
  }

  async loadTaipeiWeather() {
    const container = document.getElementById("current-weather");

    try {
      // 先嘗試載入臺北市的特定資料
      const response = await this.fetchWeatherData("location", {
        location: "臺北",
      });

      if (response.success && response.data) {
        container.innerHTML = this.renderWeatherGrid(response.data);
      } else {
        // 如果沒有臺北市資料，則載入最新資料
        const latestResponse = await this.fetchWeatherData("latest");
        if (latestResponse.success && latestResponse.data) {
          container.innerHTML = this.renderWeatherGrid(latestResponse.data);
          // 更新標題顯示實際地點
          document.querySelector(
            "#current h2"
          ).textContent = `即時天氣 - ${latestResponse.data.location}`;
        } else {
          container.innerHTML = '<div class="error">目前無可用的氣象資料</div>';
        }
      }
    } catch (error) {
      container.innerHTML = `<div class="error">載入失敗: ${error.message}</div>`;
    }
  }

  async loadCurrentWeather() {
    const container = document.getElementById("current-weather");

    try {
      const response = await this.fetchWeatherData("latest");

      if (response.success && response.data) {
        container.innerHTML = this.renderWeatherGrid(response.data);
      } else {
        container.innerHTML = '<div class="error">目前無可用的氣象資料</div>';
      }
    } catch (error) {
      container.innerHTML = `<div class="error">載入失敗: ${error.message}</div>`;
    }
  }

  async loadWeatherList() {
    const container = document.getElementById("weather-list");

    try {
      const response = await this.fetchWeatherData("list");

      if (response.success && response.data) {
        container.innerHTML = response.data
          .map((weather) => this.renderWeatherCard(weather))
          .join("");
      } else {
        container.innerHTML = '<div class="error">查無歷史資料</div>';
      }
    } catch (error) {
      container.innerHTML = `<div class="error">載入失敗: ${error.message}</div>`;
    }
  }

  async searchWeather() {
    const locationInput = document.getElementById("location-input");
    const container = document.getElementById("search-result");

    if (!locationInput || !container) {
      console.error("找不到必要的HTML元素");
      return;
    }

    const location = locationInput.value.trim();

    if (!location) {
      container.innerHTML = '<div class="error">請輸入地點名稱</div>';
      return;
    }

    container.innerHTML = '<div class="loading">搜尋中...</div>';

    try {
      const response = await this.fetchWeatherData("location", { location });

      if (response.success && response.data) {
        container.innerHTML = this.renderWeatherGrid(response.data);
      } else {
        container.innerHTML = '<div class="error">查無該地點的氣象資料</div>';
      }
    } catch (error) {
      container.innerHTML = `<div class="error">搜尋失敗: ${error.message}</div>`;
    }
  }

  showError(message) {
    const containers = ["current-weather", "search-result", "weather-list"];
    containers.forEach((id) => {
      const container = document.getElementById(id);
      if (container) {
        container.innerHTML = `<div class="error">${message}</div>`;
      }
    });
  }

  // 簡化時間格式化方法
  formatTaiwanTime(dateString) {
    if (!dateString) return "無資料";

    try {
      // API已經回傳台灣時間，直接使用
      // 可選：重新格式化顯示方式
      const date = new Date(dateString + " GMT+0800");
      return date.toLocaleString("zh-TW", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        hour12: false,
      });
    } catch (error) {
      console.error("時間格式化錯誤:", error);
      // 如果格式化失敗，直接回傳原始字串
      return dateString;
    }
  }

  renderWeatherGrid(weather) {
    const updateTime = this.formatTaiwanTime(weather.update_time);

    return `
            <div class="location-header">
                📍 ${weather.location}
            </div>
            
            <div class="weather-info-card">
                <div class="weather-icon">☁️</div>
                <div class="weather-label">天氣狀況</div>
                <div class="weather-value">${
                  weather.weather_condition || "--"
                }</div>
            </div>
            
            <div class="weather-info-card">
                <div class="weather-icon">🌧️</div>
                <div class="weather-label">降雨機率</div>
                <div class="weather-value probability">${
                  weather.rainfall_probability || "--"
                }%</div>
            </div>
            
            <div class="weather-info-card">
                <div class="weather-icon">🌡️</div>
                <div class="weather-label">最低溫度</div>
                <div class="weather-value temperature">${
                  weather.min_temperature || "--"
                }°C</div>
            </div>
            
            <div class="weather-info-card">
                <div class="weather-icon">🌡️</div>
                <div class="weather-label">最高溫度</div>
                <div class="weather-value temperature">${
                  weather.max_temperature || "--"
                }°C</div>
            </div>
            
            <div class="weather-info-card">
                <div class="weather-icon">😊</div>
                <div class="weather-label">舒適度</div>
                <div class="weather-value comfort">${
                  weather.comfort_level || "--"
                }</div>
            </div>
            
            <div class="weather-info-card">
                <div class="weather-icon">🕐</div>
                <div class="weather-label">最後更新</div>
                <div class="weather-value time">${updateTime}</div>
            </div>
        `;
  }

  renderWeatherCard(weather) {
    const updateTime = this.formatTaiwanTime(weather.update_time);

    return `
            <div class="weather-card">
                <h3>${weather.location || "未知地點"}</h3>
                <div class="weather-info">
                    <div class="info-item">
                        <div class="info-label">天氣狀況</div>
                        <div class="info-value">${
                          weather.weather_condition || "--"
                        }</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">降雨機率</div>
                        <div class="info-value probability">${
                          weather.rainfall_probability !== null
                            ? weather.rainfall_probability
                            : "--"
                        }%</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">最低溫度</div>
                        <div class="info-value temperature">${
                          weather.min_temperature !== null
                            ? weather.min_temperature
                            : "--"
                        }°C</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">最高溫度</div>
                        <div class="info-value temperature">${
                          weather.max_temperature !== null
                            ? weather.max_temperature
                            : "--"
                        }°C</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">舒適度</div>
                        <div class="info-value">${
                          weather.comfort_level || "--"
                        }</div>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 1rem; color: #666; font-size: 0.9rem;">
                    更新時間: ${updateTime}
                </div>
            </div>
        `;
  }
}

// 全局函數供HTML調用
function searchWeather() {
  window.weatherApp.searchWeather();
}

// 初始化應用程式
document.addEventListener("DOMContentLoaded", () => {
  window.weatherApp = new WeatherApp();
});
