<?php
/* =========================================================================
   Fencing Profile Linker
   ========================================================================= */
$results = [];
$error   = null;

function build_search_url(string $name): string {
    return "https://fencingtracker.com/search?s=" . urlencode($name);
}

// Function to transform a FencingTimeLive page URL to its data/JSON URL
function transformFtlUrlToDataUrl(string $pageUrl): ?string {
    $pageUrl = rtrim($pageUrl, '/'); // Remove trailing slash for consistency

    // If it's already a data URL, ensure it has a query string (e.g., for sort) or add one
    if (strpos($pageUrl, '/data/') !== false) {
        if (strpos($pageUrl, '?') === false) {
            return $pageUrl . '?sort=name'; // Add default sort if no params
        }
        // Check if sort=name is already there, if not, append it.
        if (strpos($pageUrl, 'sort=') === false) {
             return $pageUrl . (strpos($pageUrl, '?') ? '&' : '?') . 'sort=name';
        }
        return $pageUrl; 
    }

    // Pattern 1: /events/competitors/EVENT_ID (EVENT_ID is 32 hex chars)
    $pattern_competitors = '~^(https?://www\.fencingtimelive\.com/events/competitors/)([a-fA-F0-9]{32})(?:[/?#].*)?$~i';
    if (preg_match($pattern_competitors, $pageUrl, $matches)) {
        return rtrim($matches[1], '/') . '/data/' . $matches[2] . '?sort=name';
    }

    // Pattern 2: /tournaments/details/EVENT_ID/... (EVENT_ID is 32 hex chars)
    $pattern_tournaments_details = '~^(https?://www\.fencingtimelive\.com/tournaments/details/)([a-fA-F0-9]{32})(?:[/?#].*)?$~i';
    if (preg_match($pattern_tournaments_details, $pageUrl, $matches)) {
        return 'https://www.fencingtimelive.com/events/competitors/data/' . $matches[2] . '?sort=name';
    }

    // Pattern 3: Generic /events/ANY_PATH_ENDING_IN_EVENT_ID (EVENT_ID is 32 hex chars)
    $pattern_events_generic_id = '~^(https?://www\.fencingtimelive\.com/events/(?:results|pools|seeding|info)/)([a-fA-F0-9]{32})(?:[/?#].*)?$~i';
    if (preg_match($pattern_events_generic_id, $pageUrl, $matches)) {
         return 'https://www.fencingtimelive.com/events/competitors/data/' . $matches[2] . '?sort=name';
    }

    // Fallback: if the event ID is simply the last segment of a path like /events/EVENT_ID or /pools/EVENT_ID etc.
    $pattern_last_segment_id = '~^(https?://www\.fencingtimelive\.com/(?:events|pools|brackets|tournaments/details)/)([a-fA-F0-9]{32})(?:[/?#].*)?$~i';
    if (preg_match($pattern_last_segment_id, $pageUrl, $matches)) {
        return 'https://www.fencingtimelive.com/events/competitors/data/' . $matches[2] . '?sort=name';
    }

    // If no transformation matched, it's possible the user pasted a direct data URL without query params
    if (strpos($pageUrl, '/data/') !== false && strpos($pageUrl, 'fencingtimelive.com') !== false && strpos($pageUrl, '?') === false) {
        return $pageUrl . '?sort=name';
    }

    return null; // Could not reliably transform
}


/* --------------------------------------------------------------------- */
/* Main POST handler                                                    */
/* --------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $askfredUrl = trim($_POST['askfred'] ?? '');
    $usafText   = trim($_POST['usaf']   ?? '');
    $ftlUserUrl = trim($_POST['ftl'] ?? ''); 

    /* 1) ASKFRED PREREG LIST ******************************************* */
    if ($askfredUrl !== '') {
        $ch_askfred = curl_init($askfredUrl);
        curl_setopt_array($ch_askfred, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', CURLOPT_TIMEOUT => 20]);
        $html_askfred = curl_exec($ch_askfred);
        if (curl_errno($ch_askfred)) { $error = 'AskFRED cURL error: '.curl_error($ch_askfred); $html_askfred=false; }
        curl_close($ch_askfred);
        if ($html_askfred !== false) { 
            if (preg_match('/__PRELOADED_STATE__\\s*=\\s*({.+?});/s', $html_askfred, $m)) {
                $json_af = json_decode($m[1], true);
                foreach (($json_af['entities']['events'] ?? []) as $event_af) {
                    $evtName_af = $event_af['name'] ?? '';
                    foreach ($event_af['preregistrations'] ?? [] as $pr_af) {
                        $u_af     = $pr_af['user'] ?? [];
                        $name_af  = trim(($u_af['first_name'] ?? '').' '.($u_af['last_name'] ?? ''));
                        $club_af  = $u_af['club']['name'] ?? '';
                        $rating_af= $pr_af['classification'] ?? ($pr_af['rating'] ?? 'U');
                        if (!$rating_af || !preg_match('/^[ABCDEU]/', $rating_af)) $rating_af='U';
                        if (!empty($name_af)) {
                            $results[] = ['name'=>$name_af,'club'=>$club_af,'event'=>$evtName_af,'rating'=>$rating_af,'rank'=>'','url'=>build_search_url($name_af)];
                        }
                    }
                }
            } else {
                libxml_use_internal_errors(true); $dom_askfred = new DOMDocument(); @$dom_askfred->loadHTML($html_askfred); $xp_askfred  = new DOMXPath($dom_askfred);
                foreach ($xp_askfred->query('//div[contains(@class,"card") and .//table[contains(@class,"preregistration-list")]]') as $card_af) {
                    $evtNode_af = $xp_askfred->query('.//div[contains(@class,"card-header")]//span', $card_af)->item(0); $evtName_af = $evtNode_af ? trim($evtNode_af->textContent) : '';
                    foreach ($xp_askfred->query('.//table[contains(@class,"preregistration-list")]//tbody/tr', $card_af) as $tr_af) {
                        $tds_af = $tr_af->getElementsByTagName('td'); if ($tds_af->length < 3) continue;
                        $name_af   = trim($tds_af->item(1)->textContent); $club_af   = trim($tds_af->item(2)->textContent); $rating_af = trim($tds_af->item($tds_af->length-1)->textContent);
                        if ($name_af==='') continue; if (!$rating_af || !preg_match('/^[ABCDEU]/', $rating_af)) $rating_af='U';
                        $results[] = ['name'=>$name_af,'club'=>$club_af,'event'=>$evtName_af,'rating'=>$rating_af,'rank'=>'','url'=>build_search_url($name_af)];
                    }
                }
            }
        }
    }

    /* 2) USA FENCING TEXT ********************************************** */
    if ($usafText !== '') {
        $lines = preg_split('/\\r?\\n/', $usafText); $total = count($lines);
        for ($i = 0; $i < $total; $i++) {
            $current_line_text = trim($lines[$i]);
            if (strpos($current_line_text, ',') !== false && preg_match('/^[\p{L}\p{M}\s\'-]+,\s*[\p{L}\p{M}\s\'-]+$/u', $current_line_text)) {
                [$last, $first] = array_map('trim', explode(',', $current_line_text, 2)); $name = trim("$first $last");
                if (empty($name)) { continue; } $fencer_club = '';
                if ($i + 2 < $total) { $potentialClubLine = trim($lines[$i + 2]); if (strpos($potentialClubLine, ',') === false && !empty($potentialClubLine)) { $fencer_club = preg_replace('/#\\d+/', '', $potentialClubLine);}}
                $fencer_rating = ''; 
                for ($j = 0; $j <= 3; $j++) { if (($i + $j) < $total) { if (preg_match('/\\b([ABCDEU][0-9]{0,4})\\b/', trim($lines[$i + $j]), $rating_match)) { $fencer_rating = $rating_match[1]; break; }}}
                if (empty($fencer_rating) || !preg_match('/^[ABCDEU]/', $fencer_rating)) { $fencer_rating = 'U'; }
                $results[] = ['name' => $name,'club' => $fencer_club,'event' => '','rating' => $fencer_rating,'rank' => '','url' => build_search_url($name)];
            }
        }
    }

    /* 3) FENCINGTIMELIVE URL (JSON) ************************************* */
    if ($ftlUserUrl !== '') {
        $ftlDataUrl = transformFtlUrlToDataUrl($ftlUserUrl);
        $event_name_ftl = "FTL Event"; 

        if ($ftlDataUrl) {
            $ch_ftl_html_title = curl_init($ftlUserUrl); 
            curl_setopt_array($ch_ftl_html_title, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
            $html_for_title = curl_exec($ch_ftl_html_title);
            curl_close($ch_ftl_html_title);

            if ($html_for_title) {
                libxml_use_internal_errors(true); $dom_ftl_title = new DOMDocument(); @$dom_ftl_title->loadHTML($html_for_title); $xp_ftl_title = new DOMXPath($dom_ftl_title);
                $h1Node = $xp_ftl_title->query('//h1[contains(@class, "page-title")] | //h1')->item(0);
                if ($h1Node) {
                    $raw_h1_text = trim($h1Node->textContent);
                    $temp_event_name = preg_replace('/\s*\/\s*(Competitors|Seeding|Results|Pools|DEs|Team Standings)\s*$/i', '', $raw_h1_text);
                    if(!empty(trim($temp_event_name))) $event_name_ftl = trim($temp_event_name);
                }
                if (empty($event_name_ftl) || $event_name_ftl === "FTL Event" || str_word_count($event_name_ftl) < 2 ) { 
                    $titleNode = $xp_ftl_title->query('//title')->item(0);
                    if ($titleNode) {
                        $temp_event_name_title = trim($titleNode->textContent);
                        $temp_event_name_title = preg_replace('/ - Fencing Time Live$/i', '', $temp_event_name_title);
                        $temp_event_name_title = preg_replace('/ - (Results|Seeding|Pools|DEs|Team Standings|Competitors)$/i', '', $temp_event_name_title);
                        if(!empty(trim($temp_event_name_title))) $event_name_ftl = trim($temp_event_name_title);
                    }
                }
            }

            $ch_ftl_json = curl_init($ftlDataUrl);
            $headers_ftl = ['Accept: application/json, text/javascript, */*; q=0.01', 'X-Requested-With: XMLHttpRequest'];
            curl_setopt_array($ch_ftl_json, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers_ftl, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
            $json_response_ftl = curl_exec($ch_ftl_json);
            $curl_ftl_json_error_num = curl_errno($ch_ftl_json); $curl_ftl_json_error_msg = curl_error($ch_ftl_json);
            curl_close($ch_ftl_json);

            if ($curl_ftl_json_error_num === 0 && $json_response_ftl) {
                $data_ftl = json_decode($json_response_ftl, true);
                if (is_array($data_ftl)) {
                    $ftl_fencers_added = 0;
                    foreach ($data_ftl as $fencer_entry) {
                        $name_val = $fencer_entry['name'] ?? ''; 
                        if (!empty($name_val) && strpos($name_val, ',') === false) {
                            $parts = explode(' ', $name_val);
                            if (count($parts) >= 2) {
                                $last_name_ftl = array_shift($parts); 
                                $first_middle_ftl = implode(' ', $parts);
                                $name_val = trim("$first_middle_ftl $last_name_ftl");
                            }
                        } elseif (strpos($name_val, ',') !== false) {
                             [$last_ftl, $first_parts_ftl] = explode(',', $name_val, 2);
                             $name_val = trim($first_parts_ftl) . " " . trim($last_ftl);
                        }

                        $club_val = $fencer_entry['club1'] ?? ($fencer_entry['clubNames'] ?? '');
                        $rating_val = $fencer_entry['weaponRating'] ?? 'U';
                        if (empty($rating_val) || !preg_match('/^[ABCDEU]/', $rating_val)) $rating_val = 'U';
                        $rank_val = $fencer_entry['rank'] ?? '';

                        if (!empty($name_val)) {
                            $results[] = ['name' => $name_val, 'club' => $club_val, 'event'  => $event_name_ftl, 'rating' => $rating_val, 'rank'   => $rank_val, 'url' => build_search_url($name_val)];
                            $ftl_fencers_added++;
                        }
                    }
                    if ($ftl_fencers_added === 0 && empty($error) && !empty($data_ftl) ) {
                         $error = ($error ? $error.'<br>' : '') . "FencingTimeLive: JSON data received, but no fencers were extracted (check JSON structure or parsing logic).";
                    } elseif (empty($data_ftl) && $ftl_fencers_added === 0 && empty($error) ) {
                         $error = ($error ? $error.'<br>' : '') . "FencingTimeLive: JSON data was empty or not in the expected array format for the URL: " . htmlspecialchars($ftlDataUrl);
                    }
                } else {
                    $error = ($error ? $error.'<br>' : '') . "FencingTimeLive: Invalid JSON response received from " . htmlspecialchars($ftlDataUrl);
                }
            } else {
                $error = ($error ? $error.'<br>' : '') . "FencingTimeLive cURL error for JSON data ($curl_ftl_json_error_num) from ".htmlspecialchars($ftlDataUrl).": " . $curl_ftl_json_error_msg;
            }
        } else {
            $error = ($error ? $error.'<br>' : '') . "FencingTimeLive: Could not transform the provided page URL into a data URL. Please provide a URL like '.../events/competitors/EVENT_ID'. Input was: " . htmlspecialchars($ftlUserUrl);
        }
    }

    /* 4) DEDUPLICATE *************************************************** */
    $temp_combined_results = $results; 
    $seen_name_event_keys = []; $unique_by_name_event = [];
    foreach ($temp_combined_results as $row) {
        $event_name = trim($row['event'] ?? ''); $current_name = trim($row['name'] ?? '');
        if (empty($current_name)) continue;
        $key = $current_name . '|' . $event_name;
        if (!isset($seen_name_event_keys[$key])) {
            $seen_name_event_keys[$key] = true;
            $row['event'] = $event_name; $row['name'] = $current_name;
            $unique_by_name_event[] = $row;
        }
    }
    $fencer_has_specific_event = [];
    foreach ($unique_by_name_event as $row) {
        if (!empty($row['event'])) { $fencer_has_specific_event[$row['name']] = true; }
    }
    $final_results = [];
    foreach ($unique_by_name_event as $row) {
        if (!empty($row['event'])) {
            $final_results[] = $row;
        } else {
            if (!isset($fencer_has_specific_event[$row['name']])) { $final_results[] = $row; }
        }
    }
    $results = $final_results;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>FencingTracker Profile Links</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f8fafc; }
  #resultsTable th[data-sortable="true"] { cursor: pointer; position: relative; }
  #resultsTable th[data-sortable="true"] .sort-icon { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); font-size: 0.8em; }
  #resultsTable th .sort-icon.asc::after { content: "\25B2"; }
  #resultsTable th .sort-icon.desc::after { content: "\25BC"; }
</style>
</head>
<body class="container py-4">
<h1 class="mb-4">Fencing Profile Linker</h1>
<div class="mb-4">
  <a href="instructions.html"
     class="btn btn-secondary shadow-sm fw-semibold"
     style="font-size:1rem;">
    📄 View Instructions
  </a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= nl2br(htmlspecialchars($error)) ?></div><?php endif; ?>

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
  <div class="mb-3">
    <label class="form-label" for="ftl">FencingTimeLive URL (FENCERS tab)</label>
    <input class="form-control" type="url" id="ftl" name="ftl"
           value="<?= htmlspecialchars($_POST['ftl'] ?? '') ?>"
           placeholder="e.g., https://www.fencingtimelive.com/events/competitors/EVENT_ID">
  </div>
  <button class="btn btn-primary" type="submit">Generate Links</button>
  <button type="button" id="clearBtn" class="btn btn-outline-secondary ms-2">Clear Form</button>
</form>

<?php if (!empty($results)): ?>
<div class="mb-3">
  <button id="copyBtn" class="btn btn-secondary me-2">Copy to Clipboard</button>
  <button id="csvBtn" class="btn btn-warning me-2">Download CSV</button>
  <button id="xlsxBtn" class="btn btn-success">Download Excel (.xlsx)</button>
</div>

<table class="table table-striped" id="resultsTable">
  <thead>
    <tr>
      <th data-sortable="true">Name <span class="sort-icon"></span></th>
      <th data-sortable="true">Club <span class="sort-icon"></span></th>
      <th data-sortable="true">Event <span class="sort-icon"></span></th>
      <th data-sortable="true" data-type="rating">Rating <span class="sort-icon"></span></th>
      <th data-sortable="true" data-type="numeric">Rank <span class="sort-icon"></span></th>
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
    <td><?= htmlspecialchars($r['rank'] ?? '') ?></td>
    <td><a href="<?= htmlspecialchars($r['url']) ?>" target="_blank">Open</a></td>
  </tr>
<?php endforeach; ?>
  </tbody>
</table>
<script>window.tableData = <?= json_encode(array_values($results)) ?>;</script>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- SheetJS for XLSX export -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
$(function(){
  $('#clearBtn').on('click', function() { 
    $('#askfred').val(''); 
    $('#usaf').val(''); 
    $('#ftl').val(''); 
  });

  function toCSV(rows){
    const esc = v => `"${String(v??'').replace(/"/g,'""')}"`;
    const header = 'Name,Club,Event,Rating,Rank,URL';
    return [header].concat(
      rows.map(r=>[r.name,r.club,r.event,r.rating,r.rank??'',r.url].map(esc).join(','))
    ).join('\r\n');
  }

  $('#copyBtn').on('click', () => { 
    if (window.tableData && window.tableData.length > 0) {
        navigator.clipboard.writeText(
            window.tableData.map(r => {
                let eventText = r.event ? ` — ${r.event}` : '';
                let rankText = (r.rank !== null && r.rank !== '') ? ` (Rank: ${r.rank})` : '';
                return `${r.name} ${r.rating ? '(' + r.rating + ')' : '(U)'}${rankText}${eventText} — ${r.url}`;
            }).join('\n')
        ).then(() => alert('Copied!'));
    } else { alert('No data to copy.'); }
  });

  $('#csvBtn').on('click', ()=>{ 
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
    } else { alert('No data to download.'); }
  });

  // XLSX export logic using SheetJS
  $('#xlsxBtn').on('click', function () {
    if (window.tableData && window.tableData.length > 0) {
        // Prepare worksheet data (array of arrays, with header row first)
        const ws_data = [
            ['Name', 'Club', 'Event', 'Rating', 'Rank', 'URL'],
            ...window.tableData.map(r => [
                r.name,
                r.club,
                r.event,
                r.rating,
                r.rank ?? '',
                r.url
            ])
        ];
        // Create worksheet and workbook
        const ws = XLSX.utils.aoa_to_sheet(ws_data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Fencers');
        // Write file and trigger download
        XLSX.writeFile(wb, 'fencers.xlsx');
    } else {
        alert('No data to download.');
    }
  });

  const $table = $('#resultsTable');
  const $tbody = $table.find('tbody');
  $table.find('th[data-sortable="true"]').on('click', function() {
    const $th = $(this);
    const columnIndex = $th.index();
    const dataType = $th.data('type') || 'string';
    let newSortOrder;
    if ($th.hasClass('sort-asc')) { newSortOrder = 'desc'; } 
    else { newSortOrder = 'asc'; }

    $table.find('th[data-sortable="true"]').removeClass('sort-asc sort-desc').find('.sort-icon').removeClass('asc desc');
    $th.addClass('sort-' + newSortOrder).find('.sort-icon').addClass(newSortOrder);

    const rows = $tbody.find('tr').toArray();
    rows.sort(function(a, b) {
      let valA_text = $(a).find('td').eq(columnIndex).text();
      let valB_text = $(b).find('td').eq(columnIndex).text();
      let valA = valA_text;
      let valB = valB_text;
      let comparisonResult = 0;

      if (dataType === 'rating') {
        const ratingOrder = { 'U': 0, 'E': 1, 'D': 2, 'C': 3, 'B': 4, 'A': 5 };
        let letterA = valA.charAt(0).toUpperCase(); let letterB = valB.charAt(0).toUpperCase();
        let ratingAVal = ratingOrder[letterA] !== undefined ? ratingOrder[letterA] : -1;
        let ratingBVal = ratingOrder[letterB] !== undefined ? ratingOrder[letterB] : -1;
        if (ratingAVal !== ratingBVal) { comparisonResult = ratingAVal - ratingBVal; } 
        else {
            const yearA_str = valA.substring(1); const yearB_str = valB.substring(1);
            const yearA = yearA_str.length > 0 && /^\d+$/.test(yearA_str) ? parseInt(yearA_str, 10) : NaN;
            const yearB = yearB_str.length > 0 && /^\d+$/.test(yearB_str) ? parseInt(yearB_str, 10) : NaN;
            if (!isNaN(yearA) && !isNaN(yearB)) { comparisonResult = yearA - yearB; } 
            else if (!isNaN(yearA)) { comparisonResult = 1; } 
            else if (!isNaN(yearB)) { comparisonResult = -1; }
        }
      } else if (dataType === 'numeric') { 
          valA = parseFloat(valA_text.replace(/[^0-9.-]+/g,"")); 
          valB = parseFloat(valB_text.replace(/[^0-9.-]+/g,""));
          if (isNaN(valA) && isNaN(valB)) comparisonResult = 0;
          else if (isNaN(valA)) comparisonResult = (newSortOrder === 'asc' ? 1 : -1); 
          else if (isNaN(valB)) comparisonResult = (newSortOrder === 'asc' ? -1 : 1);
          else comparisonResult = valA - valB;
      } else { 
        valA = valA_text.toUpperCase(); valB = valB_text.toUpperCase();
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
