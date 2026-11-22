describe('RebelABTesting', function () {
    this.fixture = 'Piwik\\Plugins\\RebelABTesting\\tests\\Fixtures\\SiteWithGoal';

    const params = 'idSite=1&period=day&date=2024-03-05';
    const pageUrl = (subcategory) => `?module=CoreHome&action=index&${params}#?${params}`
        + `&category=RebelABTesting_RebelABTesting&subcategory=${subcategory}`;

    before(function () {
        testEnvironment.pluginsToLoad = ['RebelABTesting'];
        testEnvironment.save();
    });

    function count(selector) {
        return page.evaluate((s) => document.querySelectorAll(s).length, selector);
    }

    function text(selector) {
        return page.evaluate((s) => [...document.querySelectorAll(s)].map((e) => e.textContent.trim()).join(' | '), selector);
    }

    async function submit(selector) {
        await Promise.all([page.waitForNavigation(), page.click(selector)]);
        await page.waitForSelector('.rebelABTestingPlugin', { visible: true });
        await page.waitForNetworkIdle();
    }

    it('should add and remove variants in the form', async function () {
        await page.goto(pageUrl('RebelABTesting_CreateNewExperiment'));
        await page.waitForSelector('.rebelABTestingForm', { visible: true });
        await page.waitForNetworkIdle();

        expect(await count('.rebelABTestingVariant')).to.equal(1);

        await page.click('.rebelABTestingAddVariant');
        await page.click('.rebelABTestingAddVariant');
        expect(await count('.rebelABTestingVariant')).to.equal(3);
        expect(await count('textarea[name="variants[2][css]"]')).to.equal(1);

        await page.evaluate(() => document.querySelectorAll('.rebelABTestingRemoveVariant')[1].click());
        expect(await count('.rebelABTestingVariant')).to.equal(2);
        expect(await count('textarea[name="variants[1][js]"]')).to.equal(1);
        expect(await text('.rebelABTestingVariantNumber')).to.equal('1 | 2');
    });

    it('should add and remove the changes of a variant', async function () {
        const names = () => page.evaluate(() => [...document.querySelectorAll('.rebelABTestingVariant:nth-child(2) .rebelABTestingChange input[name$="[selector]"]')]
            .map((field) => `${field.name}=${field.value}`));

        await page.type('input[name="variants[1][changes][0][selector]"]', 'h1');
        await page.evaluate(() => document.querySelector('.rebelABTestingVariant:nth-child(2) .rebelABTestingAddChange').click());
        await page.type('input[name="variants[1][changes][1][selector]"]', '.banner');
        expect(await names()).to.deep.equal(['variants[1][changes][0][selector]=h1', 'variants[1][changes][1][selector]=.banner']);

        await page.evaluate(() => document.querySelector('.rebelABTestingVariant:nth-child(2) .rebelABTestingRemoveChange').click());
        expect(await names()).to.deep.equal(['variants[1][changes][0][selector]=.banner']);

        await page.evaluate(() => document.querySelector('.rebelABTestingVariant:nth-child(2) .rebelABTestingRemoveChange').click());
        expect(await names()).to.deep.equal(['variants[1][changes][0][selector]=']);
    });

    it('should show the fields of the kind of experiment', async function () {
        const visible = (selector) => page.evaluate((s) => document.querySelector(s).offsetParent !== null, selector);

        expect(await visible('#door_label')).to.equal(false);
        expect(await visible('.rebelABTestingVariants')).to.equal(true);

        await page.select('#kind', 'fake_door');
        expect(await visible('#door_label')).to.equal(true);
        expect(await visible('.rebelABTestingVariants')).to.equal(false);
        expect(await visible('#planner_visits')).to.equal(false);

        await page.select('#kind', 'ab');
        expect(await visible('#door_label')).to.equal(false);
    });

    it('should ask for the category of an event only when an event is chosen', async function () {
        const visible = (selector) => page.evaluate((s) => document.querySelector(s).offsetParent !== null, selector);

        expect(await visible('#primary_measure_category')).to.equal(false);

        await page.select('#primary_measure', 'event');
        expect(await visible('#primary_measure_category')).to.equal(true);
        expect(await visible('#guardrail_0_category')).to.equal(false);

        await page.select('#primary_measure', 'goal:1');
        expect(await visible('#primary_measure_category')).to.equal(false);
    });

    it('should tell how long to run the experiment', async function () {
        expect(await text('.rebelABTestingPlannerResult')).to.equal('Fill in the three numbers to see the days.');

        await page.type('#planner_visits', '2000');
        await page.type('#planner_rate', '5');

        // 24,601 visits in each of 3 groups, 2,000 visits a day.
        expect(await text('.rebelABTestingPlannerResult')).to.contain('Run for at least 37 days');
    });

    it('should create an experiment and go back to the list', async function () {
        await page.type('.rebelABTestingForm #name', 'Green button');
        await page.type('.rebelABTestingForm #hypothesis', 'Green gets more clicks');
        await page.evaluate(() => {
            document.querySelector('#target_match').value = 'contains';
            document.querySelector('#target_value').value = '/pricing';
        });
        await page.type('input[name="variants[0][name]"]', 'Green');
        await page.type('input[name="variants[0][changes][0][selector]"]', '.buy');
        await page.select('select[name="variants[0][changes][0][action]"]', 'add_class');
        await page.type('input[name="variants[0][changes][0][value]"]', 'green');
        await page.type('textarea[name="variants[0][css]"]', '.btn { background: green; }');
        await page.type('textarea[name="variants[1][js]"]', "// second\ndocument.title = 'B';");
        await submit('.rebelABTestingForm input[type=submit]');

        expect(page.url()).to.contain('subcategory=RebelABTesting_ExistingExperiments');
        expect(await text('.rebelABTestingTable tbody tr td:nth-child(2)')).to.equal('Green button');
        expect(await text('.rebelABTestingStatus')).to.equal('Running');
        expect(await text('.notification')).to.contain('The experiment was created.');
    });

    it('should show the menu icon and a page for the experiment', async function () {
        const mask = await page.evaluate(() => {
            const icon = document.querySelector('.rebelABTestingIcon');
            const style = icon ? getComputedStyle(icon, '::before') : null;
            return style ? (style.maskImage || style.webkitMaskImage) : 'no icon';
        });
        expect(mask).to.contain('svg');

        await page.goto(pageUrl('1'));
        await page.waitForSelector('.rebelABTestingPlugin h2', { visible: true });
        await page.waitForNetworkIdle();

        expect(await text('.rebelABTestingPlugin h2')).to.equal('Green button');
        expect(await text('.rebelABTestingTable:first-of-type tbody td:first-child')).to.equal('Original | Green | Variant 2');
        expect(await text('.rebelABTestingMeasureTable-primary tbody td:first-child')).to.equal('Original | Green | Variant 2');
        expect(await text('h3.form-section-title')).to.contain('Judged by: Goal: Thanks page');
        expect(await count('a[href*="#rabt-preview=1-"]')).to.equal(3);
    });

    it('should edit an experiment', async function () {
        await page.goto(pageUrl('RebelABTesting_ExistingExperiments'));
        await page.waitForSelector('.rebelABTestingTable', { visible: true });
        await Promise.all([page.waitForNavigation(), page.click('.rebelABTestingTable .actions-cell a.btn')]);
        await page.waitForSelector('.rebelABTestingForm', { visible: true });

        expect(await page.evaluate(() => document.querySelector('textarea[name="variants[1][js]"]').value)).to.equal("// second\ndocument.title = 'B';");
        expect(await page.evaluate(() => document.querySelector('#target_match').value)).to.equal('contains');
        expect(await page.evaluate(() => ['selector', 'action', 'value']
            .map((field) => document.querySelector(`[name="variants[0][changes][0][${field}]"]`).value))).to.deep.equal(['.buy', 'add_class', 'green']);

        await page.evaluate(() => { document.querySelector('.rebelABTestingForm #name').value = 'Greener button'; });
        await submit('.rebelABTestingForm input[type=submit]');

        expect(await text('.rebelABTestingTable tbody tr td:nth-child(2)')).to.equal('Greener button');
        expect(await text('.notification')).to.contain('The experiment was updated.');
    });

    it('should tell when an experiment cannot be saved', async function () {
        await page.goto(pageUrl('RebelABTesting_CreateNewExperiment'));
        await page.waitForSelector('.rebelABTestingForm', { visible: true });
        await page.type('.rebelABTestingForm #name', 'Greener button');
        await submit('.rebelABTestingForm input[type=submit]');

        expect(page.url()).to.contain('subcategory=RebelABTesting_CreateNewExperiment');
        expect(await text('.notification')).to.contain('already has an experiment with this name');
    });

    it('should stop and delete an experiment', async function () {
        await page.goto(pageUrl('RebelABTesting_ExistingExperiments'));
        await page.waitForSelector('.rebelABTestingTable', { visible: true });
        await submit('.rebelABTestingTable form[action*="action=stop"] button');
        expect(await text('.rebelABTestingStatus')).to.equal('Ended');
        expect(await count('form[action*="action=stop"]')).to.equal(0);

        await submit('.rebelABTestingTable form[action*="action=delete"] button');
        expect(await count('.rebelABTestingTable')).to.equal(0);
        expect(await text('.rebelABTestingPlugin .alert')).to.contain('No experiments');
    });
});
