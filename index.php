<?php
/* =========================================================================
   Fencing Profile Linker
   ========================================================================= */
$results = [];
$error   = null;

function build_search_url(string $name): string {
    return "https://fencingtracker.com/search?s=" . urlencode($name);
}

/* --------------------------------------------------------------------- */
/* Main POST handler                                                    */
/* --------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $askfredUrl = trim($_POST['askfred'] ?? '');
    $usafText   = trim($_POST['usaf']   ?? '');

    /* 1) ASKFRED PREREG LIST ******************************************* */
    if ($askfredUrl !== '') {
        // ... (AskFRED parsing logic remains the same) ...
        $ch = curl_init($askfredUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_TIMEOUT        => 20
        ]);
        $html = curl_exec($ch);
        if (curl_errno($ch)) { $error = 'cURL error: '.curl_error($ch); $html=false; }
        curl_close($ch);

        if ($html !== false) {
            if (preg_match('/__PRELOADED_STATE__\\s*=\\s*({.+?});/s', $html, $m)) {
                $json = json_decode($m[1], true);
                foreach (($json['entities']['events'] ?? []) as $event) {
                    $evtName = $event['name'] ?? '';
                    foreach ($event['preregistrations'] ?? [] as $pr) {
                        $u     = $pr['user'] ?? [];
                        $name  = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
                        $club  = $u['club']['name'] ?? '';
                        $rating= $pr['classification'] ?? ($pr['rating'] ?? 'U');
                        if (!$rating || !preg_match('/^[ABCDEU]/', $rating)) $rating='U';

                        if (!empty($name)) {
                            $results[] = [
                                'name'=>$name,'club'=>$club,'event'=>$evtName,
                                'rating'=>$rating,'url'=>build_search_url($name)
                            ];
                        }
                    }
                }
            } else {
                libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $xp  = new DOMXPath($dom);

                foreach ($xp->query('//div[contains(@class,"card") and .//table[contains(@class,"preregistration-list")]]') as $card) {
                    $evtNode = $xp->query('.//div[contains(@class,"card-header")]//span', $card)->item(0);
                    $evtName = $evtNode ? trim($evtNode->textContent) : '';

                    foreach ($xp->query('.//table[contains(@class,"preregistration-list")]//tbody/tr', $card) as $tr) {
                        $tds = $tr->getElementsByTagName('td');
                        if ($tds->length < 3) continue;
                        $name   = trim($tds->item(1)->textContent);
                        $club   = trim($tds->item(2)->textContent);
                        $rating = trim($tds->item($tds->length-1)->textContent);
                        if ($name==='') continue;
                        if (!$rating || !preg_match('/^[ABCDEU]/', $rating)) $rating='U';

                        $results[] = [
                            'name'=>$name,'club'=>$club,'event'=>$evtName,
                            'rating'=>$rating,'url'=>build_search_url($name)
                        ];
                    }
                }
            }
        }
    }

    /* 2) USA FENCING TEXT ********************************************** */
    // Reverted to the simpler parser from before the last "enhancement" attempt for this section
    if ($usafText !== '') {
        $lines = preg_split('/\\r?\\n/', $usafText);
        $total = count($lines);
        for ($i = 0; $i < $total; $i++) {
            $current_line_text = trim($lines[$i]);

            // Using the more robust name matching and explode limit
            if (strpos($current_line_text, ',') !== false && preg_match('/^[\p{L}\p{M}\s\'-]+,\s*[\p{L}\p{M}\s\'-]+$/u', $current_line_text)) {
                [$last, $first] = array_map('trim', explode(',', $current_line_text, 2));
                $name = trim("$first $last");

                if (empty($name)) {
                    continue;
                }

                $fencer_club = '';
                // Assumes club is 2 lines down from the name line, and not another name
                if ($i + 2 < $total) { 
                    $potentialClubLine = trim($lines[$i + 2]);
                    if (strpos($potentialClubLine, ',') === false && !empty($potentialClubLine)) { 
                         $fencer_club = preg_replace('/#\\d+/', '', $potentialClubLine);
                    }
                }

                $fencer_rating = ''; 
                // Searches name line and next 3 lines for rating
                for ($j = 0; $j <= 3; $j++) { 
                    if (($i + $j) < $total) {
                        if (preg_match('/\\b([ABCDEU][0-9]{0,4})\\b/', trim($lines[$i + $j]), $rating_match)) {
                            $fencer_rating = $rating_match[1];
                            break; 
                        }
                    }
                }
                if (empty($fencer_rating) || !preg_match('/^[ABCDEU]/', $fencer_rating)) {
                    $fencer_rating = 'U'; // Default if not found or invalid
                }

                $results[] = [
                    'name' => $name,
                    'club' => $fencer_club,
                    'event' => '', 
                    'rating' => $fencer_rating,
                    'url' => build_search_url($name)
                ];
            }
        }
    }

    /* 3) DEDUPLICATE *************************************************** */
    // ... (Deduplication logic remains the same as previous version) ...
    $temp_combined_results = $results;
    $seen_name_event_keys = [];
    $unique_by_name_event = [];
    foreach ($temp_combined_results as $row) {
        $event_name = trim($row['event'] ?? '');
        $current_name = trim($row['name'] ?? '');
        if (empty($current_name)) continue;
        $key = $current_name . '|' . $event_name;
        if (!isset($seen_name_event_keys[$key])) {
            $seen_name_event_keys[$key] = true;
            $row['event'] = $event_name;
            $row['name'] = $current_name;
            $unique_by_name_event[] = $row;
        }
    }

    $fencer_has_specific_event = [];
    foreach ($unique_by_name_event as $row) {
        if (!empty($row['event'])) {
            $fencer_has_specific_event[$row['name']] = true;
        }
    }

    $final_results = [];
    foreach ($unique_by_name_event as $row) {
        if (!empty($row['event'])) {
            $final_results[] = $row;
        } else {
            if (!isset($fencer_has_specific_event[$row['name']])) {
                $final_results[] = $row;
            }
        }
    }
    $results = $final_results;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Fencing Profile Linker</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f8fafc; }
  #resultsTable th[data-sortable="true"] { cursor: pointer; position: relative; }
  #resultsTable th[data-sortable="true"] .sort-icon {
    position: absolute;
    right: 8px; /* Adjust as needed */
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.8em;
  }
  #resultsTable th .sort-icon.asc::after { content: "\25B2"; /* UPWARDS BLACK ARROW */ }
  #resultsTable th .sort-icon.desc::after { content: "\25BC"; /* DOWNWARDS BLACK ARROW */ }
</style>
</head>
<body class="container py-4">
<h1 class="mb-4">Fencing Profile Linker</h1>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="post" class="mb-4">
  <div class="mb-3">
    <label class="form-label" for="askfred">AskFRED prereg URL</label>
    <input class="form-control" type="url" id="askfred" name="askfred"
           value="<?= htmlspecialchars($_POST['askfred'] ?? '') ?>"
           placeholder="https://www.askfred.net/tournaments/.../preregistrations">
  </div>
  <div class="mb-3">
    <label class="form-label" for="usaf">USA Fencing entrant text</label>
    <textarea class="form-control" id="usaf" name="usaf" rows="6"
              placeholder="Copy and Paste all of the entrants from the USA Fencing.org event page into this box."><?= htmlspecialchars($_POST['usaf'] ?? '') ?></textarea>
  </div>
  <button class="btn btn-primary" type="submit">Generate Links</button>
  <button type="button" id="clearBtn" class="btn btn-outline-secondary ms-2">Clear Form</button>
</form>

<?php if (!empty($results)): ?>
<div class="mb-3">
  <button id="copyBtn" class="btn btn-secondary me-2">Copy to Clipboard</button>
  <button id="csvBtn"  class="btn btn-success">Download CSV</button>
</div>

<table class="table table-striped" id="resultsTable">
  <thead>
    <tr>
      <th data-sortable="true">Name <span class="sort-icon"></span></th>
      <th data-sortable="true">Club <span class="sort-icon"></span></th>
      <th data-sortable="true">Event <span class="sort-icon"></span></th>
      <th data-sortable="true" data-type="rating">Rating <span class="sort-icon"></span></th>
      <th>Search</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($results as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['name']) ?></td>
    <td><?= htmlspecialchars($r['club']) ?></td>
    <td><?= htmlspecialchars($r['event']) ?></td>
    <td><?= htmlspecialchars($r['rating']) ?></td>
    <td><a href="<?= htmlspecialchars($r['url']) ?>" target="_blank">Open</a></td>
  </tr>
<?php endforeach; ?>
  </tbody>
</table>

<script>window.tableData = <?= json_encode($results) ?>;</script>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(function(){
  $('#clearBtn').on('click', function() {
    $('#askfred').val('');
    $('#usaf').val('');
  });

  function toCSV(rows){ /* ... (unchanged) ... */ 
    const esc = v => `"${String(v??'').replace(/"/g,'""')}"`;
    const header = 'Name,Club,Event,Rating,URL';
    return [header].concat(
      rows.map(r=>[r.name,r.club,r.event,r.rating,r.url].map(esc).join(','))
    ).join('\r\n');
  }

  $('#copyBtn').on('click', () => { /* ... (unchanged) ... */ 
    if (window.tableData && window.tableData.length > 0) {
        navigator.clipboard.writeText(
            window.tableData.map(r => {
                let eventText = r.event ? ` — ${r.event}` : '';
                return `${r.name} ${r.rating ? '(' + r.rating + ')' : '(U)'}${eventText} — ${r.url}`;
            }).join('\n')
        ).then(() => alert('Copied!'));
    } else {
        alert('No data to copy.');
    }
  });

  $('#csvBtn').on('click', ()=>{ /* ... (unchanged) ... */ 
    if (window.tableData && window.tableData.length > 0) {
        const blob = new Blob([toCSV(window.tableData)], {type:'text/csv'});
        const url  = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'fencers.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } else {
        alert('No data to download.');
    }
  });

  // Table Sorting Logic (this refined version should remain)
  const $table = $('#resultsTable');
  const $tbody = $table.find('tbody');

  $table.find('th[data-sortable="true"]').on('click', function() {
    const $th = $(this);
    const columnIndex = $th.index();
    const dataType = $th.data('type') || 'string';
    let currentSortOrder = $th.data('sort-order');

    let newSortOrder;
    if ($th.hasClass('sort-asc')) {
      newSortOrder = 'desc';
    } else { 
      newSortOrder = 'asc';
    }

    $table.find('th[data-sortable="true"]').data('sort-order', null)
        .removeClass('sort-asc sort-desc')
        .find('.sort-icon').removeClass('asc desc');

    $th.data('sort-order', newSortOrder);
    $th.addClass('sort-' + newSortOrder);
    $th.find('.sort-icon').addClass(newSortOrder);


    const rows = $tbody.find('tr').toArray();

    rows.sort(function(a, b) {
      let valA = $(a).find('td').eq(columnIndex).text();
      let valB = $(b).find('td').eq(columnIndex).text();
      let comparisonResult = 0;

      if (dataType === 'rating') {
        const ratingOrder = { 'U': 0, 'E': 1, 'D': 2, 'C': 3, 'B': 4, 'A': 5 };
        let letterA = valA.charAt(0).toUpperCase();
        let letterB = valB.charAt(0).toUpperCase();
        let ratingAVal = ratingOrder[letterA] !== undefined ? ratingOrder[letterA] : -1;
        let ratingBVal = ratingOrder[letterB] !== undefined ? ratingOrder[letterB] : -1;

        if (ratingAVal !== ratingBVal) {
            comparisonResult = ratingAVal - ratingBVal;
        } else {
            const yearA_str = valA.substring(1);
            const yearB_str = valB.substring(1);
            // Ensure parsing only happens if there are characters after the letter
            const yearA = yearA_str.length > 0 && /^\d+$/.test(yearA_str) ? parseInt(yearA_str, 10) : NaN;
            const yearB = yearB_str.length > 0 && /^\d+$/.test(yearB_str) ? parseInt(yearB_str, 10) : NaN;

            if (!isNaN(yearA) && !isNaN(yearB)) {
                // Higher year is better, so for ascending sort (U->A), smaller year should come first.
                // A24 vs A25: yearA=24, yearB=25. 24-25 = -1. A24 comes before A25. Correct.
                comparisonResult = yearA - yearB; 
            } else if (!isNaN(yearA)) { 
                comparisonResult = 1; // valA (with year) is better than valB (no year)
            } else if (!isNaN(yearB)) { 
                comparisonResult = -1; // valB (with year) is better than valA (no year)
            }
        }
      } else { 
        valA = valA.toUpperCase();
        valB = valB.toUpperCase();
        if (valA < valB) comparisonResult = -1;
        if (valA > valB) comparisonResult = 1;
      }

      return newSortOrder === 'asc' ? comparisonResult : -comparisonResult;
    });

    $tbody.empty().append(rows);
  });
});
</script>
</body>
</html>