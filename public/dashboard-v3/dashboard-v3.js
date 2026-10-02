'use strict';
(() => {
  function drawChart() {
    const canvas = document.getElementById('fasSalesChart');
    const data = window.FAS_DASHBOARD_V3;
    if (!canvas || !data) return;

    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(520, rect.width);
    const height = Math.max(220, rect.height);
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.clearRect(0, 0, width, height);

    const pad = {top:22,right:16,bottom:38,left:42};
    const chartW = width - pad.left - pad.right;
    const chartH = height - pad.top - pad.bottom;
    const sales = data.sales.map(Number);
    const labels = data.labels || [];
    const max = Math.max(...sales, 1);
    const stepX = chartW / Math.max(labels.length - 1, 1);

    ctx.font = '10px Almarai, sans-serif';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';

    for (let i = 0; i <= 4; i++) {
      const y = pad.top + (chartH / 4) * i;
      ctx.strokeStyle = '#edf1f5';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(width - pad.right, y);
      ctx.stroke();

      const value = Math.round(max - (max / 4) * i);
      ctx.fillStyle = '#93a3b1';
      ctx.fillText(value.toLocaleString('ar-EG'), pad.left - 7, y);
    }

    const barW = Math.min(32, chartW / Math.max(labels.length * 2.5, 1));
    sales.forEach((value, index) => {
      const x = pad.left + stepX * index;
      const h = (value / max) * chartH;
      ctx.fillStyle = 'rgba(255,113,0,.12)';
      ctx.fillRect(x - barW/2, pad.top + chartH - h, barW, h);

      ctx.fillStyle = '#7b8fa1';
      ctx.textAlign = 'center';
      ctx.fillText(labels[index] || '', x, height - 15);
    });

    const points = sales.map((value, index) => ({
      x: pad.left + stepX * index,
      y: pad.top + chartH - (value / max) * chartH
    }));

    const gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + chartH);
    gradient.addColorStop(0, 'rgba(255,113,0,.20)');
    gradient.addColorStop(1, 'rgba(255,113,0,0)');

    ctx.beginPath();
    points.forEach((p, index) => index ? ctx.lineTo(p.x,p.y) : ctx.moveTo(p.x,p.y));
    ctx.lineTo(points[points.length-1].x, pad.top + chartH);
    ctx.lineTo(points[0].x, pad.top + chartH);
    ctx.closePath();
    ctx.fillStyle = gradient;
    ctx.fill();

    ctx.beginPath();
    points.forEach((p, index) => index ? ctx.lineTo(p.x,p.y) : ctx.moveTo(p.x,p.y));
    ctx.strokeStyle = '#ff7100';
    ctx.lineWidth = 2.5;
    ctx.stroke();

    points.forEach(p => {
      ctx.beginPath();
      ctx.arc(p.x,p.y,4.5,0,Math.PI*2);
      ctx.fillStyle = '#ff7100';
      ctx.fill();
      ctx.beginPath();
      ctx.arc(p.x,p.y,2,0,Math.PI*2);
      ctx.fillStyle = '#fff';
      ctx.fill();
    });
  }

  const debounced = (() => {
    let timer;
    return () => {
      clearTimeout(timer);
      timer = setTimeout(drawChart, 120);
    };
  })();

  window.addEventListener('resize', debounced);
  document.addEventListener('DOMContentLoaded', drawChart);
})();