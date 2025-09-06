<?php
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

$self_script_name = basename(__FILE__);
$default_scan_dir = realpath($_SERVER['DOCUMENT_ROOT']);

$scan_results = [];
$message = '';
$current_scan_path = $default_scan_dir;
$view_file_path = null;
$view_file_content = null;

define('JOOMLA_ADMIN_HEADER_REGEX', '/^\s*<\?php\s*\/\*\*\s*(?:\r\n|\n|\r)\s*\*\s*@package\s+Joomla\.Administrator/is');

function is_suspicious_content($content) {
    $content_no_comments = preg_replace('/^\s*\/\*\*.*?\*\/\s*/s', '', $content, 1);
    $patterns = [
        'eval\s*\(base64_decode\s*\(', 'eval\s*\(\s*gzuncompress\s*\(', 'eval\s*\(\s*gzinflate\s*\(',
        'passthru\s*\(', 'shell_exec\s*\(', 'system\s*\(', 'proc_open\s*\(', 'popen\s*\(', 'assert\s*\(',
        'create_function\s*\(', 'preg_replace\s*\(\s*[\'"].*\/e[\'"]', 'P\.A\.S\.', 'Shell', 'Uploader',
        'Backdoor', 'c99', 'c99shell', 'r57', 'r57shell', 'IndoXploit', 'WSO Shell', 'wso.php',
        'b374k', 'Leafmailer', 'Webadmin', '\$\GLOBALS\[\'[a-zA-Z0-9_]+\'\]\s*\(', '\$[O0Il]{4,}\(',
        'gzuncompress\s*\(base64_decode\s*\(', 'str_rot13\s*\(base64_decode\s*\(', 'move_uploaded_file\s*\(',
        'fwrite\s*\(fopen\s*\(', 'uname -a', 'Webshell', 'pwd', 'raw.githubusercontent.com', '$a = geturlsinfo', '蚁剑', 'eval(gzinflate(base64_decode(',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match('/' . $pattern . '/i', $content_no_comments)) return true;
    }
    return false;
}

function is_suspicious_filename($filename) {
    $patterns = [
        '\.shell\.php$', 'shell[0-9_-]*', 'c99[a-z0-9_-]*', 'r57[a-z0-9_-]*', 'wso[a-z0-9_-]*',
        'b374k[a-z0-9_-]*', 'adminer[0-9_-]*', 'up', 'upload[a-z0-9_-]*', 'xleet', 'alfa[a-z0-9_-]*',
    ];
    $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
    foreach ($patterns as $pattern) {
        if (preg_match('/' . $pattern . '/i', $filename_without_ext)) return true;
        if (preg_match('/' . $pattern . '\.php$/i', $filename)) return true;
    }
    return false;
}

function has_suspicious_params($content, &$details = []) {
    $patterns = [
        '$_GET\s*\[["\']?(?:cmd|exec|shell|pass|upload)["\']?\]' => '$_GET backdoor param',
        '$_POST\s*\[["\']?(?:cmd|exec|shell|pass|upload)["\']?\]' => '$_POST backdoor param',
        '\$_REQUEST\s*\[["\']?(?:cmd|exec|shell|pass|upload)["\']?\]' => '$_REQUEST backdoor param',
        'preg_replace\s*\(.*\/e.*\)' => 'preg_replace /e exploit',
        'base64_decode\s*\(\s*\$_(GET|POST|REQUEST)' => 'Encoded payload from user input',
    ];
    $found = false;
    foreach ($patterns as $pattern => $desc) {
        if (preg_match('/' . $pattern . '/i', $content)) {
            $details[] = $desc;
            $found = true;
        }
    }
    return $found;
}

function scan_directory($dir, &$results, $self_script_name) {
    $dir = realpath($dir);
    if (!$dir || !is_dir($dir) || !is_readable($dir)) return;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iterator as $item) {
            $path = $item->getRealPath();
            $filename = $item->getFilename();
            if ($filename === $self_script_name && dirname($path) === dirname(realpath(__FILE__))) continue;

            if ($item->isFile() && $item->isReadable() && strtolower($item->getExtension()) === 'php') {
                $file_size = $item->getSize();
                $initial_chunk = '';
                $date_modified = @filemtime($path); // Ambil timestamp modifikasi

                if ($file_size > 0 && $file_size < 5000000) {
                    $fp = @fopen($path, 'r');
                    if ($fp) {
                        $initial_chunk = @fread($fp, 256);
                        @fclose($fp);
                    }
                }

                if ($initial_chunk && preg_match(JOOMLA_ADMIN_HEADER_REGEX, $initial_chunk)) {
                    continue;
                }

                $suspicious_by_name = is_suspicious_filename($filename);
                $suspicious_by_content = false;
                $reason_details = [];

               if ($file_size > 0 && $file_size < 2000000) {
                $full_content = @file_get_contents($path);
               if ($full_content !== false) {
                $content_details = [];

               if (is_suspicious_content($full_content, $content_details)) {
                $suspicious_by_content = true;
                $reason_details[] = "Pola kode berbahaya: " . implode(', ', $content_details);
            }

        if (has_suspicious_params($full_content, $content_details)) {
            $suspicious_by_content = true;
            $reason_details[] = "Parameter shell terdeteksi: " . implode(', ', $content_details);
        }
    }
} elseif ($file_size >= 2000000 && $file_size < 5000000) {
    $reason_details[] = "File size potentially large for full content scan";
} elseif ($file_size >= 5000000) {
    $reason_details[] = "File size too large for any content scan";
}
                
                if ($suspicious_by_name || $suspicious_by_content) {
                    $reasons = [];
                    if ($suspicious_by_name) $reasons[] = "Filename";
                    if ($suspicious_by_content) $reasons[] = "Content";
                    if (!empty($reason_details)) $reasons[] = implode('; ', $reason_details);
                    // *** Simpan timestamp modifikasi ***
                    $results[] = ['path' => $path, 'reason' => implode(', ', $reasons), 'modified_timestamp' => $date_modified];
                }
            }
        }
    } catch (Exception $e) { /* Log error if needed */ }
}

// --- Penanganan Request ---
// Handle View File (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['file_path'])) {
    $file_to_view = realpath($_GET['file_path']);
    if (isset($_GET['scan_path_display'])) {
        $current_scan_path = realpath(trim($_GET['scan_path_display']));
        if (!$current_scan_path || !is_dir($current_scan_path)) {
            $current_scan_path = $default_scan_dir;
        }
    }

    $safe_scan_root = realpath($_SERVER['DOCUMENT_ROOT']);
    $is_within_safe_scan_root = ($current_scan_path && strpos($current_scan_path, $safe_scan_root) === 0);
    $is_file_within_current_scan = ($file_to_view && $current_scan_path && strpos($file_to_view, $current_scan_path) === 0);

    if ($file_to_view && file_exists($file_to_view) && is_readable($file_to_view) && strtolower(pathinfo($file_to_view, PATHINFO_EXTENSION)) === 'php' && $is_within_safe_scan_root && $is_file_within_current_scan) {
        $view_file_path = $file_to_view;
        $view_file_content = @file_get_contents($file_to_view);
        if ($view_file_content === false) {
            $message .= '<p class="message error">Error: Tidak dapat membaca konten file ' . htmlspecialchars($view_file_path) . '</p>';
            $view_file_path = null;
        }
    } else {
        $message .= '<p class="message error">Error: Path file PHP untuk dilihat tidak valid, tidak ada, tidak dapat dibaca, atau di luar direktori yang diizinkan.</p>';
    }
    if ($current_scan_path && is_dir($current_scan_path) && is_readable($current_scan_path)) {
         if (strpos($current_scan_path, $safe_scan_root) === 0 || $current_scan_path === $safe_scan_root) {
            scan_directory($current_scan_path, $scan_results, $self_script_name);
            // *** Urutkan hasil setelah scan untuk tampilan view ***
            if (!empty($scan_results)) {
                usort($scan_results, function($a, $b) {
                    return $b['modified_timestamp'] <=> $a['modified_timestamp'];
                });
            }
        }
    }
}

// Handle Scan/Delete (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_scan_path = isset($_POST['scan_path']) && !empty(trim($_POST['scan_path'])) ? realpath(trim($_POST['scan_path'])) : $default_scan_dir;
    if (!$current_scan_path || !is_dir($current_scan_path)) {
        $message .= '<p class="message error">Error: Path pemindaian awal tidak valid.</p>';
        $current_scan_path = $default_scan_dir;
    }

    if (isset($_POST['action'])) {
        $safe_scan_root = realpath($_SERVER['DOCUMENT_ROOT']);
        $is_scan_path_safe = ($current_scan_path && (strpos($current_scan_path, $safe_scan_root) === 0 || $current_scan_path === $safe_scan_root));

        if (!$is_scan_path_safe) {
            $message .= '<p class="message error">Error: Path pemindaian berada di luar direktori web yang diizinkan.</p>';
        } else {
            if ($_POST['action'] === 'scan' || $_POST['action'] === 'delete') { // Proses scan juga untuk delete agar daftar diperbarui
                if (is_readable($current_scan_path)) {
                    scan_directory($current_scan_path, $scan_results, $self_script_name);
                } else {
                    $message .= '<p class="message error">Error: Path pemindaian tidak dapat dibaca.</p>';
                }
            }

            if ($_POST['action'] === 'delete' && isset($_POST['file_path'])) {
                $file_to_delete = realpath($_POST['file_path']);
                $is_file_within_current_scan = ($file_to_delete && strpos($file_to_delete, $current_scan_path) === 0);

                if ($file_to_delete && file_exists($file_to_delete) && strtolower(pathinfo($file_to_delete, PATHINFO_EXTENSION)) === 'php' && $is_file_within_current_scan) {
                    if (unlink($file_to_delete)) {
                        $message .= '<p class="message success">File PHP ' . htmlspecialchars($file_to_delete) . ' berhasil dihapus.</p>';
                        // Pindai ulang setelah penghapusan untuk memperbarui daftar
                        $scan_results = []; // Kosongkan hasil lama
                        if (is_readable($current_scan_path)) {
                            scan_directory($current_scan_path, $scan_results, $self_script_name);
                        }
                    } else {
                        $message .= '<p class="message error">Error: Tidak dapat menghapus file PHP ' . htmlspecialchars($file_to_delete) . '. Periksa izin.</p>';
                    }
                } else {
                    $message .= '<p class="message error">Error: Path file PHP untuk penghapusan tidak valid, tidak ada, bukan PHP, atau di luar direktori yang dipindai.</p>';
                }
            }
            
            // *** Urutkan hasil setelah scan atau delete ***
            if (!empty($scan_results)) {
                usort($scan_results, function($a, $b) {
                    return $b['modified_timestamp'] <=> $a['modified_timestamp']; // Urutkan descending
                });
            }

            if ($_POST['action'] === 'scan' && empty($message)) { // Hanya tampilkan pesan sukses scan jika tidak ada error lain
                 if (empty($scan_results)) {
                    $message .= '<p class="message success">Pemindaian selesai. Tidak ada file PHP mencurigakan ditemukan di ' . htmlspecialchars($current_scan_path) . '.</p>';
                } else {
                    $message .= '<p class="message warning">Pemindaian selesai. Ditemukan ' . count($scan_results) . ' file PHP mencurigakan di ' . htmlspecialchars($current_scan_path) . '.</p>';
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['action']) || $_GET['action'] !== 'view') {
    $current_scan_path = $default_scan_dir;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCAN BURU KEBURU DI AMBIL ORANG (Sort by Date)</title>
    <style>
        /* CSS Styles (tetap sama seperti versi sebelumnya) */
        @import url('https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700&family=Quicksand:wght@400;500;700&display=swap');
        body{background:linear-gradient(135deg, #e3f2fd 0%, #bbdefb 50%, #e3f2fd 100%);color:#555;font-family:'Quicksand', 'M PLUS Rounded 1c', sans-serif;margin:0;padding:20px;display:flex;flex-direction:column;align-items:center;min-height:100vh;overflow-x:hidden}
        .container{background-color:rgba(255,255,255,0.9);padding:25px 35px;border-radius:25px;box-shadow:0 8px 25px rgba(0,0,0,0.1);width:95%;max-width:950px;border:1px solid #f8bbd0;position:relative;z-index:1} /* Max-width increased */
        h1{font-family:'M PLUS Rounded 1c', sans-serif;color:#e91e63;text-align:center;text-shadow:1px 1px 2px rgba(255,255,255,0.7);margin-bottom:30px;font-size:2.5em;letter-spacing:1px}
        .scan-form{display:flex;flex-wrap:wrap;gap:15px;margin-bottom:25px;align-items:center}
        .scan-form label{font-weight:500;color:#ad1457;margin-right:5px}
        .scan-form input[type="text"]{flex-grow:1;padding:12px 15px;border-radius:12px;border:1px solid #f06292;background-color:#fff;color:#444;font-size:1em;box-shadow:inset 0 2px 4px rgba(0,0,0,0.05);transition:all .3s ease}
        .scan-form input[type="text"]:focus{outline:none;border-color:#e91e63;box-shadow:inset 0 2px 4px rgba(0,0,0,0.05), 0 0 8px rgba(233,30,99,0.3)}
        .scan-form input[type="text"]::placeholder{color:#aaa}
        button, .button{color:#fff;border:none;padding:12px 25px;border-radius:12px;cursor:pointer;font-size:1.05em;font-weight:500;font-family:'M PLUS Rounded 1c', sans-serif;text-transform:none;transition:all .3s ease;letter-spacing:.5px; text-decoration: none; display: inline-block;}
        button{background:linear-gradient(45deg, #ff80ab, #f48fb1);box-shadow:0 4px 10px rgba(233,30,99,0.2);}
        button:hover{background:linear-gradient(45deg, #f48fb1, #ff80ab);box-shadow:0 6px 15px rgba(233,30,99,0.3);transform:translateY(-1px)}
        .button.delete{background:linear-gradient(45deg, #ef9a9a, #ffcdd2);box-shadow:0 4px 10px rgba(239,154,154,0.3);padding:8px 18px;font-size:.95em}
        .button.delete:hover{background:linear-gradient(45deg, #ffcdd2, #ef9a9a);box-shadow:0 6px 15px rgba(239,154,154,0.4)}
        .button.view{background:linear-gradient(45deg, #90caf9, #a6d6fa);box-shadow:0 4px 10px rgba(144,202,249,0.3);padding:8px 18px;font-size:.95em; margin-left: 5px;}
        .button.view:hover{background:linear-gradient(45deg, #a6d6fa, #90caf9);box-shadow:0 6px 15px rgba(144,202,249,0.4)}
        .results-container{max-height:450px;overflow-y:auto;border:1px solid #f8bbd0;border-radius:12px;margin-top:20px;background-color:rgba(255,255,255,0.5)}
        .results-table{width:100%;border-collapse:collapse}
        .results-table th, .results-table td{border-bottom:1px solid #fce4ec;padding:10px 12px;text-align:left;word-break:break-all;font-size:.9em} /* Padding and font-size adjusted */
        .results-table th{background-color:#fce4ec;color:#c2185b;font-weight:500;position:sticky;top:0;z-index:1}
        .results-table tr:nth-child(even) td{background-color:#fff8f9}
        .results-table tr:hover td{background-color:#fdeaf0}
        .results-table td.actions{text-align:center;min-width:170px; white-space: nowrap;} /* Adjusted min-width */
        .message{padding:15px 20px;border-radius:12px;margin:20px 0;text-align:center;font-weight:500;font-size:1em;border-width:1px;border-style:solid;box-shadow:0 2px 5px rgba(0,0,0,0.05)}
        .message.success{background-color:#e8f5e9;border-color:#a5d6a7;color:#388e3c}
        .message.warning{background-color:#fff3e0;border-color:#ffcc80;color:#f57c00}
        .message.error{background-color:#ffebee;border-color:#ef9a9a;color:#d32f2f}
        .warning-banner{background-color:#fff9c4;color:#795548;padding:15px 20px;border-radius:12px;margin-bottom:25px;text-align:center;border:1px solid #fff176;font-size:.95em;box-shadow:0 2px 8px rgba(255,235,59,0.2)}
        .warning-banner strong{color:#c62828;font-weight:700}
        .warning-banner code{background-color:rgba(0,0,0,0.05);padding:2px 5px;border-radius:4px;font-family:monospace;color:#555}
        .results-container::-webkit-scrollbar{width:8px}
        .results-container::-webkit-scrollbar-track{background:#fce4ec;border-radius:8px}
        .results-container::-webkit-scrollbar-thumb{background:#f06292;border-radius:8px}
        .results-container::-webkit-scrollbar-thumb:hover{background:#e91e63}
        .view-content-container{margin-top:30px;padding:20px;background-color:rgba(255,255,255,0.8);border:1px solid #f06292;border-radius:15px;box-shadow:0 4px 15px rgba(0,0,0,0.08)}
        .view-content-container h3{color:#c2185b;margin-top:0;margin-bottom:15px;font-size:1.3em;word-break:break-all;}
        .view-content-container pre{background-color:#fff8f9;padding:15px;border-radius:10px;border:1px solid #fce4ec;color:#444;white-space:pre-wrap;word-wrap:break-word;max-height:500px;overflow-y:auto;font-size:0.9em;line-height:1.6;}
        .view-content-container pre::-webkit-scrollbar{width:6px}
        .view-content-container pre::-webkit-scrollbar-thumb{background:#f06292;}
    </style>
</head>
<body>
    <div class="container">
        <h1>SCAN BURU KEBURU DI AMBIL ORANG (Sort by Date)</h1>

        <div class="warning-banner">
            <strong>PERINGATAN PENTING:</strong> Skrip ini dapat <strong>MENGHAPUS FILE</strong>.
            Gunakan dengan <strong>SANGAT HATI-HATI</strong>.
            <br>Selalu <strong>BACKUP WEBSITE ANDA</strong> sebelum menggunakan.
            Deteksi mungkin tidak sempurna.
            <br><strong>HAPUS SKRIP INI (<code><?php echo htmlspecialchars($self_script_name); ?></code>) SETELAH SELESAI DIGUNAKAN.</strong>
        </div>

        <?php if (!empty($message)) echo $message; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="scan-form">
            <label for="scan_path_input">Path untuk Dipindai (Hanya File .php):</label>
            <input type="text" id="scan_path_input" name="scan_path" 
                   placeholder="Contoh: <?php echo htmlspecialchars($default_scan_dir); ?>" 
                   value="<?php echo htmlspecialchars($current_scan_path); ?>" required>
            <button type="submit" name="action" value="scan">Pindai Direktori</button>
        </form>

        <?php if (!empty($scan_results)): ?>
            <h3>File PHP Mencurigakan Ditemukan: <?php echo count($scan_results); ?></h3>
            <div class="results-container">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Path File PHP</th>
                            <th>Tgl. Modifikasi</th>
                            <th>Alasan</th>
                            <th style="min-width: 170px;">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scan_results as $file_info): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($file_info['path']); ?></td>
                                <td><?php echo $file_info['modified_timestamp'] ? date('Y-m-d H:i:s', $file_info['modified_timestamp']) : 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars($file_info['reason']); ?></td>
                                <td class="actions">
                                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" style="display:inline-block;">
                                        <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($file_info['path']); ?>">
                                        <input type="hidden" name="scan_path" value="<?php echo htmlspecialchars($current_scan_path); ?>">
                                        <button type="submit" name="action" value="delete" class="button delete" 
                                                onclick="return confirm('PERINGATAN!\nAnda yakin ingin menghapus file PHP ini secara permanen?\n\n<?php echo htmlspecialchars(addslashes($file_info['path'])); ?>\n\nTindakan ini tidak dapat dibatalkan!');">
                                            Hapus
                                        </button>
                                    </form>
                                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?action=view&file_path=<?php echo urlencode($file_info['path']); ?>&scan_path_display=<?php echo urlencode($current_scan_path); ?>" 
                                       class="button view">
                                        Lihat
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan' && empty($message) && empty($scan_results)): ?>
             <p class="message success">Tidak ada file PHP mencurigakan yang ditemukan di path yang ditentukan.</p>
        <?php endif; ?>

        <?php if (isset($view_file_content)): ?>
            <div class="view-content-container">
                <h3>Konten File: <?php echo htmlspecialchars($view_file_path); ?></h3>
                <pre><?php echo htmlspecialchars($view_file_content); ?></pre>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>
