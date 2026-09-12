# Stabilization notes after PR #167

## Historical invoices compatibility

- The stabilization keeps `_wbi_invoice_date` as the primary filter field.
- To preserve older invoices that only have `_wbi_invoice_number`, the code now runs an idempotent backfill (`WBI_Admin_Query_Helper::backfill_missing_invoice_dates`) before invoice list/export/API queries.
- Backfill is bounded in batches and uses each order creation date (WordPress timezone) when `_wbi_invoice_date` is missing.

## Date range policy

- `normalize_date_range_with_meta()` keeps normalization behavior but now exposes metadata (`error_code`, `has_error`, `is_reversed`).
- Admin screens (Dashboard + main report screens + invoice/document screens) show a warning notice and use safe defaults when the submitted range is invalid or reversed.
- REST endpoints return HTTP 400 for invalid/reversed `date_from`/`date_to`.

## Export batching policy

- Export flows for stock, best/worst sellers, costs/margins, scoring, subscribers, invoices and remitos process records in bounded batches.
- Ordering is deterministic (`opened_at/id`, `subscribed_at/id`, score/id, etc.) to avoid duplicated/skipped rows while iterating.

## Manual test matrix

- [ ] valid range
- [ ] nonexistent date
- [ ] reversed range
- [ ] scalar sent as array
- [ ] nested status arrays
- [ ] unknown enums
- [ ] page outside range
- [ ] unsupported per-page size
- [ ] expired/missing GET nonce in read-only filters (filters keep working)
- [ ] WordPress timezone different from UTC
- [ ] HPOS enabled and disabled
- [ ] historical invoice without `_wbi_invoice_date`
- [ ] exports over multiple batches
- [ ] zero, one, and many results
