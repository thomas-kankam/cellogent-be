# Cellogent API — Postman payloads

Base URL: `{{base_url}}/api`  
Example: `http://localhost/api` or `http://127.0.0.1:8000/api`

Every response uses the same envelope:

```json
{
  "in_error": false,
  "status_code": "200",
  "message": "OTP verified.",
  "reason": "verified",
  "data": {},
  "point_in_time": "2026-10-26 16:00:00"
}
```

`reason` values you should branch on: `otp_sent`, `verified`, `expired`, `not_found`, `invalid`, `account_inactive`, `unverified`, `email_taken`, `created`, `success`, `validation_error`, `unauthenticated`, `too_many_requests`.

Auth header after verify: `Authorization: Bearer {{access_token}}`

Profile photo is always a **file** field (`profile_image`), never base64. Use `form-data` for those requests.

Local only: OTP request/signup may include `data.debug_otp` when `APP_ENV=local`.

Single records go on `data` as a flat object (no `account` / `token` / `otp` wrappers). Lists use `data` as an array with `pagination` beside it.

---

## Client — self signup

`POST /client/signup`  
Creates the client with `is_verified: false`. No wallet, subscription, or archive yet. A registration OTP is emailed.

**Individual** (`account_type=individual`) — required: `first_name`, `last_name`, `date_of_birth`, `email`.  
JSON:

```json
{
  "account_type": "individual",
  "first_name": "Ama",
  "last_name": "Mensah",
  "email": "ama@example.com",
  "phone_number": "0244000000",
  "date_of_birth": "1994-05-12",
  "gender": "female"
}
```

**Business** (`account_type=business`) — required: `business_name`, `region`, `city`, `address`, `profile_image` (file), `email`. Names/DOB are optional.  
Body: `form-data`

| Key | Example |
|---|---|
| account_type | business |
| email | ama@example.com |
| phone_number | 0244000000 |
| business_name | Ama Foods |
| region | Greater Accra |
| city | Accra |
| address | 12 Ring Road |
| profile_image | *(file)* |

Then `POST /client/verify_otp` with `type: registration`. That sets `is_verified` / `verified_at`, opens a wallet, grants a 10-unit 1-month Trial, writes a complimentary `subscription_archieves` row (no payment), and returns a Passport token. Login OTPs (`type: login`) are rejected until that registration OTP succeeds.

---

## Client — OTP request

`POST /client/request_otp`

```json
{
  "email": "ama@example.com",
  "type": "login"
}
```

`type` is `login` or `registration`. Default `login`.

---

## Client — OTP resend

`POST /client/resend_otp_request`

```json
{
  "email": "ama@example.com",
  "type": "login"
}
```

---

## Client — OTP verify (returns Passport token + wallet + subscription)

`POST /client/verify_otp`

After signup, use `type: registration`:

```json
{
  "email": "ama@example.com",
  "otp": "482193",
  "type": "registration"
}
```

Later logins use `type: login`:

```json
{
  "email": "ama@example.com",
  "otp": "482193",
  "type": "login"
}
```

Save `data.access_token`. Token fields (`access_token`, `token_type`, `expires_at`) sit on `data` next to the profile — there is no `token` or `account` wrapper. `expires_at` is the datetime stored on the token row.

---

## Client — profile

`GET /client/profile`  
Header: Bearer token

`POST /client/profile` (use this when sending a photo)  
Body: `form-data`

| Key | Example |
|---|---|
| first_name | Ama |
| last_name | Mensah |
| phone_number | 0244000000 |
| city | Kumasi |
| profile_image | *(file, jpg/png/webp, max 2MB)* |

`PUT /client/profile` with JSON works for text fields only.

---

## Client — my activity logs

`GET /client/activity-logs?page=1&per_page=15&module=otp`

---

## Client — logout

`POST /client/logout`

---

## Admin — OTP request / resend / verify

Seeded admin email: `admin@cellogent.com` (or `SEED_ADMIN_EMAIL`)

`POST /admin/request_otp`

```json
{
  "email": "admin@cellogent.com",
  "type": "login"
}
```

`POST /admin/resend_otp_request`

```json
{
  "email": "admin@cellogent.com",
  "type": "login"
}
```

`POST /admin/verify_otp` — same flat `data` shape as client login, plus `role` and `role.permissions`.

```json
{
  "email": "admin@cellogent.com",
  "otp": "482193",
  "type": "login"
}
```

---

## Admin — profile

`GET /admin/profile`

`POST /admin/profile` form-data: `first_name`, `last_name`, `phone_number`, `profile_image` (file)

---

## Admin — onboard a client

`POST /admin/onboard/client`  
Body: `form-data` (same fields as client signup)

---

## Admin — onboard an admin (role assignment happens here)

`POST /admin/onboard/admin`  
Body: `form-data`

| Key | Example |
|---|---|
| first_name | Kojo |
| last_name | Asante |
| email |  |
| phone_number | 0200000000 |
| role_id | 1 |
| profile_image | *(file)* |

JSON:

```json
{
  "first_name": "Kojo",
  "last_name": "Asante",
  "email": "kojo.admin@cellogent.com",
  "phone_number": "0200000000",
  "role_id": 1
}
```

`role_id` must already exist and already have permissions attached.

---

## Roles

`GET /admin/roles?page=1&per_page=15`

`POST /admin/roles`

```json
{
  "name": "Finance",
  "description": "Wallet and billing",
  "permission_ids": [1, 5, 9]
}
```

`GET /admin/roles/1`

`PUT /admin/roles/1`

```json
{
  "name": "Finance",
  "description": "Wallet and billing",
  "permission_ids": [1, 5, 9, 12]
}
```

`POST /admin/roles/assign`

```json
{
  "admin_id": 2,
  "role_id": 1
}
```

---

## Permissions

`GET /admin/permissions?page=1&per_page=50`

---

## Activity logs (admin)

All logs:

`GET /admin/activity-logs?page=1&per_page=20&module=sender_id&action=approved&actor=admin`

One client's timeline (who requested a sender ID, who approved, reason, time):

`GET /admin/clients/1/activity-logs?module=sender_id`

Example `data[]` row:

```json
{
  "id": 44,
  "title": "Sender ID approved",
  "module": "sender_id",
  "action": "approved",
  "actor": "admin",
  "transmitter": { "type": "Admin", "id": 1, "name": "Super Admin", "email": "admin@cellogent.com" },
  "receiver": { "type": "Client", "id": 1, "name": "Ama Mensah", "email": "ama@example.com" },
  "metadata": { "sender_id": "CELLOGENT", "reason": null },
  "created_at": "2026-10-26 16:12:00"
}
```

---

## Client — plans, wallet, subscription, sender IDs, payments, orders

`GET /client/plans` is public (active plans only). The rest need `Authorization: Bearer {{access_token}}`.

`GET /client/wallet`

`GET /client/wallet/top-ups`

`POST /client/wallet/top-ups`

```json
{
  "amount": 50,
  "network": "MTN",
  "method": "momo"
}
```

`GET /client/subscription`

`GET /client/subscription/archives`

`POST /client/subscription` — buy a plan. `method: wallet` completes immediately; `momo`/`card`/`bank` stay pending until admin completes the payment.

```json
{
  "plan_id": 1,
  "months": 1,
  "type": "monthly",
  "network": "MTN",
  "method": "momo"
}
```

`months` must match one of the plan `details[]` options (e.g. 1, 2, 3).

`GET /client/sender-ids`

`POST /client/sender-ids`

```json
{
  "name": "CELLOGENT"
}
```

`GET /client/sender-ids/1`

`GET /client/payments?status=pending&purpose=sms_units`

`GET /client/payments/1`

`GET /client/orders`

`POST /client/orders`

```json
{
  "type": "airtime",
  "name": "MTN Airtime",
  "unit_price": 10,
  "quantity": 1,
  "network": "MTN",
  "method": "momo",
  "meta_data": { "phone": "0244000000" }
}
```

`GET /client/orders/1`

---

## Admin — plans, subscriptions, wallets, sender IDs, payments, orders

`GET /admin/plans`

`POST /admin/plans`

```json
{
  "name": "Gold",
  "status": "active",
  "details": [
    { "price": 200, "units": 8000, "months": 1 },
    { "price": 380, "units": 16000, "months": 2 },
    { "price": 540, "units": 24000, "months": 3 }
  ]
}
```

`PUT /admin/plans/1` — same body as create.

`GET /admin/subscriptions?client_id=1&status=active`

`GET /admin/subscription-archives?client_id=1`

`GET /admin/clients/1/subscription`

`POST /admin/clients/1/subscription` — assign a plan without payment.

```json
{
  "plan_id": 2,
  "months": 2,
  "type": "monthly"
}
```

`GET /admin/wallets?client_id=1`

`GET /admin/clients/1/wallet`

`GET /admin/wallet-top-ups?client_id=1`

`GET /admin/sender-ids?status=pending&client_id=1`

`POST /admin/sender-ids/1/approve`

`POST /admin/sender-ids/1/reject`

```json
{
  "rejection_reason": "Name already in use"
}
```

`GET /admin/payments?status=pending`

`POST /admin/payments/1/complete` — credits wallet or activates the SMS plan.

`POST /admin/payments/1/fail`

`GET /admin/orders?client_id=1`

---

## Pagination query params (any list)

`page=1&per_page=15`  
`per_page` max is 100.

Lists put rows on `data` (an array) and pagination next to it — not inside `data`:

```json
{
  "in_error": false,
  "status_code": "200",
  "message": "Plans loaded.",
  "reason": "success",
  "data": [],
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total": 40,
    "last_page": 3
  },
  "point_in_time": "2026-10-27 16:00:00"
}
```
