# Legacy Import Notes

The greenfield application is intentionally separate from `POS_v1/_review_tivoli/tivoli`.

Recommended import approach:

1. Restore `POS_v1/techbaza_hotelpos.sql` into a temporary legacy database.
2. Run the new migrations into the target database.
3. Import reference data in this order: users, rooms, extras, expense categories, bookings, booking extras, payments, stock movements, expenses.
4. Map old roles: `admin` to `administrator`, `clerk` to `reception`.
5. Preserve old financial values:
   - `bookings.rate_per_night` comes from legacy `bookings.rate_per_night` when present.
   - if legacy `rate_per_night` is null, use the legacy room rate that existed at import review time and flag for manual audit.
   - `booking_extras.unit_price` and `payments.amount` are copied as historical facts.
6. Do not import destructive deletes. Convert inactive/void/cancelled records to explicit status fields.

