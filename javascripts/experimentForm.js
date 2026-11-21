/*!
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

// The form of an experiment: its variants, the fields of its kind, and how long to run it. The
// form arrives with the page of the reporting menu, after this file has run, so events are caught
// on the document.
(function () {
    // The visits each group needs to find a difference of the given size four times out of five,
    // judged the way the result page does, at 95%.
    function requiredVisits(conversionRate, relativeEffect) {
        var original = conversionRate;
        var variant = conversionRate * (1 + relativeEffect);
        if (!(original > 0 && original < 1 && variant < 1 && relativeEffect > 0)) {
            return null;
        }

        var z = 1.6449 + 0.8416;
        return Math.ceil(z * z * (original * (1 - original) + variant * (1 - variant)) / Math.pow(variant - original, 2));
    }

    function requiredDays(visitsPerGroup, groups, visitsPerDay, trafficShare) {
        var inExperiment = visitsPerDay * trafficShare;
        return visitsPerGroup && inExperiment > 0 ? Math.ceil(visitsPerGroup * groups / inExperiment) : null;
    }

    window.RebelABTesting = { requiredVisits: requiredVisits, requiredDays: requiredDays };

    if (typeof document === 'undefined') {
        return;
    }

    function renumber(list) {
        var variants = list.querySelectorAll('.rebelABTestingVariant');

        variants.forEach(function (variant, index) {
            variant.querySelector('.rebelABTestingVariantNumber').textContent = String(index + 1);
            variant.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/^variants\[\d+\]/, 'variants[' + index + ']');
            });
            variant.querySelector('.rebelABTestingRemoveVariant').hidden = variants.length === 1;
        });

        var form = list.closest('.rebelABTestingForm');
        form.querySelector('.rebelABTestingAddVariant').hidden = variants.length >= Number(list.dataset.max);
    }

    function showKind(form) {
        var kind = form.querySelector('.rebelABTestingKind').value;
        form.querySelectorAll('[data-kind]').forEach(function (section) {
            section.hidden = section.dataset.kind !== kind;
        });
    }

    function plan(form) {
        var planner = form.querySelector('.rebelABTestingPlanner');
        var number = function (selector) {
            return parseFloat(form.querySelector(selector).value);
        };
        var groups = form.querySelectorAll('.rebelABTestingVariant').length + 1;
        var visits = requiredVisits(number('.rebelABTestingPlannerRate') / 100, number('.rebelABTestingPlannerEffect') / 100);
        var days = requiredDays(visits, groups, number('.rebelABTestingPlannerVisits'), number('#traffic_share') / 100);

        planner.querySelector('.rebelABTestingPlannerResult').textContent = days
            ? planner.dataset.result.replace('{days}', days).replace('{visits}', visits.toLocaleString()).replace('{groups}', groups)
            : planner.dataset.unknown;
    }

    function refresh(form) {
        showKind(form);
        plan(form);
    }

    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('.rebelABTestingAddVariant, .rebelABTestingRemoveVariant') : null;
        if (!button) {
            return;
        }

        var form = button.closest('.rebelABTestingForm');
        var list = form.querySelector('.rebelABTestingVariants');

        if (button.classList.contains('rebelABTestingAddVariant')) {
            var copy = list.lastElementChild.cloneNode(true);
            copy.querySelectorAll('input, textarea').forEach(function (field) {
                field.value = '';
            });
            list.appendChild(copy);
        } else {
            button.closest('.rebelABTestingVariant').remove();
        }

        renumber(list);
        plan(form);
    });

    document.addEventListener('input', function (event) {
        var form = event.target instanceof Element ? event.target.closest('.rebelABTestingForm') : null;
        if (form) {
            refresh(form);
        }
    });

    // The fields of the other kind are hidden as soon as the form is on the page.
    function prepareNewForms() {
        document.querySelectorAll('.rebelABTestingForm:not([data-ready])').forEach(function (form) {
            form.dataset.ready = '1';
            refresh(form);
        });
    }

    document.addEventListener('DOMContentLoaded', prepareNewForms);
    new MutationObserver(prepareNewForms).observe(document.documentElement, { childList: true, subtree: true });
})();
