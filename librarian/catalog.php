<?php
require_once '../config.php';
checkAuth();
checkRole(['admin', 'librarian']);

function catalogTableColumns(PDO $pdo, string $table): array {
    try {
        return array_fill_keys(
            $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN),
            true
        );
    } catch (Exception $e) {
        return [];
    }
}

// Get filter parameters
$category = $_GET['category'] ?? '';
$author = $_GET['author'] ?? '';
$publisher = $_GET['publisher'] ?? '';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'title';
$order = $_GET['order'] ?? 'ASC';

$bookColumns = catalogTableColumns($pdo, 'books');
$categoryColumns = catalogTableColumns($pdo, 'book_categories');
$hasBookCategoryText = isset($bookColumns['category']);
$hasBookCategoryId = isset($bookColumns['category_id']) && !empty($categoryColumns);
$categoryLabelColumn = isset($categoryColumns['category_name']) ? 'category_name' : (isset($categoryColumns['name']) ? 'name' : null);

$categorySelect = $hasBookCategoryText
    ? "b.category as category"
    : ($hasBookCategoryId && $categoryLabelColumn
        ? "COALESCE(bc.`{$categoryLabelColumn}`, CONCAT('Category #', b.category_id)) as category"
        : "'Uncategorized' as category");
$categoryJoin = $hasBookCategoryId && $categoryLabelColumn
    ? "LEFT JOIN book_categories bc ON b.category_id = bc.id"
    : "";
$categoryFilterExpr = $hasBookCategoryText
    ? "b.category"
    : ($hasBookCategoryId && $categoryLabelColumn
        ? "COALESCE(bc.`{$categoryLabelColumn}`, CONCAT('Category #', b.category_id))"
        : "''");

// Build query
$query = "
    SELECT b.*,
           {$categorySelect},
           (SELECT COUNT(*) FROM book_issues WHERE book_id = b.id AND return_date IS NULL) as current_issues,
           CASE 
               WHEN b.available_copies > 0 THEN 'Available'
               WHEN b.available_copies = 0 AND b.total_copies > 0 THEN 'All Issued'
               ELSE 'Unavailable'
           END as status
    FROM books b
    {$categoryJoin}
    WHERE 1=1
";
$params = [];

if ($category) {
    $query .= " AND {$categoryFilterExpr} = ?";
    $params[] = $category;
}

if ($author) {
    $query .= " AND b.author LIKE ?";
    $params[] = "%$author%";
}

if ($publisher) {
    $query .= " AND b.publisher LIKE ?";
    $params[] = "%$publisher%";
}

if ($search) {
    $query .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR {$categoryFilterExpr} LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Validate sort column
$allowed_sorts = ['title', 'author', 'category', 'publication_year', 'available_copies'];
$sort = in_array($sort, $allowed_sorts) ? $sort : 'title';
$order = in_array(strtoupper($order), ['ASC', 'DESC']) ? $order : 'ASC';

$sortExpr = $sort === 'category' ? 'category' : "b.$sort";
$query .= " ORDER BY {$sortExpr} $order";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$books = $stmt->fetchAll();

// Get distinct categories for filter
if ($hasBookCategoryText) {
    $categories = $pdo->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
} elseif ($hasBookCategoryId && $categoryLabelColumn) {
    $categories = $pdo->query("SELECT DISTINCT `{$categoryLabelColumn}` FROM book_categories WHERE `{$categoryLabelColumn}` IS NOT NULL AND `{$categoryLabelColumn}` != '' ORDER BY `{$categoryLabelColumn}`")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $categories = [];
}

// Get distinct authors for filter
$authors = $pdo->query("SELECT DISTINCT author FROM books WHERE author IS NOT NULL AND author != '' ORDER BY author LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$total_books = count($books);
$available_books = array_reduce($books, function($carry, $book) {
    return $carry + ($book['available_copies'] > 0 ? 1 : 0);
}, 0);
$total_copies = array_sum(array_column($books, 'total_copies'));
$available_copies = array_sum(array_column($books, 'available_copies'));

$page_title = "Book Catalog";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/librarian.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <style>
        /* Catalog specific styles */
        .catalog-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .catalog-header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .catalog-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .catalog-stats {
            display: flex;
            gap: 2rem;
            margin-top: 2rem;
        }

        .catalog-stat {
            background: rgba(255,255,255,0.2);
            padding: 1rem 2rem;
            border-radius: 8px;
            text-align: center;
        }

        .catalog-stat .number {
            font-size: 2rem;
            font-weight: bold;
        }

        .catalog-stat .label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .filter-panel {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .book-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .book-cover {
            height: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 4rem;
            position: relative;
        }

        .book-cover.available {
            background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
        }

        .book-cover.unavailable {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }

        .book-status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .book-info {
            padding: 1.5rem;
        }

        .book-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }

        .book-author {
            color: #6c757d;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .book-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
        }

        .book-meta-item {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .book-meta-item i {
            color: #3498db;
        }

        .book-copies {
            display: flex;
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }

        .copy-info {
            flex: 1;
            text-align: center;
        }

        .copy-info .number {
            font-size: 1.2rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .copy-info .label {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .book-actions {
            display: flex;
            gap: 0.5rem;
            padding: 1rem 1.5rem 1.5rem;
        }

        .book-actions .btn {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.85rem;
        }

        .quick-view-modal .modal-content {
            max-width: 800px;
        }

        .book-detail-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
        }

        .book-detail-cover {
            height: 400px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 5rem;
        }

        .book-detail-info h2 {
            font-size: 2rem;
            margin: 0 0 0.5rem;
            color: #2c3e50;
        }

        .book-detail-author {
            font-size: 1.2rem;
            color: #6c757d;
            margin-bottom: 1.5rem;
        }

        .detail-section {
            margin-bottom: 2rem;
        }

        .detail-section h3 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: #2c3e50;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 0.3rem;
        }

        .detail-value {
            font-weight: 600;
            color: #2c3e50;
        }

        .availability-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .availability-indicator.available {
            background: #27ae60;
        }

        .availability-indicator.unavailable {
            background: #e74c3c;
        }

        .sort-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            background: white;
            padding: 1rem;
            border-radius: 8px;
        }

        .sort-options {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .sort-options select {
            padding: 0.5rem;
            border: 1px solid #e9ecef;
            border-radius: 4px;
        }

        .view-toggle {
            display: flex;
            gap: 0.3rem;
        }

        .view-toggle button {
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            cursor: pointer;
        }

        .view-toggle button.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .list-view .book-card {
            display: flex;
            flex-direction: row;
            align-items: center;
        }

        .list-view .book-cover {
            width: 100px;
            height: 100px;
            font-size: 2rem;
        }

        .list-view .book-info {
            flex: 1;
            padding: 1rem;
        }

        .list-view .book-actions {
            width: 200px;
            padding: 1rem;
        }

        @media (max-width: 768px) {
            .catalog-grid {
                grid-template-columns: 1fr;
            }
            
            .book-detail-layout {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .catalog-stats {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Catalog Header -->
        <div class="catalog-header">
            <h1><i class="fas fa-book-open"></i> Library Catalog</h1>
            <p>Discover our collection of <?php echo $total_books; ?> books</p>
            
            <div class="catalog-stats">
                <div class="catalog-stat">
                    <div class="number"><?php echo $total_books; ?></div>
                    <div class="label">Unique Titles</div>
                </div>
                <div class="catalog-stat">
                    <div class="number"><?php echo $total_copies; ?></div>
                    <div class="label">Total Copies</div>
                </div>
                <div class="catalog-stat">
                    <div class="number"><?php echo $available_copies; ?></div>
                    <div class="label">Available Now</div>
                </div>
            </div>
        </div>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Title, author, ISBN...">
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-tag"></i> Category</label>
                        <select name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category == $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-user"></i> Author</label>
                        <select name="author">
                            <option value="">All Authors</option>
                            <?php foreach ($authors as $auth): ?>
                            <option value="<?php echo htmlspecialchars($auth); ?>" <?php echo $author == $auth ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($auth); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-building"></i> Publisher</label>
                        <input type="text" name="publisher" value="<?php echo htmlspecialchars($publisher); ?>" placeholder="Publisher name">
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="catalog.php" class="btn btn-outline">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Sort Bar -->
        <div class="sort-bar">
            <div class="sort-options">
                <span>Sort by:</span>
                <select onchange="window.location.href=updateQueryString('sort', this.value)">
                    <option value="title" <?php echo $sort == 'title' ? 'selected' : ''; ?>>Title</option>
                    <option value="author" <?php echo $sort == 'author' ? 'selected' : ''; ?>>Author</option>
                    <option value="category" <?php echo $sort == 'category' ? 'selected' : ''; ?>>Category</option>
                    <option value="publication_year" <?php echo $sort == 'publication_year' ? 'selected' : ''; ?>>Year</option>
                    <option value="available_copies" <?php echo $sort == 'available_copies' ? 'selected' : ''; ?>>Availability</option>
                </select>
                
                <button onclick="window.location.href=updateQueryString('order', '<?php echo $order == 'ASC' ? 'DESC' : 'ASC'; ?>')" class="btn btn-sm btn-outline">
                    <i class="fas fa-sort-<?php echo $order == 'ASC' ? 'up' : 'down'; ?>"></i>
                </button>
            </div>
            
            <div class="view-toggle">
                <button onclick="setViewMode('grid')" id="gridViewBtn" class="<?php echo !isset($_COOKIE['catalog_view']) || $_COOKIE['catalog_view'] == 'grid' ? 'active' : ''; ?>">
                    <i class="fas fa-th"></i>
                </button>
                <button onclick="setViewMode('list')" id="listViewBtn" class="<?php echo isset($_COOKIE['catalog_view']) && $_COOKIE['catalog_view'] == 'list' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <!-- Books Grid -->
        <div class="catalog-grid" id="catalogContainer">
            <?php foreach ($books as $book): 
                $availability_class = $book['available_copies'] > 0 ? 'available' : 'unavailable';
                $status_text = $book['available_copies'] > 0 ? 'Available' : 'Unavailable';
            ?>
            <div class="book-card" data-book-id="<?php echo $book['id']; ?>">
                <div class="book-cover <?php echo $availability_class; ?>">
                    <i class="fas fa-book"></i>
                    <span class="book-status-badge"><?php echo $status_text; ?></span>
                </div>
                
                <div class="book-info">
                    <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
                    <div class="book-author">by <?php echo htmlspecialchars($book['author']); ?></div>
                    
                    <div class="book-meta">
                        <?php if ($book['isbn']): ?>
                        <div class="book-meta-item">
                            <i class="fas fa-barcode"></i>
                            <span><?php echo htmlspecialchars($book['isbn']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($book['category']): ?>
                        <div class="book-meta-item">
                            <i class="fas fa-tag"></i>
                            <span><?php echo htmlspecialchars($book['category']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="book-copies">
                        <div class="copy-info">
                            <div class="number"><?php echo $book['total_copies']; ?></div>
                            <div class="label">Total</div>
                        </div>
                        <div class="copy-info">
                            <div class="number"><?php echo $book['available_copies']; ?></div>
                            <div class="label">Available</div>
                        </div>
                        <div class="copy-info">
                            <div class="number"><?php echo $book['current_issues']; ?></div>
                            <div class="label">Issued</div>
                        </div>
                    </div>
                </div>
                
                <div class="book-actions">
                    <button class="btn btn-outline btn-sm" onclick="quickView(<?php echo $book['id']; ?>)">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <?php if ($book['available_copies'] > 0): ?>
                    <button class="btn btn-success btn-sm" onclick="quickIssue(<?php echo $book['id']; ?>)">
                        <i class="fas fa-book-open"></i> Issue
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($books)): ?>
        <div class="no-data">
            <i class="fas fa-book-open fa-4x"></i>
            <h3>No Books Found</h3>
            <p>Try adjusting your filters or search criteria</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick View Modal -->
    <div id="quickViewModal" class="modal">
        <div class="modal-content quick-view-modal">
            <div class="modal-header">
                <h3>Book Details</h3>
                <button class="close" onclick="closeModal('quickViewModal')">&times;</button>
            </div>
            <div class="modal-body" id="quickViewBody">
                <!-- Populated via AJAX -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('quickViewModal')">Close</button>
                <button class="btn btn-primary" id="quickViewIssueBtn" style="display: none;">Issue Book</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Helper function to update query string
        function updateQueryString(key, value) {
            const url = new URL(window.location.href);
            url.searchParams.set(key, value);
            return url.toString();
        }

        // Set view mode (grid/list)
        function setViewMode(mode) {
            document.cookie = `catalog_view=${mode}; path=/; max-age=31536000`;
            
            const container = document.getElementById('catalogContainer');
            const gridBtn = document.getElementById('gridViewBtn');
            const listBtn = document.getElementById('listViewBtn');
            
            if (mode === 'list') {
                container.classList.add('list-view');
                gridBtn.classList.remove('active');
                listBtn.classList.add('active');
            } else {
                container.classList.remove('list-view');
                gridBtn.classList.add('active');
                listBtn.classList.remove('active');
            }
        }

        // Load view mode from cookie
        document.addEventListener('DOMContentLoaded', function() {
            const viewMode = document.cookie.split('; ').find(row => row.startsWith('catalog_view='));
            if (viewMode) {
                const mode = viewMode.split('=')[1];
                setViewMode(mode);
            }
        });

        // Quick view book details
        function quickView(bookId) {
            showLoading('Loading book details...');
            
            fetch(`books.php?ajax=details&id=${bookId}`)
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    
                    if (data.success) {
                        const b = data.book;
                        const available = b.available_copies > 0;
                        
                        document.getElementById('quickViewBody').innerHTML = `
                            <div class="book-detail-layout">
                                <div class="book-detail-cover ${available ? 'available' : 'unavailable'}">
                                    <i class="fas fa-book"></i>
                                </div>
                                
                                <div class="book-detail-info">
                                    <h2>${escapeHtml(b.title)}</h2>
                                    <div class="book-detail-author">by ${escapeHtml(b.author)}</div>
                                    
                                    <div class="detail-section">
                                        <h3>Book Information</h3>
                                        <div class="detail-grid">
                                            <div class="detail-item">
                                                <span class="detail-label">ISBN</span>
                                                <span class="detail-value">${escapeHtml(b.isbn || 'N/A')}</span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Publisher</span>
                                                <span class="detail-value">${escapeHtml(b.publisher || 'N/A')}</span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Publication Year</span>
                                                <span class="detail-value">${escapeHtml(b.publication_year || 'N/A')}</span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Category</span>
                                                <span class="detail-value">${escapeHtml(b.category || 'N/A')}</span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Location</span>
                                                <span class="detail-value">${escapeHtml(b.location || 'N/A')}</span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Status</span>
                                                <span class="detail-value">
                                                    <span class="availability-indicator ${available ? 'available' : 'unavailable'}"></span>
                                                    ${available ? 'Available' : 'Unavailable'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-section">
                                        <h3>Availability</h3>
                                        <div class="detail-grid">
                                            <div class="detail-item">
                                                <span class="detail-label">Total Copies</span>
                                                <span class="detail-value">${b.total_copies}</span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Available Copies</span>
                                                <span class="detail-value">${b.available_copies}</span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Currently Issued</span>
                                                <span class="detail-value">${b.total_copies - b.available_copies}</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    ${b.description ? `
                                    <div class="detail-section">
                                        <h3>Description</h3>
                                        <p>${escapeHtml(b.description)}</p>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                        
                        const issueBtn = document.getElementById('quickViewIssueBtn');
                        if (available) {
                            issueBtn.style.display = 'inline-flex';
                            issueBtn.onclick = () => {
                                closeModal('quickViewModal');
                                quickIssue(bookId);
                            };
                        } else {
                            issueBtn.style.display = 'none';
                        }
                        
                        openModal('quickViewModal');
                    } else {
                        showError('Could not load book details');
                    }
                })
                .catch(() => {
                    hideLoading();
                    showError('Network error occurred');
                });
        }

        // Quick issue from catalog
        function quickIssue(bookId) {
            // Store book ID and redirect to circulations
            sessionStorage.setItem('quick_issue_book', bookId);
            window.location.href = 'circulations.php?action=issue&book=' + bookId;
        }

        // Escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Modal functions
        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        // Loading functions
        function showLoading(message) {
            Swal.fire({
                title: message,
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        }

        function hideLoading() {
            Swal.close();
        }

        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: message
            });
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
