# Boat Sunset — API (PHP + MySQL)

Upload this `api/` folder to your cPanel `public_html/api/` alongside the static site.

## Setup

1. In cPanel → MySQL Databases: create a database and user. Note the credentials.
2. Copy `config.example.php` to `config.php`.
3. Edit `config.php` with your MySQL host, database, username, password.
4. The table `boat_sunset_tickets` is created automatically on first request.

## Endpoints

- **GET** `/api/tickets.php?password=admin123` — List all tickets (no cache)
- **POST** `/api/tickets.php` — Body: `{ "password": "admin123", "tickets": [...] }` — Full sync (replaces all)

Use the same password as the admin panel (`admin123`).
