# Rebel A/B Testing

A/B testing for Matomo. An experiment shows variants of a page to a share of the visitors and tells
you which of them converts better than the original.

A variant is CSS, JavaScript or both, which a tag of Matomo Tag Manager adds to the page.

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

Go to **A/B Testing > Create a new experiment** in the reporting menu of the website. You need admin
access to the website.

- **First and last day**, in UTC. **Stop now** in the list ends an experiment before its last day.
- **Pages**: all pages, or the pages whose address equals, contains, starts with or matches a
  regular expression. The whole address is compared, including `https://`.
- **Share of visitors**: the percentage of visitors that take part. They are split evenly between
  the original and the variants. The others get the original and are not counted.
- **Variants**: one to ten, each with CSS, JavaScript or both.
- **Primary goal**: the goal the experiment is judged by. Choose it before the start.

### How long to run

The form works out the days an A/B test needs. Give it the visits a day on the pages of the
experiment, the conversion rate of the primary goal today, and the smallest difference you want to
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

## Flicker

A variant is applied when the tag fires, so let the tag fire as early as the container allows.

- On a visitor's first page the tag has to fetch the experiments first. The page is hidden
  meanwhile, for at most the time set in the tag (500 ms by default, 0 to never hide).
- On later pages the tag uses the experiments it fetched before and applies the variant at once.
  It fetches them again every five minutes. A change to an experiment, or stopping it, therefore
  reaches a visitor up to five minutes and one page later. Visits are not counted after the stop.

## Results

Each experiment has a page in the reporting menu, under **A/B Testing**. It shows, for all the time
the experiment ran:

- the visitors and the visits per variant;
- for each goal the visits that converted, the conversion rate, the difference to the original and
  the chance that the variant is better than the original.

A variant is called better or worse on the primary goal when that chance is at least 95%. Below 30
visits or 5 conversions per variant the page says there is not enough data. The other goals are
shown without a verdict: with several goals, one of them reaches 95% by chance more often than one
in twenty. An experiment without a primary goal gets a verdict for every goal.

The page warns when the visitors are split between the groups far more unevenly than chance
explains. Then visitors are not assigned or counted as they should be, for example because a variant
breaks the page, and the result cannot be trusted.

A conversion counts from the moment the visit is in the experiment. A goal reached earlier in the
same visit is left out.

The result is read from the log of visits. If Matomo deletes old logs while an experiment runs, the
visits of the deleted days leave the result.

## Preview

The page of an experiment has a link for the original and for each variant, shown to admins of the
website. A link opens the website with that variant, whatever the state of the experiment, so you
can check a variant before the first day.

- The variant stays for the browser tab. Add `#rabt-preview=off` to the address to end it.
- A previewed visit is not counted.
- The link carries a secret of the experiment. Anyone who has the link can see the variant.

## Reports and segments

- **A/B Testing > Experiments report** and **Experiment goals** show visits and conversions per
  experiment and variant for the period chosen in Matomo.
- Any other report can be compared between variants with the segments **Experiment ID** and
  **Experiment variant**, for example `experimentId==3;experimentVariant==2`. Variant 1 is the
  original. The ID of an experiment is in the list of experiments.

## What is public

The tag runs in the browser of a visitor who is not logged in to Matomo. Anyone can therefore read
the days, the pages, the CSS and the JavaScript of an experiment while it is running. Do not put
anything secret in them. Name, hypothesis and description are not handed out, and nothing is told
about experiments that have ended or not started.

## API

| Method | Access |
| --- | --- |
| `RebelABTesting.insertExperiment` (idSite, name, hypothesis, description, fromDate, toDate, variants, trafficShare, targetMatch, targetValue, primaryGoal, kind, door) | admin |
| `RebelABTesting.updateExperiment` (id, idSite, and the same as above) | admin |
| `RebelABTesting.stopExperiment` (id, idSite) | admin |
| `RebelABTesting.deleteExperiment` (id, idSite) | admin |
| `RebelABTesting.getExperiment` (id, idSite) | admin |
| `RebelABTesting.getExperiments` (idSite) | view |
| `RebelABTesting.getExperimentResult` (idSite, id) | view |
| `RebelABTesting.getExperimentData` (idSite, period, date, segment) | view |
| `RebelABTesting.getExperimentGoalData` (idSite, period, date, segment) | view |
| `RebelABTesting.getExperimentUniqueVisitorData` (idSite, period, date, segment), days only | view |

`variants` is a list, for example `variants[0][name]=Green&variants[0][css]=...&variants[0][js]=...`.
`targetMatch` is `any`, `equals`, `contains`, `starts_with` or `regexp`. `primaryGoal` is the ID of a
goal, `ecommerceOrder` or empty. `kind` is `ab` or `fake_door`, and `door` has `selector`, `position`
(`after`, `before`, `append`, `prepend`), `element` (`button`, `link`), `label`, `css_class` and
`message`.

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

## Credits

Based on [Simple A/B Testing](https://github.com/nofrillsplugins/matomo-simple-ab-testing) by No
Frills Plugins.
