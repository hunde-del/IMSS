# IMS — PHP MySQL Inventory Management System

A simple, lightweight inventory management system built with plain PHP, MySQL and jQuery DataTables. This project is designed to run on a local XAMPP/LAMP stack for small shops or for learning how a PHP + MySQL CRUD app is organized.

## Features

- Simple product, supplier, purchase and customer management
- Order creation and tracking
- DataTables-powered listing with search, sort and pagination
- Modular PHP pages and reusable includes for header/footer

## Prerequisites

- PHP 7.2+ (bundled with XAMPP on Windows)
- MySQL / MariaDB
- A web server (Apache from XAMPP recommended for local use)
- Browser with JavaScript enabled

## Quick start

1. Put the project folder inside your web server document root (for XAMPP use `C:\xampp\htdocs\ims`).
2. Start Apache and MySQL via XAMPP Control Panel.
3. Import the database schema from `database/ims_db.sql` (phpMyAdmin or CLI):

```bash
# Example (from a shell where mysql is available):
mysql -u root -p < database/ims_db.sql
```

4. Configure database credentials in [config.php](config.php). Update host, user, password and database name as needed.
5. Open your browser and go to:

```
http://localhost/ims/
```

## Database

- Schema file: [database/ims_db.sql](database/ims_db.sql)
- The app expects a MySQL database; import the SQL file to create tables and sample data.

## Configuration

- Database connection and site-level settings are in [config.php](config.php).
- If you changed the web root or folder name, update paths accordingly.

## Project structure

- **`config.php`**: Main DB and site configuration.
- **`index.php`**: Dashboard / home page.
- **`product.php`, `category.php`, `brand.php`**: CRUD pages for catalog management.
- **`purchase.php`, `order.php`**: Purchase/order workflows.
- **`supplier.php`, `customer.php`**: Supplier and customer management.
- **`inc/header.php`, `inc/footer.php`**: Shared header/footer templates.
- **`js/`**: Client-side scripts (DataTables, page-specific JS).
- **`css/`**: Styles including DataTables styling.
- **`database/ims_db.sql`**: Database schema and seed data.

## Usage notes

- User authentication is implemented via `login.php` — change default credentials in the database if required.
- DataTables scripts are referenced in the `js/` folder. If listing pages appear empty, check browser console for JS errors.

## Development

- To add a feature, create a new PHP page and use the existing `inc/header.php` and `inc/footer.php` for a consistent layout.
- Follow existing patterns for server-side validation and prepared statements where appropriate.

## Troubleshooting

- Blank page or PHP errors: enable `display_errors` in `php.ini` during development or check Apache/PHP error logs.
- Database connection errors: verify credentials in [config.php](config.php) and that MySQL is running.

## Contributing

- Open an issue to discuss larger changes.
- For small fixes, send a pull request with a clear description of changes.

## License

This project is provided as-is for educational and small business use. Use or modify under your desired license (suggested: MIT).

---
