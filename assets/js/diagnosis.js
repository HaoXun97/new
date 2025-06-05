class DiagnosisApp {
  constructor() {
    this.apiBase = "api/diagnosis.php";
    this.diagnosisData = null;
    this.init();
  }

  init() {
    this.runDiagnosis();
  }

  async runDiagnosis() {
    try {
      this.showLoading();
      const response = await fetch(this.apiBase);

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const contentType = response.headers.get("content-type");
      if (!contentType || !contentType.includes("application/json")) {
        const text = await response.text();
        throw new Error("伺服器回應格式錯誤: " + text.substring(0, 200));
      }

      this.diagnosisData = await response.json();
      this.renderDiagnosis();
    } catch (error) {
      console.error("診斷失敗:", error);
      this.showError("診斷失敗: " + error.message);
    }
  }

  showLoading() {
    const containers = [
      "system-overview",
      "components-status",
      "detailed-info",
      "fix-recommendations",
    ];
    containers.forEach((id) => {
      const container = document.getElementById(id);
      if (container) {
        container.innerHTML = '<div class="loading">檢測中...</div>';
      }
    });
  }

  showError(message) {
    const containers = [
      "system-overview",
      "components-status",
      "detailed-info",
    ];
    containers.forEach((id) => {
      const container = document.getElementById(id);
      if (container) {
        container.innerHTML = `<div class="error">${message}</div>`;
      }
    });
  }

  renderDiagnosis() {
    if (!this.diagnosisData) return;

    this.renderSystemOverview();
    this.renderComponents();
    this.renderDetailedInfo();
    this.renderRecommendations();
  }

  renderSystemOverview() {
    const container = document.getElementById("system-overview");
    const data = this.diagnosisData;

    const statusIcon = this.getStatusIcon(data.overall_status);
    const statusText = this.getStatusText(data.overall_status);

    const componentsCount = Object.keys(data.components || {}).length;
    const healthyComponents = Object.values(data.components || {}).filter(
      (c) => c.status === "healthy"
    ).length;
    const warningComponents = Object.values(data.components || {}).filter(
      (c) => c.status === "warning"
    ).length;
    const errorComponents = Object.values(data.components || {}).filter(
      (c) => c.status === "error"
    ).length;

    container.innerHTML = `
      <div class="system-status ${data.overall_status}">
        <div class="status-icon">${statusIcon}</div>
        <div>
          <div>系統狀態: ${statusText}</div>
          <div style="font-size: 1rem; margin-top: 0.5rem; opacity: 0.8;">
            檢測時間: ${data.timestamp}
          </div>
        </div>
      </div>
      
      <div class="system-metrics">
        <div class="metrics-grid">
          <div class="metric-item">
            <div class="metric-label">總組件數</div>
            <div class="metric-value">${componentsCount}</div>
          </div>
          <div class="metric-item healthy">
            <div class="metric-label">正常組件</div>
            <div class="metric-value">${healthyComponents}</div>
          </div>
          <div class="metric-item warning">
            <div class="metric-label">警告組件</div>
            <div class="metric-value">${warningComponents}</div>
          </div>
          <div class="metric-item error">
            <div class="metric-label">錯誤組件</div>
            <div class="metric-value">${errorComponents}</div>
          </div>
        </div>
        
        <div class="health-score">
          <div class="score-label">系統健康分數</div>
          <div class="score-value">${this.calculateHealthScore()}%</div>
          <div class="progress-bar">
            <div class="progress-fill ${
              data.overall_status
            }" style="width: ${this.calculateHealthScore()}%"></div>
          </div>
        </div>
      </div>
    `;
  }

  renderComponents() {
    const container = document.getElementById("components-status");
    const components = this.diagnosisData.components || {};

    if (Object.keys(components).length === 0) {
      container.innerHTML = '<div class="error">無組件資訊</div>';
      return;
    }

    const componentsHtml = Object.entries(components)
      .map(([name, component]) => {
        const icon = this.getComponentIcon(name);
        const title = this.getComponentTitle(name);

        return `
        <div class="component-card ${component.status}">
          <div class="component-header">
            <div class="component-icon">${icon}</div>
            <div class="component-title">${title}</div>
            <div class="component-status ${
              component.status
            }">${this.getStatusText(component.status)}</div>
          </div>
          
          <div class="component-message">${component.message}</div>
          
          <div class="component-details">
            ${this.renderComponentDetails(component.details)}
          </div>
        </div>
      `;
      })
      .join("");

    container.innerHTML = componentsHtml;
  }

  renderComponentDetails(details) {
    if (!details || typeof details !== "object") return "";

    return Object.entries(details)
      .map(([key, value]) => {
        const label = this.getDetailLabel(key);
        const formattedValue = this.formatDetailValue(key, value);

        return `
        <div class="detail-item">
          <div class="detail-label">${label}</div>
          <div class="detail-value">${formattedValue}</div>
        </div>
      `;
      })
      .join("");
  }

  renderDetailedInfo() {
    const container = document.getElementById("detailed-info");

    container.innerHTML = `
      <div class="json-display">${JSON.stringify(
        this.diagnosisData,
        null,
        2
      )}</div>
      <div class="timestamp">
        詳細診斷報告生成時間: ${this.diagnosisData.timestamp}
      </div>
    `;
  }

  renderRecommendations() {
    const container = document.getElementById("fix-recommendations");
    const recommendations = this.generateRecommendations();

    if (recommendations.length === 0) {
      container.innerHTML = `
        <div class="recommendation-item healthy">
          <div class="recommendation-icon">✅</div>
          <div class="recommendation-content">
            <h4>系統運行良好</h4>
            <p>所有組件都正常運作，無需進行任何修復操作。建議定期執行系統診斷以確保持續穩定運行。</p>
          </div>
        </div>
      `;
      return;
    }

    const recommendationsHtml = recommendations
      .map(
        (rec) => `
      <div class="recommendation-item ${rec.severity}">
        <div class="recommendation-icon ${rec.severity}">${rec.icon}</div>
        <div class="recommendation-content">
          <h4>${rec.title}</h4>
          <p>${rec.description}</p>
        </div>
      </div>
    `
      )
      .join("");

    container.innerHTML = recommendationsHtml;
  }

  generateRecommendations() {
    const recommendations = [];
    const components = this.diagnosisData.components || {};

    Object.entries(components).forEach(([name, component]) => {
      if (component.status === "error") {
        recommendations.push(this.getErrorRecommendation(name, component));
      } else if (component.status === "warning") {
        recommendations.push(this.getWarningRecommendation(name, component));
      }
    });

    return recommendations;
  }

  getErrorRecommendation(componentName, component) {
    const recommendations = {
      database: {
        title: "資料庫連線異常",
        description:
          "請檢查資料庫伺服器是否正常運行，確認連線參數是否正確，並檢查網路連線狀態。",
        icon: "🔧",
      },
      table_structure: {
        title: "資料表結構異常",
        description:
          "請執行資料庫初始化腳本，確保 weather_data 資料表存在且包含所有必要欄位。",
        icon: "🗃️",
      },
      api_endpoints: {
        title: "API 端點異常",
        description:
          "請檢查 Web 伺服器配置，確認 PHP 檔案權限正確，並檢查 .htaccess 設定。",
        icon: "🌐",
      },
      data_integrity: {
        title: "資料完整性異常",
        description:
          "請檢查資料更新機制，確認 Lambda 函數是否正常執行，並檢查外部 API 連線狀態。",
        icon: "📊",
      },
    };

    return {
      severity: "error",
      ...(recommendations[componentName] || {
        title: `${componentName} 組件異常`,
        description: `${component.message}，請聯繫系統管理員進行檢修。`,
        icon: "⚠️",
      }),
    };
  }

  getWarningRecommendation(componentName, component) {
    const recommendations = {
      data_integrity: {
        title: "資料過舊警告",
        description:
          "氣象資料超過24小時未更新，建議檢查資料更新排程是否正常運行。",
        icon: "⏰",
      },
      api_endpoints: {
        title: "部分 API 異常",
        description: "某些 API 端點回應異常，建議檢查相關功能並重啟服務。",
        icon: "🔄",
      },
    };

    return {
      severity: "warning",
      ...(recommendations[componentName] || {
        title: `${componentName} 組件警告`,
        description: `${component.message}，建議進行檢查以確保系統穩定性。`,
        icon: "⚠️",
      }),
    };
  }

  calculateHealthScore() {
    const components = this.diagnosisData.components || {};
    const total = Object.keys(components).length;

    if (total === 0) return 0;

    const healthy = Object.values(components).filter(
      (c) => c.status === "healthy"
    ).length;
    const warning = Object.values(components).filter(
      (c) => c.status === "warning"
    ).length;

    // 健康組件得100分，警告組件得50分，錯誤組件得0分
    const score = (healthy * 100 + warning * 50) / total;
    return Math.round(score);
  }

  getStatusIcon(status) {
    const icons = {
      healthy: "✅",
      warning: "⚠️",
      error: "❌",
    };
    return icons[status] || "❓";
  }

  getStatusText(status) {
    const texts = {
      healthy: "正常",
      warning: "警告",
      error: "異常",
    };
    return texts[status] || "未知";
  }

  getComponentIcon(name) {
    const icons = {
      database: "🗄️",
      table_structure: "🗃️",
      data_integrity: "📊",
      api_endpoints: "🌐",
      system_resources: "💻",
    };
    return icons[name] || "🔧";
  }

  getComponentTitle(name) {
    const titles = {
      database: "資料庫連線",
      table_structure: "資料表結構",
      data_integrity: "資料完整性",
      api_endpoints: "API 端點",
      system_resources: "系統資源",
    };
    return titles[name] || name;
  }

  getDetailLabel(key) {
    const labels = {
      connection: "連線狀態",
      response_time: "回應時間",
      table_exists: "資料表存在",
      columns_count: "欄位數量",
      required_columns: "必要欄位",
      missing_columns: "缺失欄位",
      total_records: "記錄總數",
      latest_update: "最新更新",
      hours_old: "資料時效(小時)",
      freshness: "新鮮度",
      total_endpoints: "端點總數",
      healthy_endpoints: "正常端點",
      php_version: "PHP 版本",
      memory_usage: "記憶體使用",
      memory_peak: "記憶體峰值",
      disk_free_space: "可用磁碟空間",
      server_time: "伺服器時間",
      timezone: "時區",
    };
    return labels[key] || key;
  }

  formatDetailValue(key, value) {
    if (typeof value === "boolean") {
      return value ? "是" : "否";
    }
    if (Array.isArray(value)) {
      return value.length > 0 ? value.join(", ") : "無";
    }
    if (typeof value === "object") {
      return JSON.stringify(value);
    }
    return String(value);
  }

  exportReport() {
    if (!this.diagnosisData) {
      alert("無診斷資料可匯出");
      return;
    }

    const report = {
      title: "氣象系統診斷報告",
      generated_at: new Date().toISOString(),
      diagnosis: this.diagnosisData,
      summary: {
        overall_status: this.diagnosisData.overall_status,
        health_score: this.calculateHealthScore(),
        total_components: Object.keys(this.diagnosisData.components || {})
          .length,
        healthy_components: Object.values(
          this.diagnosisData.components || {}
        ).filter((c) => c.status === "healthy").length,
        recommendations: this.generateRecommendations(),
      },
    };

    const blob = new Blob([JSON.stringify(report, null, 2)], {
      type: "application/json",
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `weather_system_diagnosis_${new Date()
      .toISOString()
      .slice(0, 19)
      .replace(/:/g, "-")}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }
}

// 全域函數
function runDiagnosis() {
  if (window.diagnosisApp) {
    window.diagnosisApp.runDiagnosis();
  }
}

function exportReport() {
  if (window.diagnosisApp) {
    window.diagnosisApp.exportReport();
  }
}

// 初始化應用程式
document.addEventListener("DOMContentLoaded", () => {
  window.diagnosisApp = new DiagnosisApp();
});
