<?php
require __DIR__ . '/config.php';
foreach (['book_fines','lost_books','book_issues','books','students','classes','users','fine_settings'] as $table) {
  try {
    echo $table . ': ' . implode(',', $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL . PHP_EOL;
  } catch (Exception $e) {
    echo $table . ': missing' . PHP_EOL . PHP_EOL;
  }
}
