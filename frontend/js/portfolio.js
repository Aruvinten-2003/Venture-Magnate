// frontend/js/portfolio.js
(() => {
"use strict";

const DIRTY_KEY = "vm:portfolioDirty";

function $(id) { return document.getElementById(id); }

const cashEl   = $("cash");
const equityEl = $("equity");
const tbody    = $("holdingsBody");

if (!cashEl || !equityEl || !tbody) return;
if (!window.API || typeof window.API.portfoliosummary !== "function") {
    console.error("API not ready. Make sure api.js loads before portfolio.js");
    tbody.innerHTML = `<tr><td colspan="6">API not loaded</td></tr>`;
    return;
}

const num = (v, fallback = 0) => {
    const n = Number(v);
    return Number.isFinite(n) ? n : fallback;
};

let lastSeenDirty = localStorage.getItem(DIRTY_KEY) || "";

async function loadPortfolio() {
    try {
      // Call your API function
    const data = await window.API.portfoliosummary();

    if (!data || data.success === false) {
        throw new Error(data?.message || "Failed to load portfolio summary");
    }

      // ✅ Match your backend response shape
    const cash   = num(data?.portfolio?.total_balance ?? data?.portfolio?.total_balance ?? data?.portfolio?.virtual_balance, 0);
    const equity = num(data?.summary?.equity ?? data?.portfolio?.equity, cash);

    cashEl.textContent   = "$" + cash.toFixed(2);
    equityEl.textContent = "$" + equity.toFixed(2);

    tbody.innerHTML = "";

    const holdings = Array.isArray(data.holdings) ? data.holdings : [];

    if (holdings.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6">No holdings yet</td></tr>`;
        return;
    }

    holdings.forEach((h) => {
        const symbol = String(h.symbol ?? "");

        const qty = num(h.quantity, 0);
        const avg = num(h.average_price ?? h.avg_price, 0);

        // backend uses last_price
        const cur = num(h.last_price ?? h.current_price, avg);

        const mv  = num(h.market_value, qty * cur);
        const upl = num(h.unrealized_pl, mv - qty * avg);
        const costBasis = qty * avg;
        const uplPct = costBasis > 0 ? (upl / costBasis) * 100 : 0;

        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${symbol}</td>
            <td>${qty}</td>
            <td>$${avg.toFixed(2)}</td>
            <td>$${cur.toFixed(2)}</td>
            <td>$${mv.toFixed(2)}</td>
            <td style="color:${upl >= 0 ? "#00FF88" : "#E74C3C"}">
            $${upl.toFixed(2)} (${uplPct.toFixed(2)}%)
        </td>
        `;
        tbody.appendChild(tr);
    });

    } catch (e) {
        console.error("Portfolio summary error:", e);
        cashEl.textContent = "$0.00";
        equityEl.textContent = "$0.00";
        tbody.innerHTML = `<tr><td colspan="6">Failed to load portfolio</td></tr>`;
    }
}

function maybeRefreshFromDirtyFlag() {
    const nowDirty = localStorage.getItem(DIRTY_KEY) || "";
    if (nowDirty && nowDirty !== lastSeenDirty) {
    lastSeenDirty = nowDirty;
    loadPortfolio();
    }
}

  // Initial load
document.addEventListener("DOMContentLoaded", loadPortfolio);

  // ✅ Refresh when user comes back to this tab/page
window.addEventListener("focus", maybeRefreshFromDirtyFlag);
document.addEventListener("visibilitychange", () => {
    if (!document.hidden) maybeRefreshFromDirtyFlag();
});

  // ✅ Refresh when another tab updates localStorage
window.addEventListener("storage", (e) => {
    if (e.key === DIRTY_KEY) {
        lastSeenDirty = e.newValue || "";
        loadPortfolio();
    }
});
})();
