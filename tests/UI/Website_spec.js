// Runs the tag of the plugin on the test website, in Matomo's test environment, and checks what
// the visitor sees and what Matomo counts.
describe('RebelABTestingWebsite', function () {
    this.timeout(60000);
    this.fixture = 'Piwik\\Plugins\\RebelABTesting\\tests\\Fixtures\\SiteWithGoal';

    const site = (name, extra = '') => `${config.piwikUrl}plugins/RebelABTesting/tests/site/${name}?matomo=/tests/PHPUnit/proxy&idsite=1${extra}`;
    const day = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);
    const text = (selector) => page.evaluate((s) => [...document.querySelectorAll(s)].map((e) => e.textContent.trim()).join(' | '), selector);

    before(async function () {
        testEnvironment.pluginsToLoad = ['RebelABTesting'];
        testEnvironment.save();

        // The database of the fixture can be kept from an earlier run, with its experiments.
        for (const experiment of await testEnvironment.callApi('RebelABTesting.getExperiments', { idSite: 1 })) {
            await testEnvironment.callApi('RebelABTesting.deleteExperiment', { idSite: 1, id: experiment.id });
        }
    });

    // An experiment that runs now. What is given is added to the fields every experiment has.
    async function addExperiment(name, fields) {
        const response = await testEnvironment.callApi('RebelABTesting.insertExperiment', {
            idSite: 1, name, hypothesis: '', description: '', fromDate: day(-1), toDate: day(30), ...fields,
        });
        return response.value;
    }

    const getResult = (id) => testEnvironment.callApi('RebelABTesting.getExperimentResult', { idSite: 1, id });
    const stop = (id) => testEnvironment.callApi('RebelABTesting.stopExperiment', { idSite: 1, id });

    // A browser that has never been on the website, and that draws the given number wherever the
    // tag draws one: below 0.5 the original of two groups, above it the variant.
    let visitors = 0;
    async function newVisitor(random) {
        await page.goto(`${config.piwikUrl}plugins/RebelABTesting/tests/site/site.css`);
        await page.evaluate(() => { localStorage.clear(); sessionStorage.clear(); });
        await page.webpage._client.send('Network.clearBrowserCookies');
        // Matomo takes requests of one browser from one address for one visit.
        const userAgent = (await page.evaluate(() => navigator.userAgent)).replace(/ Visitor\/\d+$/, '');
        await page.webpage.setUserAgent(`${userAgent} Visitor/${++visitors}`);
        await page.evaluateOnNewDocument((value) => { Math.random = () => value; }, random);
    }

    // Gives the tag time to fetch the experiments and change the page. The test helper that waits
    // for the network to go quiet never returns after a request was cut off by leaving a page.
    const settle = () => new Promise((resolve) => setTimeout(resolve, 800));

    async function open(name, extra) {
        await page.goto(site(name, extra));
        await settle();
    }

    // Goes where the link goes. A click that leaves the page now and then never returns in the
    // test browser.
    async function follow(selector) {
        await page.goto(await page.evaluate((s) => document.querySelector(s).href, selector));
        await settle();
    }

    // Tracking requests are sent after the page has loaded, and counted when they arrive.
    async function waitFor(check, what = 'what the visitor did') {
        for (let attempt = 0; attempt < 20; attempt++) {
            if (await check()) return;
            await new Promise((resolve) => setTimeout(resolve, 500));
        }
        throw new Error(`Matomo did not count ${what}.`);
    }

    it('should show the variant, keep it on the next page and count the visit and its conversion', async function () {
        const id = await addExperiment('headline', {
            'variants[0][name]': 'Green',
            'variants[0][changes][0][selector]': 'h1',
            'variants[0][changes][0][action]': 'text',
            'variants[0][changes][0][value]': 'A better headline',
            'variants[0][changes][1][selector]': '.promo-banner',
            'variants[0][changes][1][action]': 'hide',
            'variants[0][changes][2][selector]': '.buy-button',
            'variants[0][changes][2][action]': 'add_class',
            'variants[0][changes][2][value]': 'green',
            'variants[0][changes][3][selector]': 'img.hero',
            'variants[0][changes][3][action]': 'image',
            'variants[0][changes][3][value]': 'hero-b.svg',
            'variants[0][changes][4][selector]': '.pricing-link',
            'variants[0][changes][4][action]': 'link',
            'variants[0][changes][4][value]': 'pricing.html?from=experiment',
            'primaryMeasure[type]': 'goal',
            'primaryMeasure[idgoal]': 1,
        });
        await newVisitor(0.9);
        await open('index.html');

        expect(await text('h1')).to.equal('A better headline');
        expect(await page.evaluate(() => getComputedStyle(document.querySelector('.promo-banner')).display)).to.equal('none');
        expect(await page.evaluate(() => document.querySelector('.buy-button').className)).to.equal('buy-button green');
        expect(await page.evaluate(() => document.querySelector('img.hero').getAttribute('src'))).to.equal('hero-b.svg');
        expect(await page.evaluate(() => document.querySelector('.pricing-link').getAttribute('href'))).to.equal('pricing.html?from=experiment');
        expect(await page.evaluate(() => document.documentElement.style.opacity + getComputedStyle(document.documentElement).opacity)).to.equal('1');

        await follow('.pricing-link');
        expect(await page.evaluate(() => document.querySelector('.buy-button').className)).to.equal('buy-button green');

        await follow('.buy-button');
        expect(await text('h1')).to.equal('A better headline');

        await waitFor(async () => (await getResult(id)).measures[0].variants[1].nb_visits_with_outcome === 1);
        const result = await getResult(id);
        expect(result.variants.map((variant) => variant.nb_visits)).to.deep.equal([0, 1]);
        expect(result.measures[0].role).to.equal('primary');
        await stop(id);
    });

    it('should leave the page as it is for the original and count that visit too', async function () {
        const id = await addExperiment('original', {
            'variants[0][changes][0][selector]': 'h1',
            'variants[0][changes][0][action]': 'text',
            'variants[0][changes][0][value]': 'A better headline',
        });
        await newVisitor(0.1);
        await open('index.html');

        expect(await text('h1')).to.equal('Welcome to the Test Shop');
        await waitFor(async () => (await getResult(id)).variants[0].nb_visits === 1);
        expect((await getResult(id)).variants[1].nb_visits).to.equal(0);
        await stop(id);
    });

    it('should run CSS and JavaScript of a variant, only on the pages of the experiment', async function () {
        const id = await addExperiment('code', {
            'variants[0][css]': 'h1 { letter-spacing: 3px; }',
            'variants[0][js]': 'document.title = "Changed by the variant";',
            targetMatch: 'contains',
            targetValue: '/pricing.html',
        });
        await newVisitor(0.9);
        await open('index.html');
        expect(await page.evaluate(() => document.title)).to.equal('Home - The Test Shop');

        await open('pricing.html');
        expect(await page.evaluate(() => document.title)).to.equal('Changed by the variant');
        expect(await page.evaluate(() => getComputedStyle(document.querySelector('h1')).letterSpacing)).to.equal('3px');
        await waitFor(async () => (await getResult(id)).variants[1].nb_visits === 1);
        await stop(id);
    });

    it('should show the banner of the template and style it', async function () {
        const id = await addExperiment('banner', {
            'variants[0][changes][0][selector]': 'body',
            'variants[0][changes][0][action]': 'insert_first',
            'variants[0][changes][0][value]': 'Free <shipping> this week',
            'variants[0][changes][1][selector]': '.rebel-experiment-insert',
            'variants[0][changes][1][action]': 'style',
            'variants[0][changes][1][value]': 'background: #fff3cd; text-align: center',
        });
        await newVisitor(0.9);
        await open('index.html');

        expect(await text('body > :first-child')).to.equal('Free <shipping> this week');
        expect(await page.evaluate(() => getComputedStyle(document.body.firstElementChild).textAlign)).to.equal('center');
        expect(await page.evaluate(() => document.querySelectorAll('.rebel-experiment-insert').length)).to.equal(1);
        await stop(id);
    });

    it.only('should send the variant of a test between two pages to the other page and count it there', async function () {
        const id = await addExperiment('two pages', {
            'variants[0][redirect]': 'pricing-b.html',
            targetMatch: 'contains',
            targetValue: '/site/pricing.html',
            'primaryMeasure[type]': 'goal',
            'primaryMeasure[idgoal]': 1,
        });
        await newVisitor(0.9);
        // The page is left while it loads, so there is no end of loading to wait for.
        await page.evaluate((address) => { window.location.href = address; }, site('pricing.html', '&utm_campaign=spring')).catch(() => {});
        // The browser moves from page to page, during which it cannot be asked anything.
        await waitFor(() => page.evaluate(() => window.location.pathname.endsWith('/pricing-b.html')).catch(() => false), 'the visitor on the other page');
        await settle();

        expect(await text('h1')).to.equal('Pricing, one plan');
        expect(await page.evaluate(() => new URLSearchParams(window.location.search).get('utm_campaign'))).to.equal('spring');

        console.log('MARK on b', await page.evaluate(() => [location.href, document.querySelector('h1').textContent, JSON.stringify(Object.assign({}, localStorage))].join(' || ')));
        await follow('.buy-button');
        await settle(); await settle();
        console.log('MARK RESULT', JSON.stringify(await getResult(id)));
        await waitFor(async () => (await getResult(id)).measures[0].variants[1].nb_visits_with_outcome === 1, 'the conversion of the variant');

        await newVisitor(0.1);
        await open('pricing.html');
        expect(await text('h1')).to.equal('Pricing');
        await waitFor(async () => (await getResult(id)).variants[0].nb_visits === 1);
        await stop(id);
    });

    it('should show a preview link its variant without counting the visit', async function () {
        const id = await addExperiment('preview', {
            fromDate: day(10),
            'variants[0][changes][0][selector]': 'h1',
            'variants[0][changes][0][action]': 'text',
            'variants[0][changes][0][value]': 'Not public yet',
        });
        const experiment = await testEnvironment.callApi('RebelABTesting.getExperiment', { idSite: 1, id });
        await newVisitor(0.1);

        await open('index.html');
        expect(await text('h1')).to.equal('Welcome to the Test Shop');

        await open('pricing.html', `#rabt-preview=${id}-2-${experiment.preview_token}`);
        await open('index.html');
        expect(await text('h1')).to.equal('Not public yet');

        await follow('.pricing-link');
        await follow('#main-menu a');
        expect(await text('h1')).to.equal('Not public yet');
        expect((await getResult(id)).variants.map((variant) => variant.nb_visits)).to.deep.equal([0, 0]);
    });

    it('should add a fake door, show its message after a click and count both', async function () {
        const id = await addExperiment('door', {
            kind: 'fake_door',
            'door[selector]': '#main-menu',
            'door[position]': 'append',
            'door[element]': 'button',
            'door[label]': 'Compare plans',
            'door[css_class]': 'compare',
            'door[message]': 'Not there yet',
        });
        await newVisitor(0.1);
        await open('index.html');

        expect(await text('#main-menu > :last-child')).to.equal('Compare plans');
        await waitFor(async () => (await getResult(id)).nb_visits === 1);
        expect((await getResult(id)).nb_clicks).to.equal(0);

        await page.click('#main-menu .compare');
        expect(await text('#main-menu > :last-child')).to.equal('Not there yet');
        await waitFor(async () => (await getResult(id)).nb_clicks === 1);
        await stop(id);
    });

    it('should count a watched element when it comes into view and when it is clicked', async function () {
        const id = await addExperiment('watch', { kind: 'watch', 'door[selector]': '.signup-button' });
        await newVisitor(0.1);
        await open('index.html');
        await new Promise((resolve) => setTimeout(resolve, 1500));
        expect((await getResult(id)).nb_visits).to.equal(0);

        await page.evaluate(() => document.querySelector('.signup-button').scrollIntoView());
        await waitFor(async () => (await getResult(id)).nb_visits === 1);

        await page.click('.signup-button');
        await waitFor(async () => (await getResult(id)).nb_clicks === 1);
        await stop(id);
    });
});
