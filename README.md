# Rebel A/B Testing

A/B testing for Matomo. An experiment shows variants of a page to a share of the visitors and tells
you which of them converts better than the original.

A variant is a list of changes to the page, such as another headline or a hidden banner, which a tag
of Matomo Tag Manager makes in the browser of the visitor.

## Requirements

- Matomo 5
- PHP 8.2 or newer
- The Tag Manager plugin, activated

## Put the tag on the website

1. In a container of Matomo Tag Manager, create a tag of the type **Rebel A/B Testing**.
2. Give it a trigger that fires on every page, for example Pageview.
3. Publish the container.

You do this once. The tag fetches the running experiments of the website from Matomo, so a new or
changed experiment needs no new version of the container.

## Create an experiment

Go to **A/B Testing > Create a new experiment** in the reporting menu of the website. You need write
access to the website. CSS and JavaScript in a variant need admin access, and an experiment that has
them can only be changed by an admin.

- **First and last day**, in UTC. **Stop now** in the list ends an experiment before its last day.
- **Pages**: all pages, or the pages whose address equals, contains, starts with or matches a
  regular expression. The whole address is compared, including `https://`. Pages can also be left
  out in the same way.
- **Audience**: who the experiment is for. New or returning visitors, the devices, visitors who came
  through a campaign or from an address, and the language of the browser. All that is given has to
  hold. A visitor is checked when first meeting the experiment, and stays in or out after that.
- **Share of visitors**: the percentage of visitors that take part. They are split evenly between
  the original and the variants. The others get the original and are not counted.
- **Variants**: one to ten. A variant is a list of changes, each doing one thing to the elements a
  CSS selector matches: change the text, hide it, add a CSS class, set a style, change the image,
  change where a link goes, or insert a text before, after or at the top of it. A template fills in
  the changes for the things that are tested most: a headline, a button, a banner, an image, a line
  of text after a button, a shorter form. Admins of the website can also give a variant CSS and
  JavaScript.
- **Another page**: a variant can send the visitor to another page instead, for a test between two
  pages. What the address carries, such as the parameters of a campaign, goes along. Limit the
  experiment to the page of the original.
- **Primary measure**: what the experiment is judged by. Choose it before the start. It is one of:
  - a goal of the website;
  - ecommerce orders, or the revenue per visit;
  - the revenue per visit of a goal, or of all goals and orders together;
  - visits that reach any goal;
  - an event, by its category and optionally its action;
  - page views per visit, or the length of a visit;
  - bounces, where fewer is better;
  - visits that search the website, where fewer is better: visitors who find what they came for
    do not need to search.
- **Guardrails**: up to two more measures that must not get worse while the primary measure gets
  better, for example orders when the experiment is about clicks.
- **Email me the result**: the one who adds the experiment gets an email when a variant is found
  better or worse on the primary measure, and when the experiment has ended. Matomo sends it with
  its scheduled tasks, once a day.
- **How sure to be**: 90, 95 or 99%. A variant is called better or worse when the chance is at
  least that high. Higher needs more visits.

### How long to run

The form works out the days an A/B test needs. Give it the visits a day on the pages of the
experiment, the share of visits that reach the primary measure today, and the smallest difference you want to
find. Set the last day accordingly, and do not end the experiment earlier because the numbers look
good: a test that is ended on its first good day often shows a difference that is not there.

## Fake door

A fake door is the other kind of experiment. It adds a button or a link to something that does not
exist yet, to see how many visitors want it before you build it.

- Give the text of the button or link, the element of the page it is put next to as a CSS selector,
  and a CSS class of your website so that it looks like your other buttons.
- A click replaces it with your message. Tell the visitor that this does not exist yet.
- The result shows the visits that saw it, the visits that clicked it, and the range the true share
  lies in.
- A click is also tracked as a Matomo event with the category "Fake door", the action "Click" and
  the ID of the experiment as its name.
- Pages, share of visitors, the days and the preview work as for an A/B test. There is no original
  group: every visitor in the experiment gets the door.

Several experiments can run at the same time. Keep them on different pages, or on different parts of
a page, as two experiments that change the same thing disturb each other.

## How visitors are assigned

A visitor gets the original or a variant by chance and keeps it until the experiment ends. The
choice is kept in the `localStorage` of the browser, under `sabt:<id of the experiment>`. A browser
without `localStorage` gets the original and is not counted.

### What the audience goes by

The tag decides in the browser, so no data about the visitor is asked from Matomo.

- **New or returning:** a visitor is returning when the tag has seen the browser before this visit.
  A visitor who was on the website before the tag was added is new once.
- **Device:** from what the browser says it is. Matomo's own device reports are more exact.
- **Campaign and referrer:** from the page the visit started on, remembered for the browser tab.
- **Language:** the first language of the browser. `sv` also takes `sv-fi`.

## Flicker

A variant is applied when the tag fires, so let the tag fire as early as the container allows.

- On a visitor's first page the tag has to fetch the experiments first. The page is hidden
  meanwhile, for at most the time set in the tag (500 ms by default, 0 to never hide).
- On later pages the tag uses the experiments it fetched before and applies the variant at once.
  It fetches them again every five minutes. A change to an experiment, or stopping it, therefore
  reaches a visitor up to five minutes and one page later. Visits are not counted after the stop.

## Watch an element

The third kind of experiment changes nothing. It counts the visits that see an element the page
already has, and the visits that click it.

- Give the element as a CSS selector. All elements that match are watched as one.
- A visit has seen the element when it comes into view, not when it is merely on the page.
- The result shows the visits that saw it, the visits that clicked it, and the share.
- A click is also tracked as a Matomo event with the category "Watched element".

Use it to learn how much an element is used before you test a change to it. The share is also the
number the form asks for when it works out how long an A/B test has to run.

## Results

**Manage experiments** lists the experiments with what their primary measure says so far. The name
of an experiment leads to its page.

Each experiment has a page in the reporting menu, under **A/B Testing**. It shows, for all the time
the experiment ran:

- the visitors and the visits per variant;
- a chart of the primary measure per variant, from the first day up to each day. Lines that still
  cross have not settled;
- for the primary measure, the guardrails and each goal that was reached: the visits, the share of
  the visits or the average per visit, the difference to the original and the chance that the
  variant is better than the original.

A variant is called better or worse on the primary measure and the guardrails when that chance is at
least as high as the experiment asks for, 95% unless you chose otherwise. With too few visits the page says there is not enough data. The goals are shown without a
verdict: with many measures, one of them reaches 95% by chance more often than one in twenty. An
experiment without a primary measure gets a verdict for every goal.

The page warns when the visitors are split between the groups far more unevenly than chance
explains. Then visitors are not assigned or counted as they should be, for example because a variant
breaks the page, and the result cannot be trusted.

Goals, revenue and events count from the moment a visit is in the experiment. A goal reached earlier
in the same visit is left out. Bounces, site searches, page views and the length of a visit are
counted for the whole visit.

The segment chosen at the top of Matomo limits the result to its visits, for example to visits from
mobile devices.

With the RebelAuditLog plugin, adding, changing, stopping and deleting an experiment is logged.

The result is read from the log of visits. If Matomo deletes old logs while an experiment runs, the
visits of the deleted days leave the result.

## Preview

The page of an experiment has a link for the original and for each variant, shown to admins of the
website. A link opens the website with that variant, whatever the state of the experiment, so you
can check a variant before the first day.

- The variant stays for the browser tab. Add `#rabt-preview=off` to the address to end it.
- A previewed visit is not counted.
- The link carries a secret of the experiment. Anyone who has the link can see the variant.

## Segments

Any report of Matomo can be compared between variants with the segments **Experiment ID** and
**Experiment variant**, for example `experimentId==3;experimentVariant==2`. Variant 1 is the
original. The ID of an experiment is in the list of experiments.

## What is public

The tag runs in the browser of a visitor who is not logged in to Matomo. Anyone can therefore read
the days, the pages and the changes, CSS and JavaScript of an experiment while it is running. Do not put
anything secret in them. Name, hypothesis and description are not handed out, and nothing is told
about experiments that have ended or not started.

## API

| Method | Access |
| --- | --- |
| `RebelABTesting.insertExperiment` (idSite, name, hypothesis, description, fromDate, toDate, variants, trafficShare, targetMatch, targetValue, primaryMeasure, kind, door, guardrails, confidence, excludeMatch, excludeValue, notify, audience) | write, admin with CSS or JavaScript |
| `RebelABTesting.updateExperiment` (id, idSite, and the same as above) | write, admin with CSS or JavaScript |
| `RebelABTesting.stopExperiment` (id, idSite) | write |
| `RebelABTesting.deleteExperiment` (id, idSite) | write |
| `RebelABTesting.getExperiment` (id, idSite) | write |
| `RebelABTesting.getExperiments` (idSite) | view |
| `RebelABTesting.getExperimentResult` (idSite, id, segment) | view |
| `RebelABTesting.getExperimentEvolution` (idSite, id, segment) | view |

`variants` is a list. Each variant has a `name`, a list of `changes` with `selector`, `action` (`text`,
`hide`, `add_class`, `style`, `image` or `link`) and `value`, and optionally `css` and `js`. For example
`variants[0][name]=Green&variants[0][changes][0][selector]=h1&variants[0][changes][0][action]=text&variants[0][changes][0][value]=Hello`.
`targetMatch` is `any`, `equals`, `contains`, `starts_with` or `regexp`. `primaryMeasure` has a `type`
(`goal`, `goal_revenue`, `orders`, `revenue`, `conversions`, `total_revenue`, `event`, `pageviews`,
`visit_length`, `bounce` or `search`) and, for a goal, its `idgoal`, or for an
event, its `category` and optionally its `action`. `guardrails` is a list of up to two of those. `kind` is `ab`, `fake_door` or `watch`. For a fake door, `door` has `selector`, `position`
(`after`, `before`, `append`, `prepend`), `element` (`button`, `link`), `label`, `css_class` and
`message`. For `watch` it has the `selector` of the element.

## Personal data

The plugin stores for each visit in an experiment: the visit, the visitor ID, the variant and a random
ID of the assignment. Matomo deletes these rows together with the visit, both when old logs are
deleted and when the data of a visitor is deleted in the GDPR tools. The CSS and JavaScript of an experiment are
yours, and what they do with personal data is up to you.

## Tests

From the root of Matomo:

```sh
./console tests:run RebelABTesting
./console tests:run-ui --plugin=RebelABTesting
deno test --allow-read plugins/RebelABTesting/tests/js
```

### The test website

`tests/site` is a small website to try experiments on: a start page with a headline, a banner, an
image, a buy button and a newsletter box far down, a pricing page, and a thank you page. The UI
tests run the tag of the plugin on it.

Open it in a browser through the web server of Matomo:

- With a container of Matomo Tag Manager that has the tag, as on a real website:
  `<matomo>/plugins/RebelABTesting/tests/site/index.html?mtm=/js/container_<id>.js`
- Without Tag Manager, with the tracker and the tag straight from Matomo:
  `<matomo>/plugins/RebelABTesting/tests/site/index.html?matomo=&idsite=<id of the website>`

The foot of each page shows the variant the browser got. Open a private window to be a new visitor.
Limit your experiments to addresses that contain `/tests/site/`, so that they stay on the test
website.

## Credits

Based on [Simple A/B Testing](https://github.com/nofrillsplugins/matomo-simple-ab-testing) by No
Frills Plugins.
