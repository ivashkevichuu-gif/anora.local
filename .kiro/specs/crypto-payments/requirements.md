# Requirements Document

## Introduction

This document specifies the integration of cryptocurrency payments into the anora.bet lottery platform via the NOWPayments payment gateway. The feature adds two new flows: crypto deposits (user pays crypto → platform credits USD balance) and crypto withdrawals/payouts (user requests USD withdrawal → platform sends crypto). Both flows integrate with the existing `users.balance` column, `user_transactions` audit trail, and admin panel. All webhook processing is idempotent and cryptographically verified.

## Glossary

- **System**: The anora.bet lottery platform backend (PHP 8.4 / MySQL InnoDB).
- **NOWPayments_API**: The NOWPayments REST API v1 used for creating invoices and payouts (`https://api.nowpayments.io/v1`).
- **Invoice_Service**: The backend component responsible for creating and tracking crypto deposit invoices via NOWPayments_API.
- **Payout_Service**: The backend component responsible for creating and tracking crypto withdrawal payouts via NOWPayments_API.
- **Webhook_Handler**: The backend endpoint that receives and validates IPN (Instant Payment Notification) callbacks from NOWPayments.
- **Crypto_Invoice**: A record in the `crypto_invoices` table representing a single deposit request, linked to a NOWPayments invoice.
- **Crypto_Payout**: A record in the `crypto_payouts` table representing a single withdrawal/payout request, linked to a NOWPayments payout.
- **IPN_Secret**: The HMAC-SHA512 secret key configured in the NOWPayments dashboard, used to validate webhook signatures.
- **API_Key**: The NOWPayments API key stored in backend configuration (`backend/config/nowpayments.php`), used for authenticating API requests.
- **User_Transactions_Log**: The existing append-only `user_transactions` table recording every balance change for each user.
- **Account_Page**: The React user account page at `/account`.
- **Admin_Panel**: The React admin interface accessible at `/admin/*`.
- **Deposit_Rate_Limit**: Maximum 5 deposit invoice creation requests per user per hour.
- **Withdrawal_Rate_Limit**: Maximum 3 withdrawal requests per user per day.
- **Minimum_Deposit**: $1.00 USD equivalent.
- **Minimum_Withdrawal**: $5.00 USD equivalent.
- **Maximum_Daily_Withdrawal**: $10,000.00 USD equivalent per user per calendar day (UTC).

---

## Requirements

### Requirement 1: NOWPayments Configuration and API Client

**User Story:** As a developer, I want a centralized configuration and HTTP client for NOWPayments API calls, so that all crypto payment logic uses consistent authentication and error handling.

#### Acceptance Criteria

1. THE System SHALL store NOWPayments credentials in `backend/config/nowpayments.php` returning an associative array with keys: `api_key`, `ipn_secret`, `api_base_url`, `sandbox_mode`.
2. THE System SHALL provide a `NowPaymentsClient` class in `backend/includes/nowpayments.php` that wraps all HTTP calls to NOWPayments_API.
3. WHEN the NowPaymentsClient makes an API request, THE NowPaymentsClient SHALL include the header `x-api-key` with the configured API_Key value.
4. IF a NOWPayments_API request returns an HTTP status code outside the 200–299 range, THEN THE NowPaymentsClient SHALL throw an exception containing the HTTP status code and response body.
5. IF a NOWPayments_API request fails due to a network error or timeout, THEN THE NowPaymentsClient SHALL throw an exception with a descriptive message and log the failure.
6. THE NowPaymentsClient SHALL set a request timeout of 30 seconds for all API calls.

---

### Requirement 2: Crypto Deposit Invoice Creation

**User Story:** As a player, I want to create a crypto deposit invoice by specifying a USD amount, so that I can pay with my preferred cryptocurrency.

#### Acceptance Criteria

1. WHEN a logged-in user submits a deposit request with a valid USD amount, THE Invoice_Service SHALL call `POST /v1/invoice` on NOWPayments_API with `price_amount` set to the requested USD amount and `price_currency` set to `usd`.
2. WHEN the NOWPayments_API returns a successful invoice response, THE Invoice_Service SHALL insert a row into `crypto_invoices` with `status = 'pending'`, `user_id`, `nowpayments_invoice_id`, `amount_usd`, `currency` (if selected), and `created_at`.
3. WHEN the invoice is created successfully, THE Invoice_Service SHALL return the NOWPayments invoice URL (or invoice ID for hosted checkout redirect) to the frontend.
4. IF the requested amount is less than Minimum_Deposit ($1.00), THEN THE Invoice_Service SHALL reject the request with HTTP 400 and message "Minimum deposit is $1.00 USD".
5. THE Invoice_Service SHALL enforce Deposit_Rate_Limit: WHEN a user has created 5 or more invoices within the last 60 minutes, THE Invoice_Service SHALL reject the request with HTTP 429 and message "Too many deposit requests. Try again later."
6. IF the NOWPayments_API returns an error, THEN THE Invoice_Service SHALL return HTTP 502 with a generic error message and log the full API response.

---

### Requirement 3: Deposit Webhook Processing

**User Story:** As the platform operator, I want deposit payments confirmed via webhook to automatically credit user balances, so that the deposit flow is fully automated.

#### Acceptance Criteria

1. WHEN NOWPayments sends an IPN webhook to the deposit webhook endpoint, THE Webhook_Handler SHALL validate the HMAC-SHA512 signature by computing `hash_hmac('sha512', json_encode(sorted_payload), IPN_Secret)` and comparing it to the `x-nowpayments-sig` header.
2. IF the HMAC signature is invalid, THEN THE Webhook_Handler SHALL return HTTP 400 and log `[Webhook] Invalid signature for payment_id={id}`.
3. WHEN the webhook payload contains `payment_status = 'finished'` AND the corresponding Crypto_Invoice has `status != 'confirmed'`, THE Webhook_Handler SHALL within a single transaction: (a) update the Crypto_Invoice status to `confirmed`, (b) update `amount_crypto` and `currency` from the payload, (c) credit the user's balance with the `price_amount` (USD equivalent), (d) insert a row into User_Transactions_Log with `type = 'crypto_deposit'`, `amount = price_amount`, `user_id`, `note` containing the `payment_id`.
4. WHEN the webhook payload contains `payment_status` of `waiting`, `confirming`, or `sending`, THE Webhook_Handler SHALL update the Crypto_Invoice status to the corresponding mapped status without crediting the balance.
5. WHEN the webhook payload contains `payment_status = 'expired'` or `payment_status = 'failed'`, THE Webhook_Handler SHALL update the Crypto_Invoice status to `expired` or `failed` respectively.
6. THE Webhook_Handler SHALL be idempotent: WHEN a webhook arrives for a Crypto_Invoice that already has `status = 'confirmed'`, THE Webhook_Handler SHALL return HTTP 200 without performing any balance or log operations.
7. IF the `nowpayments_invoice_id` from the webhook does not match any Crypto_Invoice record, THEN THE Webhook_Handler SHALL return HTTP 200 and log `[Webhook] Unknown invoice_id={id}, ignoring`.

---

### Requirement 4: Partial and Over-Payments

**User Story:** As the platform operator, I want partial and over-payments handled correctly, so that users receive fair credit and disputes are minimized.

#### Acceptance Criteria

1. WHEN a webhook reports `payment_status = 'partially_paid'`, THE Webhook_Handler SHALL update the Crypto_Invoice status to `partially_paid` and SHALL NOT credit the user's balance.
2. WHEN a webhook reports `payment_status = 'finished'` AND `actually_paid` (in crypto) converts to a USD value less than the original `price_amount`, THE Webhook_Handler SHALL credit the user's balance with the `outcome_amount` (actual USD received as reported by NOWPayments) and update the Crypto_Invoice `amount_usd` to reflect the actual credited amount.
3. WHEN a webhook reports `payment_status = 'finished'` AND the `outcome_amount` exceeds the original `price_amount`, THE Webhook_Handler SHALL credit the user's balance with the original `price_amount` only and log `[Webhook] Overpayment on invoice_id={id}: outcome={outcome} requested={requested}`.
4. THE Crypto_Invoice record SHALL store both the originally requested `amount_usd` and the actual credited amount in a separate `credited_usd` column.

---

### Requirement 5: Crypto Withdrawal Request

**User Story:** As a player, I want to withdraw funds from my balance to my crypto wallet, so that I can cash out my winnings in cryptocurrency.

#### Acceptance Criteria

1. WHEN a logged-in user submits a withdrawal request with a valid USD amount, wallet address, and currency, THE Payout_Service SHALL validate: (a) amount >= Minimum_Withdrawal ($5.00), (b) amount <= Maximum_Daily_Withdrawal ($10,000.00) considering all withdrawals for the user in the current UTC day, (c) user's balance >= amount.
2. WHEN validation passes, THE Payout_Service SHALL within a single transaction: (a) deduct the amount from the user's balance, (b) insert a row into `crypto_payouts` with `status = 'pending'`, `user_id`, `amount_usd`, `wallet_address`, `currency`, (c) insert a row into User_Transactions_Log with `type = 'crypto_withdrawal'`, `amount = amount_usd`, `user_id`.
3. AFTER the transaction commits, THE Payout_Service SHALL call `POST /v1/payout` on NOWPayments_API to initiate the crypto transfer and update the Crypto_Payout with the `nowpayments_payout_id`.
4. IF the amount is less than Minimum_Withdrawal, THEN THE Payout_Service SHALL reject with HTTP 400 and message "Minimum withdrawal is $5.00 USD".
5. IF the user's total withdrawals for the current UTC day plus the requested amount exceed Maximum_Daily_Withdrawal, THEN THE Payout_Service SHALL reject with HTTP 400 and message "Daily withdrawal limit of $10,000 exceeded".
6. THE Payout_Service SHALL enforce Withdrawal_Rate_Limit: WHEN a user has submitted 3 or more withdrawal requests within the current UTC day, THE Payout_Service SHALL reject with HTTP 429 and message "Maximum 3 withdrawal requests per day".
7. IF the user's balance is insufficient, THEN THE Payout_Service SHALL reject with HTTP 400 and message "Insufficient balance".

---

### Requirement 6: Withdrawal Webhook Processing and Failure Refund

**User Story:** As the platform operator, I want withdrawal payouts tracked via webhook with automatic refunds on failure, so that user funds are never lost.

#### Acceptance Criteria

1. WHEN NOWPayments sends an IPN webhook for a payout, THE Webhook_Handler SHALL validate the HMAC-SHA512 signature using the same method as deposit webhooks.
2. WHEN the webhook payload contains a payout status of `finished`, THE Webhook_Handler SHALL update the Crypto_Payout status to `completed`.
3. WHEN the webhook payload contains a payout status of `failed` or `expired`, THE Webhook_Handler SHALL within a single transaction: (a) update the Crypto_Payout status to `failed`, (b) refund the `amount_usd` back to the user's balance, (c) insert a row into User_Transactions_Log with `type = 'crypto_withdrawal_refund'`, `amount = amount_usd`, `user_id`, `note` containing the `nowpayments_payout_id`.
4. THE Webhook_Handler SHALL be idempotent for payout webhooks: WHEN a Crypto_Payout already has `status = 'completed'` or `status = 'failed'`, THE Webhook_Handler SHALL return HTTP 200 without performing any operations.
5. IF the NOWPayments_API call to create a payout fails (HTTP error or timeout), THEN THE Payout_Service SHALL update the Crypto_Payout status to `failed` and refund the user's balance within a transaction, logging `[Payout] API failure for payout_id={id}: {error}`.

---

### Requirement 7: Database Schema for Crypto Payments

**User Story:** As a developer, I want the database schema extended to support crypto invoices, payouts, and user crypto preferences.

#### Acceptance Criteria

1. THE System SHALL create `crypto_invoices` table: `id INT AUTO_INCREMENT PRIMARY KEY`, `user_id INT NOT NULL` (FK → `users.id`), `nowpayments_invoice_id VARCHAR(64) DEFAULT NULL`, `amount_usd DECIMAL(15,2) NOT NULL`, `credited_usd DECIMAL(15,2) DEFAULT NULL`, `amount_crypto VARCHAR(64) DEFAULT NULL`, `currency VARCHAR(10) DEFAULT NULL`, `status ENUM('pending','waiting','confirming','confirmed','partially_paid','expired','failed') NOT NULL DEFAULT 'pending'`, `invoice_url TEXT DEFAULT NULL`, `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, `INDEX idx_user_id (user_id)`, `INDEX idx_nowpayments_invoice_id (nowpayments_invoice_id)`, `INDEX idx_status (status)`.
2. THE System SHALL create `crypto_payouts` table: `id INT AUTO_INCREMENT PRIMARY KEY`, `user_id INT NOT NULL` (FK → `users.id`), `nowpayments_payout_id VARCHAR(64) DEFAULT NULL`, `amount_usd DECIMAL(15,2) NOT NULL`, `wallet_address VARCHAR(255) NOT NULL`, `currency VARCHAR(10) NOT NULL`, `status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending'`, `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, `INDEX idx_user_id (user_id)`, `INDEX idx_nowpayments_payout_id (nowpayments_payout_id)`, `INDEX idx_status (status)`.
3. THE System SHALL add to `users` table: `default_crypto_currency VARCHAR(10) DEFAULT NULL`, `default_wallet_address VARCHAR(255) DEFAULT NULL`.
4. ALL schema changes SHALL use `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` for safe idempotent migrations.

---

### Requirement 8: Webhook Signature Validation

**User Story:** As the platform operator, I want all incoming webhooks cryptographically verified, so that forged payment notifications are rejected.

#### Acceptance Criteria

1. THE Webhook_Handler SHALL compute the expected signature as `hash_hmac('sha512', $request_body_json, IPN_Secret)` where `$request_body_json` is the sorted JSON-encoded payload (keys sorted alphabetically at all nesting levels).
2. THE Webhook_Handler SHALL compare the computed signature against the `x-nowpayments-sig` HTTP header using `hash_equals()` to prevent timing attacks.
3. IF the `x-nowpayments-sig` header is missing, THEN THE Webhook_Handler SHALL return HTTP 400 and log `[Webhook] Missing signature header`.
4. IF the signature comparison fails, THEN THE Webhook_Handler SHALL return HTTP 400 and log `[Webhook] Signature mismatch for request from IP={ip}`.
5. THE Webhook_Handler SHALL read the raw request body using `file_get_contents('php://input')` before any JSON decoding to preserve the exact bytes for signature computation.

---

### Requirement 9: Deposit Rate Limiting

**User Story:** As the platform operator, I want deposit requests rate-limited to prevent abuse of the invoice creation API.

#### Acceptance Criteria

1. WHEN a user attempts to create a deposit invoice, THE Invoice_Service SHALL count the number of `crypto_invoices` rows for that user with `created_at >= NOW() - INTERVAL 1 HOUR`.
2. IF the count is >= 5 (Deposit_Rate_Limit), THEN THE Invoice_Service SHALL return HTTP 429 with message "Too many deposit requests. Try again later." without calling NOWPayments_API.
3. THE rate limit check SHALL occur before any NOWPayments_API call to avoid unnecessary external requests.

---

### Requirement 10: Withdrawal Limits and Rate Limiting

**User Story:** As the platform operator, I want withdrawal amounts and frequency limited to manage risk and prevent abuse.

#### Acceptance Criteria

1. WHEN a user submits a withdrawal request, THE Payout_Service SHALL compute the user's total withdrawal amount for the current UTC day by summing `amount_usd` from `crypto_payouts` where `user_id = ?` AND `DATE(created_at) = CURDATE()` AND `status != 'failed'`.
2. IF the sum of existing daily withdrawals plus the requested amount exceeds Maximum_Daily_Withdrawal ($10,000.00), THEN THE Payout_Service SHALL reject with HTTP 400.
3. THE Payout_Service SHALL count the user's withdrawal requests for the current UTC day from `crypto_payouts` where `DATE(created_at) = CURDATE()`.
4. IF the count is >= 3 (Withdrawal_Rate_Limit), THEN THE Payout_Service SHALL reject with HTTP 429.
5. THE minimum withdrawal amount SHALL be $5.00 USD; requests below this SHALL be rejected with HTTP 400.

---

### Requirement 11: Frontend Crypto Deposit Tab

**User Story:** As a player, I want a crypto deposit interface in my account page, so that I can easily create and track crypto deposits.

#### Acceptance Criteria

1. THE Account_Page SHALL include a "Crypto Deposit" tab (or integrate into the existing "Deposit" tab with a crypto option).
2. WHEN the user selects crypto deposit, THE Frontend SHALL display an amount input field (USD) with a "Create Invoice" button.
3. WHEN the user submits a valid amount, THE Frontend SHALL call `POST /backend/api/account/crypto_deposit.php` and display the returned invoice URL or redirect the user to the NOWPayments hosted payment page.
4. WHILE an invoice is pending, THE Frontend SHALL display the invoice status with the amount and creation time.
5. THE Frontend SHALL display a list of recent crypto invoices with their statuses (pending, confirming, confirmed, expired, failed).
6. IF the API returns a rate limit error (HTTP 429), THEN THE Frontend SHALL display the rate limit message to the user.

---

### Requirement 12: Frontend Crypto Withdrawal Tab

**User Story:** As a player, I want a crypto withdrawal interface in my account page, so that I can withdraw my balance to a crypto wallet.

#### Acceptance Criteria

1. THE Account_Page SHALL include a "Crypto Withdraw" tab (or integrate into the existing "Withdraw" tab with a crypto option).
2. THE Frontend SHALL display input fields for: withdrawal amount (USD), wallet address, and cryptocurrency selection dropdown.
3. WHEN the user submits a valid withdrawal request, THE Frontend SHALL call `POST /backend/api/account/crypto_withdraw.php` and display a confirmation message with the pending payout status.
4. THE Frontend SHALL display a list of recent crypto payouts with their statuses (pending, processing, completed, failed).
5. IF the API returns a validation error (HTTP 400) or rate limit error (HTTP 429), THEN THE Frontend SHALL display the specific error message to the user.
6. THE Frontend SHALL pre-fill the wallet address and currency fields from the user's saved defaults (`default_wallet_address`, `default_crypto_currency`) when available.

---

### Requirement 13: Frontend Crypto Transaction History

**User Story:** As a player, I want to see my crypto deposits and withdrawals in my transaction history, so that I can track all my financial activity.

#### Acceptance Criteria

1. THE existing transaction history on the Account_Page SHALL display `crypto_deposit`, `crypto_withdrawal`, and `crypto_withdrawal_refund` transaction types alongside existing types.
2. WHEN displaying a crypto transaction, THE Frontend SHALL show the type with a distinct badge color (e.g., orange for crypto deposit, blue for crypto withdrawal).
3. THE Frontend SHALL display the associated crypto currency and status for crypto transactions when available in the note field.

---

### Requirement 14: Admin Crypto Invoices Panel

**User Story:** As an admin, I want to view all crypto invoices with their statuses, so that I can monitor deposit activity and troubleshoot issues.

#### Acceptance Criteria

1. THE Admin_Panel SHALL include a "Crypto Invoices" navigation entry.
2. THE page SHALL display a paginated table of all `crypto_invoices`: id, user email, amount_usd, credited_usd, currency, status, nowpayments_invoice_id, created_at.
3. THE page SHALL support filtering by status (pending, confirming, confirmed, expired, failed).
4. ALL data SHALL be fetched from a protected admin endpoint requiring a valid admin session.

---

### Requirement 15: Admin Crypto Payouts Panel

**User Story:** As an admin, I want to view all crypto payouts with their statuses, so that I can monitor withdrawal activity and handle failures.

#### Acceptance Criteria

1. THE Admin_Panel SHALL include a "Crypto Payouts" navigation entry.
2. THE page SHALL display a paginated table of all `crypto_payouts`: id, user email, amount_usd, wallet_address, currency, status, nowpayments_payout_id, created_at.
3. THE page SHALL support filtering by status (pending, processing, completed, failed).
4. ALL data SHALL be fetched from a protected admin endpoint requiring a valid admin session.

---

### Requirement 16: Admin Manual Payout Approval

**User Story:** As an admin, I want the option to manually approve large withdrawals, so that I can add an extra layer of security for high-value payouts.

#### Acceptance Criteria

1. WHERE the manual approval feature is enabled in configuration, THE Payout_Service SHALL set `status = 'awaiting_approval'` instead of immediately calling NOWPayments_API for withdrawals exceeding a configurable threshold (default: $500.00 USD).
2. WHEN an admin approves a pending payout via the Admin_Panel, THE System SHALL call NOWPayments_API to initiate the payout and update the status to `processing`.
3. WHEN an admin rejects a pending payout, THE System SHALL within a single transaction: (a) update the Crypto_Payout status to `rejected`, (b) refund the `amount_usd` to the user's balance, (c) insert a refund row into User_Transactions_Log.
4. THE `crypto_payouts` status ENUM SHALL include `awaiting_approval` and `rejected` as additional values.

---

### Requirement 17: User Crypto Preferences

**User Story:** As a player, I want to save my preferred crypto wallet address and currency, so that I don't have to re-enter them for every withdrawal.

#### Acceptance Criteria

1. WHEN a user submits a withdrawal request, THE Payout_Service SHALL update the user's `default_wallet_address` and `default_crypto_currency` with the submitted values.
2. THE `/api/auth/me.php` endpoint SHALL include `default_wallet_address` and `default_crypto_currency` in the user response.
3. THE Frontend SHALL pre-fill the withdrawal form with the user's saved defaults when available.

---

### Requirement 18: API Endpoints for Crypto Payments

**User Story:** As a frontend developer, I want clean API endpoints for crypto deposit and withdrawal operations.

#### Acceptance Criteria

1. `POST /backend/api/account/crypto_deposit.php` SHALL accept `{ "amount": 25.00 }` and return `{ "invoice_id": "...", "invoice_url": "..." }` on success.
2. `POST /backend/api/account/crypto_withdraw.php` SHALL accept `{ "amount": 50.00, "wallet_address": "...", "currency": "btc" }` and return `{ "payout_id": "...", "message": "Withdrawal request submitted." }` on success.
3. `GET /backend/api/account/crypto_invoices.php` SHALL return a paginated list of the current user's crypto invoices.
4. `GET /backend/api/account/crypto_payouts.php` SHALL return a paginated list of the current user's crypto payouts.
5. `POST /backend/api/webhook/nowpayments.php` SHALL be the public webhook endpoint for NOWPayments IPN callbacks (no session required).
6. `GET /backend/api/admin/crypto_invoices.php` SHALL return a paginated, filterable list of all crypto invoices (admin only).
7. `GET /backend/api/admin/crypto_payouts.php` SHALL return a paginated, filterable list of all crypto payouts (admin only).
8. `POST /backend/api/admin/crypto_payouts.php` SHALL accept `{ "action": "approve"|"reject", "payout_id": 123 }` for manual payout management (admin only).
9. ALL user-facing endpoints SHALL require a valid session via `requireLogin()`.
10. ALL admin endpoints SHALL require a valid admin session.

---

### Requirement 19: Expired Invoice Cleanup

**User Story:** As the platform operator, I want expired and stale invoices cleaned up automatically.

#### Acceptance Criteria

1. THE System SHALL provide a cleanup mechanism that updates `crypto_invoices` with `status = 'pending'` AND `created_at < NOW() - INTERVAL 24 HOUR` to `status = 'expired'`.
2. THE cleanup SHALL run as part of the existing `backend/cron/cleanup.php` script.
3. THE cleanup SHALL log `[Cleanup] Expired {N} stale crypto invoices`.

---

### Requirement 20: Integration with Existing Audit Trail

**User Story:** As the platform operator, I want crypto transactions fully integrated with the existing audit trail, so that all financial activity is tracked in one place.

#### Acceptance Criteria

1. WHEN a crypto deposit is confirmed, THE Webhook_Handler SHALL insert into `user_transactions` with `type = 'crypto_deposit'`.
2. WHEN a crypto withdrawal is requested, THE Payout_Service SHALL insert into `user_transactions` with `type = 'crypto_withdrawal'`.
3. WHEN a crypto withdrawal fails and is refunded, THE Webhook_Handler SHALL insert into `user_transactions` with `type = 'crypto_withdrawal_refund'`.
4. ALL crypto-related `user_transactions` rows SHALL include a `note` field containing the relevant `nowpayments_invoice_id` or `nowpayments_payout_id` for cross-referencing.
5. THE existing `/backend/api/account/transactions.php` endpoint SHALL return crypto transaction types alongside existing types without modification (the query already selects all types from `user_transactions`).
