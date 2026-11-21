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

- the visits per variant;
- for each goal the visits that converted, the conversion rate, the difference to the original and
  the chance that the variant is better than the original.

A variant is called better or worse when that chance is at least 95%. Below 30 visits or 5
conversions per variant the page says there is not enough data.

Decide before the start which goal counts. With several goals or variants, one of them reaches 95%
by chance more often than one in twenty.

The result is read from the log of visits. If Matomo deletes old logs while an experiment runs, the
visits of the deleted days leave the result.

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
| `RebelABTesting.insertExperiment` (idSite, name, hypothesis, description, fromDate, toDate, variants, trafficShare, targetMatch, targetValue) | admin |
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
`targetMatch` is `any`, `equals`, `contains`, `starts_with` or `regexp`.

## Personal data

The plugin stores for each visit in an experiment: the visit, the visitor ID, the variant and a random
ID of the assignment. Matomo deletes these rows together with the visit, both when old logs are
deleted and when the data of a visitor is deleted in the GDPR tools. The CSS and JavaScript of an experiment are
yours, and what they do with personal data is up to you.

## Tests

From the root of Matomo:

```sh
./console tests:run RebelABTesting
deno test --allow-read plugins/RebelABTesting/tests/js
```

## Credits

Based on [Simple A/B Testing](https://github.com/nofrillsplugins/matomo-simple-ab-testing) by No
Frills Plugins.
