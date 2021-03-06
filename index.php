<?php

define('TVDB_API_KEY', '94F0BD0D5948FE69');
define('TVDB_DVD_OVER_TV', true);

## tvdb
# get mirror
#   http://www.thetvdb.com/api/94F0BD0D5948FE69/mirrors.xml
# get show id
#   http://www.thetvdb.com/api/GetSeries.php?seriesname=community
# get show details/episodes/etc
#   http://www.thetvdb.com/api/94F0BD0D5948FE69/series/<seriesid>/all/en.zip

# 1. get server time
# 2. http://www.thetvdb.com/api/Updates.php?type=all&time=1318015462
# 3. store in vars[last_tvdb_update]
# 4. get info from tvdb
# 5. store in series.data


require 'inc.bootstrap.php';
require 'inc.show.php';

// Define env vars
define('AJAX', strtolower(@$_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
define('MOBILE', is_int(strpos(strtolower($_SERVER['HTTP_USER_AGENT']), 'mobile')));

// Get and reset highlighted show
$hilited = (int)@$_GET['series_hilited'] ?: (int)@$_COOKIE['series_hilited'];
if ( $hilited ) {
	setcookie('series_hilited', '', 1);
}



$cfg = new Config;
$async = MOBILE && $cfg->async_inactive;
$skip = $cfg->dont_load_inactive;



// New show
if ( isset($_POST['name']) && !isset($_POST['id']) ) {
	$insert = array(
		'name' => $_POST['name'],
		'deleted' => 0,
		'active' => 1,
		'watching' => 0,
	);

	if ( !empty($_POST['dont_connect_tvdb']) || !empty($_POST['tvdb_series_id']) ) {
		if ( !empty($_POST['tvdb_series_id']) ) {
			$insert['tvdb_series_id'] = $_POST['tvdb_series_id'];

			if ( !empty($_POST['replace_existing']) ) {
				$existingShow = $db->select('series', array('deleted' => 0, 'name' => $insert['name']), null, 'Show')->first();
				if ( $existingShow ) {
					$update = array('name' => $insert['name'], 'tvdb_series_id' => $insert['tvdb_series_id']);
					@$_POST['tvtorrents_show_id'] && $update['tvtorrents_show_id'] = @$_POST['tvtorrents_show_id'];
					@$_POST['dailytvtorrents_name'] && $update['dailytvtorrents_name'] = @$_POST['dailytvtorrents_name'];
					$db->update('series', $update, array('id' => $existingShow->id));
				}
				else {
					$adding_show_tvdb_result = true;
					$noredirect = true;
				}

				$insert = false;
			}
		}

		if ( $insert ) {
			$db->insert('series', $insert);
			$id = $db->insert_id();
		}

		if ( empty($noredirect) ) {
			exit('OK' . $id);
		}
	}
	else {
		$adding_show_tvdb_result = simplexml_load_file('http://www.thetvdb.com/api/GetSeries.php?seriesname=' . urlencode($_POST['name']));
	}

	require 'tpl.add-show.php';
	exit;
}

// Change show order
else if ( isset($_POST['order']) ) {
	$db->begin();
	foreach ( explode(',', $_POST['order']) AS $i => $id ) {
		$db->update('series', array('o' => $i), array('id' => $id));
	}
	$db->commit();

	exit('OK');
}

// Edit scrollable field: next
else if ( isset($_POST['id'], $_POST['dir']) ) {
	if ( 0 != (int)$_POST['dir'] ) {
		$delta = $_POST['dir'] < 0 ? -1 : 1;

		// fetch show
		$show = Show::get($_POST['id']);
		$ne = $show->next_episode;

		// Parse and up/down `next_episode`
		$parts = array_map('intval', explode('.', $ne));
		$parts[count($parts)-1] += $delta;

		// Default feedback
		$episodes = 0;

		// Check S if E changed.
		if ( 2 == count($parts) ) {
			$S =& $parts[0];
			$E =& $parts[1];

			// Moving down
			if ( $E < 1 && $S > 1 ) {
				if ( isset($show->seasons[$S-1]) ) {
					$S -= 1;
					$E = $show->seasons[$S];
				}
			}
			// Moving up
			else if ( isset($show->seasons[$S]) && $E > $show->seasons[$S] ) {
				$S += 1;
				$E = 1;
			}

			// Add "0" padding
			$E = str_pad($E, 2, '0', STR_PAD_LEFT);

			// More detailed feedback
			$episodes = $db->select_one('seasons', 'episodes', array('series_id' => $show->id, 'season' => $S));
		}

		// Save
		$ne = implode('.', $parts);
		$db->update('series', array('next_episode' => $ne), array('id' => $show->id));

		// respond
		header('Content-type: text/json');
		exit(json_encode(array(
			'next_episode' => $ne,
			'season' => $S,
			'episodes' => (int)$episodes,
		)));
	}

	exit('W00t!?');
}

// Edit field: next
else if ( isset($_POST['id'], $_POST['next_episode']) ) {
	$db->update('series', array('next_episode' => $_POST['next_episode']), array('id' => $_POST['id']));

	exit($db->select_one('series', 'next_episode', array('id' => $_POST['id'])));
}

// Edit field: missed
else if ( isset($_POST['id'], $_POST['missed']) ) {
	$db->update('series', array('missed' => $_POST['missed']), array('id' => $_POST['id']));

	exit($db->select_one('series', 'missed', array('id' => $_POST['id'])));
}

// Edit field: name
else if ( isset($_POST['id'], $_POST['name']) ) {
	$db->update('series', array('name' => $_POST['name']), array('id' => $_POST['id']));

	exit($db->select_one('series', 'name', array('id' => $_POST['id'])));
}

// Toggle active status
else if ( isset($_GET['id'], $_GET['active']) ) {
	$active = (bool)$_GET['active'];

	$update = array('active' => $active);
	!$active && $update['watching'] = false;

	$db->update('series', $update, array('id' => $_GET['id']));

	header('Location: ./');
	exit;
}

// Delete show
else if ( isset($_GET['delete']) ) {
	$db->update('series', 'deleted = 1', array('id' => $_GET['id']));

	header('Location: ./');
	exit;
}

// Set current/watching show
else if ( isset($_GET['watching']) ) {
	// Toggle selected
	if ( $cfg->max_watching > 1 ) {
		$show = Show::get($_GET['watching']);

		// Unwatch
		if ($show->watching) {
			$update = array('watching' => 0);
			$db->update('series', $update, array('id' => $show->id));
		}
		// Watch
		else {
			$maxWatching = $db->select_one('series', 'max(watching)', '1');
			$update = array('watching' => $maxWatching + 1);
			$db->update('series', $update, array('id' => $show->id));

			// Only allow $cfg->max_watching shows to have watching > 1
			$allWatching = $db->select_fields('series', 'id,watching', 'watching > 0 ORDER BY watching DESC, id DESC');
			$allWatching = array_keys($allWatching);
			$illegallyWatching = array_slice($allWatching, $cfg->max_watching);
			$db->update('series', array('watching' => 0), array('id' => $illegallyWatching));
		}
	}
	// Only selected (no toggle, just ON)
	else {
		$db->update('series', 'watching = 0', '1');
		$db->update('series', 'watching = 1', array('id' => $_GET['watching']));
	}

	header('Location: ./');
	exit;
}

// Update one show
else if ( isset($_GET['updateshow']) ) {
	$id = (int)$_GET['updateshow'];

	if ( $show = Show::get($id) ) {
		if ( !$show->tvdb_series_id ) {
			// get tvdb's series_id // simple API's rule!
			$xml = simplexml_load_file('http://www.thetvdb.com/api/GetSeries.php?seriesname=' . urlencode($show->name));
			if ( isset($xml->Series[0]) ) {
				$Series = (array)$xml->Series[0];
				if ( isset($Series['seriesid'], $Series['IMDB_ID']) ) {
					// okay, this is the right one
					$db->update('series', array(
						'name' => $Series['SeriesName'],
						'tvdb_series_id' => $Series['seriesid'],
						'data' => json_encode($Series),
					), array('id' => $id));

					$show->tvdb_series_id = $Series['seriesid'];
				}
			}
		}

		if ( $show->tvdb_series_id ) {
			// get package with details
			$zipfile = './tmp/show-' . $show->tvdb_series_id . '.zip';
			file_put_contents($zipfile, file_get_contents('http://www.thetvdb.com/api/' . TVDB_API_KEY . '/series/' . $show->tvdb_series_id . '/all/en.zip'));

			// read from it
			$zip = new ZipArchive;
			if ($zip->open($zipfile) !== TRUE) {
				exit('Ugh?');
			}
			$xml = $zip->getFromName('en.xml');
			$zip->close();

			$xml = simplexml_load_string($xml);
			$data = (array)$xml->Series;

			// save description
			$db->update('series', array(
				'description' => $data['Overview'],
				'data' => json_encode($data),
			), array('id' => $id));

			// get seasons
			$seasons = array();
			foreach ( $xml->Episode AS $episode ) {
				// TV airings might have different episode/season numbers than DVD productions, so the number
				// of episodes in a season depends on this constant, which should be a Config var.
				if ( TVDB_DVD_OVER_TV ) {
					$S = (int)(string)$episode->Combined_season;
					$E = (int)(string)$episode->Combined_episodenumber;
				}
				else {
					$S = (int)(string)$episode->SeasonNumber;
					$E = (int)(string)$episode->EpisodeNumber;
				}

				if ( $S && $E ) {
					if ( !isset($seasons[$S]) ) {
						$seasons[$S] = $E;
					}
					else {
						$seasons[$S] = max($seasons[$S], $E);
					}
				}
			}

			// save seasons
			$db->begin();
			$db->delete('seasons', array('series_id' => $show->id));
			foreach ( $seasons AS $S => $E ) {
				$db->insert('seasons', array(
					'series_id' => $show->id,
					'season' => $S,
					'episodes' => $E,
				));
			}
			$db->commit();
		}
	}

	if ( AJAX ) {
		setcookie('series_hilited', $id);
		echo 'OK';
	}
	else {
		header('Location: ./#show-' . $id);
	}

	exit;
}

// reset one show
else if ( isset($_GET['resetshow']) ) {
	// delete seasons/episodes
	$db->delete('seasons', array('series_id' => $_GET['resetshow']));

	// delete tvdb series id
	$db->update('series', array('tvdb_series_id' => 0), array('id' => $_GET['resetshow']));

	header('Location: ./');
	exit;
}

// lazy/async load inactive shows
else if ( isset($_GET['inactive']) ) {
	require 'tpl.shows.php';
	exit;
}

$exists = false;
if (@$adding_show_tvdb_result) {
	$existingShow = $db->select('series', array('deleted' => 0, 'name' => $_POST['name']), null, 'Show')->first();
	if ($existingShow) {
		$exists = true;
		if ($existingShow->tvdb_series_id && empty($_POST['tvdb_series_id'])) {
			$_POST['tvdb_series_id'] = $existingShow->tvdb_series_id;
		}
	}
}

?>
<!doctype html>
<html>

<head>
	<meta charset="utf-8" />
	<link rel="shortcut icon" type="image/x-icon" href="favicon.ico" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Series</title>
	<style><?php require 'series.css' ?></style>
</head>

<body>

<img id="banner" />

<table id="series">
	<thead>
		<tr class="hd" bgcolor="#bbbbbb">
			<th class="tvdb"></th>
			<th>Name <a href="javascript:$('showname').focus();void(0);">+</a></th>
			<? if ($cfg->banners): ?>
				<th class="picture"></th>
			<? endif ?>
			<th>Nxt</th>
			<th class="missed">Not</th>
			<th class="seasons" title="Existing seasons">S</th>
			<th class="icon" colspan="2"></th>
		</tr>
	</thead>
	<tbody>
		<?php require 'tpl.shows.php' ?>
	</tbody>
	<? if ($cfg->search_inactives && ($async || $skip)): ?>
		<?php require 'tpl.search.php' ?>
	<? endif ?>
</table>

<script src="rjs.js"></script>

<div id="add-show-wrapper">
	<?php require 'tpl.add-show.php' ?>
</div>

<script>
<? if ($async || $skip): ?>
	function startLazyLoad(delay) {
		var $series = $('series');
		var $loadingMore = document.el('tbody').attr('id', 'loading-more').addClass('loading-more').setHTML('<tr><td colspan="9">&nbsp;</td></tr>').inject($series);
		setTimeout(function() {
			$loadingMore.addClass('loading');
			$.get('?inactive=1&series_hilited=<?= $hilited ?>').on('done', function(e, html) {
				$loadingMore.remove();
				document.el('tbody', {"id": 'shows-inactive'}).setHTML(html).inject($series);
				document.body.addClass('show-all');
			});
		}, delay || 1);
	}
	<? if ($skip): ?>
		var $series = $('series');
		var $loadMore = document.el('tbody').attr('id', 'load-more').addClass('load-more').setHTML('<tr><td colspan="9"><a href>Load the rest</a></td></tr>').inject($series);
		$loadMore.getElement('a').on('click', function(e) {
			e.preventDefault();

			$loadMore.remove();
			startLazyLoad();
		});
	<? else: ?>
		window.on('load', function(e) {
			startLazyLoad(2000);
		});
	<? endif ?>
<? else: ?>
	document.body.addClass('show-all');
<? endif ?>

function RorA(t, fn) {
	fn || (fn = function() {
		location.reload();
	});
	if ( t == 'OK' ) {
		return fn();
	}
	alert(t);
}

function changeName(id, name) {
	$.post('', 'id=' + id + '&name=' + encodeURIComponent(name)).on('done', function(e, t) {
		$('show-name-' + id).setHTML(t);
	});
	return false;
}

function changeValue(o, id, n, v) {
	v == undefined && (v = o.getText().trim());
	var nv = prompt('New value:', v);
	if ( null === nv ) {
		return false;
	}
	if ( 'name' == n ) {
		return changeName(id, nv);
	}
	return doAndRespond(o, 'id=' + id + '&' + n + '=' + nv);
}

function doAndRespond(o, d) {
	o.setHTML('<img src="spinner.gif" />');
	$.post('', d).on('done', function(e, rsp) {
		if ( typeof rsp == 'string' ) {
			return o.setHTML(rsp);
		}

		o.setHTML(rsp.next_episode);
		if ( rsp.season && rsp.episodes ) {
			o.attr('title', 'Season ' + rsp.season + ' has ' + rsp.episodes + ' episodes');
		}
	});
	return false;
}

<? if ($cfg->search_inactives): ?>
	document.on('keydown', function(e) {
		if ( document.activeElement == document.body ) {
			if ( e.which == 191 ) {
				$('search').focus();
				e.preventDefault();
			}
		}
	});
	var trs;
	$('search')
		.on('search', function(e) {
			if ( !trs ) {
				trs = new Elements($('shows-inactive').rows);
				trs.each(function(tr) {
					var span = tr.getElement('.show-name');
					tr._txt = (span.textContent + ' ' + (span.title || '')).toLowerCase();
				});
			}

			var q = this.value.trim().toLowerCase();
			if ( !q ) {
				trs.removeClass('filtered-out');
			}
			else {
				trs.each(function(tr) {
					var match = tr._txt.indexOf(q) != -1,
						method = match ? 'removeClass' : 'addClass';
					tr[method]('filtered-out');
				});
			}
		})
		.on('keyup', function(e) {
			if ( this.value != this.$lastValue ) {
				this.$lastValue = this.value;
				this.fire('search', e);
			}
		})
	;
<? endif ?>

$('series')
	.on('contextmenu', '.next.oc a', function(e) {
		e.preventDefault();
		this.addClass('eligible');
	})
	.on('keydown', '.next.oc a', function(e) {
		var space = e.key == Event.Keys.space,
			esc = e.key == Event.Keys.esc,
			up = e.key == Event.Keys.up,
			down = e.key == Event.Keys.down;
		if ( space ) {
			e.preventDefault();
			this.toggleClass('eligible');
		}
		else if ( esc ) {
			this.removeClass('eligible');
		}
		else if ( up || down ) {
			if ( this.hasClass('eligible') ) {
				e.preventDefault();
				var direction = up ? 1 : -1;
				doAndRespond(this, 'id=' + this.ancestor('tr').attr('showid') + '&dir=' + direction);
			}
		}
	})
	.on('blur', '.next.oc a', function(e) {
		this.removeClass('eligible');
	})
	.on('mouseleave', '.next.oc a', function(e) {
		this.removeClass('eligible');
	})
	.on('mousewheel', '.next.oc a', function(e) {
		var direction = 'number' == typeof e.originalEvent.wheelDelta ? -e.originalEvent.wheelDelta : e.originalEvent.detail;
		// Firefox messes up here... It doesn't cancel the scroll event. If I move
		// the preventDefault to the top of this function, sometimes it does cancel
		// the event (and sometimes it doesn't!?). Very strange behaviour that I can't
		// seem to reproduce in http://jsfiddle.net/rudiedirkx/dDW63/show/ (always works).
		if ( this.hasClass('eligible') && direction ) {
			e.preventDefault();
			direction /= -Math.abs(direction);
			doAndRespond(this, 'id=' + this.ancestor('tr').attr('showid') + '&dir=' + direction);
		}
	})
	.on('mouseover', 'tr[data-banner] .show-banner', function(e) {
		var src = 'http://thetvdb.com/banners/' + this.ancestor('tr').data('banner');
		$('banner').attr('src', src).show();

		this.on('mouseout', this._onmouseout = function(e) {
			$('banner').hide();

			this.off('mouseout', this._onmouseout);
		});
	})
	.on('click', 'td.tvdb > a', function(e) {
		e.preventDefault();
		this.getChildren('img').attr('src', 'loading16.gif');
		$.post(this.attr('href')).on('done', function(e) {
			var t = this.responseText;
			RorA(t);
		});
	})
;
</script>

</body>

<!-- <?= count($db->queries) ?> queries -->
<!-- <? print_r($db->queries) ?> -->

</html>


