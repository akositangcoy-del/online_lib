<?php
declare(strict_types=1);

final class DatabaseConfigurationException extends RuntimeException
{
}

function database(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $localConfigPath = __DIR__ . '/private/database.php';
    $localConfig = [];
    if (is_file($localConfigPath)) {
        $loadedConfig = require $localConfigPath;
        if (!is_array($loadedConfig)) {
            throw new DatabaseConfigurationException('private/database.php must return an array of database settings.');
        }
        $localConfig = $loadedConfig;
    }

    $setting = static function (string $environmentName, string $configName, string $default = '') use ($localConfig): string {
        $environmentValue = getenv($environmentName);
        if ($environmentValue !== false) {
            return $environmentValue;
        }
        $configValue = $localConfig[$configName] ?? $default;
        if (!is_string($configValue)) {
            throw new DatabaseConfigurationException($configName . ' in private/database.php must be a string.');
        }
        return $configValue;
    };

    $host = $setting('DB_HOST', 'host', 'localhost');
    $name = $setting('DB_NAME', 'name', 'library_db');
    $user = $setting('DB_USER', 'user', 'root');
    $password = $setting('DB_PASSWORD', 'password');
    $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isLocalHost = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($requestHost, '.localhost');
    if (!$isLocalHost && $requestHost !== '' && $localConfig === [] &&
        getenv('DB_HOST') === false && getenv('DB_NAME') === false && getenv('DB_USER') === false) {
        throw new DatabaseConfigurationException(
            'Hosting database settings are missing. Create private/database.php using private/database.example.php and enter the MySQL details from your hosting control panel.'
        );
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new DatabaseConfigurationException('DB_NAME may contain only letters, numbers, and underscores.');
    }

    $connection = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            name VARCHAR(120) NOT NULL DEFAULT '',
            course VARCHAR(160) NOT NULL DEFAULT '',
            year_level VARCHAR(60) NOT NULL DEFAULT '',
            contact_no VARCHAR(40) NOT NULL DEFAULT '',
            theme VARCHAR(20) NOT NULL DEFAULT 'light',
            role ENUM('member', 'admin') NOT NULL DEFAULT 'member',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = $connection->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $columnSet = array_fill_keys($columns, true);
    $addColumn = static function (string $column, string $definition) use ($connection, &$columnSet): void {
        if (!isset($columnSet[$column])) {
            $connection->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
            $columnSet[$column] = true;
        }
    };

    $addColumn('username', 'VARCHAR(50) NULL');
    $addColumn('password', "VARCHAR(255) NOT NULL DEFAULT ''");
    $addColumn('name', "VARCHAR(120) NOT NULL DEFAULT ''");
    $addColumn('course', "VARCHAR(160) NOT NULL DEFAULT ''");
    $addColumn('year_level', "VARCHAR(60) NOT NULL DEFAULT ''");
    $addColumn('contact_no', "VARCHAR(40) NOT NULL DEFAULT ''");
    $addColumn('theme', "VARCHAR(20) NOT NULL DEFAULT 'light'");
    $addColumn('role', "ENUM('member', 'admin') NOT NULL DEFAULT 'member'");
    $addColumn('created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');

    if (isset($columnSet['password_hash'])) {
        $connection->exec(
            "UPDATE users SET password = password_hash
             WHERE password = '' AND password_hash IS NOT NULL AND password_hash <> ''"
        );
    }
    if (isset($columnSet['email'])) {
        $connection->exec('ALTER TABLE users MODIFY email VARCHAR(190) NULL');
        $connection->exec(
            "UPDATE users SET username = LEFT(SUBSTRING_INDEX(email, '@', 1), 50)
             WHERE (username IS NULL OR username = '') AND email IS NOT NULL AND email <> ''"
        );
    }
    if (isset($columnSet['password_hash'])) {
        $connection->exec('ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL');
    }
    $connection->exec(
        "UPDATE users SET username = CONCAT('member', id)
         WHERE username IS NULL OR username = ''"
    );
    if (isset($columnSet['name'])) {
        $connection->exec(
            "UPDATE users SET name = username
             WHERE name = ''"
        );
    }
    $connection->exec('ALTER TABLE users MODIFY username VARCHAR(50) NOT NULL');

    $usernameIndex = $connection->query(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'username' AND NON_UNIQUE = 0 LIMIT 1"
    )->fetchColumn();
    if (!$usernameIndex) {
        $connection->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_username (username)');
    }

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS books (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(140) NOT NULL,
            category VARCHAR(100) NOT NULL,
            isbn VARCHAR(20) NOT NULL DEFAULT '',
            published_year SMALLINT UNSIGNED NULL,
            cover_url VARCHAR(500) NOT NULL DEFAULT '',
            copies INT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_books_title (title),
            INDEX idx_books_author (author),
            INDEX idx_books_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $bookColumns = $connection->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('isbn', $bookColumns, true)) {
        $connection->exec("ALTER TABLE books ADD COLUMN isbn VARCHAR(20) NOT NULL DEFAULT ''");
    }
    if (!in_array('published_year', $bookColumns, true)) {
        $connection->exec('ALTER TABLE books ADD COLUMN published_year SMALLINT UNSIGNED NULL');
    }
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS borrowed_books (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            book_title VARCHAR(255) NOT NULL,
            borrow_date DATE NOT NULL,
            due_date DATE NOT NULL,
            status ENUM('Borrowed', 'Returned') NOT NULL DEFAULT 'Borrowed',
            returned_at DATETIME NULL,
            INDEX idx_borrowed_books_user_status (user_id, status, due_date),
            INDEX idx_borrowed_books_title_status (book_title, status),
            INDEX idx_borrowed_books_borrow_date (borrow_date),
            CONSTRAINT fk_borrowed_books_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $loanColumns = $connection->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'borrowed_books'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('returned_at', $loanColumns, true)) {
        $connection->exec('ALTER TABLE borrowed_books ADD COLUMN returned_at DATETIME NULL');
    }

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS book_catalog_migrations (
            migration_key VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $connection->beginTransaction();
    $migration = $connection->prepare(
        'INSERT IGNORE INTO book_catalog_migrations (migration_key) VALUES (?)'
    );
    $migration->execute(['remove-unused-demo-books-v1']);
    if ($migration->rowCount() === 1) {
        $removeUnusedDemoBook = $connection->prepare(
            "DELETE FROM books
             WHERE title = ? AND author = ?
               AND NOT EXISTS (
                   SELECT 1 FROM borrowed_books l
                   WHERE l.book_title COLLATE utf8mb4_unicode_ci = books.title COLLATE utf8mb4_unicode_ci
               )"
        );
        foreach ([
            ['Photography', 'John Doe'],
            ['Cookbook', 'Chef Maria'],
            ['Bookkeeping', 'McCarthy'],
            ['Welding Basics', 'Craft Series'],
        ] as $demoBook) {
            $removeUnusedDemoBook->execute($demoBook);
        }
    }
    $connection->commit();

    $seed = $connection->prepare(
        'INSERT IGNORE INTO books (title, author, category, isbn, published_year, cover_url, copies)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $books = [
        ['Pride and Prejudice', 'Jane Austen', 'Classic Fiction', '9780141439518', 1813, 'https://covers.openlibrary.org/b/isbn/9780141439518-M.jpg', 2],
        ['Frankenstein', 'Mary Shelley', 'Gothic Fiction', '9780141439471', 1818, 'https://covers.openlibrary.org/b/isbn/9780141439471-M.jpg', 2],
        ['The Great Gatsby', 'F. Scott Fitzgerald', 'Classic Fiction', '9780743273565', 1925, 'https://covers.openlibrary.org/b/isbn/9780743273565-M.jpg', 2],
        ['To Kill a Mockingbird', 'Harper Lee', 'Classic Fiction', '9780061120084', 1960, 'https://covers.openlibrary.org/b/isbn/9780061120084-M.jpg', 2],
        ['Nineteen Eighty-Four', 'George Orwell', 'Dystopian Fiction', '9780451524935', 1949, 'https://covers.openlibrary.org/b/isbn/9780451524935-M.jpg', 2],
        ['Animal Farm', 'George Orwell', 'Political Fiction', '9780451526342', 1945, 'https://covers.openlibrary.org/b/isbn/9780451526342-M.jpg', 2],
        ['The Hobbit', 'J. R. R. Tolkien', 'Fantasy', '9780547928227', 1937, 'https://covers.openlibrary.org/b/isbn/9780547928227-M.jpg', 2],
        ['The Little Prince', 'Antoine de Saint-Exupéry', 'Children’s Literature', '9780156012195', 1943, 'https://covers.openlibrary.org/b/isbn/9780156012195-M.jpg', 2],
        ['The Alchemist', 'Paulo Coelho', 'Fiction', '9780062315007', 1988, 'https://covers.openlibrary.org/b/isbn/9780062315007-M.jpg', 2],
        ['The Book Thief', 'Markus Zusak', 'Historical Fiction', '9780375842207', 2005, 'https://covers.openlibrary.org/b/isbn/9780375842207-M.jpg', 2],
        ['The Kite Runner', 'Khaled Hosseini', 'Historical Fiction', '9781594631931', 2003, 'https://covers.openlibrary.org/b/isbn/9781594631931-M.jpg', 2],
        ['Harry Potter and the Philosopher’s Stone', 'J. K. Rowling', 'Fantasy', '9780747532699', 1997, 'https://covers.openlibrary.org/b/isbn/9780747532699-M.jpg', 2],
        ['The Hunger Games', 'Suzanne Collins', 'Science Fiction', '9780439023481', 2008, 'https://covers.openlibrary.org/b/isbn/9780439023481-M.jpg', 2],
        ['The Diary of a Young Girl', 'Anne Frank', 'Biography', '9780553296983', 1947, 'https://covers.openlibrary.org/b/isbn/9780553296983-M.jpg', 2],
        ['The Picture of Dorian Gray', 'Oscar Wilde', 'Gothic Fiction', '9780141439570', 1890, 'https://covers.openlibrary.org/b/isbn/9780141439570-M.jpg', 2],
        ['Dracula', 'Bram Stoker', 'Gothic Fiction', '9780141439846', 1897, 'https://covers.openlibrary.org/b/isbn/9780141439846-M.jpg', 2],
        ['The Adventures of Sherlock Holmes', 'Arthur Conan Doyle', 'Mystery', '9780140439076', 1892, 'https://covers.openlibrary.org/b/isbn/9780140439076-M.jpg', 2],
        ['Moby-Dick', 'Herman Melville', 'Classic Fiction', '9780142437247', 1851, 'https://covers.openlibrary.org/b/isbn/9780142437247-M.jpg', 2],
        ['The Secret Garden', 'Frances Hodgson Burnett', 'Children’s Literature', '9780141321062', 1911, 'https://covers.openlibrary.org/b/isbn/9780141321062-M.jpg', 2],
        ['Great Expectations', 'Charles Dickens', 'Classic Fiction', '9780141439563', 1861, 'https://covers.openlibrary.org/b/isbn/9780141439563-M.jpg', 2],
        ['Jane Eyre', 'Charlotte Brontë', 'Classic Fiction', '9780141441146', 1847, 'https://covers.openlibrary.org/b/isbn/9780141441146-M.jpg', 2],
        ['Alice’s Adventures in Wonderland', 'Lewis Carroll', 'Children’s Literature', '9780141439761', 1865, 'https://covers.openlibrary.org/b/isbn/9780141439761-M.jpg', 2],
        ['Think Python', 'Allen B. Downey', 'Technology / Programming', '9781491939369', 2015, 'https://covers.openlibrary.org/b/isbn/9781491939369-M.jpg', 2],
        ['Python Crash Course', 'Eric Matthes', 'Technology / Programming', '9781718502703', 2023, 'https://covers.openlibrary.org/b/isbn/9781718502703-M.jpg', 2],
        ['Clean Code', 'Robert C. Martin', 'Technology / Software Engineering', '9780132350884', 2008, 'https://covers.openlibrary.org/b/isbn/9780132350884-M.jpg', 2],
        ['The Pragmatic Programmer', 'David Thomas and Andrew Hunt', 'Technology / Software Engineering', '9780135957059', 2019, 'https://covers.openlibrary.org/b/isbn/9780135957059-M.jpg', 2],
        ['Code Complete', 'Steve McConnell', 'Technology / Software Engineering', '9780735619678', 2004, 'https://covers.openlibrary.org/b/isbn/9780735619678-M.jpg', 2],
        ['Computer Networking: A Top-Down Approach', 'James Kurose and Keith Ross', 'Technology / Networking', '9780136681557', 2021, 'https://covers.openlibrary.org/b/isbn/9780136681557-M.jpg', 2],
        ['Database System Concepts', 'Abraham Silberschatz, Henry Korth, and S. Sudarshan', 'Technology / Databases', '9781260084504', 2019, 'https://covers.openlibrary.org/b/isbn/9781260084504-M.jpg', 2],
        ['The Innovators', 'Walter Isaacson', 'Technology / Computing History', '9781476708706', 2014, 'https://covers.openlibrary.org/b/isbn/9781476708706-M.jpg', 2],
        ['How Learning Works: Seven Research-Based Principles for Smart Teaching', 'Susan A. Ambrose, Michael W. Bridges, Michele DiPietro, Marsha C. Lovett, and Marie K. Norman', 'College Learning / Teaching Methods', '9780470484104', 2010, 'https://covers.openlibrary.org/b/isbn/9780470484104-M.jpg', 2],
        ['Understanding by Design', 'Grant Wiggins and Jay McTighe', 'College Learning / Lesson Planning', '9781416600350', 2005, 'https://covers.openlibrary.org/b/isbn/9781416600350-M.jpg', 2],
        ['Classroom Instruction That Works', 'Ceri B. Dean, Elizabeth Ross Hubbell, Howard Pitler, and Bj Stone', 'Teaching Resources / Classroom Strategies', '9781416615071', 2012, 'https://covers.openlibrary.org/b/isbn/9781416615071-M.jpg', 2],
        ['The Differentiated Classroom', 'Carol Ann Tomlinson', 'Teaching Resources / Inclusive Education', '9781416618607', 2014, 'https://covers.openlibrary.org/b/isbn/9781416618607-M.jpg', 2],
        ['Visible Learning for Teachers', 'John Hattie', 'Teaching Resources / Assessment', '9780415690157', 2012, 'https://covers.openlibrary.org/b/isbn/9780415690157-M.jpg', 2],
        ['Teach Like a Champion 3.0', 'Doug Lemov', 'Teaching Resources / Classroom Strategies', '9781119712619', 2021, 'https://covers.openlibrary.org/b/isbn/9781119712619-M.jpg', 2],
        ['The First Days of School', 'Harry K. Wong and Rosemary T. Wong', 'Teaching Resources / Classroom Management', '9780976423386', 2018, 'https://covers.openlibrary.org/b/isbn/9780976423386-M.jpg', 2],
        ['Teaching at Its Best', 'Linda B. Nilson', 'College Learning / Teaching Methods', '9781119158660', 2016, 'https://covers.openlibrary.org/b/isbn/9781119158660-M.jpg', 2],
        ['The Skillful Teacher', 'Jon Saphier, Mary Ann Haley-Speca, and Robert Gower', 'Teaching Resources / Teaching Methods', '9781118450291', 2018, 'https://covers.openlibrary.org/b/isbn/9781118450291-M.jpg', 2],
        ['The Art and Science of Teaching', 'Robert J. Marzano', 'Teaching Resources / Teaching Methods', '9781416605713', 2007, 'https://covers.openlibrary.org/b/isbn/9781416605713-M.jpg', 2],
        ['Calculus: Early Transcendentals', 'James Stewart', 'College Textbooks / Mathematics', '9781285741550', 2015, 'https://covers.openlibrary.org/b/isbn/9781285741550-M.jpg', 2],
        ['Campbell Biology', 'Lisa A. Urry, Michael L. Cain, Steven A. Wasserman, Peter V. Minorsky, and Rebecca B. Orr', 'College Textbooks / Biology', '9780135188743', 2020, 'https://covers.openlibrary.org/b/isbn/9780135188743-M.jpg', 2],
        ['Chemistry: The Central Science', 'Theodore L. Brown, H. Eugene LeMay, Bruce E. Bursten, Catherine J. Murphy, Patrick M. Woodward, and Matthew W. Stoltzfus', 'College Textbooks / Chemistry', '9780134414232', 2018, 'https://covers.openlibrary.org/b/isbn/9780134414232-M.jpg', 2],
        ['Psychology', 'David G. Myers and C. Nathan DeWall', 'College Textbooks / Psychology', '9781319132101', 2021, 'https://covers.openlibrary.org/b/isbn/9781319132101-M.jpg', 2],
        ['Introduction to Hospitality', 'John R. Walker', 'College Textbooks / Hospitality and Tourism', '', null, '', 2],
        ['Tourism: Principles and Practice', 'Chris Cooper, John Fletcher, Alan Fyall, David Gilbert, and Stephen Wanhill', 'College Textbooks / Hospitality and Tourism', '', null, '', 2],
    ];
    foreach ($books as $book) {
        $seed->execute($book);
    }

    return $connection;
}
