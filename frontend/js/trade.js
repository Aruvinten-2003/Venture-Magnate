// frontend/js/trade.js
(() => {
  "use strict";

  const API = window.API;
  if (!API) {
    console.error("window.API not loaded. Make sure api.js is included BEFORE trade.js");
    return;
  }

  const $ = (id) => document.getElementById(id);

  const symbolInput  = $("symbolInput");
  const catSelect    = $("categorySelect");
  const chartTypeSelect = $("chartTypeSelect");

  const buyPill    = $("buyPill");
  const sellPill   = $("sellPill");
  const marketPill = $("marketPill");
  const limitPill  = $("limitPill");

  const tradeForm  = $("tradeForm");
  const qtyInput   = $("qtyInput");
  const priceInput = $("priceInput");
  const tradeMsg   = $("tradeMsg");

  const chartCanvas = $("priceChart");

  const required = [symbolInput, catSelect, buyPill, sellPill, marketPill, limitPill, tradeForm, qtyInput, priceInput, tradeMsg, chartCanvas];
  if (required.some((x) => !x)) {
    console.error("Missing one or more required elements. Check your trading.html IDs.");
    return;
  }

  let side      = "buy";
  let orderType = "Market";
  let symbol    = (symbolInput.value || "AAPL").trim().toUpperCase();
  let lastPrice = 0;

  const setActive = (el, siblings) => {
    siblings.forEach((s) => s.classList.remove("active"));
    el.classList.add("active");
  };

  const showMsg = (text, ok = false) => {
    tradeMsg.style.color = ok ? "#00ff88" : "#ef5350";
    tradeMsg.textContent = text;
  };

  const num = (v, fallback = 0) => {
    const n = Number(v);
    return Number.isFinite(n) ? n : fallback;
  };

  // Chart
  const ctx = chartCanvas.getContext("2d");
  const grad = ctx.createLinearGradient(0, 0, 0, 280);
  grad.addColorStop(0, "rgba(0,255,136,.30)");
  grad.addColorStop(1, "rgba(0,255,136,0)");

  const chart = new Chart(ctx, {
    type: "line",
    data: { labels: [], datasets: [{
      label: "Price ($)",
      data: [],
      borderColor: "#00ff88",
      backgroundColor: grad,
      tension: 0.35,
      pointRadius: 2.2,
      pointBackgroundColor: "#00ff88",
      fill: true
    }]},
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { color: "#e6e6e6" } } },
      scales: {
        x: { grid: { color: "#202020" }, ticks: { color: "#cfcfcf" } },
        y: { grid: { color: "#202020" }, ticks: { color: "#cfcfcf" } }
      }
    }
  });

  function toLabel(ts) {
    if (typeof ts === "number") {
      const d = new Date(ts * 1000);
      return d.toLocaleDateString(undefined, { weekday: "short" });
    }
    const d = new Date(ts);
    if (!isNaN(d)) return d.toLocaleDateString(undefined, { weekday: "short" });
    return String(ts);
  }

  function sampleSeries() {
    const base = 170 + Math.random() * 10;
    return Array.from({ length: 7 }, (_, i) => ({
      t: Date.now() / 1000 - (6 - i) * 86400,
      p: +(base + i * 2 + (Math.random() * 2 - 1)).toFixed(2)
    }));
  }

  function updateChart(series) {
    const labels = series.map((p) => toLabel(p.t ?? p.time ?? p.date ?? ""));
    const values = series.map((p) => num(p.p ?? p.price ?? p.c, 0));
    lastPrice = values.length ? values[values.length - 1] : 0;

    chart.data.labels = labels;
    chart.data.datasets[0].data = values;
    chart.data.datasets[0].label = `${symbol} Price ($)`;
    chart.update();

    if (orderType === "Market") {
      priceInput.value = "";
      priceInput.placeholder = lastPrice ? `Auto (${lastPrice})` : "Auto (Market)";
    }
  }

  async function loadSeries() {
    try {
      const cat = catSelect.value;
      let querySymbol = symbol.trim().toUpperCase();

      if (cat === "crypto" && !/USDT$/.test(querySymbol)) querySymbol += "USDT";

      const res = await API.fetchPrices(querySymbol);

      const row = Array.isArray(res)
        ? res.find((x) => String(x.symbol).toUpperCase() === querySymbol)
        : null;

      lastPrice = row?.price ? num(row.price, 0) : 0;

      const baseSeries = sampleSeries();
      const series = baseSeries.map((p, i) => ({
        t: p.t,
        p: lastPrice
          ? Number((lastPrice - (6 - i) * 0.6 + (Math.random() * 0.4 - 0.2)).toFixed(2))
          : p.p
      }));

      updateChart(series);
    } catch (e) {
      console.warn("Price fetch failed; using sample data.", e);
      updateChart(sampleSeries());
    }
  }

  // Pills
  buyPill.addEventListener("click", () => {
    side = "buy";
    setActive(buyPill, [buyPill, sellPill]);
  });

  sellPill.addEventListener("click", () => {
    side = "sell";
    setActive(sellPill, [buyPill, sellPill]);
  });

  marketPill.addEventListener("click", () => {
    orderType = "Market";
    setActive(marketPill, [marketPill, limitPill]);
    priceInput.disabled = true;
    priceInput.value = "";
    priceInput.placeholder = lastPrice ? `Auto (${lastPrice})` : "Auto (Market)";
  });

  limitPill.addEventListener("click", () => {
    orderType = "Limit";
    setActive(limitPill, [marketPill, limitPill]);
    priceInput.disabled = false;
    priceInput.placeholder = "Enter price";
  });

  // Inputs
  symbolInput.addEventListener("change", () => {
    symbol = symbolInput.value.trim().toUpperCase() || "AAPL";
    loadSeries();
  });

  catSelect.addEventListener("change", loadSeries);
  chartTypeSelect?.addEventListener("change", loadSeries);

  // Submit
  tradeForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    showMsg("");

    symbol = (symbolInput.value || symbol).trim().toUpperCase();
    const qty = num(qtyInput.value, 0);

    if (!symbol || qty <= 0) {
      showMsg("Enter a valid symbol and quantity.");
      return;
    }

    const payload = {
      symbol,
      quantity: qty,          // send as number (PHP can read it)
      order_type: orderType,
    };

    if (orderType === "Limit") {
      const px = num(priceInput.value, 0);
      if (px <= 0) {
        showMsg("Enter a valid limit price.");
        return;
      }
      payload.price = px;
    }

    try {
      // Helpful: detect not-logged-in clearly
      const me = await API.me();
      if (!me?.user_id) {
        showMsg("You are not logged in. Please login first.");
        return;
      }

      const resp = (side === "sell") ? await API.sell(payload) : await API.buy(payload);

      if (!resp || resp.success === false) {
        throw new Error(resp?.message || "Order failed.");
      }

      // ✅ ONLY here (after successful trade)
      localStorage.setItem("vm:portfolioDirty", String(Date.now()));

      showMsg(`${side.toUpperCase()} ${qty} ${symbol} — OK`, true);

      qtyInput.value = "";
      if (orderType === "Limit") priceInput.value = "";
    } catch (err) {
      showMsg(err?.message || "Order failed.");
      console.error("Trade error:", err);
    }
  });

  // Init
  symbolInput.value = symbol;
  priceInput.disabled = true;
  priceInput.placeholder = "Auto (Market)";

  setActive(buyPill, [buyPill, sellPill]);
  setActive(marketPill, [marketPill, limitPill]);

  loadSeries();
})();
