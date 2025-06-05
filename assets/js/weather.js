class WeatherApp {
  constructor() {
    this.apiBase = "/api/weather.php";
    this.init();
  }

  init() {
    this.loadCurrentWeather();
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

  async fetchWeatherData(action, params = {}) {
    try {
      const queryParams = new URLSearchParams({
        action,
        ...params,
      });

      const response = await fetch(`${this.apiBase}?${queryParams}`);
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || "請求失敗");
      }

      return data;
    } catch (error) {
      console.error("API 請求錯誤:", error);
      throw error;
    }
  }

  async loadCurrentWeather() {
    const container = document.getElementById("current-weather");

    try {
      const response = await this.fetchWeatherData("latest");

      if (response.success && response.data) {
        container.innerHTML = this.renderWeatherCard(response.data);
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
    const location = document.getElementById("location-input").value.trim();
    const container = document.getElementById("search-result");

    if (!location) {
      container.innerHTML = '<div class="error">請輸入地點名稱</div>';
      return;
    }

    container.innerHTML = '<div class="loading">搜尋中...</div>';

    try {
      const response = await this.fetchWeatherData("location", { location });

      if (response.success && response.data) {
        container.innerHTML = this.renderWeatherCard(response.data);
      } else {
        container.innerHTML = '<div class="error">查無該地點的氣象資料</div>';
      }
    } catch (error) {
      container.innerHTML = `<div class="error">搜尋失敗: ${error.message}</div>`;
    }
  }

  renderWeatherCard(weather) {
    const updateTime = new Date(weather.update_time).toLocaleString("zh-TW");

    return `
            <div class="weather-card">
                <h3>${weather.location}</h3>
                <div class="weather-info">
                    <div class="info-item">
                        <div class="info-label">天氣狀況</div>
                        <div class="info-value">${
                          weather.weather_condition
                        }</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">降雨機率</div>
                        <div class="info-value probability">${
                          weather.rainfall_probability
                        }%</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">最低溫度</div>
                        <div class="info-value temperature">${
                          weather.min_temperature
                        }°C</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">最高溫度</div>
                        <div class="info-value temperature">${
                          weather.max_temperature
                        }°C</div>
                    </div>
                    ${
                      weather.current_temperature
                        ? `
                    <div class="info-item">
                        <div class="info-label">目前溫度</div>
                        <div class="info-value temperature">${weather.current_temperature}°C</div>
                    </div>
                    `
                        : ""
                    }
                    <div class="info-item">
                        <div class="info-label">舒適度</div>
                        <div class="info-value">${weather.comfort_level}</div>
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
