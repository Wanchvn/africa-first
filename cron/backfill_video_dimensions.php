<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->query("
    SELECT id, media_path FROM posts
    WHERE media_type = 'video'
      AND (video_width IS NULL OR video_height IS NULL)
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$projectRoot = dirname(__DIR__);
$count = 0;

foreach ($rows as $row) {
    $abs = $projectRoot . '/' . $row['media_path'];
    if (!is_file($abs)) {
        echo "SKIP (missing): {$row['id']}\n";
        continue;
    }

    $cmd = 'ffprobe -v error -select_streams v:0 '
         . '-show_entries stream=width,height '
         . '-of csv=p=0 ' . escapeshellarg($abs) . ' 2>&1';
    $out = @shell_exec($cmd);

    if ($out && preg_match('/^(\d+),(\d+)/', trim($out), $m)) {
        $pdo->prepare("
            UPDATE posts SET video_width = :w, video_height = :h WHERE id = :id
        ")->execute([':w' => (int)$m[1], ':h' => (int)$m[2], ':id' => (int)$row['id']]);
        echo "OK: {$row['id']} → {$m[1]}x{$m[2]}\n";
        $count++;
    } else {
        echo "FAIL: {$row['id']}\n";
    }
}

echo "Done. Backfilled {$count} video(s).\n";