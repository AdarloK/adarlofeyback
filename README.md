# Product Management API (LavaLust)

REST API for **Laboratory Exercise No. 6** - built with the LavaLust framework, JWT authentication
and an Aiven MySQL database.

Opening the deployed API base URL returns a JSON health response with the API name,
running status, version, and endpoint list.

## Endpoints

| Method | URL | Auth | Description |
|---|---|---|---|
| POST | `/api/auth/register` | - | Create an account |
| POST | `/api/auth/login` | - | Returns `tokens.access_token` + `tokens.refresh_token` |
| POST | `/api/auth/refresh` | - | Body `{ "refresh_token": "..." }` -> new tokens |
| POST | `/api/auth/logout` | - | Body `{ "refresh_token": "..." }` -> revokes it |
| GET | `/api/auth/me` | Bearer | Current user |
| GET | `/api/products` | Bearer | List products (`?search=`) |
| GET | `/api/products/{id}` | Bearer | One product |
| POST | `/api/products` | Bearer | Add a product |
| PUT / PATCH | `/api/products/{id}` | Bearer | Update a product |
| DELETE | `/api/products/{id}` | Bearer | Delete a product |

Send the token as `Authorization: Bearer <access_token>`.
Access tokens last 15 minutes; the React app refreshes them automatically.

Product body:

```json
{ "product_name": "Wireless Mouse", "description": "2.4GHz", "price": 499.00, "quantity": 50 }
```

## Setup

```bash
cp .env.example .env          # fill in the Aiven credentials
php lava jwt:generate         # writes JWT_SECRET and REFRESH_TOKEN_KEY into .env
php lava migration run        # creates migrations, users, refresh_tokens, products (+ seed data)
php lava serve                # http://127.0.0.1:3000
```

Default account created by the migrations: **admin / Admin@123** (change it).

## Migration commands

```bash
php lava migration status
php lava migration run
php lava migration create-migration create_something_table
php lava migration rollback
php lava migration rollback-all     # development database only!
php lava migration refresh          # development database only!
```

## Environment variables (never commit `.env`)

`APP_ENV`, `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `DB_CHARSET`,
`DB_SSL`, `DB_SSL_CA`, `DB_SSL_VERIFY`, `JWT_SECRET`, `REFRESH_TOKEN_KEY`, `ALLOW_ORIGIN`

## Deploy to Render

New **Web Service** -> connect this repo -> Runtime **Docker** -> add the environment variables above
(`APP_ENV=production`, `DB_SSL=true`, `ALLOW_ORIGIN=https://your-frontend.onrender.com`).
