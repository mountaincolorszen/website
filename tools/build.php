<?php

/**
* Builds the website into the _site folder with the latest schedule.
*
* Run by .github/workflows/update-schedule.yml. Not part of the website.
*
* Any page can show the schedule: put these two lines where it should go,
* and the build fills in what's between them.
*
*     <!-- schedule:start -->
*     <!-- schedule:end -->
*
* If Google can't be reached, the page keeps the schedule that is live now.
*/

require __DIR__ . '/gdoc_schedule.php';

$root = dirname(__DIR__);
$out = $root . '/_site';
$start = '<!-- schedule:start -->';
$end = '<!-- schedule:end -->';

// folders and files that are not part of the website
$skip = array('.git', '.github', 'tools', '_site', 'README.md', '.gitignore');

$live = rtrim((string) getenv('LIVE_URL'), '/');
$force = (getenv('FORCE_DEPLOY') === 'true');


// ------------------------------------------------- copy the site to _site

build_remove($out);
build_copy($root, $out, $skip);


// ------------------------------------------------- get the latest schedule

$fresh = false;
$id = build_doc_id((string) getenv('SCHEDULE_DOC'));

if ($id === false)
{
	build_warning('The schedule doc address is not set up: paste it into SCHEDULE_DOC in .github/workflows/update-schedule.yml.');
}
else
{
	$source = defined('SCHEDULE_EXPORT_URL') ? SCHEDULE_EXPORT_URL : 'https://docs.google.com/document/d/' . $id . '/export?format=';
	$fresh = gdoc_schedule_build($source);

	if ($fresh === false) build_warning('Could not get the schedule from Google, so the site keeps the one it has now. ' . gdoc_schedule_problem());
}


// ------------------------------------------------- put it into the pages

$changed = false;
$pages = 0;

foreach (build_html_files($out) as $file)
{
	$page = file_get_contents($file);
	$a = strpos($page, $start);
	$b = ($a === false) ? false : strpos($page, $end, $a);
	if ($b === false) continue;

	$pages++;
	$path = substr($file, strlen($out) + 1);
	$now = ($live != '') ? build_live_block($live . '/' . $path, $start, $end) : false;

	if ($fresh !== false) $block = $fresh;
	elseif ($now !== false) $block = $now;
	else $block = false;

	if ($block !== false)
	{
		$page = substr($page, 0, $a + strlen($start)) . "\n" . trim($block) . "\n" . substr($page, $b);
		file_put_contents($file, $page);
	}

	// publish only when there's a new schedule that differs from what's live
	// (never when Google failed: that could replace a good live schedule)
	if ($fresh !== false && ($now === false || build_same($fresh, $now) === false)) $changed = true;

	echo $path . ': ' . (($fresh !== false) ? 'schedule updated from the doc' : 'kept the current schedule') . "\n";
}

if ($pages == 0) build_warning('No page has the <!-- schedule:start --> and <!-- schedule:end --> lines, so the schedule is not shown anywhere.');

// changes to the site itself (a push) are always published
if ($force) $changed = true;

echo ($changed ? 'Publishing the site.' : 'Nothing has changed: not publishing.') . "\n";

if (getenv('GITHUB_OUTPUT')) file_put_contents(getenv('GITHUB_OUTPUT'), 'changed=' . ($changed ? 'true' : 'false') . "\n", FILE_APPEND);

exit(0);


// ------------------------------------------------------------- helpers


function build_doc_id($doc)
{
	$doc = trim($doc);
	if (preg_match('~/d/([A-Za-z0-9_-]{20,})~', $doc, $m)) $doc = $m[1];

	return preg_match('/^[A-Za-z0-9_-]{20,}$/', $doc) ? $doc : false;
}


function build_warning($message)
{
	// shows as a yellow note on the run in the Actions tab
	echo '::warning::' . str_replace(array("\r", "\n"), ' ', $message) . "\n";
}


/**
* Returns the schedule currently between the markers on the live page,
* or false if the live page can't be read.
*/
function build_live_block($url, $start, $end)
{
	$got = gdoc_schedule_curl($url . '?t=' . time(), '');
	if ($got['body'] === false || $got['code'] != 200) return false;

	$a = strpos($got['body'], $start);
	$b = ($a === false) ? false : strpos($got['body'], $end, $a);
	if ($b === false) return false;

	return trim(substr($got['body'], $a + strlen($start), $b - $a - strlen($start)));
}


function build_same($x, $y)
{
	return preg_replace('/\s+/', ' ', trim($x)) === preg_replace('/\s+/', ' ', trim($y));
}


function build_copy($from, $to, $skip)
{
	@mkdir($to, 0755, true);

	foreach (scandir($from) as $name)
	{
		if ($name == '.' || $name == '..' || in_array($name, $skip)) continue;

		$a = $from . '/' . $name;
		$b = $to . '/' . $name;

		if (is_dir($a)) build_copy($a, $b, array());
		else copy($a, $b);
	}
}


function build_remove($path)
{
	if (!file_exists($path)) return;

	if (is_dir($path) && !is_link($path))
	{
		foreach (scandir($path) as $name)
		{
			if ($name != '.' && $name != '..') build_remove($path . '/' . $name);
		}

		rmdir($path);
	}
	else
	{
		unlink($path);
	}
}


function build_html_files($dir)
{
	$found = array();
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

	foreach ($items as $item)
	{
		if ($item->isFile() && preg_match('/\.html?$/i', $item->getFilename())) $found[] = $item->getPathname();
	}

	sort($found);

	return $found;
}
