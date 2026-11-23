describe('RebelExperiments', function () {
    this.fixture = 'Piwik\\Plugins\\RebelExperiments\\tests\\Fixtures\\SiteWithGoal';

    const params = 'idSite=1&period=day&date=2024-03-05';
    const pageUrl = (subcategory) => `?module=CoreHome&action=index&${params}#?${params}`
        + `&category=RebelExperiments_RebelExperiments&subcategory=${subcategory}`;

    before(async function () {
        testEnvironment.pluginsToLoad = ['RebelExperiments'];
        testEnvironment.save();

        // The database of the fixture can be kept from an earlier run, with its experiments.
        for (const experiment of await testEnvironment.callApi('RebelExperiments.getExperiments', { idSite: 1 })) {
            await testEnvironment.callApi('RebelExperiments.deleteExperiment', { idSite: 1, id: experiment.id });
        }
    });

    function count(selector) {
        return page.evaluate((s) => document.querySelectorAll(s).length, selector);
    }

    function text(selector) {
        return page.evaluate((s) => [...document.querySelectorAll(s)].map((e) => e.textContent.trim()).join(' | '), selector);
    }

    // The steps as a visitor reads them, without what is hidden.
    const stepNames = () => page.evaluate(() => [...document.querySelectorAll('.rebelExperimentsStep')]
        .filter((step) => step.offsetParent !== null).map((step) => step.innerText.replace(/\s+/g, ' ').trim()).join(' | '));

    // Shows a step of the form.
    const step = (name) => page.click(`.rebelExperimentsStep[data-panel=${name}]`);
    const visible = (selector) => page.evaluate((s) => document.querySelector(s).offsetParent !== null, selector);

    async function submit(selector) {
        await Promise.all([page.waitForNavigation(), page.click(selector)]);
        await page.waitForSelector('.rebelExperimentsPlugin', { visible: true });
        await page.waitForNetworkIdle();
    }

    it('should add and remove variants in the form', async function () {
        await page.goto(pageUrl('RebelExperiments_CreateNewExperiment'));
        await page.waitForSelector('.rebelExperimentsForm', { visible: true });
        await page.waitForNetworkIdle();

        expect(await stepNames()).to.equal('1 Basics | 2 Variants | 3 Where and who | 4 Measures | 5 When');
        expect(await visible('#name')).to.equal(true);
        expect(await visible('.rebelExperimentsVariants')).to.equal(false);

        await page.click('.rebelExperimentsNext');
        expect(await visible('.rebelExperimentsVariants')).to.equal(true);
        expect(await visible('#name')).to.equal(false);
        expect(await stepNames()).to.contain('2 Variants');
        expect(await page.evaluate(() => document.querySelector('.rebelExperimentsStep-current').dataset.panel)).to.equal('changes');
        expect(await count('.rebelExperimentsVariant')).to.equal(1);
        expect(await visible('.rebelExperimentsRemoveVariant')).to.equal(false);

        await page.click('.rebelExperimentsAddVariant');
        await page.click('.rebelExperimentsAddVariant');
        expect(await count('.rebelExperimentsVariant')).to.equal(3);
        expect(await count('textarea[name="variants[2][css]"]')).to.equal(1);

        await page.evaluate(() => document.querySelectorAll('.rebelExperimentsRemoveVariant')[1].click());
        expect(await count('.rebelExperimentsVariant')).to.equal(2);
        expect(await count('textarea[name="variants[1][js]"]')).to.equal(1);
        expect(await text('.rebelExperimentsVariantNumber')).to.equal('1 | 2');
    });

    it('should add and remove the changes of a variant', async function () {
        const names = () => page.evaluate(() => [...document.querySelectorAll('.rebelExperimentsVariant:nth-child(2) .rebelExperimentsChange input[name$="[selector]"]')]
            .map((field) => `${field.name}=${field.value}`));

        await page.type('input[name="variants[1][changes][0][selector]"]', 'h1');
        await page.evaluate(() => document.querySelector('.rebelExperimentsVariant:nth-child(2) .rebelExperimentsAddChange').click());
        await page.type('input[name="variants[1][changes][1][selector]"]', '.banner');
        expect(await names()).to.deep.equal(['variants[1][changes][0][selector]=h1', 'variants[1][changes][1][selector]=.banner']);

        await page.evaluate(() => document.querySelector('.rebelExperimentsVariant:nth-child(2) .rebelExperimentsRemoveChange').click());
        expect(await names()).to.deep.equal(['variants[1][changes][0][selector]=.banner']);

        await page.evaluate(() => document.querySelector('.rebelExperimentsVariant:nth-child(2) .rebelExperimentsRemoveChange').click());
        expect(await names()).to.deep.equal(['variants[1][changes][0][selector]=']);
    });

    it('should fill in the changes of a template', async function () {
        const rows = () => page.evaluate(() => [...document.querySelectorAll('.rebelExperimentsVariant:nth-child(2) .rebelExperimentsChange')]
            .map((row) => ['selector', 'action', 'value'].map((field) => row.querySelector(`[name$="[${field}]"]`).value).join(' / ')));

        await page.select('.rebelExperimentsVariant:nth-child(2) .rebelExperimentsTemplate', 'banner');
        expect(await rows()).to.deep.equal([
            'body / insert_first / Free shipping this week',
            '.rebel-experiment-insert / style / background: #fff3cd; color: #222222; padding: 10px; text-align: center',
        ]);
        expect(await count('[name="variants[1][changes][1][value]"]')).to.equal(1);

        await page.select('.rebelExperimentsVariant:nth-child(2) .rebelExperimentsTemplate', 'hide');
        expect(await rows()).to.deep.equal([' / hide / ']);
        expect(await page.evaluate(() => document.activeElement.name)).to.equal('variants[1][changes][0][selector]');
    });

    it('should show the fields of the kind of experiment', async function () {
        expect(await visible('#door_label')).to.equal(false);
        expect(await visible('.rebelExperimentsVariants')).to.equal(true);

        await step('basics');
        await page.click('.rebelExperimentsKind[value=fake_door]');
        expect(await stepNames()).to.equal('1 Basics | 2 Fake door | 3 Where and who | 4 When');
        await step('changes');
        expect(await visible('#door_label')).to.equal(true);
        expect(await visible('.rebelExperimentsVariants')).to.equal(false);

        await step('basics');
        await page.click('.rebelExperimentsKind[value=rollout]');
        expect(await stepNames()).to.equal('1 Basics | 2 Change | 3 Where and who | 4 Measures | 5 When');
        await step('changes');
        // A rollout is one change: the second variant of the form and the way to add more are gone.
        expect(await page.evaluate(() => [...document.querySelectorAll('.rebelExperimentsVariant')].map((variant) => variant.offsetParent !== null))).to.deep.equal([true, false]);
        expect(await visible('.rebelExperimentsAddVariant')).to.equal(false);
        await step('audience');
        expect(await page.evaluate(() => document.querySelector('label[for=traffic_share]').innerText.trim())).to.equal('Share of visitors that get the change (%)');

        await step('basics');
        await page.click('.rebelExperimentsKind[value=watch]');
        await step('changes');
        expect(await visible('#watch_selector')).to.equal(true);
        expect(await visible('#door_label')).to.equal(false);

        await step('basics');
        await page.click('.rebelExperimentsKind[value=ab]');
        await step('changes');
        expect(await visible('#door_label')).to.equal(false);
        expect(await visible('.rebelExperimentsVariants')).to.equal(true);
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
        await page.click('.rebelExperimentsPlanner summary');
        expect(await text('.rebelExperimentsPlannerResult')).to.equal('Fill in the three numbers to see the days.');

        await page.type('#planner_visits', '2000');
        await page.type('#planner_rate', '5');

        // 24,601 visits in each of 3 groups, 2,000 visits a day.
        expect(await text('.rebelExperimentsPlannerResult')).to.contain('Run for at least 37 days');
    });

    it('should create an experiment and go back to the list', async function () {
        await step('audience');
        await page.select('#audience_visitor', 'returning');
        await page.click('input[name="audience[devices][]"][value=mobile] + span');
        await step('basics');
        await page.type('.rebelExperimentsForm #name', 'Green button');
        await page.type('.rebelExperimentsForm #hypothesis', 'Green gets more clicks');
        await step('changes');
        await page.evaluate(() => document.querySelectorAll('details.rebelExperimentsMore').forEach((details) => { details.open = true; }));
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
        await submit('.rebelExperimentsForm input[type=submit]');

        expect(page.url()).to.contain('subcategory=RebelExperiments_ManageExperiments');
        expect(await text('.rebelExperimentsTable tbody tr td:nth-child(2)')).to.equal('Green button');
        expect(await text('.rebelExperimentsStatus')).to.equal('Running');
        expect(await text('.notification')).to.contain('The experiment was created.');
    });

    it('should show the menu icon and a page for the experiment', async function () {
        const mask = await page.evaluate(() => {
            const icon = document.querySelector('.rebelExperimentsIcon');
            const style = icon ? getComputedStyle(icon, '::before') : null;
            return style ? (style.maskImage || style.webkitMaskImage) : 'no icon';
        });
        expect(mask).to.contain('svg');

        // The name of an experiment in the list leads to its page.
        await page.click('.rebelExperimentsTable tbody td a');
        await page.waitForFunction(() => {
            const heading = document.querySelector('.rebelExperimentsPlugin h2');
            return heading && heading.textContent === 'Green button';
        });
        expect(page.url()).to.match(/subcategory=\d+$/);
        await page.waitForNetworkIdle();

        expect(await text('.rebelExperimentsPlugin h2')).to.equal('Green button');
        expect(await text('.rebelExperimentsTable:first-of-type tbody td:first-child')).to.equal('Original | Green | Variant 2');
        expect(await text('.rebelExperimentsMeasureTable-primary tbody td:first-child')).to.equal('Original | Green | Variant 2');
        expect(await text('h3.form-section-title')).to.contain('Judged by: Goal: Thanks page');
        expect(await count('a[href*="#rexp-preview=1-"]')).to.equal(3);
    });

    it('should edit an experiment', async function () {
        await page.goto(pageUrl('RebelExperiments_ManageExperiments'));
        await page.waitForSelector('.rebelExperimentsTable', { visible: true });
        await page.click('.rebelExperimentsTable .actions-cell a.btn');
        await page.waitForSelector('.rebelExperimentsForm', { visible: true });

        // The form is in the reporting interface, in the place of the list.
        expect(page.url()).to.contain('subcategory=RebelExperiments_ManageExperiments');
        expect(await count('.rebelExperimentsTable')).to.equal(0);
        expect(await text('.rebelExperimentsPlugin h2')).to.equal('Edit experiment');

        expect(await page.evaluate(() => document.querySelector('textarea[name="variants[1][js]"]').value)).to.equal("// second\ndocument.title = 'B';");
        expect(await page.evaluate(() => document.querySelector('#target_match').value)).to.equal('contains');
        expect(await page.evaluate(() => [document.querySelector('#audience_visitor').value, ...[...document.querySelectorAll('input[name="audience[devices][]"]:checked')].map((box) => box.value)])).to.deep.equal(['returning', 'mobile']);
        expect(await page.evaluate(() => ['selector', 'action', 'value']
            .map((field) => document.querySelector(`[name="variants[0][changes][0][${field}]"]`).value))).to.deep.equal(['.buy', 'add_class', 'green']);

        await page.evaluate(() => { document.querySelector('.rebelExperimentsForm #name').value = 'Greener button'; });
        await submit('.rebelExperimentsForm input[type=submit]');

        expect(await text('.rebelExperimentsTable tbody tr td:nth-child(2)')).to.equal('Greener button');
        expect(await text('.notification')).to.contain('The experiment was updated.');
    });

    it('should show the step with a field that is not filled in', async function () {
        await page.goto(pageUrl('RebelExperiments_CreateNewExperiment'));
        await page.waitForSelector('.rebelExperimentsForm', { visible: true });
        await step('schedule');
        expect(await visible('#name')).to.equal(false);

        await page.click('.rebelExperimentsForm input[type=submit]');

        expect(await visible('#name')).to.equal(true);
        expect(await page.evaluate(() => document.querySelector('.rebelExperimentsStep-current').dataset.panel)).to.equal('basics');
    });

    it('should tell when an experiment cannot be saved', async function () {
        await page.goto(pageUrl('RebelExperiments_CreateNewExperiment'));
        await page.waitForSelector('.rebelExperimentsForm', { visible: true });
        await page.type('.rebelExperimentsForm #name', 'Greener button');
        await submit('.rebelExperimentsForm input[type=submit]');

        expect(page.url()).to.contain('subcategory=RebelExperiments_CreateNewExperiment');
        expect(await text('.notification')).to.contain('already has an experiment with this name');
    });

    it('should stop and delete an experiment', async function () {
        await page.goto(pageUrl('RebelExperiments_ManageExperiments'));
        await page.waitForSelector('.rebelExperimentsTable', { visible: true });
        await submit('.rebelExperimentsTable form[action*="action=stop"] button');
        expect(await text('.rebelExperimentsStatus')).to.equal('Ended');
        expect(await count('form[action*="action=stop"]')).to.equal(0);

        await submit('.rebelExperimentsTable form[action*="action=delete"] button');
        expect(await count('.rebelExperimentsTable')).to.equal(0);
        expect(await text('.rebelExperimentsPlugin .alert')).to.contain('No experiments');
    });
});
