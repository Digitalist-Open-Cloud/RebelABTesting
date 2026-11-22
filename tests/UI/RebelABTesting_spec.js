describe('RebelABTesting', function () {
    this.fixture = 'Piwik\\Plugins\\RebelABTesting\\tests\\Fixtures\\SiteWithGoal';

    const params = 'idSite=1&period=day&date=2024-03-05';
    const pageUrl = (subcategory) => `?module=CoreHome&action=index&${params}#?${params}`
        + `&category=RebelABTesting_RebelABTesting&subcategory=${subcategory}`;

    before(async function () {
        testEnvironment.pluginsToLoad = ['RebelABTesting'];
        testEnvironment.save();

        // The database of the fixture can be kept from an earlier run, with its experiments.
        for (const experiment of await testEnvironment.callApi('RebelABTesting.getExperiments', { idSite: 1 })) {
            await testEnvironment.callApi('RebelABTesting.deleteExperiment', { idSite: 1, id: experiment.id });
        }
    });

    function count(selector) {
        return page.evaluate((s) => document.querySelectorAll(s).length, selector);
    }

    function text(selector) {
        return page.evaluate((s) => [...document.querySelectorAll(s)].map((e) => e.textContent.trim()).join(' | '), selector);
    }

    // The steps as a visitor reads them, without what is hidden.
    const stepNames = () => page.evaluate(() => [...document.querySelectorAll('.rebelABTestingStep')]
        .filter((step) => step.offsetParent !== null).map((step) => step.innerText.replace(/\s+/g, ' ').trim()).join(' | '));

    // Shows a step of the form.
    const step = (name) => page.click(`.rebelABTestingStep[data-panel=${name}]`);
    const visible = (selector) => page.evaluate((s) => document.querySelector(s).offsetParent !== null, selector);

    async function submit(selector) {
        await Promise.all([page.waitForNavigation(), page.click(selector)]);
        await page.waitForSelector('.rebelABTestingPlugin', { visible: true });
        await page.waitForNetworkIdle();
    }

    it('should add and remove variants in the form', async function () {
        await page.goto(pageUrl('RebelABTesting_CreateNewExperiment'));
        await page.waitForSelector('.rebelABTestingForm', { visible: true });
        await page.waitForNetworkIdle();

        expect(await stepNames()).to.equal('1 Basics | 2 Variants | 3 Where and who | 4 Measures | 5 When');
        expect(await visible('#name')).to.equal(true);
        expect(await visible('.rebelABTestingVariants')).to.equal(false);

        await page.click('.rebelABTestingNext');
        expect(await visible('.rebelABTestingVariants')).to.equal(true);
        expect(await visible('#name')).to.equal(false);
        expect(await stepNames()).to.contain('2 Variants');
        expect(await page.evaluate(() => document.querySelector('.rebelABTestingStep-current').dataset.panel)).to.equal('changes');
        expect(await count('.rebelABTestingVariant')).to.equal(1);
        expect(await visible('.rebelABTestingRemoveVariant')).to.equal(false);

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

    it('should fill in the changes of a template', async function () {
        const rows = () => page.evaluate(() => [...document.querySelectorAll('.rebelABTestingVariant:nth-child(2) .rebelABTestingChange')]
            .map((row) => ['selector', 'action', 'value'].map((field) => row.querySelector(`[name$="[${field}]"]`).value).join(' / ')));

        await page.select('.rebelABTestingVariant:nth-child(2) .rebelABTestingTemplate', 'banner');
        expect(await rows()).to.deep.equal([
            'body / insert_first / Free shipping this week',
            '.rebel-experiment-insert / style / background: #fff3cd; color: #222222; padding: 10px; text-align: center',
        ]);
        expect(await count('[name="variants[1][changes][1][value]"]')).to.equal(1);

        await page.select('.rebelABTestingVariant:nth-child(2) .rebelABTestingTemplate', 'hide');
        expect(await rows()).to.deep.equal([' / hide / ']);
        expect(await page.evaluate(() => document.activeElement.name)).to.equal('variants[1][changes][0][selector]');
    });

    it('should show the fields of the kind of experiment', async function () {
        expect(await visible('#door_label')).to.equal(false);
        expect(await visible('.rebelABTestingVariants')).to.equal(true);

        await step('basics');
        await page.click('.rebelABTestingKind[value=fake_door]');
        expect(await stepNames()).to.equal('1 Basics | 2 Fake door | 3 Where and who | 4 When');
        await step('changes');
        expect(await visible('#door_label')).to.equal(true);
        expect(await visible('.rebelABTestingVariants')).to.equal(false);

        await step('basics');
        await page.click('.rebelABTestingKind[value=watch]');
        await step('changes');
        expect(await visible('#watch_selector')).to.equal(true);
        expect(await visible('#door_label')).to.equal(false);

        await step('basics');
        await page.click('.rebelABTestingKind[value=ab]');
        await step('changes');
        expect(await visible('#door_label')).to.equal(false);
        expect(await visible('.rebelABTestingVariants')).to.equal(true);
    });

    it('should ask for the category of an event only when an event is chosen', async function () {
        await step('measures');
        expect(await visible('#primary_measure_category')).to.equal(false);

        await page.select('#primary_measure', 'event');
        expect(await visible('#primary_measure_category')).to.equal(true);
        expect(await visible('#guardrail_0_category')).to.equal(false);

        await page.select('#primary_measure', 'goal:1');
        expect(await visible('#primary_measure_category')).to.equal(false);
    });

    it('should tell how long to run the experiment', async function () {
        await step('schedule');
        await page.click('.rebelABTestingPlanner summary');
        expect(await text('.rebelABTestingPlannerResult')).to.equal('Fill in the three numbers to see the days.');

        await page.type('#planner_visits', '2000');
        await page.type('#planner_rate', '5');

        // 24,601 visits in each of 3 groups, 2,000 visits a day.
        expect(await text('.rebelABTestingPlannerResult')).to.contain('Run for at least 37 days');
    });

    it('should create an experiment and go back to the list', async function () {
        await step('audience');
        await page.select('#audience_visitor', 'returning');
        await page.click('input[name="audience[devices][]"][value=mobile] + span');
        await step('basics');
        await page.type('.rebelABTestingForm #name', 'Green button');
        await page.type('.rebelABTestingForm #hypothesis', 'Green gets more clicks');
        await step('changes');
        await page.evaluate(() => document.querySelectorAll('details.rebelABTestingMore').forEach((details) => { details.open = true; }));
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

        expect(page.url()).to.contain('subcategory=RebelABTesting_ManageExperiments');
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

        // The name of an experiment in the list leads to its page.
        await page.click('.rebelABTestingTable tbody td a');
        await page.waitForFunction(() => {
            const heading = document.querySelector('.rebelABTestingPlugin h2');
            return heading && heading.textContent === 'Green button';
        });
        expect(page.url()).to.match(/subcategory=\d+$/);
        await page.waitForNetworkIdle();

        expect(await text('.rebelABTestingPlugin h2')).to.equal('Green button');
        expect(await text('.rebelABTestingTable:first-of-type tbody td:first-child')).to.equal('Original | Green | Variant 2');
        expect(await text('.rebelABTestingMeasureTable-primary tbody td:first-child')).to.equal('Original | Green | Variant 2');
        expect(await text('h3.form-section-title')).to.contain('Judged by: Goal: Thanks page');
        expect(await count('a[href*="#rabt-preview=1-"]')).to.equal(3);
    });

    it('should edit an experiment', async function () {
        await page.goto(pageUrl('RebelABTesting_ManageExperiments'));
        await page.waitForSelector('.rebelABTestingTable', { visible: true });
        await page.click('.rebelABTestingTable .actions-cell a.btn');
        await page.waitForSelector('.rebelABTestingForm', { visible: true });

        // The form is in the reporting interface, in the place of the list.
        expect(page.url()).to.contain('subcategory=RebelABTesting_ManageExperiments');
        expect(await count('.rebelABTestingTable')).to.equal(0);
        expect(await text('.rebelABTestingPlugin h2')).to.equal('Edit experiment');

        expect(await page.evaluate(() => document.querySelector('textarea[name="variants[1][js]"]').value)).to.equal("// second\ndocument.title = 'B';");
        expect(await page.evaluate(() => document.querySelector('#target_match').value)).to.equal('contains');
        expect(await page.evaluate(() => [document.querySelector('#audience_visitor').value, ...[...document.querySelectorAll('input[name="audience[devices][]"]:checked')].map((box) => box.value)])).to.deep.equal(['returning', 'mobile']);
        expect(await page.evaluate(() => ['selector', 'action', 'value']
            .map((field) => document.querySelector(`[name="variants[0][changes][0][${field}]"]`).value))).to.deep.equal(['.buy', 'add_class', 'green']);

        await page.evaluate(() => { document.querySelector('.rebelABTestingForm #name').value = 'Greener button'; });
        await submit('.rebelABTestingForm input[type=submit]');

        expect(await text('.rebelABTestingTable tbody tr td:nth-child(2)')).to.equal('Greener button');
        expect(await text('.notification')).to.contain('The experiment was updated.');
    });

    it('should show the step with a field that is not filled in', async function () {
        await page.goto(pageUrl('RebelABTesting_CreateNewExperiment'));
        await page.waitForSelector('.rebelABTestingForm', { visible: true });
        await step('schedule');
        expect(await visible('#name')).to.equal(false);

        await page.click('.rebelABTestingForm input[type=submit]');

        expect(await visible('#name')).to.equal(true);
        expect(await page.evaluate(() => document.querySelector('.rebelABTestingStep-current').dataset.panel)).to.equal('basics');
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
        await page.goto(pageUrl('RebelABTesting_ManageExperiments'));
        await page.waitForSelector('.rebelABTestingTable', { visible: true });
        await submit('.rebelABTestingTable form[action*="action=stop"] button');
        expect(await text('.rebelABTestingStatus')).to.equal('Ended');
        expect(await count('form[action*="action=stop"]')).to.equal(0);

        await submit('.rebelABTestingTable form[action*="action=delete"] button');
        expect(await count('.rebelABTestingTable')).to.equal(0);
        expect(await text('.rebelABTestingPlugin .alert')).to.contain('No experiments');
    });
});
