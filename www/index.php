<?php
/**
 * Stack health page: proves PHP is served by Apache, which extensions are
 * loaded, and whether the MySQL and SMTP services are reachable from PHP.
 *
 * Replace this file with your project, or upload over it through FTP.
 */
declare(strict_types=1);

$extensions = ['pdo_mysql', 'mysqli', 'gd', 'zip', 'intl', 'opcache', 'curl', 'mbstring'];

$dbStatus = 'not configured';
$dbDetail = '';
$dbHost = getenv('DB_HOST') ?: '';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASSWORD') ?: '';

if ($dbHost !== '') {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $dbHost, getenv('DB_PORT') ?: '3306', $dbName),
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $dbStatus = 'connected';
        $dbDetail = sprintf('%s@%s/%s — MySQL %s', $dbUser, $dbHost, $dbName, $pdo->query('SELECT VERSION()')->fetchColumn());
    } catch (PDOException $e) {
        $dbStatus = 'unreachable';
        $dbDetail = $e->getMessage();
    }
}

$mailStatus = 'not configured';
$mailDetail = '';
$mailHost = getenv('MAIL_HOST') ?: '';
$mailPort = (int) (getenv('MAIL_PORT') ?: 0);

if ($mailHost !== '' && $mailPort > 0) {
    $socket = @fsockopen($mailHost, $mailPort, $errno, $errstr, 2);
    if ($socket !== false) {
        // Reading the greeting proves a mail server answered, not merely that a
        // port is open somewhere.
        stream_set_timeout($socket, 2);
        $greeting = trim((string) fgets($socket, 256));
        fclose($socket);
        $mailStatus = 'reachable';
        $mailDetail = sprintf('%s:%d — %s', $mailHost, $mailPort, $greeting);
    } else {
        $mailStatus = 'unreachable';
        $mailDetail = sprintf('%s:%d — %s', $mailHost, $mailPort, $errstr);
    }
}

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$uploads = [];
if (is_dir($docRoot)) {
    foreach (scandir($docRoot) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..' && $entry !== 'index.php') {
            $uploads[] = $entry;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES) ?> stack</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 15px/1.55 ui-sans-serif, system-ui, sans-serif; margin: 2.5rem auto; max-width: 44rem; padding: 0 1.25rem; }
  h1 { font-size: 1.4rem; margin-bottom: .25rem; }
  h2 { font-size: 1rem; margin-top: 2rem; text-transform: uppercase; letter-spacing: .06em; opacity: .65; }
  table { border-collapse: collapse; width: 100%; }
  td { padding: .3rem .6rem .3rem 0; vertical-align: top; border-bottom: 1px solid rgba(128,128,128,.25); }
  td:first-child { white-space: nowrap; font-family: ui-monospace, monospace; }
  .ok { color: #17803d; } .bad { color: #b42318; } .warn { color: #b54708; }
  code { font-family: ui-monospace, monospace; }
  .hint { margin-top: 2rem; padding: .75rem 1rem; border-left: 3px solid rgba(128,128,128,.5); opacity: .85; }
</style>
</head>
<body>
<h1>Apache + PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES) ?></h1>
<p>Server API: <code><?= htmlspecialchars(PHP_SAPI, ENT_QUOTES) ?></code> ·
   Document root: <code><?= htmlspecialchars($docRoot, ENT_QUOTES) ?></code></p>

<h2>Extensions</h2>
<table>
<?php foreach ($extensions as $extension): ?>
  <tr>
    <td><?= htmlspecialchars($extension, ENT_QUOTES) ?></td>
    <td class="<?= extension_loaded($extension) ? 'ok' : 'bad' ?>">
      <?= extension_loaded($extension) ? 'loaded' : 'missing' ?>
    </td>
  </tr>
<?php endforeach; ?>
</table>

<h2>Database</h2>
<table>
  <tr>
    <td>MySQL</td>
    <td class="<?= $dbStatus === 'connected' ? 'ok' : ($dbStatus === 'unreachable' ? 'bad' : 'warn') ?>">
      <?= htmlspecialchars($dbStatus, ENT_QUOTES) ?>
    </td>
  </tr>
  <?php if ($dbDetail !== ''): ?>
  <tr><td></td><td><?= htmlspecialchars($dbDetail, ENT_QUOTES) ?></td></tr>
  <?php endif; ?>
</table>

<h2>Mail</h2>
<table>
  <tr>
    <td>SMTP</td>
    <td class="<?= $mailStatus === 'reachable' ? 'ok' : ($mailStatus === 'unreachable' ? 'bad' : 'warn') ?>">
      <?= htmlspecialchars($mailStatus, ENT_QUOTES) ?>
    </td>
  </tr>
  <?php if ($mailDetail !== ''): ?>
  <tr><td></td><td><code><?= htmlspecialchars($mailDetail, ENT_QUOTES) ?></code></td></tr>
  <?php endif; ?>
</table>

<h2>Files in the document root</h2>
<?php if ($uploads === []): ?>
  <p class="warn">Nothing here yet. Upload a project over FTP and it will show up immediately.</p>
<?php else: ?>
  <ul>
  <?php foreach ($uploads as $entry): ?>
    <li><code><?= htmlspecialchars($entry, ENT_QUOTES) ?></code></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<div class="hint">
  This page is a placeholder. Overwrite <code>index.php</code> through FTP, or delete it
  and drop your own <code>public/</code> contents into <code>./www</code>.
</div>
</body>
</html>
