# Website

A plain HTML/CSS site published with GitHub Pages. The schedule comes from a Google Doc that volunteers edit.

## How the schedule gets onto the site

Every 15 minutes, a GitHub Action (`.github/workflows/update-schedule.yml`) downloads the Google Doc, turns it into HTML, and puts it into any page that has these two lines:

```html
<!-- schedule:start -->
<!-- schedule:end -->
```

It only republishes when the schedule has changed. Edits to the doc appear on the site within about 15 to 30 minutes.

If Google can't be reached, the site keeps the schedule it already has, and the run shows a yellow warning on the Actions tab saying why.

## Editing the schedule

Edit the Google Doc. One event per line, a blank line between days, and the first line of each group is the day. Bold, italics, colours, highlights and links carry over; fonts don't.

## Editing the rest of the site

Edit the HTML and CSS files and push to `main`. The site republishes straight away.

## Settings

- **Which doc:** `SCHEDULE_DOC` near the top of `.github/workflows/update-schedule.yml`. The doc must be shared as "Anyone with the link: Viewer".
- **How often:** the `cron` line in the same file. `*/15 * * * *` means every 15 minutes.
- **Schedule styling:** `schedule.css`.

## Files

- `tools/gdoc_schedule.php` turns the doc into HTML.
- `tools/build.php` builds the site with the schedule in it.
- Neither is part of the website; GitHub runs them.

## One-time setup

In the repository's Settings > Pages, set Source to **GitHub Actions**.
