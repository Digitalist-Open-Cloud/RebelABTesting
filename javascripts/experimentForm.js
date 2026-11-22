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
    // How far from the average a result has to be, to be as sure as the experiment asks for.
    var CERTAINTY = { 90: 1.2816, 95: 1.6449, 99: 2.3263 };

    // The visits each group needs to find a difference of the given size four times out of five,
    // judged the way the result page does.
    function requiredVisits(conversionRate, relativeEffect, confidence) {
        var original = conversionRate;
        var variant = conversionRate * (1 + relativeEffect);
        if (!(original > 0 && original < 1 && variant < 1 && relativeEffect > 0)) {
            return null;
        }

        var z = (CERTAINTY[confidence] || CERTAINTY[95]) + 0.8416;
        return Math.ceil(z * z * (original * (1 - original) + variant * (1 - variant)) / Math.pow(variant - original, 2));
    }

    function requiredDays(visitsPerGroup, groups, visitsPerDay, trafficShare) {
        var inExperiment = visitsPerDay * trafficShare;
        return visitsPerGroup && inExperiment > 0 ? Math.ceil(visitsPerGroup * groups / inExperiment) : null;
    }

    window.RebelExperiments = { requiredVisits: requiredVisits, requiredDays: requiredDays };

    if (typeof document === 'undefined') {
        return;
    }

    function renumber(list) {
        var variants = list.querySelectorAll('.rebelExperimentsVariant');

        variants.forEach(function (variant, index) {
            variant.querySelector('.rebelExperimentsVariantNumber').textContent = String(index + 1);
            variant.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/^variants\[\d+\]/, 'variants[' + index + ']');
            });
            variant.querySelectorAll('.rebelExperimentsChange').forEach(function (change, changeIndex) {
                change.querySelectorAll('[name]').forEach(function (field) {
                    field.name = field.name.replace(/\[changes\]\[\d+\]/, '[changes][' + changeIndex + ']');
                });
            });
            variant.querySelector('.rebelExperimentsRemoveVariant').hidden = variants.length === 1;
        });

        var form = list.closest('.rebelExperimentsForm');
        form.querySelector('.rebelExperimentsAddVariant').hidden = variants.length >= Number(list.dataset.max);
    }

    function showKind(form) {
        var kind = form.querySelector('.rebelExperimentsKind:checked').value;
        form.querySelectorAll('[data-kind]').forEach(function (section) {
            section.hidden = section.dataset.kind.split(' ').indexOf(kind) === -1;
        });

        // A rollout is one change. Further variants of the form are kept for another kind.
        var isRollout = kind === 'rollout';
        form.querySelectorAll('.rebelExperimentsVariant').forEach(function (variant, index) {
            variant.hidden = isRollout && index > 0;
        });
        form.querySelector('.rebelExperimentsAddVariant').hidden = isRollout
            || form.querySelectorAll('.rebelExperimentsVariant').length >= Number(form.querySelector('.rebelExperimentsVariants').dataset.max);
    }

    // The form is a row of steps, of which one is shown at a time. A kind of experiment that has
    // nothing to say in a step does not have it.
    function steps(form) {
        return Array.prototype.filter.call(form.querySelectorAll('.rebelExperimentsStep'), function (step) {
            return !step.hidden;
        });
    }

    function showPanel(form, name) {
        var all = steps(form);
        var current = all.filter(function (step) {
            return step.dataset.panel === name;
        })[0] || all[0];

        form.dataset.panel = current.dataset.panel;
        form.classList.add('rebelExperimentsForm-stepped');
        form.querySelectorAll('.rebelExperimentsPanel').forEach(function (panel) {
            panel.hidden = panel.dataset.panel !== current.dataset.panel;
        });
        all.forEach(function (step, index) {
            step.querySelector('.rebelExperimentsStepNumber').textContent = String(index + 1);
            step.classList.toggle('rebelExperimentsStep-current', step === current);
            if (step === current) {
                step.setAttribute('aria-current', 'step');
            } else {
                step.removeAttribute('aria-current');
            }
        });
        form.querySelector('.rebelExperimentsPrevious').hidden = current === all[0];
        form.querySelector('.rebelExperimentsNext').hidden = current === all[all.length - 1];
    }

    function move(form, by) {
        var all = steps(form);
        var names = all.map(function (step) {
            return step.dataset.panel;
        });
        var next = all[names.indexOf(form.dataset.panel) + by];
        if (next) {
            showPanel(form, next.dataset.panel);
            form.scrollIntoView({ block: 'start' });
        }
    }

    // What a change is made with depends on what it does, and hiding needs nothing.
    function showChangeFields(form) {
        form.querySelectorAll('.rebelExperimentsChange').forEach(function (change) {
            var action = change.querySelector('select');
            var value = change.querySelector('[name$="[value]"]');
            var placeholder = action.options[action.selectedIndex].dataset.placeholder;
            value.placeholder = placeholder;
            value.style.visibility = placeholder ? '' : 'hidden';
        });

        var isAnyPage = form.querySelector('#target_match').value === 'any';
        form.querySelector('.rebelExperimentsTargetValue').hidden = isAnyPage;
        form.querySelector('.rebelExperimentsExcludeValue').hidden = form.querySelector('#exclude_match').value === 'none';
    }

    function plan(form) {
        var planner = form.querySelector('.rebelExperimentsPlanner');
        var number = function (selector) {
            return parseFloat(form.querySelector(selector).value);
        };
        var groups = form.querySelectorAll('.rebelExperimentsVariant').length + 1;
        var visits = requiredVisits(number('.rebelExperimentsPlannerRate') / 100, number('.rebelExperimentsPlannerEffect') / 100, number('#confidence'));
        var days = requiredDays(visits, groups, number('.rebelExperimentsPlannerVisits'), number('#traffic_share') / 100);

        planner.querySelector('.rebelExperimentsPlannerResult').textContent = days
            ? planner.dataset.result.replace('{days}', days).replace('{visits}', visits.toLocaleString()).replace('{groups}', groups)
            : planner.dataset.unknown;
    }

    // The category and action of an event are only asked for when an event is chosen.
    function showEventFields(form) {
        form.querySelectorAll('.rebelExperimentsMeasure').forEach(function (measure) {
            var isEvent = measure.querySelector('select').value === 'event';
            measure.querySelectorAll('.rebelExperimentsMeasureEvent').forEach(function (field) {
                field.hidden = !isEvent;
            });
        });
    }

    function refresh(form) {
        renumber(form.querySelector('.rebelExperimentsVariants'));
        showKind(form);
        showPanel(form, form.dataset.panel);
        showChangeFields(form);
        showEventFields(form);
        plan(form);
    }

    function clear(element) {
        element.querySelectorAll('input, textarea').forEach(function (field) {
            field.value = '';
        });
        element.querySelectorAll('select').forEach(function (field) {
            field.selectedIndex = 0;
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element
            ? event.target.closest('.rebelExperimentsForm button, .rebelExperimentsForm input[type=submit]')
            : null;
        if (!button) {
            return;
        }

        var form = button.closest('.rebelExperimentsForm');
        var list = form.querySelector('.rebelExperimentsVariants');
        var changes;
        var copy;

        if (button.classList.contains('rebelExperimentsStep')) {
            showPanel(form, button.dataset.panel);
            return;
        }
        if (button.classList.contains('rebelExperimentsPrevious') || button.classList.contains('rebelExperimentsNext')) {
            move(form, button.classList.contains('rebelExperimentsNext') ? 1 : -1);
            return;
        }
        if (button.type === 'submit') {
            // A field that is not filled in right is shown, so that the browser can point at it.
            var invalid = form.querySelector(':invalid');
            if (invalid) {
                showPanel(form, invalid.closest('.rebelExperimentsPanel').dataset.panel);
            }
            return;
        }

        if (button.classList.contains('rebelExperimentsAddVariant')) {
            copy = list.lastElementChild.cloneNode(true);
            copy.querySelectorAll('.rebelExperimentsChange:not(:first-child)').forEach(function (change) {
                change.remove();
            });
            clear(copy);
            list.appendChild(copy);
        } else if (button.classList.contains('rebelExperimentsRemoveVariant')) {
            button.closest('.rebelExperimentsVariant').remove();
        } else if (button.classList.contains('rebelExperimentsAddChange')) {
            changes = button.closest('.rebelExperimentsVariant').querySelector('.rebelExperimentsChanges');
            copy = changes.lastElementChild.cloneNode(true);
            clear(copy);
            changes.appendChild(copy);
        } else if (button.classList.contains('rebelExperimentsRemoveChange')) {
            changes = button.closest('.rebelExperimentsChanges');
            // The last row stays, empty, so that there is one to fill in.
            if (changes.children.length === 1) {
                clear(changes.firstElementChild);
            } else {
                button.closest('.rebelExperimentsChange').remove();
            }
        } else {
            return;
        }

        renumber(list);
        refresh(form);
    });

    // Replaces the changes of a variant with the ones of the template that was chosen.
    function useTemplate(select) {
        var option = select.options[select.selectedIndex];
        var changes = option && option.dataset.changes ? JSON.parse(option.dataset.changes) : null;
        if (!changes) {
            return;
        }

        var variant = select.closest('.rebelExperimentsVariant');
        var rows = variant.querySelector('.rebelExperimentsChanges');
        while (rows.children.length > 1) {
            rows.lastElementChild.remove();
        }

        changes.forEach(function (change, index) {
            var row = index === 0 ? rows.firstElementChild : rows.firstElementChild.cloneNode(true);
            row.querySelector('[name$="[selector]"]').value = change.selector;
            row.querySelector('[name$="[action]"]').value = change.action;
            row.querySelector('[name$="[value]"]').value = change.value;
            rows.appendChild(row);
        });

        renumber(variant.closest('.rebelExperimentsVariants'));
        showChangeFields(variant.closest('.rebelExperimentsForm'));
        var empty = Array.prototype.find.call(rows.querySelectorAll('[name$="[selector]"]'), function (field) {
            return field.value === '';
        });
        if (empty) {
            empty.focus();
        }
    }

    // The form to edit an experiment takes the place of the list, and the list comes back by
    // loading the page again.
    document.addEventListener('click', function (event) {
        var link = event.target instanceof Element ? event.target.closest('.rebelExperimentsEdit, .rebelExperimentsBack') : null;
        if (!link) {
            return;
        }
        event.preventDefault();

        if (link.classList.contains('rebelExperimentsBack')) {
            window.location.reload();
            return;
        }

        var list = link.closest('.rebelExperimentsPlugin');
        fetch(link.href, { credentials: 'same-origin' })
            .then(function (response) {
                return response.text();
            })
            .then(function (html) {
                var form = document.createElement('div');
                form.innerHTML = html;
                list.replaceWith(form.firstElementChild);
                window.scrollTo(0, 0);
            });
    });

    document.addEventListener('change', function (event) {
        if (event.target instanceof Element && event.target.classList.contains('rebelExperimentsTemplate')) {
            useTemplate(event.target);
        }
    });

    document.addEventListener('input', function (event) {
        var form = event.target instanceof Element ? event.target.closest('.rebelExperimentsForm') : null;
        if (form) {
            refresh(form);
        }
    });

    // The fields of the other kind are hidden as soon as the form is on the page.
    function prepareNewForms() {
        document.querySelectorAll('.rebelExperimentsForm:not([data-ready])').forEach(function (form) {
            form.dataset.ready = '1';
            refresh(form);
        });
    }

    document.addEventListener('DOMContentLoaded', prepareNewForms);
    new MutationObserver(prepareNewForms).observe(document.documentElement, { childList: true, subtree: true });
})();
