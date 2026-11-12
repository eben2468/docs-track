/* Dashboard charts (Chart.js, local copy). Each <canvas data-chart='{"type","labels","values","colors","label"}'>
 * becomes a chart; data comes from the server as JSON, so no inline script is needed (CSP-safe). */
(function () {
    'use strict';

    if (typeof window.Chart === 'undefined') {
        return;
    }

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
        var spec;
        try {
            spec = JSON.parse(canvas.getAttribute('data-chart'));
        } catch (e) {
            return;
        }

        var isDoughnut = spec.type === 'doughnut';
        new window.Chart(canvas, {
            type: spec.type,
            data: {
                labels: spec.labels,
                datasets: [{
                    label: spec.label || '',
                    data: spec.values,
                    backgroundColor: spec.colors,
                    borderWidth: isDoughnut ? 2 : 0,
                    borderRadius: isDoughnut ? 0 : 4,
                    maxBarThickness: 48
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduceMotion ? false : {duration: 400},
                cutout: isDoughnut ? '62%' : undefined,
                plugins: {
                    legend: {display: isDoughnut, position: 'right', labels: {boxWidth: 12}}
                },
                scales: isDoughnut ? {} : {
                    y: {beginAtZero: true, ticks: {precision: 0}},
                    x: {grid: {display: false}}
                }
            }
        });
    });
})();
