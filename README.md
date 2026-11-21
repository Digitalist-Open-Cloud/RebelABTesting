# Rebel A/B Testing

A/B testing for Matomo. An experiment shows a variant of a page to half of the visitors, and the
reports show visits and goal conversions per variant.

The variant is CSS, JavaScript or both, which a tag of Matomo Tag Manager adds to the page.

## Requirements

- Matomo 5
- PHP 8.2 or newer
- The Tag Manager plugin, activated

## Create an experiment

1. Go to **A/B Testing > Create a new experiment** in the reporting menu of the website. You need
   admin access to the website.
2. Give the experiment a name, a first and a last day, and the CSS or JavaScript of the variant.

Only one experiment can run on a website at a time. Days are in UTC.

## Put the experiment on the website

1. In a container of Matomo Tag Manager, create a tag of the type **Rebel A/B Testing**.
2. Choose the experiment.
3. Choose a trigger, for example Pageview.
4. Publish the container.

The tag fetches the experiment from Matomo each time it fires. A change to the experiment therefore
needs no new version of the container.

## How visitors are assigned

A visitor gets the original or the variant by chance and keeps it until the experiment ends. The
choice is kept in the `localStorage` of the browser, under `sabt:<id of the experiment>`. A browser
without `localStorage` gets the original and is not counted.

## Reports

- **A/B Testing > Experiments Report**: visits per experiment. Open an experiment to see its
  variants: 1 is the original, 2 the variant.
- **A/B Testing > Experiment Goals**: conversions per experiment, variant and goal.

Any other report can be compared between the variants with the segments **Experiment variant** and
**Experiment ID**, for example `experimentId==3;experimentVariant==2`.

## What is public

The tag runs in the browser of a visitor who is not logged in to Matomo. Anyone can therefore read
the name, the days, the CSS and the JavaScript of an experiment while it is running. Do not put
anything secret in them. Hypothesis and description are only shown to admins of the website, and
nothing is told about experiments that have ended or not started.

## API

| Method | Access |
| --- | --- |
| `RebelABTesting.insertExperiment` (idSite, name, hypothesis, description, fromDate, toDate, cssInsert, customJs) | admin |
| `RebelABTesting.updateExperiment` (id, idSite, name, hypothesis, description, fromDate, toDate, cssInsert, customJs) | admin |
| `RebelABTesting.deleteExperiment` (id, idSite) | admin |
| `RebelABTesting.getExperiment` (id, idSite) | admin |
| `RebelABTesting.getExperimentData` (idSite, period, date, segment) | view |
| `RebelABTesting.getExperimentGoalData` (idSite, period, date, segment) | view |
| `RebelABTesting.getExperimentUniqueVisitorData` (idSite, period, date, segment), days only | view |
| `RebelABTesting.getExperimentReportData` (idSite, period, date), read from the log | view |

## Personal data

The plugin stores for each tracking request of a visitor in an experiment: the visit, the visitor
ID, the variant and a random ID of the assignment. The CSS and JavaScript of an experiment are
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
