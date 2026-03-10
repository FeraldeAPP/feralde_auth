# ARCHITECTURE RULES

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

| Element       | Purpose                                          |
| ------------- | ------------------------------------------------ |
| `<header>`    | Page or section header                           |
| `<nav>`       | Navigation links                                 |
| `<main>`      | Primary page content (one per page)              |
| `<section>`   | Thematic grouping of content                     |
| `<article>`   | Self-contained content block                     |
| `<aside>`     | Supplementary or sidebar content                 |
| `<footer>`    | Page or section footer                           |
| `<form>`      | Input forms                                      |
| `<label>`     | Form field labels (must be associated to inputs) |
| `<button>`    | Clickable actions                                |
| `<h1>`-`<h6>` | Headings in strict descending hierarchy          |

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

The directory structure below defines the architectural pattern. Feature folders must not expand into mini-applications.

```text
frontend/
+-- src/
|   +-- lib/
|   |   +-- api/
|   |   |   +-- client.ts       (Centralized HTTP client)
|   |   |   +-- types.ts        (Shared API response types)
|   |   +-- utils.ts            (Shared utility functions)
|   +-- features/
|   |   +-- [feature-name]/
|   |   |   +-- pages/          (REQUIRED - Feature page components)
|   |   |   +-- api/            (OPTIONAL - Feature API functions)
|   |   |   |   +-- index.ts
|   |   |   |   +-- [feature].api.ts
|   |   |   +-- types/          (REQUIRED - Feature type definitions)
|   |   |   |   +-- index.ts
|   |   |   |   +-- [feature].types.ts
|   |   |   +-- components/     (OPTIONAL - Feature-local components)
|   |   |   +-- hooks/          (OPTIONAL - Feature-local hooks)
|   |   |   +-- [feature].routes.ts  (REQUIRED - Route definitions)
|   +-- components/
|   |   +-- ui/                 (Design system components)
|   +-- hooks/                  (Shared custom hooks)
|   +-- routes/
|   |   +-- index.tsx           (Route assembly)
|   |   +-- layouts.tsx         (Layout route definitions)
|   +-- stores/                 (Global state stores)
|   +-- styles/                 (Global styles)
|   +-- assets/                 (Static assets)
+-- public/
+-- tests/
```

### Feature Pattern

Each feature follows this structure:

**REQUIRED folders:**
- `pages/` - All page components
- `types/` - Type definitions with index.ts
- `[feature].routes.ts` - Route configuration

**OPTIONAL folders:**
- `api/` - Feature-specific API functions (uses centralized client)
- `components/` - Components used only within this feature
- `hooks/` - Hooks used only within this feature

**Example minimal feature:**
```text
features/
+-- dashboard/
    +-- pages/
    |   +-- DashboardPage.tsx
    +-- types/
    |   +-- index.ts
    +-- dashboard.routes.ts
```

**Example full feature:**
```text
features/
+-- products/
    +-- pages/
    |   +-- ProductsPage.tsx
    |   +-- ProductDetailPage.tsx
    |   +-- CreateProductPage.tsx
    +-- api/
    |   +-- index.ts
    |   +-- products.api.ts
    +-- types/
    |   +-- index.ts
    |   +-- products.types.ts
    +-- components/
    |   +-- ProductCard.tsx
    |   +-- ProductForm.tsx
    +-- hooks/
    |   +-- use-products.ts
    +-- products.routes.ts
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
