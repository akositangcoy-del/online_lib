<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function input(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function requireUser(): int
{
    if (!isset($_SESSION['user_id'])) {
        respond(['error' => 'Please sign in to continue.'], 401);
    }
    return (int) $_SESSION['user_id'];
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        respond(['error' => 'Your session expired. Refresh the page and try again.'], 403);
    }
}

function requireAdmin(PDO $db, int $userId): void
{
    $query = $db->prepare("SELECT role FROM users WHERE id = ?");
    $query->execute([$userId]);
    if ($query->fetchColumn() !== 'admin') {
        respond(['error' => 'Administrator access is required for this action.'], 403);
    }
}

function safeUser(array $user): array
{
    unset($user['password']);
    return $user;
}

function dashboardData(PDO $db, int $userId): array
{
    $userQuery = $db->prepare('SELECT id, username, name, course, year_level, contact_no, theme, role, created_at FROM users WHERE id = ?');
    $userQuery->execute([$userId]);
    $user = $userQuery->fetch();
    if (!$user) {
        session_destroy();
        respond(['error' => 'This account could not be found. Please sign in again.'], 401);
    }

    $books = $db->query(
        "SELECT b.id, b.title, b.author, b.category, b.isbn, b.published_year,
            b.cover_url AS cover,
            b.copies AS total_copies,
            GREATEST(b.copies - COUNT(CASE WHEN l.status = 'Borrowed' THEN l.id END), 0) AS available_copies
         FROM books b
         LEFT JOIN borrowed_books l
            ON l.book_title COLLATE utf8mb4_unicode_ci = b.title
         GROUP BY b.id
         ORDER BY b.title"
    )->fetchAll();

    $loanSql =
        "SELECT l.id, b.id AS book_id, l.book_title AS title,
            COALESCE(b.author, '') AS author, l.borrow_date AS borrowed_at,
            l.due_date AS due_at,
            CASE WHEN l.status = 'Returned' THEN l.returned_at ELSE NULL END AS returned_at,
            u.name AS borrower_name, u.username AS borrower_username
         FROM borrowed_books l
         JOIN users u ON u.id = l.user_id
         LEFT JOIN books b
            ON b.title = l.book_title COLLATE utf8mb4_unicode_ci";
    if ($user['role'] === 'admin') {
        $loanSql .= ' ORDER BY l.borrow_date DESC, l.id DESC';
        $loanQuery = $db->query($loanSql);
    } else {
        $loanSql .= ' WHERE l.user_id = ? ORDER BY l.borrow_date DESC, l.id DESC LIMIT 100';
        $loanQuery = $db->prepare($loanSql);
        $loanQuery->execute([$userId]);
    }
    $loans = $loanQuery->fetchAll();

    $stats = $db->query(
        "SELECT
            (SELECT COALESCE(SUM(copies), 0) FROM books) AS total_books,
            (SELECT COUNT(*) FROM users) AS total_borrowers,
            (SELECT COUNT(*) FROM borrowed_books WHERE status = 'Borrowed') AS active_loans,
            (SELECT COUNT(*) FROM borrowed_books WHERE status = 'Borrowed' AND due_date < UTC_DATE()) AS overdue,
            (SELECT COUNT(*) FROM borrowed_books) AS total_transactions"
    )->fetch();

    $monthlyQuery = $db->query(
        "SELECT DATE_FORMAT(borrow_date, '%Y-%m') AS month_key, COUNT(*) AS total
         FROM borrowed_books
         WHERE borrow_date >= DATE_SUB(UTC_DATE(), INTERVAL 5 MONTH)
         GROUP BY month_key ORDER BY month_key"
    )->fetchAll();
    $monthly = [];
    foreach ($monthlyQuery as $row) {
        $monthly[$row['month_key']] = (int) $row['total'];
    }
    $activityLabels = [];
    $activityValues = [];
    for ($offset = 5; $offset >= 0; $offset--) {
        $relativeMonth = $offset === 0 ? 'first day of this month' : "first day of -{$offset} months";
        $month = new DateTimeImmutable($relativeMonth, new DateTimeZone('UTC'));
        $key = $month->format('Y-m');
        $activityLabels[] = $month->format('M Y');
        $activityValues[] = $monthly[$key] ?? 0;
    }

    $categories = $db->query('SELECT category AS label, COUNT(*) AS total FROM books GROUP BY category ORDER BY category')->fetchAll();
    $notifications = [];
    foreach ($loans as $loan) {
        if ($user['role'] === 'admin') {
            if ($loan['returned_at'] === null) {
                $notifications[] = [
                    'type' => 'warning',
                    'title' => 'Book borrowed',
                    'message' => $loan['borrower_name'] . ' (@' . $loan['borrower_username'] . ') borrowed ' . $loan['title'] . '.',
                    'created_at' => $loan['borrowed_at'],
                ];
            }
        } elseif ($loan['returned_at'] === null) {
            $due = new DateTimeImmutable($loan['due_at'], new DateTimeZone('UTC'));
            $days = (int) (new DateTimeImmutable('today', new DateTimeZone('UTC')))->diff($due)->format('%r%a');
            if ($days < 0 || $days <= 3) {
                $notifications[] = [
                    'type' => $days < 0 ? 'danger' : 'warning',
                    'title' => $days < 0 ? 'Book overdue' : 'Due soon',
                    'message' => $loan['title'] . ($days < 0 ? ' is overdue.' : ' is due in ' . $days . ' day(s).'),
                    'created_at' => $loan['due_at'],
                ];
            }
        } elseif (strtotime($loan['returned_at']) >= time() - 14 * 86400) {
            $notifications[] = [
                'type' => 'success',
                'title' => 'Book returned',
                'message' => 'Your return of ' . $loan['title'] . ' was recorded.',
                'created_at' => $loan['returned_at'],
            ];
        }
    }
    usort($notifications, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

    return [
        'user' => safeUser($user),
        'books' => $books,
        'loans' => $loans,
        'stats' => $stats,
        'notifications' => array_slice($notifications, 0, 20),
        'reports' => [
            'activity_labels' => $activityLabels,
            'activity_values' => $activityValues,
            'category_labels' => array_column($categories, 'label'),
            'category_values' => array_map('intval', array_column($categories, 'total')),
        ],
        'csrf_token' => $_SESSION['csrf_token'],
    ];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET' && $action === 'session') {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        respond([
            'authenticated' => isset($_SESSION['user_id']),
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }
    if ($method !== 'POST' && !($method === 'GET' && $action === 'dashboard')) {
        respond(['error' => 'Unsupported request.'], 405);
    }

    $db = database();
    $data = input();

    if ($action === 'register') {
        $name = trim((string) ($data['name'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if ($name === '' || strlen($name) > 120 || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            respond(['error' => 'Enter a name and a username (3-50 letters, numbers, dots, underscores, or hyphens).'], 422);
        }
        if (strlen($password) < 8) {
            respond(['error' => 'Password must be at least 8 characters long.'], 422);
        }
        $create = $db->prepare('INSERT INTO users (name, username, password) VALUES (?, ?, ?)');
        try {
            $create->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                respond(['error' => 'That username is already taken.'], 409);
            }
            throw $error;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $db->lastInsertId();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(dashboardData($db, (int) $_SESSION['user_id']), 201);
    }

    if ($action === 'login') {
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $query = $db->prepare('SELECT id, password FROM users WHERE username = ?');
        $query->execute([$username]);
        $user = $query->fetch();
        if (!$user || !password_verify($password, $user['password'])) {
            respond(['error' => 'Username or password is incorrect.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(dashboardData($db, (int) $user['id']));
    }

    if ($action === 'dashboard' && $method === 'GET') {
        respond(dashboardData($db, requireUser()));
    }

    $userId = requireUser();
    requireCsrf();

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        respond(['ok' => true]);
    }

    if ($action === 'borrow') {
        $bookId = filter_var($data['book_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$bookId) {
            respond(['error' => 'Choose a valid book.'], 422);
        }
        $db->beginTransaction();
        $bookQuery = $db->prepare('SELECT id, title, copies FROM books WHERE id = ? FOR UPDATE');
        $bookQuery->execute([$bookId]);
        $book = $bookQuery->fetch();
        if (!$book) {
            $db->rollBack();
            respond(['error' => 'That book is no longer in the catalog.'], 404);
        }
        $active = $db->prepare("SELECT COUNT(*) FROM borrowed_books WHERE book_title = ? AND status = 'Borrowed'");
        $active->execute([$book['title']]);
        if ((int) $active->fetchColumn() >= (int) $book['copies']) {
            $db->rollBack();
            respond(['error' => 'This book is currently unavailable.'], 409);
        }
        $create = $db->prepare(
            "INSERT INTO borrowed_books (user_id, book_title, borrow_date, due_date, status)
             VALUES (?, ?, UTC_DATE(), DATE_ADD(UTC_DATE(), INTERVAL 14 DAY), 'Borrowed')"
        );
        $create->execute([$userId, $book['title']]);
        $db->commit();
        respond(dashboardData($db, $userId));
    }

    if ($action === 'return') {
        requireAdmin($db, $userId);
        $loanId = filter_var($data['loan_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$loanId) {
            respond(['error' => 'Choose a valid loan.'], 422);
        }
        $update = $db->prepare(
            "UPDATE borrowed_books SET status = 'Returned', returned_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = 'Borrowed'"
        );
        $update->execute([$loanId]);
        if ($update->rowCount() !== 1) {
            respond(['error' => 'That active loan could not be found.'], 404);
        }
        respond(dashboardData($db, $userId));
    }

    if ($action === 'save-profile') {
        $name = trim((string) ($data['name'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $course = trim((string) ($data['course'] ?? ''));
        $year = trim((string) ($data['year_level'] ?? ''));
        $contact = trim((string) ($data['contact_no'] ?? ''));
        if ($name === '' || strlen($name) > 120 || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            respond(['error' => 'Enter a name and a username (3-50 letters, numbers, dots, underscores, or hyphens).'], 422);
        }
        if (strlen($course) > 160 || strlen($year) > 60 || strlen($contact) > 40) {
            respond(['error' => 'One or more profile fields are too long.'], 422);
        }
        try {
            $update = $db->prepare('UPDATE users SET name = ?, username = ?, course = ?, year_level = ?, contact_no = ? WHERE id = ?');
            $update->execute([$name, $username, $course, $year, $contact, $userId]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                respond(['error' => 'That username is already taken.'], 409);
            }
            throw $error;
        }
        respond(dashboardData($db, $userId));
    }

    if ($action === 'change-password') {
        $current = (string) ($data['current_password'] ?? '');
        $new = (string) ($data['new_password'] ?? '');
        $query = $db->prepare('SELECT password FROM users WHERE id = ?');
        $query->execute([$userId]);
        $hash = $query->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) {
            respond(['error' => 'Current password is incorrect.'], 422);
        }
        if (strlen($new) < 8) {
            respond(['error' => 'New password must be at least 8 characters long.'], 422);
        }
        $update = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
        $update->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        respond(['ok' => true, 'message' => 'Password updated.']);
    }

    if ($action === 'save-theme') {
        $theme = $data['theme'] ?? '';
        if (!in_array($theme, ['light', 'dark'], true)) {
            respond(['error' => 'Choose a supported appearance.'], 422);
        }
        $update = $db->prepare('UPDATE users SET theme = ? WHERE id = ?');
        $update->execute([$theme, $userId]);
        respond(dashboardData($db, $userId));
    }

    if ($action === 'save-book') {
        requireAdmin($db, $userId);
        $bookId = null;
        if (isset($data['id']) && $data['id'] !== '') {
            $bookId = filter_var($data['id'], FILTER_VALIDATE_INT);
            if (!$bookId) {
                respond(['error' => 'Choose a valid book.'], 422);
            }
        }
        $title = trim((string) ($data['title'] ?? ''));
        $author = trim((string) ($data['author'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));
        $isbn = trim((string) ($data['isbn'] ?? ''));
        $yearInput = trim((string) ($data['published_year'] ?? ''));
        $publishedYear = $yearInput === '' ? null : filter_var($yearInput, FILTER_VALIDATE_INT);
        $cover = trim((string) ($data['cover_url'] ?? ''));
        $copies = filter_var($data['copies'] ?? null, FILTER_VALIDATE_INT);
        if ($title === '' || strlen($title) > 255 || $author === '' || strlen($author) > 140 ||
            $category === '' || strlen($category) > 100 || strlen($cover) > 500 ||
            strlen($isbn) > 20 || ($isbn !== '' && !preg_match('/^[0-9Xx -]+$/', $isbn)) ||
            ($yearInput !== '' && ($publishedYear === false || $publishedYear < 1450 || $publishedYear > (int) gmdate('Y') + 1)) ||
            $copies === false || $copies < 1 || $copies > 100000 ||
            ($cover !== '' && !filter_var($cover, FILTER_VALIDATE_URL))) {
            respond(['error' => 'Enter valid book details, ISBN, publication year, cover URL, and copy count.'], 422);
        }
        if ($cover === '' && $isbn !== '') {
            $coverIsbn = preg_replace('/[^0-9Xx]/', '', $isbn);
            $cover = 'https://covers.openlibrary.org/b/isbn/' . $coverIsbn . '-M.jpg';
        }

        try {
            if ($bookId) {
                $db->beginTransaction();
                $current = $db->prepare('SELECT title, copies FROM books WHERE id = ? FOR UPDATE');
                $current->execute([$bookId]);
                $existing = $current->fetch();
                if (!$existing) {
                    $db->rollBack();
                    respond(['error' => 'That book was not found.'], 404);
                }
                $activeLoans = $db->prepare(
                    "SELECT COUNT(*) FROM borrowed_books
                     WHERE book_title COLLATE utf8mb4_unicode_ci = ? AND status = 'Borrowed'"
                );
                $activeLoans->execute([$existing['title']]);
                if ((int) $copies < (int) $activeLoans->fetchColumn()) {
                    $db->rollBack();
                    respond(['error' => 'Copies cannot be fewer than the number of active loans.'], 409);
                }
                if ($title !== $existing['title']) {
                    $history = $db->prepare(
                        'SELECT COUNT(*) FROM borrowed_books
                         WHERE book_title COLLATE utf8mb4_unicode_ci = ?'
                    );
                    $history->execute([$existing['title']]);
                    if ((int) $history->fetchColumn() > 0) {
                        $db->rollBack();
                        respond(['error' => 'The title cannot be changed because borrowing history uses it.'], 409);
                    }
                }
                $update = $db->prepare(
                    'UPDATE books
                     SET title = ?, author = ?, category = ?, isbn = ?, published_year = ?, cover_url = ?, copies = ?
                     WHERE id = ?'
                );
                $update->execute([$title, $author, $category, $isbn, $publishedYear, $cover, $copies, $bookId]);
                $db->commit();
            } else {
                $create = $db->prepare(
                    'INSERT INTO books (title, author, category, isbn, published_year, cover_url, copies)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $create->execute([$title, $author, $category, $isbn, $publishedYear, $cover, $copies]);
            }
        } catch (PDOException $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (($error->errorInfo[1] ?? null) === 1062) {
                respond(['error' => 'A book with that title already exists.'], 409);
            }
            throw $error;
        }
        respond(dashboardData($db, $userId));
    }

    if ($action === 'delete-book') {
        requireAdmin($db, $userId);
        $bookId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$bookId) {
            respond(['error' => 'Choose a valid book.'], 422);
        }
        $bookQuery = $db->prepare('SELECT title FROM books WHERE id = ?');
        $bookQuery->execute([$bookId]);
        $title = $bookQuery->fetchColumn();
        if ($title === false) {
            respond(['error' => 'That book was not found.'], 404);
        }
        $history = $db->prepare(
            'SELECT COUNT(*) FROM borrowed_books WHERE book_title COLLATE utf8mb4_unicode_ci = ?'
        );
        $history->execute([$title]);
        if ((int) $history->fetchColumn() > 0) {
            respond(['error' => 'This book cannot be deleted because it has borrowing history.'], 409);
        }
        $delete = $db->prepare('DELETE FROM books WHERE id = ?');
        $delete->execute([$bookId]);
        respond(dashboardData($db, $userId));
    }

    respond(['error' => 'Unknown request.'], 404);
} catch (Throwable $error) {
    error_log((string) $error);
    if ($error instanceof PDOException) {
        $message = 'The database is unavailable. Check that MySQL is running and the DB settings are correct.';
    } elseif ($error instanceof DatabaseConfigurationException) {
        $message = $error->getMessage();
    } else {
        $message = 'The request could not be completed. Please try again.';
    }
    respond(['error' => $message], 500);
}
