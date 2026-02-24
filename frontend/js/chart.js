/* ===== Simulated time-series for 4 symbols (aligns with your table) ===== */
const labels = ['Mon','Tue','Wed','Thu','Fri'];
const series = {
    AAPL: [220, 224, 226, 225, 227],
    TSLA: [195, 198, 200, 197, 199],
    AMZN: [132, 135, 137, 138, 139],
    MSFT: [410, 412, 411, 414, 415]
};
const colors = {
    AAPL: '#00FF88',
    TSLA: '#E74C3C',
    AMZN: '#36A2EB',
    MSFT: '#FFB020'
};

/* ===== Build a gradient that matches your dark theme ===== */
function lineGradient(ctx, color) {
    const g = ctx.createLinearGradient(0, 0, 0, 280);
  g.addColorStop(0, color + 'CC');   // ~80% opacity
  g.addColorStop(1, color + '00');   // transparent
    return g;
}

/* ===== Create Chart.js line chart ===== */
const canvas = document.getElementById('marketChart');
const ctx = canvas.getContext('2d');

let currentSymbols = ['AAPL', 'TSLA']; // default datasets on load

function datasetFor(sym) {
    const color = colors[sym] || '#00FF88';
    return {
    label: sym,
    data: series[sym] || [],
    borderColor: color,
    backgroundColor: lineGradient(ctx, '#00FF88'), // subtle fill for first dataset
    pointRadius: 0,
    tension: 0.25,
    borderWidth: 2,
    fill: sym === currentSymbols[0] ? true : false
};
}

const chart = new Chart(ctx, {
    type: 'line',
    data: {
    labels,
    datasets: currentSymbols.map(datasetFor)
},
    options: {
    responsive: true,
    maintainAspectRatio: false, // use .chart-wrap height
    plugins: {
        legend: { labels: { color: '#E0E0E0' } },
        tooltip: { mode: 'index', intersect: false }
    },
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { ticks: { color: '#E0E0E0' }, grid: { color: '#333', display: true } },
        y: { ticks: { color: '#E0E0E0' }, grid: { color: '#333' } }
    }
}
});

/* ===== Click table row to switch chart ===== */
document.querySelectorAll('#marketTable tr[data-symbol]').forEach(row => {
    row.addEventListener('click', () => {
    const sym = row.getAttribute('data-symbol');
    if (!series[sym]) return;

    // Put clicked symbol first, keep second as last selected (or default)
    const second = currentSymbols.find(s => s !== sym) || 'TSLA';
    currentSymbols = [sym, second];

    chart.data.datasets = currentSymbols.map(datasetFor);
    chart.update();
});
});

/* ===== Form: update chart symbol + simulate trade ===== */
const form = document.getElementById('tradeForm');
const msg = document.getElementById('tradeMsg');

form.addEventListener('submit', (e) => {
    e.preventDefault();
    const sym = (document.getElementById('symbol').value || '').trim().toUpperCase();
    const qty = Number(document.getElementById('quantity').value || 0);
    const side = document.getElementById('type').value;

if (!sym || !side || qty <= 0) {
    msg.textContent = 'Please enter a valid symbol, type, and quantity.';
    msg.style.color = '#E74C3C';
    return;
}

if (!series[sym]) {
    msg.textContent = `Symbol ${sym} not found in demo dataset. Try AAPL, TSLA, AMZN, or MSFT.`;
    msg.style.color = '#E74C3C';
    return;
}

  // Update chart to show the submitted symbol (keep one other)
const other = currentSymbols.find(s => s !== sym) || 'AAPL';
currentSymbols = [sym, other];
chart.data.datasets = currentSymbols.map(datasetFor);
chart.update();

  // Simulated confirmation message
    msg.textContent = `Simulated ${side.toUpperCase()} order placed for ${qty} shares of ${sym}.`;
    msg.style.color = '#00FF88';
});
