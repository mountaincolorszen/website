<?php

/**
* Google Doc schedule: turns a Google Doc into schedule HTML.
*
* Used by tools/build.php, which the GitHub Action runs. Visitors never run
* this file: GitHub Pages only serves the finished HTML.
*
* Bold, italic, underline, text colour, highlight colour and links come
* across from the doc. Fonts and font sizes do not: the site's CSS sets those.
*/





/**
* Remembers the reason the last download failed.
*/
function gdoc_schedule_problem($set = null)
{
	static $problem = '';
	if ($set !== null) $problem = $set;

	return $problem;
}


/**
* Downloads the doc and turns it into schedule HTML. Returns false on any problem.
* Uses Google's web-page version of the doc (which has the bold and colours).
* On a server that can't read that, it falls back to the plain text version.
*/
function gdoc_schedule_build($source)
{
	$lines = false;

	if (class_exists('DOMDocument'))
	{
		$page = gdoc_schedule_fetch($source . 'html', false);
		if ($page === false) return false;

		$lines = gdoc_schedule_lines_from_html($page);
		if ($lines === false) gdoc_schedule_problem('Google sent the doc but it could not be read.');
	}

	if ($lines === false)
	{
		$text = gdoc_schedule_fetch($source . 'txt', true);
		if ($text === false) return false;

		$lines = gdoc_schedule_lines_from_text($text);
	}

	return gdoc_schedule_render($lines);
}


/**
* Downloads one address. Returns false on any problem.
*/
function gdoc_schedule_fetch($url, $plain_text)
{
	$body = false;

	if (function_exists('curl_init'))
	{
		$got = gdoc_schedule_curl($url, '');

		// some PHP installs (MAMP among them) don't know where the computer keeps
		// its list of trusted certificates: point at the usual places and try again
		if ($got['errno'] == 60 || $got['errno'] == 77)
		{
			foreach (array('/etc/ssl/cert.pem', '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/opt/homebrew/etc/openssl@3/cert.pem', '/usr/local/etc/openssl@3/cert.pem') as $bundle)
			{
				if (!@is_readable($bundle)) continue;

				$got = gdoc_schedule_curl($url, $bundle);
				if ($got['errno'] != 60 && $got['errno'] != 77) break;
			}
		}

		if ($got['body'] === false)
		{
			gdoc_schedule_problem('Could not connect to Google (' . $got['error'] . ').');
			return false;
		}

		$body = $got['body'];
		$code = $got['code'];
		$type = $got['type'];
		$final = $got['final'];

		// a doc that is not shared sends us to Google's sign-in page, or refuses
		if (gdoc_schedule_is_signin($final) || $code == 401 || $code == 403)
		{
			gdoc_schedule_problem('Google asked for a sign-in, so the doc is not shared publicly. In the doc click Share, and under General access choose "Anyone with the link".');
			return false;
		}

		if ($code == 404 || $code == 400)
		{
			gdoc_schedule_problem('Google could not find that doc (error ' . $code . '). Check the doc address in the workflow file, and that it is a Google Doc and not an uploaded Word file.');
			return false;
		}

		if ($code != 200)
		{
			gdoc_schedule_problem('Google answered with error ' . $code . '.');
			return false;
		}

		if (($type != '' && stripos($type, 'text/') !== 0) || ($plain_text && $type != '' && stripos($type, 'text/plain') === false))
		{
			gdoc_schedule_problem('Google sent back something that is not a doc (' . $type . ').');
			return false;
		}
	}
	elseif (ini_get('allow_url_fopen'))
	{
		$context = stream_context_create(array('http' => array(
			'timeout' => 8,
			'follow_location' => 1,
			'user_agent' => 'gdoc_schedule'
		)));

		$body = @file_get_contents($url, false, $context);

		if ($body === false)
		{
			gdoc_schedule_problem('Could not download the doc from Google.');
			return false;
		}

		// find where we ended up after any redirects
		$final = $url;

		if (isset($http_response_header) && is_array($http_response_header))
		{
			foreach ($http_response_header as $header)
			{
				if (stripos($header, 'Location:') === 0) $final = trim(substr($header, 9));
			}
		}
	}
	else
	{
		gdoc_schedule_problem("This server's PHP is not able to download web pages (curl is switched off).");
		return false;
	}

	// a doc that is not shared sends us to Google's sign-in page instead
	if (gdoc_schedule_is_signin($final))
	{
		gdoc_schedule_problem('Google asked for a sign-in, so the doc is not shared publicly. In the doc click Share, and under General access choose "Anyone with the link".');
		return false;
	}

	if ($plain_text)
	{
		$start = strtolower(substr(ltrim($body), 0, 200));

		if (strpos($start, '<!doctype') !== false || strpos($start, '<html') !== false)
		{
			gdoc_schedule_problem('Google sent back a web page where the doc text should be.');
			return false;
		}

		// remove the invisible marker Google puts first, and unify line endings
		if (substr($body, 0, 3) == "\xEF\xBB\xBF") $body = substr($body, 3);
		$body = str_replace(array("\r\n", "\r", "\x0B", "\x0C"), "\n", $body);
	}
	else
	{
		// pictures in the doc arrive as very long pieces of text: drop them
		$body = gdoc_schedule_strip_images($body);
	}

	// too big to be a schedule
	if (strlen($body) > 3000000)
	{
		gdoc_schedule_problem('The doc is too big to show as a schedule.');
		return false;
	}

	return $body;
}


/**
* One download with curl. $bundle is an optional file of trusted certificates.
*/
function gdoc_schedule_curl($url, $bundle)
{
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
	curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	curl_setopt($ch, CURLOPT_ENCODING, '');
	curl_setopt($ch, CURLOPT_COOKIEFILE, '');
	curl_setopt($ch, CURLOPT_USERAGENT, 'gdoc_schedule');
	if ($bundle != '') curl_setopt($ch, CURLOPT_CAINFO, $bundle);

	$got = array(
		'body' => curl_exec($ch),
		'errno' => curl_errno($ch),
		'error' => curl_error($ch),
		'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
		'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
		'final' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)
	);

	// (newer PHP closes the connection by itself)
	if (PHP_VERSION_ID < 80000) curl_close($ch);

	return $got;
}


/**
* Removes every picture from the web-page version of the doc.
*/
function gdoc_schedule_strip_images($page)
{
	$out = '';
	$at = 0;

	while (($start = stripos($page, '<img', $at)) !== false)
	{
		$end = strpos($page, '>', $start);
		if ($end === false) break;

		$out .= substr($page, $at, $start - $at);
		$at = $end + 1;
	}

	return $out . substr($page, $at);
}


function gdoc_schedule_is_signin($url)
{
	return (bool) preg_match('~^https?://accounts\.google\.[a-z.]+/~i', $url);
}


// ------------------------------------------------------- reading the doc
//
// Both readers return a list of lines. A blank line is null. Any other line is
//   array('kind' => 'text' | 'heading' | 'title', 'runs' => array(...))
// and each run is a piece of text with one look:
//   array('text' => '...', 'style' => array(...), 'href' => '...')


function gdoc_schedule_plain_style()
{
	return array('b' => false, 'i' => false, 'u' => false, 's' => false, 'c' => '', 'bg' => '');
}


/**
* Reads the plain text version of the doc (no bold or colours).
*/
function gdoc_schedule_lines_from_text($text)
{
	$lines = array();

	foreach (explode("\n", $text) as $line)
	{
		$line = trim(str_replace(array("\t", "\xC2\xA0"), ' ', $line));

		// drop the bullet characters Google adds for list items
		if (strpos($line, '* ') === 0 || strpos($line, '- ') === 0) $line = substr($line, 2);
		if (strpos($line, "\xE2\x80\xA2") === 0) $line = substr($line, 3);

		$run = array('text' => $line, 'style' => gdoc_schedule_plain_style(), 'href' => '');
		$lines[] = gdoc_schedule_line(array($run), 'text');
	}

	return $lines;
}


/**
* Reads Google's web-page version of the doc. Returns false if it can't be read.
*/
function gdoc_schedule_lines_from_html($page)
{
	$before = libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	$ok = $dom->loadHTML('<?xml encoding="UTF-8">' . $page);
	libxml_clear_errors();
	libxml_use_internal_errors($before);

	if (!$ok) return false;

	$body = $dom->getElementsByTagName('body')->item(0);
	if (!$body) return false;

	// Google describes each look as a named rule, like .c4{font-weight:700}
	$rules = array();

	foreach ($dom->getElementsByTagName('style') as $style)
	{
		if (preg_match_all('/\.([A-Za-z0-9_-]+)\s*\{([^}]*)\}/', $style->textContent, $found, PREG_SET_ORDER))
		{
			foreach ($found as $rule)
			{
				$rules[$rule[1]] = (isset($rules[$rule[1]]) ? $rules[$rule[1]] . ';' : '') . $rule[2];
			}
		}
	}

	$lines = array();
	gdoc_schedule_walk($body, $rules, $lines);

	return $lines;
}


/**
* Goes through the doc top to bottom collecting its lines.
*/
function gdoc_schedule_walk($node, $rules, &$lines)
{
	foreach ($node->childNodes as $child)
	{
		if ($child->nodeType != XML_ELEMENT_NODE) continue;

		$tag = strtolower($child->nodeName);
		$is_heading = (bool) preg_match('/^h[1-6]$/', $tag);

		if ($tag == 'p' || $tag == 'li' || $is_heading)
		{
			$classes = ' ' . strtolower($child->getAttribute('class')) . ' ';

			// "Title" and "Heading 1" in the doc are the schedule's title,
			// the smaller headings each start a new day
			$kind = 'text';
			if ($is_heading) $kind = 'heading';
			if ($tag == 'h1' || strpos($classes, ' title ') !== false) $kind = 'title';

			// a paragraph can hold several lines (shift+enter)
			$found = array(array());
			$style = gdoc_schedule_style($child, $rules, gdoc_schedule_plain_style());
			gdoc_schedule_runs($child, $rules, $style, '', $found);

			foreach ($found as $runs) $lines[] = gdoc_schedule_line($runs, $kind);
		}
		elseif ($tag == 'tr')
		{
			// a table row becomes one line: "9:00 am | Morning sitting"
			$row = array();

			foreach ($child->childNodes as $cell)
			{
				if ($cell->nodeType != XML_ELEMENT_NODE) continue;

				$found = array(array());
				gdoc_schedule_runs($cell, $rules, gdoc_schedule_plain_style(), '', $found);

				foreach ($found as $runs)
				{
					foreach ($runs as $run) $row[] = $run;
					$row[] = array('text' => ' ', 'style' => gdoc_schedule_plain_style(), 'href' => '');
				}
			}

			$lines[] = gdoc_schedule_line($row, 'text');
		}
		elseif ($tag == 'hr')
		{
			$lines[] = null;
		}
		elseif ($tag == 'style' || $tag == 'script' || $tag == 'head')
		{
			continue;
		}
		else
		{
			gdoc_schedule_walk($child, $rules, $lines);
		}
	}
}


/**
* Collects the pieces of text inside one paragraph, each with its look.
*/
function gdoc_schedule_runs($node, $rules, $style, $href, &$found)
{
	foreach ($node->childNodes as $child)
	{
		if ($child->nodeType == XML_TEXT_NODE)
		{
			if ($child->nodeValue !== '')
			{
				$found[count($found) - 1][] = array('text' => $child->nodeValue, 'style' => $style, 'href' => $href);
			}

			continue;
		}

		if ($child->nodeType != XML_ELEMENT_NODE) continue;

		$tag = strtolower($child->nodeName);

		if ($tag == 'br')
		{
			$found[] = array();
			continue;
		}

		if ($tag == 'style' || $tag == 'script') continue;

		// separate paragraphs (inside a table cell) don't run together
		if (($tag == 'p' || $tag == 'div' || $tag == 'li') && $found[count($found) - 1]) $found[] = array();

		$link =($tag == 'a' && $child->hasAttribute('href')) ? gdoc_schedule_link($child->getAttribute('href')) : $href;

		gdoc_schedule_runs($child, $rules, gdoc_schedule_style($child, $rules, $style), $link, $found);
	}
}


/**
* Works out the look of one element, starting from the look around it.
* Only bold, italic, underline, strike-through and the two colours are kept.
*/
function gdoc_schedule_style($element, $rules, $style)
{
	$tag = strtolower($element->nodeName);

	if ($tag == 'b' || $tag == 'strong') $style['b'] = true;
	if ($tag == 'i' || $tag == 'em') $style['i'] = true;
	if ($tag == 'u') $style['u'] = true;
	if ($tag == 's' || $tag == 'strike' || $tag == 'del') $style['s'] = true;

	$css = '';

	foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) as $class)
	{
		if ($class != '' && isset($rules[$class])) $css .= ';' . $rules[$class];
	}

	$css .= ';' . $element->getAttribute('style');

	foreach (explode(';', $css) as $declaration)
	{
		$pair = explode(':', $declaration, 2);
		if (count($pair) != 2) continue;

		$name = strtolower(trim($pair[0]));
		$value = trim(str_replace('!important', '', strtolower($pair[1])));

		if ($name == 'font-weight')
		{
			$style['b'] = ($value == 'bold' || $value == 'bolder' || (is_numeric($value) && $value >= 600));
		}
		elseif ($name == 'font-style')
		{
			$style['i'] = ($value == 'italic' || $value == 'oblique');
		}
		elseif ($name == 'text-decoration' || $name == 'text-decoration-line')
		{
			$style['u'] = (strpos($value, 'underline') !== false);
			$style['s'] = (strpos($value, 'line-through') !== false);
		}
		elseif ($name == 'color')
		{
			// black is the doc's normal text colour: leave that to the site
			$colour = gdoc_schedule_colour($value);
			$style['c'] = ($colour == '#000000') ? '' : $colour;
		}
		elseif ($name == 'background-color' || $name == 'background')
		{
			// white is the doc's normal page colour
			$colour = gdoc_schedule_colour($value);
			$style['bg'] = ($colour == '#ffffff') ? '' : $colour;
		}
	}

	return $style;
}


/**
* Returns a colour as #rrggbb, or '' if it isn't a plain colour.
*/
function gdoc_schedule_colour($value)
{
	$value = trim($value);

	if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m))
	{
		return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
	}

	if (preg_match('/^#[0-9a-f]{6}$/', $value)) return $value;

	if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $value, $m))
	{
		return sprintf('#%02x%02x%02x', min(255, $m[1]), min(255, $m[2]), min(255, $m[3]));
	}

	return '';
}


/**
* Returns a safe web address for a link, or '' to drop the link.
* Google wraps every link in its own redirect, which we remove.
*/
function gdoc_schedule_link($href)
{
	$href = trim($href);

	if (preg_match('~^https?://(www\.)?google\.com/url\?~i', $href))
	{
		parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
		if (!empty($query['q']) && is_string($query['q'])) $href = trim($query['q']);
	}

	return preg_match('~^(https?://|mailto:)[^\s<>"\']+$~i', $href) ? $href : '';
}


/**
* Tidies the spacing in one line. Returns null if the line is blank,
* or has no letters or numbers in it (a row of dashes, for example).
*/
function gdoc_schedule_line($runs, $kind)
{
	$clean = array();

	foreach ($runs as $run)
	{
		$text = preg_replace('/[ \t\r\n]+/', ' ', str_replace("\xC2\xA0", ' ', $run['text']));
		$last = count($clean) - 1;

		// no spaces to open the line, and never two in a row
		if ($last < 0 || substr($clean[$last]['text'], -1) == ' ') $text = ltrim($text, ' ');
		if ($text === '') continue;

		if ($last >= 0 && $clean[$last]['style'] == $run['style'] && $clean[$last]['href'] == $run['href'])
		{
			$clean[$last]['text'] .= $text;
		}
		else
		{
			$clean[] = array('text' => $text, 'style' => $run['style'], 'href' => $run['href']);
		}
	}

	// no spaces to close the line
	while ($clean)
	{
		$last = count($clean) - 1;
		$clean[$last]['text'] = rtrim($clean[$last]['text'], ' ');

		if ($clean[$last]['text'] !== '') break;
		array_pop($clean);
	}

	if (!preg_match('/[A-Za-z0-9\x80-\xFF]/', gdoc_schedule_plain($clean))) return null;

	return array('kind' => $kind, 'runs' => $clean);
}


function gdoc_schedule_plain($runs)
{
	$text = '';
	foreach ($runs as $run) $text .= $run['text'];

	return $text;
}


// ----------------------------------------------------- writing the HTML


/**
* Turns the lines into the schedule's HTML.
*/
function gdoc_schedule_render($lines)
{
	// gather the lines into groups: a blank line or a heading starts a new one
	$blocks = array();
	$current = array();

	foreach ($lines as $line)
	{
		if ($line === null || $line['kind'] != 'text')
		{
			if ($current) $blocks[] = $current;
			$current = array();
		}

		if ($line === null) continue;

		$current[] = $line;

		if ($line['kind'] == 'title')
		{
			$blocks[] = $current;
			$current = array();
		}
	}

	if ($current) $blocks[] = $current;

	if (!$blocks) return "<p class='schedule-note'>Nothing is scheduled right now.</p>\n";

	$s = "<div class='schedule'>\n";

	foreach ($blocks as $n => $block)
	{
		$first = array_shift($block);
		$alone = (count($block) == 0);

		// the title: marked as one in the doc, or a short line on its own that opens the doc
		if ($first['kind'] == 'title' || ($alone && $n == 0 && $first['kind'] == 'text' && strlen(gdoc_schedule_plain($first['runs'])) <= 80))
		{
			$s .= "<h2 class='schedule-title'>" . gdoc_schedule_inline($first['runs']) . "</h2>\n";
			continue;
		}

		// any other line on its own is a note
		if ($alone && $first['kind'] == 'text')
		{
			$s .= "<p class='schedule-note'>" . gdoc_schedule_inline($first['runs']) . "</p>\n";
			continue;
		}

		$s .= "<div class='schedule-day'>\n";
		$s .= "<h3>" . gdoc_schedule_inline($first['runs']) . "</h3>\n";

		if ($block)
		{
			$s .= "<ul>\n";

			foreach ($block as $line)
			{
				$parts = gdoc_schedule_time($line['runs']);

				$s .= ($parts)
					? "<li><span class='schedule-time'>" . gdoc_schedule_inline($parts[0]) . "</span> <span class='schedule-what'>" . gdoc_schedule_inline($parts[1]) . "</span></li>\n"
					: "<li><span class='schedule-what schedule-wide'>" . gdoc_schedule_inline($line['runs']) . "</span></li>\n";
			}

			$s .= "</ul>\n";
		}

		$s .= "</div>\n";
	}

	$s .= "</div>\n";

	return $s;
}


/**
* If a line starts with a time ("9:00 am", "7pm", "9:00 - 10:30 am", "Noon")
* returns array(the time, the rest), each with its bold and colours intact.
* Otherwise returns false.
*/
function gdoc_schedule_time($runs)
{
	$line = gdoc_schedule_plain($runs);

	$one = '(?:\d{1,2}(?::\d{2})?\s*(?:[ap]\.?m\b\.?)?|noon\b|midnight\b)';
	$dash = '(?:-|\x{2013}|\x{2014}|\bto\b)';
	$f = '/^(' . $one . '(?:\s*' . $dash . '\s*' . $one . ')?)(?:\s*(?:' . $dash . '|:|\|)\s+|\s+)(\S.*)$/iu';

	if (!preg_match($f, $line, $m, PREG_OFFSET_CAPTURE)) return false;

	// a bare number ("3 bells") is not a time: it needs a colon, am/pm or a word
	if (!preg_match('/:|[ap]\.?m|noon|midnight/i', $m[1][0])) return false;

	$time = gdoc_schedule_line(gdoc_schedule_slice($runs, 0, strlen($m[1][0])), 'text');
	$what = gdoc_schedule_line(gdoc_schedule_slice($runs, $m[2][1], strlen($line)), 'text');

	if (!$time || !$what) return false;

	return array($time['runs'], $what['runs']);
}


/**
* Cuts out part of a line, from one position to another.
*/
function gdoc_schedule_slice($runs, $from, $to)
{
	$out = array();
	$at = 0;

	foreach ($runs as $run)
	{
		$length = strlen($run['text']);
		$a = max($from, $at) - $at;
		$b = min($to, $at + $length) - $at;

		if ($b > $a)
		{
			$run['text'] = substr($run['text'], $a, $b - $a);
			$out[] = $run;
		}

		$at += $length;
	}

	return $out;
}


/**
* Writes out one line's pieces. Everything is made safe to print: nothing
* typed in the doc can add HTML or scripts to the site. The only markup that
* comes out is bold, italic, underline, strike-through, colours and links.
*/
function gdoc_schedule_inline($runs)
{
	$s = '';

	foreach ($runs as $run)
	{
		$style = $run['style'];

		if ($run['href'] != '')
		{
			$h = "<a href='" . htmlspecialchars($run['href'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($run['text'], ENT_QUOTES, 'UTF-8') . "</a>";

			// links keep the site's own link colour and underline
			$style['c'] = '';
			$style['u'] = false;
		}
		else
		{
			$h = gdoc_schedule_autolink($run['text']);
		}

		if ($style['s']) $h = '<s>' . $h . '</s>';
		if ($style['u']) $h = '<u>' . $h . '</u>';
		if ($style['i']) $h = '<em>' . $h . '</em>';
		if ($style['b']) $h = '<strong>' . $h . '</strong>';

		$css = array();
		if (preg_match('/^#[0-9a-f]{6}$/', $style['c'])) $css[] = 'color:' . $style['c'];
		if (preg_match('/^#[0-9a-f]{6}$/', $style['bg'])) $css[] = 'background-color:' . $style['bg'];
		if ($css) $h = "<span style='" . implode(';', $css) . "'>" . $h . "</span>";

		$s .= $h;
	}

	return $s;
}


/**
* Makes plain text safe to print, and turns typed web addresses into links.
*/
function gdoc_schedule_autolink($text)
{
	$parts = preg_split('~(https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]])~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	if ($parts === false) return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

	$out = '';

	foreach ($parts as $i => $part)
	{
		$safe = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');

		// odd pieces are the web addresses
		$out .= ($i % 2) ? "<a href='" . $safe . "'>" . $safe . "</a>" : $safe;
	}

	return $out;
}
