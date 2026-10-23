-- H289 / Transport V2 — dependency-complete invalidation coverage for Public read models.
-- Existing H242 operational triggers are immutable history. Secondary H289 triggers add missing
-- model dependencies; synthetic safety invalidation remains a bounded backstop for future drift.

CREATE TRIGGER tr_h289_inventory_items_ai AFTER INSERT ON inventory_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_items.insert','inventory_items','*','inventory,inventory_cost,operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_items_au AFTER UPDATE ON inventory_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_items.update','inventory_items','*','inventory,inventory_cost,operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_items_ad AFTER DELETE ON inventory_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_items.delete','inventory_items','*','inventory,inventory_cost,operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_categories_ai AFTER INSERT ON inventory_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_categories.insert','inventory_categories','*','operations_workspace');

CREATE TRIGGER tr_h289_inventory_categories_au AFTER UPDATE ON inventory_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_categories.update','inventory_categories','*','operations_workspace');

CREATE TRIGGER tr_h289_inventory_categories_ad AFTER DELETE ON inventory_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_categories.delete','inventory_categories','*','operations_workspace');

CREATE TRIGGER tr_h289_inventory_balances_ai AFTER INSERT ON inventory_balances FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_balances.insert','inventory_balances','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_balances_au AFTER UPDATE ON inventory_balances FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_balances.update','inventory_balances','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_balances_ad AFTER DELETE ON inventory_balances FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_balances.delete','inventory_balances','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_item_aliases_ai AFTER INSERT ON inventory_item_aliases FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_item_aliases.insert','inventory_item_aliases','*','operations_workspace');

CREATE TRIGGER tr_h289_inventory_item_aliases_au AFTER UPDATE ON inventory_item_aliases FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_item_aliases.update','inventory_item_aliases','*','operations_workspace');

CREATE TRIGGER tr_h289_inventory_item_aliases_ad AFTER DELETE ON inventory_item_aliases FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_item_aliases.delete','inventory_item_aliases','*','operations_workspace');

CREATE TRIGGER tr_h289_inventory_purchase_units_ai AFTER INSERT ON inventory_purchase_units FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_purchase_units.insert','inventory_purchase_units','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_purchase_units_au AFTER UPDATE ON inventory_purchase_units FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_purchase_units.update','inventory_purchase_units','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_purchase_units_ad AFTER DELETE ON inventory_purchase_units FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_purchase_units.delete','inventory_purchase_units','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_movements_ai AFTER INSERT ON inventory_movements FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_movements.insert','inventory_movements','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_movements_au AFTER UPDATE ON inventory_movements FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_movements.update','inventory_movements','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_movements_ad AFTER DELETE ON inventory_movements FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_movements.delete','inventory_movements','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_supply_receipts_ai AFTER INSERT ON inventory_supply_receipts FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_receipts.insert','inventory_supply_receipts','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_supply_receipts_au AFTER UPDATE ON inventory_supply_receipts FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_receipts.update','inventory_supply_receipts','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_supply_receipts_ad AFTER DELETE ON inventory_supply_receipts FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_receipts.delete','inventory_supply_receipts','*','inventory,inventory_cost,operations_workspace,reports');

CREATE TRIGGER tr_h289_inventory_supply_receipt_allocat_ai AFTER INSERT ON inventory_supply_receipt_allocations FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_receipt_allocations.insert','inventory_supply_receipt_allocations','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_supply_receipt_allocat_au AFTER UPDATE ON inventory_supply_receipt_allocations FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_receipt_allocations.update','inventory_supply_receipt_allocations','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_supply_receipt_allocat_ad AFTER DELETE ON inventory_supply_receipt_allocations FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_receipt_allocations.delete','inventory_supply_receipt_allocations','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_count_sessions_ai AFTER INSERT ON inventory_count_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_sessions.insert','inventory_count_sessions','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_count_sessions_au AFTER UPDATE ON inventory_count_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_sessions.update','inventory_count_sessions','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_count_sessions_ad AFTER DELETE ON inventory_count_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_sessions.delete','inventory_count_sessions','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_count_lines_ai AFTER INSERT ON inventory_count_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_lines.insert','inventory_count_lines','*','reports');

CREATE TRIGGER tr_h289_inventory_count_lines_au AFTER UPDATE ON inventory_count_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_lines.update','inventory_count_lines','*','reports');

CREATE TRIGGER tr_h289_inventory_count_lines_ad AFTER DELETE ON inventory_count_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_lines.delete','inventory_count_lines','*','reports');

CREATE TRIGGER tr_h289_inventory_supply_needs_ai AFTER INSERT ON inventory_supply_needs FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_needs.insert','inventory_supply_needs','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_supply_needs_au AFTER UPDATE ON inventory_supply_needs FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_needs.update','inventory_supply_needs','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_supply_needs_ad AFTER DELETE ON inventory_supply_needs FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_supply_needs.delete','inventory_supply_needs','*','operations_workspace,deferred_context,reports');

CREATE TRIGGER tr_h289_inventory_recipe_components_ai AFTER INSERT ON inventory_recipe_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_recipe_components.insert','inventory_recipe_components','*','reports');

CREATE TRIGGER tr_h289_inventory_recipe_components_au AFTER UPDATE ON inventory_recipe_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_recipe_components.update','inventory_recipe_components','*','reports');

CREATE TRIGGER tr_h289_inventory_recipe_components_ad AFTER DELETE ON inventory_recipe_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_recipe_components.delete','inventory_recipe_components','*','reports');

CREATE TRIGGER tr_h289_inventory_recipe_versions_ai AFTER INSERT ON inventory_recipe_versions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_recipe_versions.insert','inventory_recipe_versions','*','reports');

CREATE TRIGGER tr_h289_inventory_recipe_versions_au AFTER UPDATE ON inventory_recipe_versions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_recipe_versions.update','inventory_recipe_versions','*','reports');

CREATE TRIGGER tr_h289_inventory_recipe_versions_ad AFTER DELETE ON inventory_recipe_versions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_recipe_versions.delete','inventory_recipe_versions','*','reports');

CREATE TRIGGER tr_h289_subscribers_ai AFTER INSERT ON subscribers FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('subscribers.insert','subscribers','*','subscribers,deferred_context,reports');

CREATE TRIGGER tr_h289_subscribers_au AFTER UPDATE ON subscribers FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('subscribers.update','subscribers','*','subscribers,deferred_context,reports');

CREATE TRIGGER tr_h289_subscribers_ad AFTER DELETE ON subscribers FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('subscribers.delete','subscribers','*','subscribers,deferred_context,reports');

CREATE TRIGGER tr_h289_subscriber_ledger_ai AFTER INSERT ON subscriber_ledger FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('subscriber_ledger.insert','subscriber_ledger','*','subscribers,deferred_context,reports');

CREATE TRIGGER tr_h289_subscriber_ledger_au AFTER UPDATE ON subscriber_ledger FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('subscriber_ledger.update','subscriber_ledger','*','subscribers,deferred_context,reports');

CREATE TRIGGER tr_h289_subscriber_ledger_ad AFTER DELETE ON subscriber_ledger FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('subscriber_ledger.delete','subscriber_ledger','*','subscribers,deferred_context,reports');

CREATE TRIGGER tr_h289_expense_categories_ai AFTER INSERT ON expense_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('expense_categories.insert','expense_categories','*','deferred_context,reports');

CREATE TRIGGER tr_h289_expense_categories_au AFTER UPDATE ON expense_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('expense_categories.update','expense_categories','*','deferred_context,reports');

CREATE TRIGGER tr_h289_expense_categories_ad AFTER DELETE ON expense_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('expense_categories.delete','expense_categories','*','deferred_context,reports');

CREATE TRIGGER tr_h289_expenses_ai AFTER INSERT ON expenses FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('expenses.insert','expenses','*','reports');

CREATE TRIGGER tr_h289_expenses_au AFTER UPDATE ON expenses FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('expenses.update','expenses','*','reports');

CREATE TRIGGER tr_h289_expenses_ad AFTER DELETE ON expenses FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('expenses.delete','expenses','*','reports');

CREATE TRIGGER tr_h289_financial_periods_ai AFTER INSERT ON financial_periods FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('financial_periods.insert','financial_periods','*','subscribers,reports');

CREATE TRIGGER tr_h289_financial_periods_au AFTER UPDATE ON financial_periods FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('financial_periods.update','financial_periods','*','subscribers,reports');

CREATE TRIGGER tr_h289_financial_periods_ad AFTER DELETE ON financial_periods FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('financial_periods.delete','financial_periods','*','subscribers,reports');

CREATE TRIGGER tr_h289_accommodation_transfers_ai AFTER INSERT ON accommodation_transfers FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('accommodation_transfers.insert','accommodation_transfers','*','reports');

CREATE TRIGGER tr_h289_accommodation_transfers_au AFTER UPDATE ON accommodation_transfers FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('accommodation_transfers.update','accommodation_transfers','*','reports');

CREATE TRIGGER tr_h289_accommodation_transfers_ad AFTER DELETE ON accommodation_transfers FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('accommodation_transfers.delete','accommodation_transfers','*','reports');

CREATE TRIGGER tr_h289_audit_log_ai AFTER INSERT ON audit_log FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('audit_log.insert','audit_log','*','reports');

CREATE TRIGGER tr_h289_audit_log_au AFTER UPDATE ON audit_log FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('audit_log.update','audit_log','*','reports');

CREATE TRIGGER tr_h289_audit_log_ad AFTER DELETE ON audit_log FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('audit_log.delete','audit_log','*','reports');

CREATE TRIGGER tr_h289_settlement_record_lines_ai AFTER INSERT ON settlement_record_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_record_lines.insert','settlement_record_lines','*','reports');

CREATE TRIGGER tr_h289_settlement_record_lines_au AFTER UPDATE ON settlement_record_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_record_lines.update','settlement_record_lines','*','reports');

CREATE TRIGGER tr_h289_settlement_record_lines_ad AFTER DELETE ON settlement_record_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_record_lines.delete','settlement_record_lines','*','reports');

CREATE TRIGGER tr_h289_settlement_tender_allocations_ai AFTER INSERT ON settlement_tender_allocations FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_tender_allocations.insert','settlement_tender_allocations','*','reports');

CREATE TRIGGER tr_h289_settlement_tender_allocations_au AFTER UPDATE ON settlement_tender_allocations FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_tender_allocations.update','settlement_tender_allocations','*','reports');

CREATE TRIGGER tr_h289_settlement_tender_allocations_ad AFTER DELETE ON settlement_tender_allocations FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_tender_allocations.delete','settlement_tender_allocations','*','reports');

CREATE TRIGGER tr_h289_staff_consumptions_ai AFTER INSERT ON staff_consumptions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('staff_consumptions.insert','staff_consumptions','*','preparation,reports');

CREATE TRIGGER tr_h289_staff_consumptions_au AFTER UPDATE ON staff_consumptions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('staff_consumptions.update','staff_consumptions','*','preparation,reports');

CREATE TRIGGER tr_h289_staff_consumptions_ad AFTER DELETE ON staff_consumptions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('staff_consumptions.delete','staff_consumptions','*','preparation,reports');

CREATE TRIGGER tr_h289_user_preparation_areas_ai AFTER INSERT ON user_preparation_areas FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('user_preparation_areas.insert','user_preparation_areas','*','preparation');

CREATE TRIGGER tr_h289_user_preparation_areas_au AFTER UPDATE ON user_preparation_areas FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('user_preparation_areas.update','user_preparation_areas','*','preparation');

CREATE TRIGGER tr_h289_user_preparation_areas_ad AFTER DELETE ON user_preparation_areas FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('user_preparation_areas.delete','user_preparation_areas','*','preparation');

CREATE TRIGGER tr_h289_users_ai AFTER INSERT ON users FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('users.insert','users','*','operations_workspace,notifications,preparation,reports');

CREATE TRIGGER tr_h289_users_au AFTER UPDATE ON users FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('users.update','users','*','operations_workspace,notifications,preparation,reports');

CREATE TRIGGER tr_h289_users_ad AFTER DELETE ON users FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('users.delete','users','*','operations_workspace,notifications,preparation,reports');

CREATE TRIGGER tr_h289_notification_inbox_ai AFTER INSERT ON notification_inbox FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_inbox.insert','notification_inbox','*','notifications');

CREATE TRIGGER tr_h289_notification_inbox_au AFTER UPDATE ON notification_inbox FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_inbox.update','notification_inbox','*','notifications');

CREATE TRIGGER tr_h289_notification_inbox_ad AFTER DELETE ON notification_inbox FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_inbox.delete','notification_inbox','*','notifications');

CREATE TRIGGER tr_h289_notification_outbox_ai AFTER INSERT ON notification_outbox FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_outbox.insert','notification_outbox','*','notifications');

CREATE TRIGGER tr_h289_notification_outbox_au AFTER UPDATE ON notification_outbox FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_outbox.update','notification_outbox','*','notifications');

CREATE TRIGGER tr_h289_notification_outbox_ad AFTER DELETE ON notification_outbox FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_outbox.delete','notification_outbox','*','notifications');

CREATE TRIGGER tr_h289_notification_preferences_ai AFTER INSERT ON notification_preferences FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_preferences.insert','notification_preferences','*','notifications');

CREATE TRIGGER tr_h289_notification_preferences_au AFTER UPDATE ON notification_preferences FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_preferences.update','notification_preferences','*','notifications');

CREATE TRIGGER tr_h289_notification_preferences_ad AFTER DELETE ON notification_preferences FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_preferences.delete','notification_preferences','*','notifications');

CREATE TRIGGER tr_h289_notification_push_subscriptions_ai AFTER INSERT ON notification_push_subscriptions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_push_subscriptions.insert','notification_push_subscriptions','*','notifications');

CREATE TRIGGER tr_h289_notification_push_subscriptions_au AFTER UPDATE ON notification_push_subscriptions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_push_subscriptions.update','notification_push_subscriptions','*','notifications');

CREATE TRIGGER tr_h289_notification_push_subscriptions_ad AFTER DELETE ON notification_push_subscriptions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('notification_push_subscriptions.delete','notification_push_subscriptions','*','notifications');

CREATE TRIGGER tr_h289_settings_ai AFTER INSERT ON settings FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settings.insert','settings','*','operations_workspace,notifications,deferred_context,reports,guest_operational');

CREATE TRIGGER tr_h289_settings_au AFTER UPDATE ON settings FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settings.update','settings','*','operations_workspace,notifications,deferred_context,reports,guest_operational');

CREATE TRIGGER tr_h289_settings_ad AFTER DELETE ON settings FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settings.delete','settings','*','operations_workspace,notifications,deferred_context,reports,guest_operational');

CREATE TRIGGER tr_h289_cafe_tables_ai AFTER INSERT ON cafe_tables FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('cafe_tables.insert','cafe_tables','*','reports');

CREATE TRIGGER tr_h289_cafe_tables_au AFTER UPDATE ON cafe_tables FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('cafe_tables.update','cafe_tables','*','reports');

CREATE TRIGGER tr_h289_cafe_tables_ad AFTER DELETE ON cafe_tables FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('cafe_tables.delete','cafe_tables','*','reports');

CREATE TRIGGER tr_h289_table_sessions_ai AFTER INSERT ON table_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_sessions.insert','table_sessions','*','reports');

CREATE TRIGGER tr_h289_table_sessions_au AFTER UPDATE ON table_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_sessions.update','table_sessions','*','reports');

CREATE TRIGGER tr_h289_table_sessions_ad AFTER DELETE ON table_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_sessions.delete','table_sessions','*','reports');

CREATE TRIGGER tr_h289_orders_ai AFTER INSERT ON orders FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('orders.insert','orders','*','reports');

CREATE TRIGGER tr_h289_orders_au AFTER UPDATE ON orders FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('orders.update','orders','*','reports');

CREATE TRIGGER tr_h289_orders_ad AFTER DELETE ON orders FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('orders.delete','orders','*','reports');

CREATE TRIGGER tr_h289_order_items_ai AFTER INSERT ON order_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_items.insert','order_items','*','reports');

CREATE TRIGGER tr_h289_order_items_au AFTER UPDATE ON order_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_items.update','order_items','*','reports');

CREATE TRIGGER tr_h289_order_items_ad AFTER DELETE ON order_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_items.delete','order_items','*','reports');

CREATE TRIGGER tr_h289_waiter_calls_ai AFTER INSERT ON waiter_calls FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('waiter_calls.insert','waiter_calls','*','reports');

CREATE TRIGGER tr_h289_waiter_calls_au AFTER UPDATE ON waiter_calls FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('waiter_calls.update','waiter_calls','*','reports');

CREATE TRIGGER tr_h289_waiter_calls_ad AFTER DELETE ON waiter_calls FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('waiter_calls.delete','waiter_calls','*','reports');

CREATE TRIGGER tr_h289_items_ai AFTER INSERT ON items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('items.insert','items','*','reports');

CREATE TRIGGER tr_h289_items_au AFTER UPDATE ON items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('items.update','items','*','reports');

CREATE TRIGGER tr_h289_items_ad AFTER DELETE ON items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('items.delete','items','*','reports');
