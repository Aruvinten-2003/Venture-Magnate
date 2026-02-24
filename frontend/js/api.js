// frontend/js/api.js
(() => {
  "use strict";

  const BASE = "/venture-magnate/backend-php/api";

  async function toJson(res) {
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); }
    catch { throw new Error(text || `HTTP ${res.status}`); }

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${data.message || "Request failed"}`);
    }
    return data;
  }

  function apiGet(path) {
    return fetch(`${BASE}${path}`, { credentials: "include" }).then(toJson);
  }

  function apiPost(path, payload) {
    return fetch(`${BASE}${path}`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json; charset=utf-8" },
      body: JSON.stringify(payload),
    }).then(toJson);
  }

  window.API = {
    // prices
    fetchPrices: (symbols) => {
      const q = new URLSearchParams({ symbols: String(symbols) }).toString();
      return apiGet(`/prices.php?${q}`);
    },

    // auth
    login:  (payload) => apiPost(`/auth/login.php`, payload),
    logout: ()        => apiPost(`/auth/logout.php`, {}),
    me:     ()        => apiGet(`/auth/me.php`),

    // portfolio (✅ under /api/portfolio)
    portfoliosummary: () => apiGet(`/portfolio/portfoliosummary.php`),
    profitloss:       () => apiGet(`/portfolio/profitloss.php`),

    // trading
    buy:  (payload) => apiPost(`/trading/buyAsset.php`, payload),
    sell: (payload) => apiPost(`/trading/sellAsset.php`, payload),
    txns: ()        => apiGet(`/trading/transaction.php`),
  };
})();
