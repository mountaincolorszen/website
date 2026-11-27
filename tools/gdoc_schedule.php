<?php

/**
* Google Doc schedule: turns a Google Doc into HTML that looks like the doc.
*
* Used by tools/build.php, which the GitHub Action runs. Visitors never run
* this file: GitHub Pages only serves the finished HTML.
*
* What you see in the doc is what you get, with no guessing:
*   - every line, blank line and space, as typed
*   - bold, italic, underline, strike-through, only where the doc has them
*   - text colour, highlight colour, text size (relative to the doc's normal size)
*   - links, alignment, line spacing, space before/after paragraphs, indents
*   - bulleted and numbered lists, tables (with their borders), horizontal lines
* Not copied: the font itself (the site's font is used) and pictures.
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
* Downloads the doc and turns it into HTML. Returns false on any problem.
* Uses Google's web-page version of the doc (which has the formatting).
* On a server that can't read that, it falls back to the plain text version.
*/
function gdoc_schedule_build($source)
{
	if (class_exists('DOMDocument'))
	{
		$page = gdoc_schedule_fetch($source . 'html', false);
		if ($page === false) return false;

		$html = gdoc_schedule_from_html($page);
		if ($html !== false) return $html;

		gdoc_schedule_problem('Google sent the doc but it could not be read.');
	}

	$text = gdoc_schedule_fetch($source . 'txt', true);
	if ($text === false) return false;

	return gdoc_schedule_from_text($text);
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




// ------------------------------------------------------- converting the doc


/**
* The plain text version (no formatting): each line as it was typed.
*/
function gdoc_schedule_from_text($text)
{
	$out = '';

	foreach (explode("\n", rtrim($text)) as $line)
	{
		$line = rtrim($line, "\r");
		$out .= (trim(str_replace("\xC2\xA0", ' ', $line)) === '')
			? "<p class='gs-blank'><br></p>\n"
			: '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "</p>\n";
	}

	return "<div class='schedule gdoc'>\n" . $out . "</div>\n";
}


/**
* Google's web-page version of the doc. Returns false if it can't be read.
*/
function gdoc_schedule_from_html($page)
{
	$before = libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	$ok = $dom->loadHTML('<?xml encoding="UTF-8">' . $page);
	libxml_clear_errors();
	libxml_use_internal_errors($before);

	if (!$ok) return false;

	$body = $dom->getElementsByTagName('body')->item(0);
	if (!$body) return false;

	// Google describes each look as a named rule, like .c4{font-weight:700},
	// plus rules for whole kinds of paragraph, like h2{font-size:16pt}
	$css = '';
	foreach ($dom->getElementsByTagName('style') as $style) $css .= $style->textContent;

	$doc = array('classes' => array(), 'tags' => array(), 'base' => 11.0);

	if (preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $found, PREG_SET_ORDER))
	{
		foreach ($found as $rule)
		{
			$selector = trim($rule[1]);

			if (preg_match('/^\.([A-Za-z0-9_-]+)$/', $selector, $m))
			{
				$doc['classes'][$m[1]] = (isset($doc['classes'][$m[1]]) ? $doc['classes'][$m[1]] . ';' : '') . $rule[2];
			}
			elseif (preg_match('/^(p|li|h[1-6])$/', $selector, $m))
			{
				$doc['tags'][$m[1]] = (isset($doc['tags'][$m[1]]) ? $doc['tags'][$m[1]] . ';' : '') . $rule[2];
			}
		}
	}

	// the doc's normal text size: sizes on the site are relative to it
	$normal = gdoc_schedule_props(isset($doc['tags']['p']) ? $doc['tags']['p'] : '', array());
	if (!empty($normal['size'])) $doc['base'] = $normal['size'];

	$blocks = array();
	gdoc_schedule_blocks($body, $doc, $blocks);

	// a blank line at the very end of a doc isn't visible there, so leave those off
	while ($blocks && $blocks[count($blocks) - 1] === "<p class='gs-blank'><br></p>") array_pop($blocks);

	if (!$blocks) return "<div class='schedule gdoc'>\n<p>Nothing is scheduled right now.</p>\n</div>\n";

	return "<div class='schedule gdoc'>\n" . implode("\n", $blocks) . "\n</div>\n";
}


/**
* Goes through the doc top to bottom, turning each paragraph, list,
* table and line into HTML.
*/
function gdoc_schedule_blocks($node, $doc, &$blocks)
{
	foreach ($node->childNodes as $child)
	{
		if ($child->nodeType != XML_ELEMENT_NODE) continue;

		$tag = strtolower($child->nodeName);

		if ($tag == 'p' || preg_match('/^h[1-6]$/', $tag))
		{
			$blocks[] = gdoc_schedule_paragraph($child, $doc, 'p');
		}
		elseif ($tag == 'ul' || $tag == 'ol')
		{
			$blocks[] = gdoc_schedule_list($child, $doc);
		}
		elseif ($tag == 'table')
		{
			$blocks[] = gdoc_schedule_table($child, $doc);
		}
		elseif ($tag == 'hr')
		{
			$blocks[] = '<hr>';
		}
		elseif ($tag != 'style' && $tag != 'script' && $tag != 'head')
		{
			gdoc_schedule_blocks($child, $doc, $blocks);
		}
	}
}


/**
* One paragraph (or list item, with $as = 'li').
*/
function gdoc_schedule_paragraph($element, $doc, $as)
{
	$look = gdoc_schedule_look($element, $doc, gdoc_schedule_empty_look(), true);

	$runs = array();
	gdoc_schedule_runs($element, $doc, $look, '', $runs);

	// the paragraph's own layout: alignment, line spacing, space around it, indent
	$css = array();
	$em = function ($pt) use ($doc) { return round($pt / $doc['base'], 3) . 'em'; };

	if (in_array($look['align'], array('center', 'right', 'justify'))) $css[] = 'text-align:' . $look['align'];
	if ($look['lh'] > 0) $css[] = 'line-height:' . $look['lh'];
	if ($look['pt'] > 0) $css[] = 'padding-top:' . $em($look['pt']);
	if ($look['pb'] > 0) $css[] = 'padding-bottom:' . $em($look['pb']);
	if ($as == 'p' && $look['ml'] > 0) $css[] = 'margin-left:' . $em($look['ml']);
	if ($look['ti'] != 0) $css[] = 'text-indent:' . $em($look['ti']);

	$attr = $css ? " style='" . implode(';', $css) . "'" : '';

	// a line with nothing on it is a blank line, kept as one
	$visible = '';
	foreach ($runs as $run) if ($run !== 'br') $visible .= $run['text'];

	if (trim(str_replace("\xC2\xA0", ' ', $visible)) === '' && !in_array('br', $runs, true))
	{
		return ($as == 'li') ? "<li$attr><br></li>" : "<p class='gs-blank'><br></p>";
	}

	return "<$as$attr>" . gdoc_schedule_write($runs, $doc) . "</$as>";
}


/**
* A bulleted or numbered list. Google writes each level of indent as its own
* list, named like "lst-kix_abc-1" (the last number is the level).
*/
function gdoc_schedule_list($element, $doc)
{
	$tag = strtolower($element->nodeName);
	$level = preg_match('/lst-kix_\S+-(\d+)/', $element->getAttribute('class'), $m) ? min(8, (int) $m[1]) : 0;

	$marks = ($tag == 'ol') ? array('decimal', 'lower-alpha', 'lower-roman') : array('disc', 'circle', 'square');
	$css = 'list-style-type:' . $marks[$level % 3] . ($level ? ';margin-left:' . (1.5 * $level) . 'em' : '');

	$start = ($tag == 'ol' && ctype_digit($element->getAttribute('start')) && $element->getAttribute('start') > 1)
		? " start='" . (int) $element->getAttribute('start') . "'" : '';

	$items = array();

	foreach ($element->childNodes as $li)
	{
		if ($li->nodeType == XML_ELEMENT_NODE && strtolower($li->nodeName) == 'li') $items[] = gdoc_schedule_paragraph($li, $doc, 'li');
	}

	return "<$tag class='gs-list'$start style='$css'>\n" . implode("\n", $items) . "\n</$tag>";
}


/**
* A table, with each cell's paragraphs, borders, padding and background.
*/
function gdoc_schedule_table($table, $doc)
{
	$s = "<table class='gs-table'>\n";

	foreach ($table->getElementsByTagName('tr') as $tr)
	{
		$s .= '<tr>';

		foreach ($tr->childNodes as $cell)
		{
			if ($cell->nodeType != XML_ELEMENT_NODE || !in_array(strtolower($cell->nodeName), array('td', 'th'))) continue;

			$inside = array();
			gdoc_schedule_blocks($cell, $doc, $inside);

			$span = '';
			foreach (array('colspan', 'rowspan') as $name)
			{
				$n = $cell->getAttribute($name);
				if (ctype_digit($n) && $n > 1) $span .= " $name='" . (int) $n . "'";
			}

			$css = gdoc_schedule_cell_css($cell, $doc);
			$s .= "<td$span" . ($css ? " style='$css'" : '') . '>' . implode('', $inside) . '</td>';
		}

		$s .= "</tr>\n";
	}

	return $s . '</table>';
}


/**
* Only the cell settings we understand, with checked values.
*/
function gdoc_schedule_cell_css($cell, $doc)
{
	$decl = gdoc_schedule_declarations($cell, $doc, false);
	$out = array();

	foreach ($decl as $name => $value)
	{
		if (preg_match('/^border-(top|right|bottom|left)-width$/', $name) && preg_match('/^([\d.]+)pt$/', $value, $m))
		{
			$out[$name] = $name . ':' . min(10, (float) $m[1]) . 'pt';
		}
		elseif (preg_match('/^border-(top|right|bottom|left)-style$/', $name) && in_array($value, array('solid', 'dashed', 'dotted', 'double', 'none')))
		{
			$out[$name] = $name . ':' . $value;
		}
		elseif (preg_match('/^border-(top|right|bottom|left)-color$|^background-color$/', $name) && gdoc_schedule_colour($value) != '')
		{
			$out[$name] = $name . ':' . gdoc_schedule_colour($value);
		}
		elseif (preg_match('/^padding-(top|right|bottom|left)$/', $name) && preg_match('/^([\d.]+)pt$/', $value, $m))
		{
			$out[$name] = $name . ':' . round(min(72, (float) $m[1]) / $doc['base'], 3) . 'em';
		}
		elseif ($name == 'vertical-align' && in_array($value, array('top', 'middle', 'bottom')))
		{
			$out[$name] = 'vertical-align:' . $value;
		}
	}

	return implode(';', $out);
}


// ------------------------------------------------------- the look of text


function gdoc_schedule_empty_look()
{
	return array(
		// text
		'b' => false, 'i' => false, 'u' => false, 's' => false,
		'c' => '', 'bg' => '', 'size' => 0, 'va' => '',
		// paragraph
		'align' => '', 'lh' => 0, 'pt' => 0, 'pb' => 0, 'ml' => 0, 'ti' => 0
	);
}


/**
* All the style settings that apply to an element, in order: the rule for its
* kind of paragraph (only for the paragraph itself), its named rules, then its own.
*/
function gdoc_schedule_declarations($element, $doc, $with_tag)
{
	$tag = strtolower($element->nodeName);
	$css = ($with_tag && isset($doc['tags'][$tag])) ? $doc['tags'][$tag] : '';

	foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) as $class)
	{
		if ($class != '' && isset($doc['classes'][$class])) $css .= ';' . $doc['classes'][$class];
	}

	$css .= ';' . $element->getAttribute('style');

	$decl = array();

	foreach (explode(';', $css) as $declaration)
	{
		$pair = explode(':', $declaration, 2);
		if (count($pair) != 2) continue;

		$name = strtolower(trim($pair[0]));
		$value = trim(str_replace('!important', '', strtolower($pair[1])));

		// "padding:5pt 5pt 5pt 5pt" is shorthand for the four sides
		if ($name == 'padding')
		{
			$v = preg_split('/\s+/', $value);
			$v = array_pad($v, 4, null);
			$top = $v[0]; $right = isset($v[1]) ? $v[1] : $top; $bottom = isset($v[2]) ? $v[2] : $top; $left = isset($v[3]) ? $v[3] : $right;
			$decl['padding-top'] = $top; $decl['padding-right'] = $right; $decl['padding-bottom'] = $bottom; $decl['padding-left'] = $left;
			continue;
		}

		$decl[$name] = $value;
	}

	return $decl;
}


/**
* Works out the look of one element, starting from the look around it.
*/
function gdoc_schedule_look($element, $doc, $look, $is_paragraph)
{
	$tag = strtolower($element->nodeName);

	if ($tag == 'b' || $tag == 'strong') $look['b'] = true;
	if ($tag == 'i' || $tag == 'em') $look['i'] = true;
	if ($tag == 'u') $look['u'] = true;
	if ($tag == 's' || $tag == 'strike' || $tag == 'del') $look['s'] = true;
	if ($tag == 'sup') $look['va'] = 'super';
	if ($tag == 'sub') $look['va'] = 'sub';

	return gdoc_schedule_props(gdoc_schedule_declarations($element, $doc, $is_paragraph), $look);
}


/**
* Applies style settings (a list, or a CSS string) to a look.
*/
function gdoc_schedule_props($decl, $look)
{
	if (is_string($decl))
	{
		$list = array();
		foreach (explode(';', $decl) as $d)
		{
			$pair = explode(':', $d, 2);
			if (count($pair) == 2) $list[strtolower(trim($pair[0]))] = trim(strtolower($pair[1]));
		}
		$decl = $list;
	}

	$look += gdoc_schedule_empty_look();

	foreach ($decl as $name => $value)
	{
		switch ($name)
		{
			case 'font-weight':
				$look['b'] = ($value == 'bold' || $value == 'bolder' || (is_numeric($value) && $value >= 600));
				break;

			case 'font-style':
				$look['i'] = ($value == 'italic' || $value == 'oblique');
				break;

			case 'text-decoration':
			case 'text-decoration-line':
				$look['u'] = (strpos($value, 'underline') !== false);
				$look['s'] = (strpos($value, 'line-through') !== false);
				break;

			case 'color':
				// black is the doc's normal text colour: leave that to the site
				$colour = gdoc_schedule_colour($value);
				$look['c'] = ($colour == '#000000') ? '' : $colour;
				break;

			case 'background-color':
				// white is the doc's normal page colour
				$colour = gdoc_schedule_colour($value);
				$look['bg'] = ($colour == '#ffffff') ? '' : $colour;
				break;

			case 'font-size':
				$pt = gdoc_schedule_points($value);
				if ($pt > 0) $look['size'] = $pt;
				break;

			case 'vertical-align':
				$look['va'] = in_array($value, array('super', 'sub')) ? $value : '';
				break;

			case 'text-align':
				$look['align'] = in_array($value, array('left', 'center', 'right', 'justify')) ? $value : '';
				break;

			case 'line-height':
				$look['lh'] = (is_numeric($value) && $value > 0) ? min(5, (float) $value) : 0;
				break;

			case 'padding-top':
				$look['pt'] = gdoc_schedule_points($value);
				break;

			case 'padding-bottom':
				$look['pb'] = gdoc_schedule_points($value);
				break;

			case 'margin-left':
				$look['ml'] = gdoc_schedule_points($value);
				break;

			case 'text-indent':
				$look['ti'] = gdoc_schedule_points($value, true);
				break;
		}
	}

	return $look;
}


/**
* A size in points ("12pt", "16px"), or 0. Kept within sensible limits.
*/
function gdoc_schedule_points($value, $signed = false)
{
	if (!preg_match('/^(-?[\d.]+)(pt|px)$/', trim($value), $m)) return 0;

	$pt = (float) $m[1] * (($m[2] == 'px') ? 0.75 : 1);
	if (!$signed && $pt < 0) return 0;

	return max(-144, min(144, $pt));
}


/**
* Collects the pieces of text inside a paragraph, each with its look.
* A line break inside the paragraph (shift+enter) comes through as 'br'.
*/
function gdoc_schedule_runs($node, $doc, $look, $href, &$runs)
{
	foreach ($node->childNodes as $child)
	{
		if ($child->nodeType == XML_TEXT_NODE || $child->nodeType == XML_CDATA_SECTION_NODE)
		{
			// spaces are kept exactly; only stray newlines in Google's code become spaces
			$text = str_replace(array("\r\n", "\n", "\r"), ' ', $child->nodeValue);
			if ($text !== '') $runs[] = array('text' => $text, 'look' => $look, 'href' => $href);
			continue;
		}

		if ($child->nodeType != XML_ELEMENT_NODE) continue;

		$tag = strtolower($child->nodeName);

		if ($tag == 'br') { $runs[] = 'br'; continue; }
		if ($tag == 'style' || $tag == 'script' || $tag == 'img') continue;

		$link = ($tag == 'a' && $child->hasAttribute('href')) ? gdoc_schedule_link($child->getAttribute('href')) : $href;

		gdoc_schedule_runs($child, $doc, gdoc_schedule_look($child, $doc, $look, false), $link, $runs);
	}
}


/**
* Writes out the pieces of a paragraph. Everything is made safe to print:
* nothing typed in the doc can add HTML or scripts to the site.
*/
function gdoc_schedule_write($runs, $doc)
{
	// join neighbouring pieces that look the same
	$merged = array();

	foreach ($runs as $run)
	{
		$last = count($merged) - 1;

		if ($run !== 'br' && $last >= 0 && $merged[$last] !== 'br'
			&& gdoc_schedule_text_look($merged[$last]['look']) == gdoc_schedule_text_look($run['look'])
			&& $merged[$last]['href'] == $run['href'])
		{
			$merged[$last]['text'] .= $run['text'];
		}
		else
		{
			$merged[] = $run;
		}
	}

	$s = '';

	foreach ($merged as $run)
	{
		if ($run === 'br') { $s .= '<br>'; continue; }

		$look = $run['look'];
		$h = htmlspecialchars($run['text'], ENT_QUOTES, 'UTF-8');

		// links use the site's own link colour and underline
		if ($run['href'] != '')
		{
			$h = "<a href='" . htmlspecialchars($run['href'], ENT_QUOTES, 'UTF-8') . "'>" . $h . '</a>';
			$look['c'] = '';
			$look['u'] = false;
		}

		if ($look['va'] == 'super') $h = '<sup>' . $h . '</sup>';
		if ($look['va'] == 'sub') $h = '<sub>' . $h . '</sub>';
		if ($look['s']) $h = '<s>' . $h . '</s>';
		if ($look['u']) $h = '<u>' . $h . '</u>';
		if ($look['i']) $h = '<em>' . $h . '</em>';
		if ($look['b']) $h = '<strong>' . $h . '</strong>';

		$css = array();
		if (preg_match('/^#[0-9a-f]{6}$/', $look['c'])) $css[] = 'color:' . $look['c'];
		if (preg_match('/^#[0-9a-f]{6}$/', $look['bg'])) $css[] = 'background-color:' . $look['bg'];

		// sizes are relative to the doc's normal size, so the site's size stays in charge
		if ($look['size'] > 0)
		{
			$ratio = round(max(0.4, min(5, $look['size'] / $doc['base'])), 3);
			if (abs($ratio - 1) > 0.02) $css[] = 'font-size:' . $ratio . 'em';
		}

		if ($css) $h = "<span style='" . implode(';', $css) . "'>" . $h . '</span>';

		$s .= $h;
	}

	return $s;
}


function gdoc_schedule_text_look($look)
{
	return array($look['b'], $look['i'], $look['u'], $look['s'], $look['c'], $look['bg'], $look['size'], $look['va']);
}
