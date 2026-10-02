'use strict';
(() => {
  function drawChart() {
    const canvas = document.getElementById('fasSalesChart');
    const data = window.FAS_DASHBOARD_V3;
    if (!canvas || !data) return;

    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(360, rect.width || 0);
    const height = Math.max(145, rect.height || 0);
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);

    const ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);

    const pad = {top:14,right:12,bottom:28,left:36};
    const chartW = Math.max(1, width - pad.left - pad.right);
    const chartH = Math.max(1, height - pad.top - pad.bottom);
    const sales = (data.sales || []).map(Number);
    const labels = data.labels || [];

    if (!sales.length) return;

    const max = Math.max(...sales, 1);
    const stepX = chartW / Math.max(labels.length - 1, 1);

    ctx.font = '8px Almarai, sans-serif';
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
      ctx.textAlign = 'right';
      ctx.fillText(value.toLocaleString('ar-EG'), pad.left - 5, y);
    }

    const barW = Math.min(24, chartW / Math.max(labels.length * 2.4, 1));
    sales.forEach((value, index) => {
      const x = pad.left + stepX * index;
      const h = (value / max) * chartH;
      ctx.fillStyle = 'rgba(255,113,0,.13)';
      ctx.fillRect(x - barW / 2, pad.top + chartH - h, barW, h);

      ctx.fillStyle = '#7b8fa1';
      ctx.textAlign = 'center';
      ctx.fillText(labels[index] || '', x, height - 11);
    });

    const points = sales.map((value, index) => ({
      x: pad.left + stepX * index,
      y: pad.top + chartH - (value / max) * chartH,
    }));

    const gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + chartH);
    gradient.addColorStop(0, 'rgba(255,113,0,.18)');
    gradient.addColorStop(1, 'rgba(255,113,0,0)');

    ctx.beginPath();
    points.forEach((point, index) => index ? ctx.lineTo(point.x, point.y) : ctx.moveTo(point.x, point.y));
    ctx.lineTo(points[points.length - 1].x, pad.top + chartH);
    ctx.lineTo(points[0].x, pad.top + chartH);
    ctx.closePath();
    ctx.fillStyle = gradient;
    ctx.fill();

    ctx.beginPath();
    points.forEach((point, index) => index ? ctx.lineTo(point.x, point.y) : ctx.moveTo(point.x, point.y));
    ctx.strokeStyle = '#ff7100';
    ctx.lineWidth = 2.2;
    ctx.stroke();

    points.forEach(point => {
      ctx.beginPath();
      ctx.arc(point.x, point.y, 3.5, 0, Math.PI * 2);
      ctx.fillStyle = '#ff7100';
      ctx.fill();
      ctx.beginPath();
      ctx.arc(point.x, point.y, 1.5, 0, Math.PI * 2);
      ctx.fillStyle = '#fff';
      ctx.fill();
    });
  }

  function initCustomPeriod() {
    const toggle = document.querySelector('[data-custom-period-toggle]');
    const form = document.querySelector('[data-custom-period-form]');
    if (!toggle || !form) return;

    toggle.addEventListener('click', () => {
      form.classList.toggle('open');
      toggle.classList.toggle('active', form.classList.contains('open'));
      if (form.classList.contains('open')) {
        form.querySelector('input[name="from"]')?.focus();
      }
    });
  }

  const redraw = (() => {
    let timer;
    return () => {
      clearTimeout(timer);
      timer = setTimeout(drawChart, 120);
    };
  })();

  window.addEventListener('resize', redraw);
  document.addEventListener('DOMContentLoaded', () => {
    initCustomPeriod();
    drawChart();
  });
})();