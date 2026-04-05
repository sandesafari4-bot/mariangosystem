<?php
require_once __DIR__ . '/../config.php';

// Check students table structure
$result = $pdo->query('DESCRIBE students');
$cols = $result->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Students Table Columns:</h2>";
foreach($cols as $col) {
    if (strpos($col['Field'], 'parent') !== false) {
        echo "<strong>" . $col['Field'] . "</strong> - " . $col['Type'] . "<br>";
    }
}

// Check if parent_id exists
$has_parent_id = false;
foreach($cols as $col) {
    if ($col['Field'] === 'parent_id') {
        $has_parent_id = true;
        break;
    }
}

if (!$has_parent_id) {
    echo "<h2>Adding parent_id column to students table...</h2>";
    try {
        $pdo->exec("ALTER TABLE students ADD COLUMN parent_id INT AFTER class_id");
        echo "✓ parent_id column added successfully<br>";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}

// Verify parents table exists
try {
    $result = $pdo->query('DESCRIBE parents');
    $parentCols = $result->fetchAll(PDO::FETCH_ASSOC);
    echo "<h2>✓ Parents table exists with columns:</h2>";
    foreach($parentCols as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")<br>";
    }
} catch (Exception $e) {
    echo "<h2>✗ Parents table doesn't exist: " . $e->getMessage() . "</h2>";
}

// Test the query
echo "<h2>Testing students query with parents join...</h2>";
try {
    $students = $pdo->query("
        SELECT s.*, c.class_name, p.full_name as parent_name, p.phone as parent_phone 
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        LEFT JOIN parents p ON s.parent_id = p.id 
        LIMIT 1
    ")->fetchAll();
    echo "✓ Query executed successfully. Found " . count($students) . " record(s)";
} catch (Exception $e) {
    echo "✗ Query failed: " . $e->getMessage();
}
?>
