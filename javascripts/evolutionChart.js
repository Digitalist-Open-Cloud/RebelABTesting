/*!
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

// The chart of an experiment: a line under the pointer, and the values of that day beside it.
(function () {
    function nearest(xs, x) {
        var best = 0;
        for (var i = 1; i < xs.length; i++) {
            if (Math.abs(xs[i] - x) < Math.abs(xs[best] - x)) {
                best = i;
            }
        }
        return best;
    }

    function show(chart, event) {
        var svg = chart.querySelector('svg');
        var box = svg.getBoundingClientRect();
        var xs = JSON.parse(chart.dataset.xs);
        var index = nearest(xs, (event.clientX - box.left) * svg.viewBox.baseVal.width / box.width);
        var lines = JSON.parse(chart.dataset.lines);
        var crosshair = chart.querySelector('.rebelABTestingChartCrosshair');
        var tip = chart.querySelector('.rebelABTestingChartTip');

        crosshair.setAttribute('x1', xs[index]);
        crosshair.setAttribute('x2', xs[index]);
        crosshair.removeAttribute('hidden');

        tip.textContent = '';
        var day = document.createElement('strong');
        day.textContent = JSON.parse(chart.dataset.days)[index];
        tip.appendChild(day);
        lines.forEach(function (line) {
            if (line.labels[index] === '') {
                return;
            }
            var row = document.createElement('div');
            var swatch = document.createElement('span');
            swatch.className = 'rebelABTestingChartSwatch';
            swatch.style.background = line.colour;
            row.appendChild(swatch);
            row.appendChild(document.createTextNode(line.name + ': ' + line.labels[index]));
            tip.appendChild(row);
        });

        tip.hidden = false;
        var left = xs[index] * box.width / svg.viewBox.baseVal.width;
        tip.style.left = Math.min(left + 12, box.width - tip.offsetWidth) + 'px';
    }

    function hide(chart) {
        chart.querySelector('.rebelABTestingChartCrosshair').setAttribute('hidden', '');
        chart.querySelector('.rebelABTestingChartTip').hidden = true;
    }

    document.addEventListener('mousemove', function (event) {
        var chart = event.target instanceof Element ? event.target.closest('.rebelABTestingChart') : null;
        document.querySelectorAll('.rebelABTestingChart').forEach(function (other) {
            if (other !== chart) {
                hide(other);
            }
        });
        if (chart && event.target.closest('svg')) {
            show(chart, event);
        } else if (chart) {
            hide(chart);
        }
    });
})();
