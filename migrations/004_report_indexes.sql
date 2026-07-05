-- Additional indexes for Reports & Analytics filters and aggregates.
ALTER TABLE bookings ADD INDEX idx_bookings_created_by (created_by);
ALTER TABLE bookings ADD INDEX idx_bookings_updated (updated_at);
ALTER TABLE booking_extras ADD INDEX idx_booking_extras_created (created_at);
ALTER TABLE booking_extras ADD INDEX idx_booking_extras_created_by (created_by);
ALTER TABLE expenses ADD INDEX idx_expenses_user (user_id);
ALTER TABLE expenses ADD INDEX idx_expenses_method (method);
ALTER TABLE stock_movements ADD INDEX idx_stock_created_by (created_by);
ALTER TABLE stock_movements ADD INDEX idx_stock_type_created (movement_type, created_at);