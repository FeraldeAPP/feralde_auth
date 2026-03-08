# Auth Service

Centralized authentication and authorization microservice. Handles user identity, session lifecycle, and Role-Based Access Control (RBAC) on behalf of all downstream services.

---

## Table of Contents

1. [Overview](#overview)
2. [Stack & Requirements](#stack--requirements)
3. [Setup & Installation](#setup--installation)
4. [How It Works](#how-it-works)
5. [Integration Guide](#integration-guide)
6. [API Reference](#api-reference)
7. [Schema & Data Models](#schema--data-models)
8. [RBAC -- Role-Based Access Control](#rbac--role-based-access-control)
9. [Security Features](#security-features)
10. [Email & Password Flows](#email--password-flows)
11. [Social OAuth](#social-oauth)
12. [Key Files](#key-files)
13. [Architecture Rules](#architecture-rules)

---

## Overview

The **Auth Service** is a standalone Laravel 12 application that acts as the single source of truth for:

- Who a user is (authentication)
- What a user can do (authorization via RBAC)
- Session management (cookie-based, database-backed)
- Account security (locking, deactivation, email verification)

All downstream services **proxy their auth checks** to this service rather than implementing their own. No service issues its own tokens or manages user sessions independently.

---

## Stack & Requirements

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Auth | Laravel Sanctum 4.x (session + token) |
| OAuth | Laravel Socialite 5.x |
| PHP | >= 8.2 |
| Session store | Database (`sessions` table) |
| DB | MySQL / compatible |

---

## Setup & Installation

### 1. Install dependencies

```bash
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Minimum `.env` settings:

```env
APP_URL=http://auth.app.local

DB_HOST=127.0.0.1
DB_DATABASE=auth_db
DB_USERNAME=root
DB_PASSWORD=secret

SESSION_DRIVER=database
SESSION_DOMAIN=.app.local       # Shared cookie domain (dot prefix for subdomains)
SESSION_COOKIE=app_session
SANCTUM_STATEFUL_DOMAINS=app.local,backend.app.local

MAIL_MAILER=smtp
MAIL_HOST=mailhog
MAIL_PORT=1025

# Social OAuth (omit or leave blank to disable social login)
FRONTEND_URL=http://localhost:3000
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
```

### 3. Run migrations

```bash
php artisan migrate
```

### 4. Start the server

```bash
php artisan serve --port=8090
# or via PM2:
pm2 start ecosystem.config.js
```

> Default dev port: **8090**. The Backend service expects the auth service at the URL configured in its `AUTH_SERVICE_URL` env variable.

---

## How It Works

```
Browser / Frontend
        |
        |  cookie (app_session)
        v
  Backend Service
        |
        |  proxies /api/auth/* and /api/user  ->  Auth Service
        |  forwards session cookie
        v
   Auth Service
        |
        +-- validates session against DB
        +-- checks roles / permissions
        +-- returns user + permissions payload
```

### Dual Auth Mode

The Auth Service supports two simultaneous auth mechanisms:

| Mode | Mechanism | Use case |
|---|---|---|
| Session-based | `app_session` cookie | Browser clients (frontend) |
| Token-based | `Authorization: Bearer <token>` | API clients, cross-origin requests |

On login, **both** a session and a Sanctum personal access token are created. Clients may use whichever is appropriate for their context.

### Social OAuth Entry Point

Social login is an additional entry point that ends with a standard Sanctum session, so
downstream integrations require no changes.

```
Browser -> GET /api/auth/social/{provider}/redirect -> JSON { url: "..." }
Browser navigates to provider consent screen
Provider -> GET /api/auth/social/{provider}/callback -> Auth::login() -> redirect to FRONTEND_URL?social_login=1
SPA calls GET /api/user with session cookie -> authenticated
```

---

## Integration Guide

### Step 1 -- Obtain a CSRF cookie

Before making any state-changing request, fetch the CSRF cookie:

```http
GET /csrf-cookie
GET /auth/csrf-cookie
```

The response sets `XSRF-TOKEN` and `app_session` cookies. All subsequent requests must include `X-XSRF-TOKEN` header (automatically handled by Axios with `withCredentials: true`).

### Step 2 -- Login

```http
POST /api/auth/login
Content-Type: application/json

{
  "email": "admin@example.com",
  "password": "secret"
}
```

**Success response `200`:**

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@example.com",
    "last_login_at": "2026-02-26T10:00:00Z",
    "is_active": true,
    "failed_login_attempts": 0,
    "locked_at": null,
    "roles": ["super-admin"],
    "permissions": {
      "app": {
        "resources": ["resources.view", "resources.create", "resources.update", "resources.delete"],
        "reports": ["reports.view", "reports.generate"]
      },
      "settings": {
        "users": ["users.view", "users.create", "users.update", "users.delete"]
      }
    },
    "token": "1|abc123..."
  }
}
```

Store the `token` for bearer auth or rely on the session cookie for browser clients.

### Step 3 -- Identify the current user

```http
GET /api/user
Cookie: app_session=...
# or
Authorization: Bearer 1|abc123...
```

Returns the same payload shape as login.

### Step 4 -- Consuming permissions on a downstream service

The Backend service reads the `permissions` object returned by `/api/user` and uses it to enforce access control on its own routes:

```php
// Example: CheckPermission middleware on the Backend
$user->hasPermission('resources.create');
```

The permission object is a two-level map:

```
category -> module -> [permission slugs]
```

### Step 5 -- Logout

```http
POST /api/auth/logout
```

Destroys the session, revokes all Sanctum tokens, and expires the session cookie.

---

## API Reference

All routes return JSON only (`force.json` middleware is applied globally).

### Public Routes (no auth required)

| Method | Path | Description |
|---|---|---|
| `GET` | `/` | Health check |
| `GET` | `/csrf-cookie` | Set CSRF + session cookies |
| `GET` | `/auth/csrf-cookie` | Alias for CSRF cookie |
| `POST` | `/auth/login` | Login |
| `POST` | `/auth/logout` | Logout (also works unauthenticated, cleans up cookies) |
| `POST` | `/auth/refresh` | Regenerate session ID |
| `GET` | `/auth/verify-email/{id}` | Verify email via signed URL |
| `POST` | `/auth/forgot-password` | Request password reset email |
| `POST` | `/auth/reset-password` | Reset password with token |
| `GET` | `/api/auth/social/{provider}/redirect` | Return OAuth redirect URL as JSON |
| `GET` | `/api/auth/social/{provider}/callback` | Handle OAuth callback; redirect browser to frontend |
| `POST` | `/api/auth/login` | Login (API prefix alias) |
| `POST` | `/api/auth/logout` | Logout (API prefix alias) |
| `POST` | `/api/auth/refresh` | Refresh (API prefix alias) |

The social callback route intentionally returns a browser redirect, not JSON. It is excluded
from the `force.json` middleware so the browser can follow the redirect back to the SPA.

### Protected Routes (require `auth:sanctum`)

| Method | Path | Permission required | Description |
|---|---|---|---|
| `GET` | `/user` | -- | Get current authenticated user |
| `GET` | `/api/user` | -- | Get current authenticated user (API prefix) |
| `POST` | `/resend-verification-email` | -- | Resend email verification |
| `POST` | `/change-password` | -- | Change own password |
| `GET` | `/api/roles` | `users.view` | List all roles with permissions |
| `POST` | `/api/roles` | `users.create` | Create a new role |
| `GET` | `/api/users` | `users.view` | List all users |
| `POST` | `/api/users` | `users.create` | Create a new user |
| `GET` | `/api/users/{id}` | `users.view` | Get user by ID |
| `PUT` | `/api/users/{id}` | `users.update` | Update user |
| `DELETE` | `/api/users/{id}` | `users.delete` | Delete user |
| `POST` | `/api/users/{id}/roles` | `users.update` | Assign roles to user |

---

## Schema & Data Models

### Entity Relationship Diagram

```
+-------------------------+        +--------------------------+
|         users           |        |  personal_access_tokens  |
+-------------------------+        +--------------------------+
| id          bigint PK   |--+     | id            bigint PK  |
| name        string      |  +---> | tokenable_type string    |
| email       string UQ   |  |     | tokenable_id   bigint    |
| password    string      |  |     | name           string    |
| email_verified_at ts?   |  |     | token          string UQ |
| last_login_at     ts?   |  |     | abilities      text?     |
| is_active   bool        |  |     | last_used_at   ts?       |
| failed_login_attempts   |  |     | expires_at     ts?       |
|             smallint    |  |     +--------------------------+
| locked_at   ts?         |  |
| remember_token string?  |  |     +--------------------------+
| created_at  timestamp   |  |     |       sessions           |
| updated_at  timestamp   |  |     +--------------------------+
+------------+------------+  |     | id            string PK  |
             |               |     | user_id       bigint?    |
             | many-to-many  |     | ip_address    string?    |
             v               |     | user_agent    text?      |
+-------------------------+  |     | payload       longtext   |
|       user_role         |  |     | last_activity integer    |
+-------------------------+  |     +--------------------------+
| id          bigint PK   |  |
| user_id     FK->users   |  |     +--------------------------+
| role_id     FK->roles   |  |     |  password_reset_tokens   |
| UNIQUE(user_id,role_id) |  |     +--------------------------+
| created_at  timestamp   |  |     | email    string PK       |
| updated_at  timestamp   |  |     | token    string          |
+-------------+-----------+  |     | created_at  ts?          |
              |              |     +--------------------------+
              v              |
+-------------------------+  |     +--------------------------+
|         roles           |  |     |      social_accounts     |
+-------------------------+  |     +--------------------------+
| id          bigint PK   |  +---> | id            bigint PK  |
| name        string UQ   |        | user_id   FK->users      |
| slug        string UQ   |        | provider      string     |
| description text?       |        | provider_id   string     |
| created_at  timestamp   |        | provider_token  text?    |
| updated_at  timestamp   |        | provider_refresh text?   |
+------------+------------+        | created_at  timestamp    |
             | one-to-one          | updated_at  timestamp    |
             v                     +--------------------------+
+-------------------------+        UNIQUE (provider, provider_id)
|    role_permission      |
+-------------------------+        +--------------------------+
| id          bigint PK   |        |       permissions        |
| role_id     FK->roles UQ|        +--------------------------+
| permissions json        |        | id        bigint PK      |
|  {"mod": ["slug",...]}  |        | permission string        |
| created_at  timestamp   |        |  (category label)        |
| updated_at  timestamp   |        | module    json           |
+-------------------------+        |  {"mod": ["slug",...]}   |
                                   | created_at timestamp     |
                                   | updated_at timestamp     |
                                   +--------------------------+
```

---

### Table: `users`

Primary user identity and account-status store.

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | PK, auto-increment | |
| `name` | varchar(255) | No | -- | | Display name |
| `email` | varchar(255) | No | -- | UNIQUE | Login identifier |
| `password` | varchar(255) | No | -- | | bcrypt hash |
| `email_verified_at` | timestamp | Yes | NULL | | Set when email is confirmed |
| `last_login_at` | timestamp | Yes | NULL | | Updated on each successful login |
| `is_active` | tinyint(1) | No | `1` | | `0` blocks login |
| `failed_login_attempts` | smallint unsigned | No | `0` | | Resets to 0 on success |
| `locked_at` | timestamp | Yes | NULL | | Set after 5 consecutive failures |
| `remember_token` | varchar(100) | Yes | NULL | | Laravel remember-me token |
| `created_at` | timestamp | Yes | NULL | | |
| `updated_at` | timestamp | Yes | NULL | | |

**Indexes:** PRIMARY (`id`), UNIQUE (`email`)

---

### Table: `sessions`

Database-backed session store (Laravel session driver = `database`).

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `id` | varchar(255) | No | -- | PK | Session ID string |
| `user_id` | bigint unsigned | Yes | NULL | INDEX | Linked after login |
| `ip_address` | varchar(45) | Yes | NULL | | IPv4 or IPv6 |
| `user_agent` | text | Yes | NULL | | Raw UA string |
| `payload` | longtext | No | -- | | Encrypted session data (base64) |
| `last_activity` | int | No | -- | INDEX | Unix timestamp |

**Indexes:** PRIMARY (`id`), INDEX (`user_id`), INDEX (`last_activity`)

> Sessions are deleted on logout by `user_id` (all sessions for a user) or by `id` (specific session).

---

### Table: `roles`

Named, slugged role definitions.

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | PK | |
| `name` | varchar(255) | No | -- | UNIQUE | Human label, e.g. `Manager` |
| `slug` | varchar(255) | No | -- | UNIQUE | Machine name, e.g. `manager` |
| `description` | text | Yes | NULL | | |
| `created_at` | timestamp | Yes | NULL | | |
| `updated_at` | timestamp | Yes | NULL | | |

**Special value:** A role with `name = 'super-admin'` bypasses all permission checks.

---

### Table: `user_role`

Many-to-many pivot linking users to roles.

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | PK | |
| `user_id` | bigint unsigned | No | -- | FK->users, CASCADE DELETE | |
| `role_id` | bigint unsigned | No | -- | FK->roles, CASCADE DELETE | |
| `created_at` | timestamp | Yes | NULL | | |
| `updated_at` | timestamp | Yes | NULL | | |

**Indexes:** UNIQUE (`user_id`, `role_id`) -- a user cannot be assigned the same role twice.

---

### Table: `permissions`

Registry of all available permission slugs, organized by category and module.

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | PK | |
| `permission` | varchar(255) | No | -- | | Category label, e.g. `app`, `settings` |
| `module` | json | No | -- | | Module->slugs map (see below) |
| `created_at` | timestamp | Yes | NULL | | |
| `updated_at` | timestamp | Yes | NULL | | |

**`module` column JSON structure:**

```json
{
  "resources": [
    "resources.view",
    "resources.create",
    "resources.update",
    "resources.delete"
  ],
  "reports": [
    "reports.view",
    "reports.generate",
    "reports.approve"
  ]
}
```

Each row represents one **category** (`permission` column) and contains all modules and their permission slugs for that category. This table is **read-only at runtime** -- it drives the module-to-category mapping cache.

---

### Table: `role_permission`

Stores the actual permissions granted to each role (one row per role).

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | PK | |
| `role_id` | bigint unsigned | No | -- | FK->roles, CASCADE DELETE, UNIQUE | One record per role |
| `permissions` | json | No | -- | | Module->slugs map (see below) |
| `created_at` | timestamp | Yes | NULL | | |
| `updated_at` | timestamp | Yes | NULL | | |

**`permissions` column JSON structure:**

```json
{
  "resources": [
    "resources.view",
    "resources.update"
  ],
  "reports": [
    "reports.view"
  ]
}
```

Key is the module name (prefix of the permission slug). Value is an array of granted slugs for that module. Only slugs actually granted to the role appear -- not the full universe from `permissions`.

---

### Table: `personal_access_tokens`

Laravel Sanctum token store for API/bearer authentication.

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | PK | |
| `tokenable_type` | varchar(255) | No | -- | | Morph type, always `App\Models\User` |
| `tokenable_id` | bigint unsigned | No | -- | | User ID |
| `name` | varchar(255) | No | -- | | Token name, always `auth-token` |
| `token` | varchar(64) | No | -- | UNIQUE | SHA-256 hash of the plain token |
| `abilities` | text | Yes | NULL | | JSON array, always `["*"]` |
| `last_used_at` | timestamp | Yes | NULL | | Updated by Sanctum on each use |
| `expires_at` | timestamp | Yes | NULL | | NULL = no expiry |
| `created_at` | timestamp | Yes | NULL | | |
| `updated_at` | timestamp | Yes | NULL | | |

**Indexes:** PRIMARY (`id`), UNIQUE (`token`), INDEX (`tokenable_type`, `tokenable_id`)

> All tokens for a user are deleted on logout. Tokens are revoked entirely -- there is no token refresh mechanism; clients re-login to get a new token.

---

### Table: `password_reset_tokens`

One-time password reset token store.

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `email` | varchar(255) | No | -- | PK | |
| `token` | varchar(255) | No | -- | | Hashed reset token |
| `created_at` | timestamp | Yes | NULL | | Used to enforce expiry |

---

### Table: `social_accounts`

Links users to third-party OAuth provider identities.

| Column | Type | Nullable | Default | Constraints | Notes |
|---|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | PK, auto-increment | |
| `user_id` | bigint unsigned | No | -- | FK->users, CASCADE DELETE | |
| `provider` | varchar(255) | No | -- | | `google` or `facebook` |
| `provider_id` | varchar(255) | No | -- | | Provider's unique user ID (OAuth `sub`) |
| `provider_token` | text | Yes | NULL | | OAuth access token (can exceed 1500 chars) |
| `provider_refresh_token` | text | Yes | NULL | | OAuth refresh token |
| `created_at` | timestamp | Yes | NULL | | |
| `updated_at` | timestamp | Yes | NULL | | |

**Indexes:** PRIMARY (`id`), UNIQUE (`provider`, `provider_id`)

> A single user may have multiple rows -- one per linked provider. The unique constraint on
> `(provider, provider_id)` prevents the same provider account from being linked to more than
> one local user.

---

## Data Models

The following document the exact JSON shapes returned and accepted by the API.

---

### `AuthUser` -- Returned by `/api/user` and Login

```json
{
  "id": 1,
  "name": "Jane Smith",
  "email": "jane@example.com",
  "last_login_at": "2026-02-26T10:00:00.000000Z",
  "is_active": true,
  "failed_login_attempts": 0,
  "locked_at": null,
  "roles": ["manager", "editor"],
  "permissions": {
    "app": {
      "resources": ["resources.view", "resources.update"],
      "reports":   ["reports.view", "reports.generate"]
    },
    "settings": {
      "users": ["users.view"]
    }
  }
}
```

| Field | Type | Notes |
|---|---|---|
| `id` | integer | User primary key |
| `name` | string | Display name |
| `email` | string | Login email |
| `last_login_at` | ISO 8601 string \| null | Timestamp of previous login |
| `is_active` | boolean | `false` means account is deactivated |
| `failed_login_attempts` | integer | 0 or higher; resets on success |
| `locked_at` | ISO 8601 string \| null | Non-null means account is locked |
| `roles` | string[] | Array of role **slugs** |
| `permissions` | object | Two-level map: `category -> module -> slug[]` |

Login additionally returns `token` at the top level of `data`:

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "...AuthUser fields...",
    "token": "1|PlainTextToken..."
  }
}
```

---

### `UserSummary` -- Returned by `GET /api/users` (list)

```json
{
  "id": 1,
  "name": "Jane Smith",
  "email": "jane@example.com",
  "email_verified_at": "2026-01-10T08:00:00.000000Z",
  "created_at": "2026-01-09T07:30:00.000000Z",
  "updated_at": "2026-02-26T10:00:00.000000Z",
  "roles": [
    {
      "id": 2,
      "name": "Manager",
      "permissions": {
        "app": {
          "resources": ["resources.view", "resources.update"]
        }
      }
    }
  ]
}
```

The list endpoint wraps items in a paginated envelope:

```json
{
  "success": true,
  "message": "Users retrieved successfully",
  "data": {
    "users": [],
    "pagination": {
      "current_page": 1,
      "last_page": 4,
      "per_page": 15,
      "total": 52
    }
  }
}
```

**Query parameters for `GET /api/users`:**

| Parameter | Type | Description |
|---|---|---|
| `search` | string | Filters by `name` or `email` (LIKE, case-insensitive) |
| `per_page` | integer | Results per page (default: 15) |

---

### `UserDetail` -- Returned by `GET /api/users/{id}`

```json
{
  "id": 1,
  "name": "Jane Smith",
  "email": "jane@example.com",
  "email_verified_at": "2026-01-10T08:00:00.000000Z",
  "created_at": "2026-01-09T07:30:00.000000Z",
  "updated_at": "2026-02-26T10:00:00.000000Z",
  "roles": ["manager"],
  "permissions": {
    "app": {
      "resources": ["resources.view", "resources.update"]
    }
  }
}
```

> `roles` here is a flat string array of role slugs (unlike the list endpoint which embeds full role objects).

---

### `Role` -- Returned by `GET /api/roles` and `POST /api/roles`

```json
{
  "id": 2,
  "name": "Manager",
  "slug": "manager",
  "description": "Manages records and approvals",
  "permissions": {
    "app": {
      "resources": ["resources.view", "resources.create", "resources.update"],
      "reports":   ["reports.view", "reports.approve"]
    }
  }
}
```

---

### `StoreUser` -- Request body for `POST /api/users`

```json
{
  "name": "John Smith",
  "email": "john@example.com",
  "password": "secret123",
  "role_ids": [2, 5]
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | Yes | Max 255 chars |
| `email` | string | Yes | Must be unique, valid email |
| `password` | string | Yes | Min 8 chars |
| `role_ids` | integer[] | No | Each must exist in `roles` table |

---

### `UpdateUser` -- Request body for `PUT /api/users/{id}`

```json
{
  "name": "John Smith",
  "email": "john@example.com",
  "password": "newpassword123",
  "role_ids": [2]
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | Yes | |
| `email` | string | Yes | Must be unique (ignores own record) |
| `password` | string \| null | No | Omit or send null to keep existing |
| `role_ids` | integer[] | No | Syncs (replaces) role assignments |

---

### `StoreRole` -- Request body for `POST /api/roles`

```json
{
  "name": "Editor",
  "slug": "editor",
  "description": "Creates and updates content records",
  "permission_ids": [
    "resources.view",
    "resources.create",
    "resources.update",
    "reports.view"
  ]
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | Yes | Unique, max 255 |
| `slug` | string | Yes | Unique, max 255 |
| `description` | string \| null | No | |
| `permission_ids` | string[] | Yes | Min 1 item; flat list of permission slugs |

> Slugs are auto-grouped by module prefix and stored in `role_permission.permissions` as a JSON map.

---

### `AssignRoles` -- Request body for `POST /api/users/{id}/roles`

```json
{
  "role_ids": [2, 5]
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `role_ids` | integer[] | Yes | All IDs must exist in `roles` table; replaces existing |

---

### `Login` -- Request body for `POST /api/auth/login`

```json
{
  "email": "admin@example.com",
  "password": "secret"
}
```

---

### Error Envelope

All errors follow this structure:

```json
{
  "success": false,
  "message": "Human-readable error description",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

`errors` is only present on `422 Unprocessable Entity` (validation failures). Other error codes (`401`, `403`, `404`, `500`) include only `success` and `message`.

| HTTP Code | Meaning |
|---|---|
| `200` | Success |
| `201` | Resource created |
| `400` | Bad request (e.g. already verified, invalid reset token) |
| `401` | Not authenticated / invalid credentials / account locked |
| `403` | Authenticated but lacking required permission |
| `404` | Resource not found |
| `422` | Validation failed (includes `errors` object) |
| `500` | Server error |

---

## RBAC -- Role-Based Access Control

The Auth Service implements a **flat-permission, role-grouped RBAC** system. Permissions are fine-grained slugs assigned to roles; users inherit permissions through their roles.

### Conceptual Model

```
User  --(many-to-many)-->  Role  --(one-to-one)-->  RolePermission
                                                         |
                                              { "module": ["perm.slug", ...] }
```

> Full table definitions are in the [Schema & Data Models](#schema--data-models) section above.

---

### Permission Slug Format

```
{module}.{action}
```

Examples:

- `resources.view`
- `resources.create`
- `reports.generate`
- `users.delete`
- `settings.update`

The module prefix (first segment before the dot) is used to group slugs in the JSON structures.

---

### Permission Resolution Flow

When a request hits a protected route:

```
1.  Request arrives with session cookie or Bearer token
2.  auth:sanctum resolves the User model
3.  CheckPermission middleware calls $user->hasPermission('resources.create')
4.  hasPermission():
    a. If user has role 'super-admin' -> return true immediately (bypass all checks)
    b. Load $user->permissions (lazy-loaded, cached per request instance)
         i.  Load user's roles via user_role pivot
         ii. Query role_permission for all role IDs in a single query
         iii.Decode and merge JSON permission arrays (deduped per module)
    c. Search merged structure for the requested slug
    d. Return true / false
5.  false -> 403 JSON  |  true -> proceed to controller
```

**Permission caching:** The merged permissions are stored on `$user->permissionsCache` (instance-level) to prevent repeated DB queries within the same request lifecycle.

**Module-to-category mapping:** A static cache (`User::$moduleToCategoryCache`) maps module names to their parent category by reading the `permissions` table once per process. Used when building the API response shape.

---

### API Response: Permissions Structure

The `/api/user` endpoint (and the login response) return permissions in a two-level hierarchical structure, grouped by **category** then **module**:

```json
{
  "permissions": {
    "app": {
      "resources": ["resources.view", "resources.create"],
      "reports":   ["reports.view"]
    },
    "settings": {
      "users": ["users.view", "users.update"]
    }
  }
}
```

This allows frontend clients to render permission trees by category without any additional lookup.

---

### Super-Admin Bypass

Any user with a role named exactly `super-admin` bypasses all permission checks at the middleware level:

```php
// CheckPermission middleware
if ($user->hasRole('super-admin')) {
    return $next($request);  // skip permission check entirely
}
```

---

### Creating a Role (API)

```http
POST /api/roles
Authorization: Bearer <token>
Content-Type: application/json

{
  "name": "Editor",
  "slug": "editor",
  "description": "Creates and updates content records",
  "permission_ids": [
    "resources.view",
    "resources.create",
    "reports.view"
  ]
}
```

The `permission_ids` array is a flat list of slugs. The service auto-groups them by module prefix before storing in `role_permission`.

---

### Assigning Roles to a User

```http
POST /api/users/{id}/roles
Authorization: Bearer <token>
Content-Type: application/json

{
  "role_ids": [2, 5]
}
```

This **replaces** the user's current role assignments with the supplied list (sync operation).

---

### Multi-Role Permission Merging

When a user holds multiple roles, their permissions are merged at resolution time:

```
Role A: { "resources": ["resources.view"] }
Role B: { "resources": ["resources.create"], "reports": ["reports.view"] }

Merged: {
  "resources": ["resources.view", "resources.create"],
  "reports":   ["reports.view"]
}
```

Duplicates are removed via `array_unique`. The merge result is cached on the user instance for the duration of the request.

---

## Security Features

### Account Locking

Failed login attempts are tracked per user in `failed_login_attempts`. After **5 consecutive failures**, `locked_at` is set to the current timestamp and all subsequent login attempts are rejected regardless of password correctness.

Unlocking requires an administrator to clear `locked_at` and reset `failed_login_attempts` (no self-service unlock).

### Account Deactivation

Setting `is_active = false` blocks login immediately. The user record is preserved for audit purposes -- it is not deleted.

### Single-Session Enforcement

On login, if another user is already authenticated on the same session/request context, the previous user is automatically logged out before the new session is established.

### Session Expiry & Cookie Cleanup

On logout and on unauthenticated `me()` / `refresh()` responses, the service:

1. Invalidates the database session record
2. Expires the session cookie for both the configured domain and `null` (localhost)
3. Sets `Cache-Control: no-store` headers to prevent browser caching

### CSRF Protection

Browser clients must fetch a CSRF cookie (`GET /csrf-cookie`) before any login or state-changing request. The `web` middleware group enforces CSRF validation. The `X-XSRF-TOKEN` header (populated from the `XSRF-TOKEN` cookie by Axios) is used for verification.

### Password Security

- Passwords are hashed with bcrypt via Laravel's `password` cast
- Minimum length: 8 characters
- The forgot-password endpoint **always returns HTTP 200** regardless of whether the email exists (prevents user enumeration attacks)

---

## Email & Password Flows

### Email Verification

1. After account creation, a signed verification URL is sent to the user's email
2. User clicks: `GET /auth/verify-email/{id}?hash=...&signature=...`
3. Signed URL is validated; `email_verified_at` is set on success

```http
POST /resend-verification-email    # Resend (protected, requires auth)
```

### Forgot Password

```http
POST /auth/forgot-password
{ "email": "user@example.com" }
```

Sends a password reset link via email. Always returns HTTP 200.

### Reset Password

```http
POST /auth/reset-password
{
  "email": "user@example.com",
  "token": "<reset-token-from-email>",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

### Change Password (authenticated)

```http
POST /change-password
{
  "current_password": "oldpassword",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

---

## Social OAuth

The Auth Service supports social login via Google and Facebook using Laravel Socialite
(stateless flow). No server-side state is stored between the redirect and callback steps.

### Supported Providers

- `google`
- `facebook`

### Flow

```
1. Frontend calls GET /api/auth/social/{provider}/redirect
2. Service returns JSON: { "success": true, "url": "https://accounts.google.com/..." }
3. Frontend sets window.location.href to the returned URL (browser leaves the SPA)
4. Provider authenticates the user; redirects to GET /api/auth/social/{provider}/callback
5. Service resolves or creates the user, calls Auth::login() to establish a session
6. Browser is redirected to FRONTEND_URL?social_login=1
7. SPA loads, calls GET /api/user with session cookie -> user is authenticated
```

### Account Resolution

| Scenario | Outcome |
|---|---|
| `social_accounts` row exists for `(provider, provider_id)` | Existing linked user is signed in |
| No social row; email matches existing local user | Accounts auto-linked; existing user signed in |
| No social row; email is new | New user created (random password, `email_verified_at` = now()) |

### Error Redirect Parameters

On failure the browser is redirected to `FRONTEND_URL` with one of these query params:

| Parameter | Cause |
|---|---|
| `?social_error=unsupported` | Provider is not `google` or `facebook` |
| `?social_error=auth_failed` | OAuth handshake failed (bad code, user cancelled) |
| `?social_error=account_locked` | User account has `is_active = false` |

### Configuration

Credentials are read from `.env`:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
FRONTEND_URL=http://localhost:3000
```

Redirect URIs registered with each provider must match the callback URL exactly:

- Google: `{APP_URL}/api/auth/social/google/callback`
- Facebook: `{APP_URL}/api/auth/social/facebook/callback`

---

## Key Files

| File | Purpose |
|---|---|
| `routes/api.php` | All `/api/*` routes with auth/permission middleware |
| `routes/web.php` | Root routes, CSRF cookie, non-prefixed auth aliases |
| `app/Models/User.php` | Core auth logic: login, logout, RBAC helpers, session management, validation |
| `app/Http/Controllers/Api/UserController.php` | Thin HTTP layer -- delegates to `User` model static methods |
| `app/Http/Controllers/Api/SocialAuthController.php` | Social OAuth redirect and callback handler |
| `app/Models/SocialAccount.php` | Eloquent model for provider link records |
| `app/Http/Middleware/CheckPermission.php` | Route-level permission guard (`->middleware('permission:module.action')`) |
| `app/Http/Middleware/ForceJsonResponse.php` | Ensures all responses (including errors) are `application/json` |
| `database/migrations/0001_01_01_000000_create_users_table.php` | `users`, `password_reset_tokens`, `sessions` tables |
| `database/migrations/2025_12_08_104431_create_roles_table.php` | `roles` table |
| `database/migrations/2025_12_08_104443_create_user_role_table.php` | `user_role` pivot table |
| `database/migrations/2025_12_09_100000_create_permissions_table.php` | `permissions` registry table |
| `database/migrations/2025_12_09_100001_create_role_permission_table.php` | `role_permission` table |
| `database/migrations/2026_03_04_000001_create_social_accounts_table.php` | `social_accounts` table |

---

**Last Updated**: 2026-03-04
**Version**: 1.2.0

---

# Architecture Rules

## API-Only, Model-Centric Architecture

---

## PROJECT CONTEXT

This is a **backend API** designed to serve JSON responses only. It follows a **model-centric architecture** where business logic lives with data models.

This backend:

- Serves frontend applications
- Exposes REST-style JSON endpoints
- Contains no server-rendered views
- Enforces strict architectural boundaries

---

## CRITICAL: API-ONLY ARCHITECTURE

All endpoints MUST return JSON. No other response format is permitted under any circumstance.

Rules:

- All endpoints MUST return JSON
- No HTML responses
- No server-rendered templates
- No redirects (except health checks)
- No plain-text responses

---

## API VERSIONING

URL-based versioning is strictly forbidden. The API exposes a single, unversioned entrypoint.

Rules:

- API versioning in URLs is forbidden
- Do NOT use `/v1`, `/v2`, or similar prefixes
- Do NOT use `/api/v1`
- Use a single `/api` entrypoint only
- Routes must not embed version information

---

## DOCUMENTATION STYLE (MANDATORY)

All documentation must be written in plain ASCII. No visual decoration is permitted.

Rules:

- Do not use emojis or icon glyphs in any documentation or rules files
- Applies to all `*.md` and `.rules` files
- Do not use non-ASCII arrow glyphs
- Use plain ASCII alternatives instead
- Use `->` for right arrows
- Use `<-` for left arrows

---

## STANDARD JSON RESPONSE FORMAT

All responses must conform to one of the following two structures.

### Success

```json
{
  "success": true,
  "message": "Operation successful",
  "data": {}
}
```

### Error

```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field": ["Error message"]
  }
}
```

### HTTP STATUS CODES

Every response must use the appropriate status code from this list:

- 200 -- OK
- 201 -- Created
- 400 -- Bad Request
- 401 -- Unauthorized
- 403 -- Forbidden
- 404 -- Not Found
- 422 -- Validation Error
- 500 -- Server Error

---

## TYPE SAFETY (MANDATORY)

Strict typing is required in every file. Implicit or ambiguous types are architectural defects.

Rules:

- Every file must enable strict typing
- All parameters must be typed
- All return values must be typed
- Nullable values must be explicit
- Implicit typing is forbidden
- Avoid `mixed` unless genuinely unavoidable

Best practices:

- Prefer immutable data where possible
- Use final classes unless extension is required
- Use readonly properties when applicable

---

## BUSINESS LOGIC LOCATION

### SINGLE SOURCE OF TRUTH

Business logic belongs in models. Controllers are entry points only.

Rules:

- Business logic MUST live in models
- Controllers must remain thin
- Service layers are forbidden
- Logic must not be duplicated

Allowed in models:

- Calculations
- State transitions
- Aggregations
- Validations
- Relationship logic
- Transaction boundaries

Forbidden:

- Service classes
- Business logic in controllers
- Logic spread across layers

---

## TRANSACTION MANAGEMENT

Transaction scope must be confined to model methods. Transactions must never leak into controllers or middleware.

Rules:

- Transactions must be managed within model methods
- Transactions must be scoped to business operations
- Nested or scattered transaction logic is forbidden
- Controllers must never open or manage transactions directly

---

## CONTROLLERS

Controllers are thin orchestrators. They receive input, delegate to models, and return responses.

Controllers MAY:

- Validate input using `Validator::make(...)`
- Authorize requests
- Call model methods
- Return JSON responses
- Invoke response transformers

Controllers MUST NOT:

- Contain business rules
- Perform calculations
- Manage state
- Handle transactions directly
- Implement domain logic
- Access the database directly

---

## VALIDATION

Input validation is the controller's responsibility. All validation errors must follow the standard error response format.

Rules:

- Validation MUST use `Validator::make(...)`
- Validation errors MUST return 422
- Validation responses MUST follow the standard JSON error structure
- Error messages must be human-readable
- Validation must not contain business logic
- Controllers are responsible for validation, not models or services

Example pattern:

```php
$validator = Validator::make($request->all(), [
    'field' => 'required|string'
], [
    'field.required' => 'Field is required'
]);

if ($validator->fails()) {
    return response()->json([
        'success' => false,
        'message' => 'Validation error',
        'errors'  => $validator->errors()
    ], 422);
}
```

---

## DATABASE ACCESS

All database interaction must go through the ORM. Direct SQL or facade usage is forbidden.

Rules:

- Use ORM exclusively
- Raw SQL is forbidden
- `DB::table()` is forbidden
- Direct database facades are forbidden except for `DB::transaction()` inside models
- Unsafe dynamic queries are forbidden
- Query builder must not bypass models

Allowed:

- ORM models
- Model relationships
- ORM query builders through models
- ORM-managed transactions inside models only

---

## SEEDERS

Seeders must reflect real data relationships and respect the order of model dependencies.

Rules:

- Seeders MUST follow existing model relationships
- Seeders MUST respect foreign keys and relationship constraints
- Seeders MUST seed parent records before dependent records
- Seeders MUST use Eloquent models only
- Seeders MUST NOT modify schema
- Seeders MUST NOT contain business logic

---

## MIGRATIONS

Migrations are create-only. Schema mutation through migrations is strictly forbidden.

Rules:

- Migrations are STRICTLY CREATE-ONLY
- Only `create_*_table` migrations are allowed
- `Schema::table(...)` is forbidden
- Incremental, patch, or adjustment migrations are forbidden

Forbidden migration types:

- `add_*`
- `drop_*`
- `rename_*`
- `update_*`
- `make_*`
- Any migration modifying an existing table

Forbidden operations:

- Adding columns
- Dropping columns
- Renaming columns
- Changing column types
- Adding indexes
- Dropping indexes
- Modifying foreign keys
- Modifying constraints

If a schema change is required:

- Modify or replace the original `create_*_table` migration
- Re-run migrations from a clean database state
- Maintain a single source of truth per table

Each table must have exactly one create migration.

---

## ERROR HANDLING

All errors must be returned as JSON. No exception may produce an HTML page or redirect.

Rules:

- All errors must return JSON
- No HTML error pages
- No redirect-based error handling
- Errors must follow standard response format

---

## SECURITY

### CORS

Rules:

- Credentials must be explicitly allowed when required
- Preflight requests must succeed
- CORS must be applied before authentication

### CSRF

Rules:

- Required for session-based authentication
- Tokens must be validated on state-changing requests
- Cookie policies must be explicit

---

## AUTHORIZATION (RBAC)

Access control is enforced at the route or middleware level using dot-notation permissions.

Rules:

- Permissions use dot-notation: `module.action`
- Authorization enforced at route or middleware level
- Super-admin roles may bypass checks
- Authorization failures return 403

---

## HEALTH CHECK

A health check endpoint must exist and return the following response:

```json
{
  "status": "ok"
}
```

---

## PROJECT STRUCTURE (FIXED)

The directory structure below is fixed. No additions or reorganization are permitted without architectural review.

```text
backend/
+-- app/
|   +-- Http/
|   |   +-- Controllers/
|   |   +-- Middleware/
|   |   +-- Requests/
|   |   +-- Resources/
|   +-- Models/
|   +-- Policies/
+-- database/
+-- routes/
|   +-- api.php
+-- tests/
```

This structure is frozen. Changes require architectural review.

---

## GLOBAL PROHIBITIONS

The following are unconditionally forbidden throughout the entire backend:

- No API versioning in URLs
- No service layers
- No raw SQL
- No schema mutation migrations
- No direct database facades
- No HTML responses
- No business logic outside models
- No duplicated logic

---

## FINAL STATEMENT

This backend architecture is intentionally strict.

It prioritizes:

- Clarity
- Predictability
- Type safety
- Long-term maintainability

Violations are architectural defects, not shortcuts.

---

# FRONTEND RULES

## React + TypeScript, API-Only Backend Consumer

---

## PROJECT CONTEXT

This is a **modern React frontend** built with:

- React
- Vite
- TypeScript (strict mode)
- Client-side routing
- Centralized API layer
- Typed state management
- Utility-first CSS

The frontend consumes an **API-only backend** and contains **no backend logic**.

---

## CRITICAL: REACT (NOT NEXT.JS)

This is a **React application**, not Next.js. Next.js directives must never appear in this codebase.

Rules:

- NEVER use `"use client"`
- Do NOT add `"use client"` to components
- Remove `"use client"` if found anywhere in the codebase
- All components are client-side by default

---

## LAYOUT STABILITY (MANDATORY)

All pages MUST prevent layout shift caused by scrollbars appearing or disappearing.

Rules:

- Main scroll containers must use `overflow-auto`
- Use `scrollbar-gutter: stable` on main content containers
- Applies to all pages

Example:

```tsx
<main className="flex-1 overflow-auto" style={{ scrollbarGutter: "stable" }}>
  {children}
</main>
```

Rationale:

- Prevents horizontal layout shift
- Ensures professional, stable UI behavior

---

## API CONSUMPTION

The frontend may only communicate with the backend through the centralized API layer.

Rules:

- Frontend must only call `/api/*`
- Versioned API paths are forbidden
- API calls must not be made directly from components
- All HTTP communication must go through the API layer

---

## TYPE SAFETY (MANDATORY)

TypeScript strict mode is required. Any usage of `any` is a build violation.

Rules:

- `any` is forbidden
- All API responses must be typed
- All component state must be typed
- All hooks must be typed
- Type casting to `any` is forbidden
- Prefer `unknown` over `any`
- NEVER use `useState<any>`
- NEVER use `as any`

TypeScript configuration requirements:

- `strict: true`
- No implicit `any`
- Strict null checks enabled
- Explicit return types for functions

---

## COMMON TYPE SAFETY RULES

Type definitions must be explicit and complete. Narrowing is required before use.

Rules:

- Always define interfaces or types for data structures
- Use union types for controlled values
- Use type guards for narrowing
- Always handle `null | undefined`
- Event handlers must be explicitly typed

---

## FEATURE GROUPING

The UI is organized by feature. Each feature is a self-contained unit.

Rules:

- UI is grouped by feature
- A feature owns its pages, local components, and local hooks
- Features do not own infrastructure
- Features must not depend on other features

---

## FEATURE BOUNDARIES

Features are isolated units. Cross-feature imports are forbidden.

A feature may contain:

- Pages
- Local UI components
- Local hooks
- UI-only types

A feature must not contain:

- API clients
- HTTP calls
- Global state
- Shared utilities
- Backend business logic
- Cross-feature imports

---

## SHARED CODE

Shared code is centralized at the root level. Features must not duplicate or shadow shared code.

Directory mapping:

- Shared UI components -> `components/`
- Shared hooks -> `hooks/`
- Global state -> `stores/`
- Utilities -> `utils/`
- Styling -> `styles/`

Features must not duplicate shared logic.

---

## API LAYER

All API communication is centralized in `src/api`. UI code must not import HTTP clients directly.

Rules:

- All API calls live in `src/api`
- API request and response types live in `src/api/types`
- API endpoints are defined centrally
- Features consume API functions, not URLs

UI code must never import HTTP clients directly.

---

## ROUTING

Routes are assembled at the root level. Features expose pages only and do not self-register.

Rules:

- Routing is centralized
- Features expose pages only
- Features do not register routes globally
- Root router assembles application routes

---

## STATE MANAGEMENT

Each state category has a dedicated layer. Mixing state responsibilities is forbidden.

Rules:

- Server state handled via query/caching library
- Client state handled via centralized stores
- Form state handled via form library
- Do not mix responsibilities

---

## FORM VALIDATION

Forms must use schema-based validation with fully inferred types. No casting with `any`.

Rules:

- Use schema-based validation
- Infer types from schemas
- Never cast form values with `any`
- Always type `setValue`, `onChange`, and callbacks
- Always handle nullable values from inputs

---

## SEMANTIC HTML & ACCESSIBILITY

Markup must be semantically correct and keyboard-accessible. Use elements that carry inherent meaning about the content they contain, rather than generic layout containers.

Rules:

- Use semantic HTML elements
- Maintain proper heading hierarchy
- Ensure keyboard accessibility
- Use labels for form inputs
- Avoid generic `div` usage where semantics apply

### Semantic Element Reference

Use the element that matches the content's meaning:

| Element | Purpose |
|---|---|
| `<header>` | Page or section header |
| `<nav>` | Navigation links |
| `<main>` | Primary page content (one per page) |
| `<section>` | Thematic grouping of content |
| `<article>` | Self-contained content block |
| `<aside>` | Supplementary or sidebar content |
| `<footer>` | Page or section footer |
| `<form>` | Input forms |
| `<label>` | Form field labels (must be associated to inputs) |
| `<button>` | Clickable actions |
| `<h1>`-`<h6>` | Headings in strict descending hierarchy |

### Heading Hierarchy

Headings must descend in order. Never skip levels.

```tsx
// Correct
<h1>Page Title</h1>
<h2>Section</h2>
<h3>Subsection</h3>

// Wrong - skipped h2
<h1>Page Title</h1>
<h3>Subsection</h3>
```

### Form Labels

Every input must have an associated label. Placeholder text is not a substitute for a label.

```tsx
// Correct
<label htmlFor="email">Email</label>
<input id="email" type="email" />

// Wrong - no label
<input type="email" placeholder="Email" />
```

### Keyboard Accessibility

All interactive elements must be reachable and operable via keyboard alone.

- Use native elements (`<button>`, `<a>`, `<input>`) that have built-in keyboard support
- Never attach click handlers to `<div>` or `<span>` without also adding `role`, `tabIndex`, and `onKeyDown`
- Focus order must follow a logical reading sequence

### What To Avoid

#### Generic `div` Overuse

```tsx
// Wrong - div soup with no meaning
<div className="header">
  <div className="nav">
    <div onClick={handleClick}>Home</div>
  </div>
</div>

// Correct
<header>
  <nav>
    <a href="/">Home</a>
  </nav>
</header>
```

#### Clickable Non-Interactive Elements

```tsx
// Wrong - div acting as a button
<div onClick={handleSubmit}>Submit</div>

// Correct
<button type="submit" onClick={handleSubmit}>Submit</button>
```

#### Missing Labels on Inputs

```tsx
// Wrong
<input type="text" placeholder="Username" />

// Correct
<label htmlFor="username">Username</label>
<input id="username" type="text" />
```

#### Broken Heading Order

```tsx
// Wrong - jumped from h1 to h4
<h1>Dashboard</h1>
<h4>Recent Activity</h4>

// Correct
<h1>Dashboard</h1>
<h2>Recent Activity</h2>
```

Core principle: if an element has a native HTML equivalent that matches the content's meaning, use it. Reach for `<div>` only when no semantic element fits.

---

## STYLING

Styling follows a utility-first approach with consistent, reusable patterns.

Rules:

- Utility-first CSS approach
- Use conditional class helpers when needed
- Follow responsive design patterns
- Keep styling consistent and reusable

---

## FILE NAMING

All files must follow the naming conventions below:

- Components: PascalCase `.tsx`
- Hooks: `use-*`
- Stores: `*-store.ts`
- Types: `.ts`
- Utilities: kebab-case `.ts`

---

## BUILD REQUIREMENTS

The build must pass without errors before any commit. No exceptions.

Pre-commit checklist:

- No TypeScript errors
- No unused variables
- No missing imports
- No `any`
- No broken path aliases

Always run a full build before commit.

---

## FRONTEND STRUCTURE (FIXED)

The directory structure below is fixed. Feature folders must not expand into mini-applications.

```text
frontend/
+-- src/
|   +-- api/
|   |   +-- client.ts
|   |   +-- endpoints/
|   |   +-- types/
|   +-- features/
|   |   +-- feature-a/
|   |   |   +-- pages/
|   |   |   +-- components/
|   |   |   +-- hooks/
|   |   |   +-- types.ts
|   |   +-- feature-b/
|   |   +-- feature-c/
|   +-- components/
|   +-- hooks/
|   +-- routes/
|   +-- stores/
|   +-- utils/
|   +-- styles/
+-- public/
+-- tests/
```

This structure is frozen. Feature folders must not become mini-applications.

---

## GLOBAL PROHIBITIONS

The following are unconditionally forbidden throughout the entire frontend:

- No `/v1` APIs
- No `any` types
- No service layers
- No direct API calls from UI
- No duplicated backend logic
- No cross-feature imports
- No business logic in frontend
- Do not create or modify `.md` files unless explicitly requested by the user

---

## FINAL STATEMENT

This architecture is intentionally strict. It prioritizes clarity, enforceability, and long-term maintainability. Deviations are architectural decisions, not refactors.

---

## MONOREPO RULES

- Each directory contains its own `.rules` and `context.md`
- Directory-specific rules take precedence over root rules
