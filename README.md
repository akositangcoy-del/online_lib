# EL ROYALE Library Dashboard

## Run locally with XAMPP

1. Start Apache and MySQL from the XAMPP Control Panel.
2. Open `http://localhost/APPLICATION/`.
3. Use **Register here** to create the first member account.

Create the `library_db` database using your supplied SQL, then open the app. On its first API request, it creates or upgrades the `users`, `books`, and `borrowed_books` tables, and seeds a starter catalog of real books with author, ISBN, publication year, and Open Library cover links. Unborrowed demo placeholders from older versions are removed; entries with borrowing history are kept. PHP must have PDO MySQL enabled.

The default connection is `localhost`, database `library_db`, user `root`, and a blank password, which matches a typical local XAMPP install. For another setup, define `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the environment inherited by Apache before starting it. Do not put database credentials in browser code or commit them to the project.

## Deploy to a web host

1. **Back up and export the local database.** In XAMPP phpMyAdmin, select `library_db`, choose **Export**, use the **Quick** method and **SQL** format, then download the `.sql` backup. Keep this backup somewhere private.
2. **Create the production database.** In your hosting control panel, create a MySQL database and a database user, then grant that user access to the database. Record the database host, name, username, and password provided by the host; the database host is not always `localhost`.
3. **Import the backup.** Open the hosting phpMyAdmin, select the production database, choose **Import**, and upload the `.sql` backup. Do not place the SQL dump in the public website folder.
4. **Upload the application.** Upload the PHP, HTML, and `assets` files to the web root or application folder. Do not overwrite or expose the SQL backup.
5. **Configure database credentials privately.** InfinityFree may not provide per-application environment variables. In its File Manager, copy `private/database.example.php` to `private/database.php`, then edit the copy and enter the MySQL host, database name, username, and password shown under **MySQL Databases** in the hosting control panel. Keep `private/.htaccess` in place; it blocks public web access to the private folder. The real `private/database.php` is excluded by `.gitignore`. Alternatively, define `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the PHP environment; environment values take precedence. Never put production credentials in `db.php`, browser code, or a public file.
6. **Check PHP requirements.** Enable PHP with PDO MySQL (`pdo_mysql`) and use a supported PHP version.
7. **Test the deployed app.** Open the site over HTTPS, sign in, check that the catalog and users are present, and test borrowing and admin-only returns. Promote an administrator through phpMyAdmin if needed, using the instructions below.

The application creates or upgrades its own `users`, `books`, and `borrowed_books` tables and seeds catalog entries on an API request. This does not replace importing your existing database backup: import it first when you need to preserve local accounts, books, or borrowing history. The automatic setup uses `CREATE TABLE IF NOT EXISTS` and does not copy data from your local MySQL server by itself.

On a non-local website, the app now reports a clear setup message if neither hosting environment variables nor `private/database.php` are configured. The values in `private/database.example.php` are placeholders and must be replaced with the exact database details from the hosting panel.

## Available features

- Member registration, sign-in, and sign-out using usernames, PHP sessions, and password hashes.
- Live catalog search and author, category, and availability filters.
- A catalog of classic, popular, technology, college, hospitality, and teaching-method books with real authors, ISBNs where available, publication years, and cover images.
- College textbooks and teaching resources for lesson planning, classroom management, inclusive teaching, and assessment.
- A **Preview** action opens Google Books reading pages when the publisher makes them available; full access is subject to Google Books and publisher restrictions.
- Members can borrow available books with 14-day due dates; administrators review all loans and record returns.
- Member profile, password, and appearance settings.
- Admin notifications for active member loans, plus member due-date notifications, activity, dashboard counts, and reports.
- Admin-only book catalog and return management. The admin dashboard checks for new borrowing activity every 30 seconds.

## Create an administrator

1. Register an account through the app and note its username.
2. In phpMyAdmin, select `library_db`, open the SQL tab, and run:

   ```sql
   UPDATE users SET role = 'admin' WHERE username = 'your_username';
   ```

3. Sign out and sign back in. The **Manage Books** section will appear.

The app adds a `role` column defaulting to `member`; public registrations cannot assign themselves admin privileges. Only an administrator can create, edit, or delete catalog entries, view all borrowing records, and record returns. Members can see their own borrowing history but cannot return books through the app. Deletion is blocked if a book has borrowing history, and copies cannot be reduced below its active loans.

Stylesheets, JavaScript, and local images are organized under `assets/css`, `assets/js`, and `assets/images`.
