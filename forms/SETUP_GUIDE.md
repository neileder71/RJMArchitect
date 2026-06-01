# Website Database Setup Guide

## Files

1. `db_config.php` - Database connection configuration.
2. `contact.php` - Contact form handler with database storage.
3. `quote.php` - Quote form handler with database storage.
4. `database_setup.sql` - Creates the database, form tables, admin login table, and project file upload table.

## Setup Instructions

### Step 1: Create the Database

Use one of the following methods.

#### Method A: phpMyAdmin

1. Open phpMyAdmin, usually at `http://localhost/phpmyadmin`.
2. Click the SQL tab.
3. Copy and paste the contents of `database_setup.sql`.
4. Click Go.

#### Method B: MySQL Command Line

1. Open Command Prompt or Terminal.
2. Navigate to your MySQL bin directory, for example `C:\xampp\mysql\bin`.
3. Run:

```powershell
mysql -u root -p < "C:\xampp\htdocs\public_html\public_html\forms\database_setup.sql"
```

4. Press Enter if your local XAMPP root account has no password.

### Step 2: Verify Configuration

Open `db_config.php` and verify these settings match your MySQL setup:

```php
$db_host = 'localhost';
$db_username = 'u649217041_rjm_architect';
$db_password = '072410Rjm';
$db_name = 'u649217041_rjm_architect';
```

For local XAMPP, make sure that MySQL user exists and has access to the database.

### Step 3: Login to Dashboard

1. Open `http://localhost/public_html/public_html/Admin/New%20folder/login.html`.
2. Use the default admin account for full access:
   - Email: `admin@rjmarchibuild.com`
   - Password: `admin123`
3. Use one of the default employee accounts for limited access:
   - Email: `employee@rjmarchibuild.com`
   - Password: `employee123`
   - Email: `employee2@rjmarchibuild.com`
   - Password: `employee223`
   - Email: `employee3@rjmarchibuild.com`
   - Password: `employee323`
4. Change the default passwords after setup by updating the `admins.password_hash` values with new `password_hash()` results.

### Account Access

- Admin accounts have full dashboard access.
- Employee accounts can access the Project Files workspace, add or rename project names, and upload DWG/PDF files under Drawing Details.
- Employee accounts cannot access admin-only controls like Settings and message deletion.

### Step 4: Test the Forms

1. Navigate to your contact page or quote page in the browser.
2. Fill out the form and submit.
3. Open the admin dashboard to confirm submitted messages appear.

### Step 5: View Submitted Data

1. Open phpMyAdmin.
2. Go to `u649217041_rjm_architect`.
3. Browse the `contact_submissions`, `quote_submissions`, `project_files`, or `admins` table.

## Features

- Direct database storage.
- Admin and employee logins stored in the database with hashed passwords.
- Role-based dashboard access.
- Drawing Details uploads for `.dwg` and `.pdf` files.
- Project name management for adding and renaming project folders.
- Server-side form validation.
- SQL injection protection with prepared statements.
- JSON responses for the admin AJAX endpoints.
- Timestamps for each submission.
- Error handling and reporting.

## Troubleshooting

**Error: Connection failed**

- Check if MySQL is running in the XAMPP Control Panel.
- Verify credentials in `db_config.php`.
- Confirm the MySQL user exists and has permission for `u649217041_rjm_architect`.

**Error: Table does not exist**

- Run `database_setup.sql` again.

**Admin login fails**

- Confirm the `admins` table contains `admin@rjmarchibuild.com`.
- Confirm the account status is `active`.
- Reset the password hash if needed.
