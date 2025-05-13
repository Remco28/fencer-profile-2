<?php
/* =========================================================================
   Fencing Profile Linker — clean build, May 2025
   ========================================================================= */
$results = [];
$error   = null;

function build_search_url(string $name): string {
    return "https://fencingtracker.com/search?s=" . urlencode($name);
}

/* --------------------------------------------------------------------- */
/*  Main POST handler                                                    */
/* --------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $askfredUrl = trim($_POST['askfred'] ?? '');
    $usafText   = trim($_POST['usaf']   ?? '');

    /* 1) ASKFRED PREREG LIST ******************************************* */
    if ($askfredUrl !== '') {
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
            file_put_contents('debug.html', $html);   // snapshot for debugging

            /* --- A. New‑site JSON blob -------------------------------- */
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

                        $results[] = [
                            'name'=>$name,'club'=>$club,'event'=>$evtName,
                            'rating'=>$rating,'url'=>build_search_url($name)
                        ];
                    }
                }

            /* --- B. Old‑site card tables ------------------------------ */
            } else {
                libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                $dom->loadHTML($html);
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

                        $results[] = [
                            'name'=>$name,'club'=>$club,'event'=>$evtName,
                            'rating'=>$rating,'url'=>build_search_url($name)
                        ];
                    }
                }
            }
        }
    }

    /* 2) USA FENCING TEXT ********************************************** */
    if ($usafText !== '') {
        $lines = preg_split('/\\r?\\n/', $usafText);
        $total = count($lines);
        for ($i=0;$i<$total;$i++){
            $line = trim($lines[$i]);
            if (strpos($line, ',') !== false) {
                $club = ($i+2<$total)?preg_replace('/#\\d+/', '', trim($lines[$i+2])):'';
                $rating='';
                for ($j=1;$j<=3&&$i+$j<$total;$j++){
                    if (preg_match('/\\b[ABCDEU]\\d{0,4}\\b/', $lines[$i+$j], $m)) { $rating=$m[0]; break; }
                }
                [$last,$first]=array_map('trim', explode(',', $line)+['','']);
                $name=trim("$first $last");

                $results[]=[
                    'name'=>$name,'club'=>$club,'event'=>'',
                    'rating'=>$rating,'url'=>build_search_url($name)
                ];
            }
        }
    }

    /* 3) DEDUPLICATE (Name + Event) *********************************** */
    $seen = [];
    $unique = [];
    foreach ($results as $row) {
        $k = $row['name'].'|'.$row['event'];
        if (!isset($seen[$k])) { $seen[$k]=1; $unique[]=$row; }
    }
    $results = $unique;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Fencing Profile Linker</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f8fafc;}</style>
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
              placeholder="Paste entrant list here"><?= htmlspecialchars($_POST['usaf'] ?? '') ?></textarea>
  </div>
  <button class="btn btn-primary" type="submit">Generate Links</button>
</form>

<?php if ($results): ?>
<div class="mb-3">
  <button id="copyBtn" class="btn btn-secondary me-2">Copy to Clipboard</button>
  <button id="csvBtn"  class="btn btn-success">Download CSV</button>
  <a href="/debug.html" class="btn btn-outline-info ms-2" target="_blank">View debug.html</a>
</div>

<table class="table table-striped">
<thead><tr><th>Name</th><th>Club</th><th>Event</th><th>Rating</th><th>Search</th></tr></thead>
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
// CSV + clipboard helpers
function toCSV(rows){
  const esc = v => `"${String(v??'').replace(/"/g,'""')}"`;
  const header = 'Name,Club,Event,Rating,URL';
  return [header].concat(
    rows.map(r=>[r.name,r.club,r.event,r.rating,r.url].map(esc).join(','))
  ).join('\r\n');   // real CRLF
}

$(function(){
  $('#copyBtn').on('click', () => navigator.clipboard.writeText(
    window.tableData.map(r=>`${r.name} (${r.rating||'U'}) — ${r.event}`).join('\n')
  ).then(()=>alert('Copied!')));

  $('#csvBtn').on('click', ()=>{
    const blob = new Blob([toCSV(window.tableData)], {type:'text/csv'});
    const url  = URL.createObjectURL(blob);
    $('<a>').attr({href:url,download:'fencers.csv'})[0].click();
    URL.revokeObjectURL(url);
  });
});
</script>
</body>
</html>
