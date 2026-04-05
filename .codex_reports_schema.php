<?php
require __DIR__ . '/config.php';
echo 'students: ' . implode(',', $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo 'classes: ' . implode(',', $pdo->query("SHOW COLUMNS FROM classes")->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo 'book_issues: ' . implode(',', $pdo->query("SHOW COLUMNS FROM book_issues")->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo 'books: ' . implode(',', $pdo->query("SHOW COLUMNS FROM books")->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
