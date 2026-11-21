/*!
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

// Adds and removes variants in the form of an experiment. The form arrives with the page of the
// reporting menu, after this file has run, so the clicks are caught on the document.
(function () {
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

    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('.rebelABTestingAddVariant, .rebelABTestingRemoveVariant') : null;
        if (!button) {
            return;
        }

        var list = button.closest('.rebelABTestingForm').querySelector('.rebelABTestingVariants');

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
    });
})();
