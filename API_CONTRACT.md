# UCC-FMS — API Contract (Phase 0 of the PHP port)

_Machine-extracted from `app.py` route tables and `server.py` handler bodies on 2026-06-11._
_This document + the gate suites (`smoke_test.py` 11 checks, `regression_fixes.py` 49 checks,
both language-agnostic HTTP clients) define ACCEPTANCE for any re-implementation: a PHP
backend that serves these routes and passes 60/60 is, by definition, functionally complete._

## Conventions
- Auth: `POST /api/login {username, password}` → `{ok, sid, user{username, full_name, role}}`;
  subsequent calls send `X-Session-ID: <sid>` header (or the session cookie). MFA: response may
  instead be `{ok:false, mfa_required:true, challenge_id}` → `POST /api/security/mfa/verify`.
- Envelope: success `{ok:true, ...payload}`; failure `{ok:false, error:"message"}` (HTTP usually 200).
- Roles: Admin, Finance Officer, Project Leader (SBS: 'Head of Department'), Auditor (read-only: ALL POSTs except auth/MFA rejected).
- Money: GHS floats rounded to 2dp; dates ISO `YYYY-MM-DD`; periods monthly `YYYY-MM`.
- Posting paths route through ONE canonical balanced-journal gate; unbalanced journals are rejected.

## Route inventory: 432 routes (248 GET, 184 POST)

### GET endpoints

| Path | Handler | Query params | Response keys (probed) |
|---|---|---|---|
| `/api/acceptance-testing` | `api_acceptance_testing` | — | {checked_at, checks, score, status} |
| `/api/accounting-periods` | `api_get_accounting_periods` | — | ARRAY[13] of {closed_at, closed_by, created_at, end_date, id, opened_at, opened_by…} |
| `/api/active-sessions` | `api_get_active_sessions` | — | {count, sessions} |
| `/api/actuals` | `api_get_actuals` | — | ARRAY[0] |
| `/api/actuals/` | `(inline handler)` | — | — |
| `/api/actuals/lines` | `api_get_actual_lines` | — | ARRAY[0] |
| `/api/admin-recovery` | `(inline handler)` | — | — |
| `/api/advanced-query/` | `(inline handler)` | — | — |
| `/api/ai-governance` | `api_ai_governance` | — | {governance, recent_logs} |
| `/api/ai/context` | `api_ai_context` | — | {context} |
| `/api/ap/aging` | `api_ap_aging` | — | {bills, buckets, by_vendor, total_outstanding} |
| `/api/ap/bill-lines` | `api_ap_bill_lines` | — | {error} |
| `/api/ap/bills` | `api_ap_bills` | — | {bills} |
| `/api/ap/recurring` | `api_ap_recurring` | — | {templates} |
| `/api/ap/statement` | `api_ap_statement` | — | {error} |
| `/api/ap/vendors` | `api_ap_vendors` | — | {vendors} |
| `/api/app-version` | `api_app_version` | — | {app, build, database_path, environment, release, role, user, version} |
| `/api/approval-notification-centre` | `api_approval_notification_centre` | — | {alerts, count, generated_at, version} |
| `/api/approval-rules` | `api_get_approval_rules` | — | ARRAY[7] of {created_at, document_type, id, is_active, level, level_label, max_amount…} |
| `/api/approval-rules-v55` | `api_approval_rules` | — | {approval_rules} |
| `/api/approvals` | `api_get_approvals` | — | ARRAY[0] |
| `/api/ar/aging` | `api_ar_aging` | — | {buckets, by_customer, invoices, total_outstanding} |
| `/api/ar/customers` | `api_ar_customers` | — | {customers} |
| `/api/ar/invoice-lines` | `api_ar_invoice_lines` | — | {error} |
| `/api/ar/invoices` | `api_ar_invoices` | — | {invoices} |
| `/api/ar/recurring` | `api_ar_recurring` | — | {templates} |
| `/api/ar/statement` | `api_ar_statement` | — | {error} |
| `/api/assets` | `api_get_assets` | — | {assets, standard, total_assets, total_carrying_amount, total_cost, total_depreciation, total_impairment, total_revaluation} |
| `/api/attachments` | `api_get_attachments` | — | ARRAY[0] |
| `/api/audit` | `api_get_audit` | — | ARRAY[4] of {action, details, id, ip_address, module, new_snapshot, old_snapshot…} |
| `/api/audit-log` | `api_audit_log_viewer` | — | {page, pages, rows, total} |
| `/api/audit-pack` | `api_audit_pack` | — | {filename, fy, sections, size_bytes, zip_b64} |
| `/api/backup-restore-centre` | `api_backup_restore_centre` | — | (binary/file or operational — not probed) |
| `/api/backup/download` | `(inline handler)` | — | — |
| `/api/backup/info` | `api_backup_info` | — | (binary/file or operational — not probed) |
| `/api/backup/offsite-test` | `api_offsite_backup_test` | — | (binary/file or operational — not probed) |
| `/api/backup/schedule` | `api_get_backup_schedule` | — | (binary/file or operational — not probed) |
| `/api/bank-accounts` | `api_get_bank_accounts` | — | ARRAY[175] of {account_name, account_number, account_type, bank_name, branch, coa_account_name, coa_code…} |
| `/api/bank-reconciliation` | `api_bank_reconciliation` | — | {account, items, period_month, reconciliation} |
| `/api/bank-reconciliation-statement` | `api_get_bank_recon_statement` | — | {reconciliations} |
| `/api/bank-reconciliations` | `api_get_bank_reconciliations` | — | ARRAY[0] |
| `/api/bank-statement` | `(inline handler)` | — | — |
| `/api/bog/rate` | `(inline handler)` | — | — |
| `/api/budget-control` | `api_budget_control` | — | {budget_lines, items, lines, period_from, period_to, total_budget, total_committed, total_spent, total_variance} |
| `/api/budget-periods` | `api_get_budget_periods` | — | ARRAY[7] of {academic_year, created_at, end_date, id, is_active, period_name, period_type…} |
| `/api/budget-revisions` | `api_budget_revisions` | — | {budget, revisions} |
| `/api/budget-upload-validation-reports` | `api_budget_upload_validation_reports` | — | {reports} |
| `/api/budget-uploads` | `api_get_budget_uploads` | — | ARRAY[0] |
| `/api/budget-uploads/` | `(inline handler)` | — | — |
| `/api/budget-variance` | `api_budget_variance_v556` | — | {lines, period_from, period_to, total_budget, total_committed, total_spent, total_variance} |
| `/api/budgets` | `api_get_budgets` | — | ARRAY[0] |
| `/api/budgets/` | `(inline handler)` | — | — |
| `/api/bulk-approve-queue` | `api_get_bulk_approve_queue_v560` | — | {count, items} |
| `/api/cagd-mappings` | `api_cagd_mappings` | — | {mappings} |
| `/api/cash-forecast` | `api_cash_forecast` | — | {generated_at, opening_cash, projected_closing, rows, total_inflows, total_outflows} |
| `/api/cashbook` | `api_cashbook` | — | {bank, basis, closing_balance, date_from, date_to, opening_balance, rows, total_payments, total_receipts} |
| `/api/cashflow` | `api_cashflow_v556` | — | {basis, closing_cash, financing, investing, net_change, opening_cash, operating, period_from, period_to…} |
| `/api/changes-in-net-assets` | `api_changes_in_net_assets_v1` | — | {basis, closing, contributions, date_from, date_to, opening, project_id, surplus_for_period, unit_code} |
| `/api/client-errors/recent` | `api_client_errors_recent` | — | {errors, summary} |
| `/api/coa` | `api_get_coa` | — | ARRAY[834] of {account_name, account_type, category, code, id, sub_category, vat_applicable…} |
| `/api/coa/` | `(inline handler)` | — | — |
| `/api/coa/bank-accounts` | `api_get_bank_coa` | — | ARRAY[175] of {account_name, account_type, category, code, id, sub_category, vat_applicable…} |
| `/api/coa/delete` | `(inline handler)` | — | — |
| `/api/coa/expense-accounts` | `api_get_expense_coa` | — | ARRAY[195] of {account_name, account_type, category, code, id, sub_category, vat_applicable…} |
| `/api/coa/reset` | `api_reset_coa` | — | {message, status} |
| `/api/coa/revenue-accounts` | `api_get_revenue_coa` | — | ARRAY[56] of {account_name, account_type, category, code, id, sub_category, vat_applicable…} |
| `/api/coa/ucc-status` | `api_coa_ucc_status` | — | {bank_accounts_seeded, bank_default_aliases, expense_accounts, legacy_short_codes_present, mode, official_expected, official_installed, official_missing, org…} |
| `/api/commitments` | `api_get_commitments` | — | ARRAY[0] |
| `/api/commitments/` | `(inline handler)` | — | — |
| `/api/comparative-report` | `api_comparative_report` | — | {error} |
| `/api/contracts` | `api_get_contracts` | — | {active, contracts, expiring_30, total_value} |
| `/api/contracts/alerts` | `api_contract_expiry_alerts` | — | {alerts} |
| `/api/control-cockpit` | `api_control_cockpit` | — | {cards, checks, current_period, enterprise_controls, generated_at, metrics, next_actions, rating, score} |
| `/api/cron/backup` | `(inline handler)` | — | — |
| `/api/cron/backup/download` | `(inline handler)` | — | — |
| `/api/dashboard` | `api_dashboard` | — | {division_summary, projects, stats} |
| `/api/dashboard-kpis-v55` | `api_dashboard_kpis_v55` | — | {kpis} |
| `/api/dashboard/charts` | `api_get_dashboard_charts` | — | {by_category, monthly_actuals, receipts_trend, utilisation} |
| `/api/database-migration-check` | `api_database_migration_check` | — | {app, checks, failed, generated_at, score, status, version, warnings} |
| `/api/deleted-items` | `api_deleted_items` | — | {items} |
| `/api/departments` | `api_get_departments` | — | ARRAY[7] of {academic_year, active_grants, committed, created_at, dept_code, dept_name, grant_budget…} |
| `/api/deployment-audit` | `api_final_system_audit` | — | {academic_context, checks, default_period, rating, score} |
| `/api/deployment/status` | `api_deployment_status` | — | {brand_new, database_path, deployment_polish_version, fresh_start_note, generated_at, transactional_counts, transactional_total, version} |
| `/api/depreciation-schedule` | `api_depreciation_schedule` | — | {history, schedule, total_monthly} |
| `/api/dept-allocations` | `api_get_dept_allocations` | — | ARRAY[0] |
| `/api/dept-summary` | `api_dept_summary` | — | ARRAY[7] of {active_grants, allocated, committed, dept_code, dept_name, grant_budget, head_name…} |
| `/api/docs` | `(inline handler)` | — | — |
| `/api/document-attachments` | `api_document_attachments` | — | {attachments} |
| `/api/document-watermark` | `api_doc_watermark` | — | {watermark} |
| `/api/donor-reports` | `api_donor_reports` | — | {donor_reports} |
| `/api/dual-control` | `api_get_dual_control` | — | {threshold_ghs} |
| `/api/dunning-preview` | `api_dunning_preview` | — | {count, customers, total_overdue} |
| `/api/email/status` | `api_email_status` | — | {outbox, required_env, smtp_configured} |
| `/api/exception-audit-dashboard` | `api_exception_audit_dashboard` | — | {audit, exceptions, generated_at, version} |
| `/api/exchange-rates` | `api_get_fx_rates` | — | ARRAY[0] |
| `/api/export` | `(inline handler)` | — | — |
| `/api/export/` | `(inline handler)` | — | — |
| `/api/export/audit-trail` | `(inline handler)` | — | — |
| `/api/export/budget-validation` | `(inline handler)` | — | — |
| `/api/export/deleted-items` | `(inline handler)` | — | — |
| `/api/export/fuel-control-report` | `(inline handler)` | — | — |
| `/api/export/invoices` | `(inline handler)` | — | — |
| `/api/export/production-polish-report` | `(inline handler)` | — | — |
| `/api/export/reversals` | `(inline handler)` | — | — |
| `/api/export/system-assurance` | `(inline handler)` | — | — |
| `/api/export/workflow-compliance` | `(inline handler)` | — | — |
| `/api/export/workflow-status` | `(inline handler)` | — | — |
| `/api/final-system-audit` | `api_final_system_audit` | — | {academic_context, checks, default_period, rating, score} |
| `/api/finance-overview` | `api_finance_overview` | — | {as_of, cash, current_ratio, inventory_value, low_stock_items, net_working_capital, overdue_customers, overdue_total, payables…} |
| `/api/financial-integrity` | `api_control_cockpit` | — | {cards, checks, current_period, enterprise_controls, generated_at, metrics, next_actions, rating, score} |
| `/api/first-time-setup` | `api_first_time_setup` | — | {completion, steps} |
| `/api/fixed-assets` | `api_fixed_assets_v558d` | — | {assets, summary} |
| `/api/flash-pack` | `api_flash_pack` | — | {ap_buckets, ar_buckets, as_of, income_expenditure, period, sfp, top_expenditure, trial_balance, working_capital…} |
| `/api/flash-recipients` | `api_get_flash_recipients` | — | {recipients} |
| `/api/fuel-coupons` | `api_fuel` | — | {batches, deployment_polish_version, ifrs_ipsas_stabilisation_version, movements, project_balances, return_modes_restored_version, summary, vehicles, version_fuel_vehicle} |
| `/api/fuel-coupons/batch/` | `(inline handler)` | — | — |
| `/api/fuel-coupons/control-report` | `api_fuel_control_report` | — | {by_project, by_vehicle, generated_at, missing_documents, pending_approvals, posted_issue_expense, stock, version, warnings} |
| `/api/fuel-coupons/detail` | `api_fuel` | — | {batches, deployment_polish_version, ifrs_ipsas_stabilisation_version, movements, project_balances, return_modes_restored_version, summary, vehicles, version_fuel_vehicle} |
| `/api/fuel-coupons/movement/` | `(inline handler)` | — | — |
| `/api/fuel-coupons/return-source` | `api_fuel_return_sources` | — | {counts, sources, version} |
| `/api/fuel-coupons/tracker` | `api_fuel_tracker` | — | {borrowed, lent, overdue_borrowed, overdue_lent, total_borrowed_outstanding, total_lent_outstanding} |
| `/api/fuel-stock-health` | `api_fuel_stock_health` | — | {borrowed_value, by_denomination, calculated_stock_value, double_entry, expense_coa, generated_at, issued_value, lent_value, posted_stock_value…} |
| `/api/fuel-vehicles` | `api_get_fuel_vehicles` | — | ARRAY[0] |
| `/api/fuel-vehicles/` | `(inline handler)` | — | — |
| `/api/fund-receipts` | `api_get_fund_receipts` | — | ARRAY[0] |
| `/api/fx-rates` | `api_get_fx_rates` | — | ARRAY[0] |
| `/api/general-ledger` | `(inline handler)` | — | — |
| `/api/go-live-enforcement` | `api_go_live_enforcement` | — | {dynamic_blockers, dynamic_checks, generated_at, manual_blockers, manual_checklist, operational_counts, posting_allowed, posting_message, ready_for_live…} |
| `/api/go-live-readiness` | `api_go_live_readiness` | — | {checked_at, checks, score, status} |
| `/api/gra-remittance` | `api_gra_remittance` | — | {generated_at, paye, quarter, totals, ucf, wht, whvat, year} |
| `/api/grant-utilisation` | `api_grant_utilisation_v556` | — | {donors, generated_at, period_from, period_to} |
| `/api/health` | `(inline handler)` | — | — |
| `/api/import/jobs` | `api_get_import_jobs` | — | {jobs} |
| `/api/import/template` | `(inline handler)` | — | — |
| `/api/income-expenditure` | `api_income_expenditure_v556` | — | {expenditure, income, period_from, period_to, project_id, surplus_deficit, total_expenditure, total_income, unit_code} |
| `/api/institutional-control-centre` | `api_institutional_control_centre` | — | {auto_backup, cards, features, generated_at, status, version} |
| `/api/institutional-readiness` | `api_institutional_readiness` | — | {checks, failures, generated_at, go_live_gate, status, version, warnings} |
| `/api/interunit-transfers` | `api_get_interunit_transfers` | — | {transfers} |
| `/api/inv/items` | `api_inv_items` | — | {items} |
| `/api/inv/reorder` | `api_inv_reorder` | — | {count, items, total_est} |
| `/api/inv/report` | `api_inv_report` | — | {item_count, items, low_stock, total_value_ghs} |
| `/api/invoices` | `api_get_invoices` | — | ARRAY[0] |
| `/api/invoices/html` | `(inline handler)` | — | — |
| `/api/ipsas24` | `api_ipsas24` | — | {basis, by_account, date_from, date_to, fy, gl_reconciliation, lines, material_variances, revisions_tracked…} |
| `/api/journal-vouchers` | `api_journal_vouchers_v557` | — | {count, journal_vouchers, period_from, period_to} |
| `/api/jvs/` | `(inline handler)` | — | — |
| `/api/launch-lock` | `api_launch_lock` | — | {effects, launch_lock, settings} |
| `/api/leave-requests` | `api_leave_requests` | — | {leave_requests} |
| `/api/ledger-summary` | `(inline handler)` | — | — |
| `/api/management-alerts` | `api_management_alerts` | — | {alerts, generated_at} |
| `/api/management-reports` | `api_management_reports` | — | {exceptions, fuel, generated_at, summary, version} |
| `/api/me` | `(inline handler)` | — | — |
| `/api/migration-template/` | `(inline handler)` | — | — |
| `/api/migration-templates` | `api_migration_templates` | — | {note, templates} |
| `/api/my-sessions` | `api_my_sessions` | — | {sessions} |
| `/api/notes-to-accounts` | `api_notes_to_accounts` | — | {as_at, basis, commitments, entity, notes, period_from, period_to, policies, reconciliation} |
| `/api/notification-log` | `api_get_notification_log` | — | {log} |
| `/api/notification-summary` | `api_notification_summary_v558` | — | {alerts, total} |
| `/api/notifications` | `api_get_notifications` | — | ARRAY[1] of {module, title, type…} |
| `/api/notifications/settings` | `api_get_notification_settings` | — | {settings} |
| `/api/opening-balance-wizard` | `api_opening_balance_wizard` | — | {batches, coa, default_date, periods, version} |
| `/api/opening-balances/list` | `api_list_opening_journals` | — | {journals} |
| `/api/payment-runs` | `api_payment_runs` | — | {runs} |
| `/api/payroll/employees` | `api_get_employees` | — | ARRAY[0] |
| `/api/payroll/months` | `api_get_payroll_months` | — | ARRAY[0] |
| `/api/payroll/payslip` | `(inline handler)` | — | — |
| `/api/payroll/payslip/html` | `(inline handler)` | — | — |
| `/api/payroll/payslips/all` | `(inline handler)` | — | — |
| `/api/payroll/photo` | `(inline handler)` | — | — |
| `/api/payroll/photo/` | `(inline handler)` | — | — |
| `/api/payroll/register` | `(inline handler)` | — | — |
| `/api/payroll/schedules` | `(inline handler)` | — | — |
| `/api/payroll/settings` | `api_get_payroll_settings` | — | {bands, settings} |
| `/api/pending-approvals` | `api_pending_approvals_v557b` | — | {count, grouped, pending_approvals} |
| `/api/period-close` | `api_period_close_checklist` | — | {all_pass, checks, period_code, signoffs} |
| `/api/permissions` | `api_get_permissions_v562` | — | {is_admin, permissions} |
| `/api/petty-cash` | `api_petty_cash` | — | {current_balance, entries} |
| `/api/petty-cash2` | `api_pc2_state` | — | {counts, floats, gl_balance, gl_tie_ok, legacy_entries, replenishments, total_book_balance, vouchers} |
| `/api/petty-cash2/ledger` | `api_pc2_ledger` | — | {float, rows} |
| `/api/pilot-feedback` | `api_get_pilot_feedback` | — | {feedback} |
| `/api/postgres/export` | `(inline handler)` | — | — |
| `/api/postgres/readiness` | `api_postgres_readiness` | — | {readiness} |
| `/api/ppa-check` | `(inline handler)` | — | — |
| `/api/ppe-schedule` | `api_ppe_schedule` | — | {fy, gl_tie, note, rows, totals} |
| `/api/procure-to-pay` | `api_get_procure_to_pay` | — | ARRAY[0] |
| `/api/production-control` | `api_production_control` | — | {checks, polish_score, polish_status, score, status, version} |
| `/api/production-polish` | `api_production_polish` | — | {app, checks, failed, generated_at, id, release, score, status, version…} |
| `/api/project-closeout` | `api_project_closeout` | — | {projects} |
| `/api/project-closeouts` | `api_project_closeouts` | — | {project_closeouts} |
| `/api/projects` | `api_get_projects` | — | ARRAY[0] |
| `/api/purchase-orders` | `api_get_purchase_orders` | — | {grns, orders, requisitions} |
| `/api/pv-analytics` | `api_pv_analytics` | — | {forecast, generated_at, monthly, top_payees, version} |
| `/api/pv-batch-template` | `(inline handler)` | — | — |
| `/api/pv-print-data` | `api_pv_print_data` | — | {error} |
| `/api/pv/suggest-coa` | `api_pv_smart_coa_suggestions` | — | {payment_type, suggestions} |
| `/api/quality-seal` | `api_quality_seal` | — | {checks, quality} |
| `/api/quarterly-budgets` | `api_get_quarterly_budgets` | — | ARRAY[0] |
| `/api/quarterly-budgets/` | `(inline handler)` | — | — |
| `/api/quarterly-performance` | `api_quarterly_performance` | — | ARRAY[0] |
| `/api/rec-journal-lines` | `api_rec_journal_lines` | — | {error} |
| `/api/rec-journals` | `api_rec_journals` | — | {templates} |
| `/api/recurring-commitments` | `api_recurring_commitments` | — | {templates} |
| `/api/reference-preview` | `api_reference_preview` | — | {prefix, references} |
| `/api/report` | `(inline handler)` | — | — |
| `/api/report-designer` | `api_get_report_designer` | — | {settings} |
| `/api/report/fuel-coupons` | `(inline handler)` | — | — |
| `/api/reversals-register` | `api_reversals_register` | — | {count, reversals} |
| `/api/saved-reports` | `api_get_saved_reports` | — | {reports} |
| `/api/search` | `(inline handler)` | — | — |
| `/api/security/mfa` | `api_get_mfa_settings` | — | {settings, smtp_configured} |
| `/api/sfp` | `api_sfp_v556` | — | {as_at, assets, basis, equity, liabilities, net_assets, presentation_difference, project_id, unit_code} |
| `/api/ssnit-remittance-advice` | `api_ssnit_remittance_advice` | — | {count, employees, month, period, reference_no, total, total_tier1, total_tier2, year} |
| `/api/ssnit-schedule` | `api_ssnit_schedule` | — | {generated_at, month, rows, totals} |
| `/api/stability-audit` | `api_stability_audit` | — | {checked_at, checks, score, status, version} |
| `/api/staff-advances` | `api_get_staff_advances` | — | {advances} |
| `/api/staff-advances/report` | `api_staff_advance_report_v558` | — | {by_type, count, overdue, retired_this_month, rows, total_outstanding} |
| `/api/statutory-filings` | `api_statutory_filings` | — | {deadlines, employer, paye, period, period_label, tier1, tier2, wht, whvat} |
| `/api/support-maintenance` | `api_support_maintenance` | — | {support} |
| `/api/system-assurance` | `api_system_assurance` | — | {app, checks, failed, generated_at, release, score, status, version, warnings} |
| `/api/system-audit` | `api_system_audit_v556` | — | {generated_at, recent_activity, summary} |
| `/api/system-health` | `api_system_health` | — | {health} |
| `/api/takeoff-wizard` | `api_takeoff_wizard` | — | {acceptance, generated_at, stages, title} |
| `/api/tax-reconciliation` | `api_tax_reconciliation` | — | {date_from, date_to, generated_at, ledger, monthly, payables, summary, version} |
| `/api/tax-schedules` | `api_tax_schedules` | — | {detail, period, summary, total_accrued, total_outstanding} |
| `/api/three-way-match` | `api_three_way_match` | — | {count, counts, rows} |
| `/api/trends` | `api_trends` | — | {series} |
| `/api/trial-balance` | `api_trial_balance_v556` | — | {accounts, balanced, period_from, period_to, total_credit, total_debit} |
| `/api/unbudgeted-spend` | `api_unbudgeted_spend` | — | {budget_linked, items, pct_unbudgeted, total_actuals, unbudgeted_count, unbudgeted_total} |
| `/api/unit-allocations` | `api_get_dept_allocations` | — | ARRAY[0] |
| `/api/unit-summary` | `api_dept_summary` | — | ARRAY[7] of {active_grants, allocated, committed, dept_code, dept_name, grant_budget, head_name…} |
| `/api/units` | `api_get_departments` | — | ARRAY[7] of {academic_year, active_grants, committed, created_at, dept_code, dept_name, grant_budget…} |
| `/api/user-manual` | `api_user_manual` | — | {sections, version} |
| `/api/users` | `api_get_users` | — | ARRAY[5] of {active, created_at, email, full_name, id, role, username…} |
| `/api/vendors` | `api_get_vendors` | — | ARRAY[0] |
| `/api/vendors-v55` | `api_vendor_register` | — | {total, vendors} |
| `/api/virements` | `api_get_virements` | — | {count, total_reallocated, virements} |
| `/api/vote-on-account` | `api_vote_on_account` | — | {records} |
| `/api/wht-certificate` | `api_wht_certificate` | — | {error} |
| `/api/withholding-payables` | `api_get_withholding_payables` | — | {rows, summary} |
| `/api/workflow-compliance` | `api_workflow_compliance` | — | {app, checks, domains, failed, generated_at, metrics, release, score, status…} |
| `/api/workflow-status` | `api_workflow_status` | — | {summary, workflows} |
| `/api/working-capital` | `api_working_capital` | — | {ap_buckets, ar_buckets, as_of, cash_and_bank_ghs, current_assets_ghs, current_liabilities_ghs, current_ratio, inventory_value_ghs, net_working_capital_ghs…} |
| `/api/year-end-status` | `api_year_end_status` | — | {blockers, financial_year, is_ready, surplus_deficit, total_expenditure, total_income} |
| `/assets/branding/` | `(inline handler)` | — | — |
| `/favicon.ico` | `(inline handler)` | — | — |
| `/healthz` | `(inline handler)` | — | — |
| `/manifest.json` | `(inline handler)` | — | — |
| `/sw.js` | `(inline handler)` | — | — |

### POST endpoints

| Path | Handler | Request fields (from handler body) |
|---|---|---|
| `/api/acceptance-testing/run` | `api_run_acceptance_test` | — |
| `/api/accounting-periods` | `api_manage_period` | `action`, `end_date`, `period`, `period_code`, `period_name`, `start_date` |
| `/api/actuals` | `api_save_actual` | `amount`, `amount_fcy`, `description`, `id`, `payee` |
| `/api/actuals/batch` | `api_pv_batch_upload` | — |
| `/api/actuals/multiline` | `api_save_multiline_actual` | `bank_account_id`, `cheque_no`, `date`, `description`, `expense_date`, `lines`, `payee`, `payment_method`, `payment_reference`, `project_id`, `receipt_no`, `transfer_ref` |
| `/api/actuals/post` | `api_post_actual` | `actual_id`, `id` |
| `/api/actuals/tag-budget` | `api_tag_actual_budget` | `actual_id`, `budget_id`, `id` |
| `/api/actuals/update` | `api_save_actual` | `amount`, `amount_fcy`, `description`, `id`, `payee` |
| `/api/ai-governance` | `api_save_ai_governance` | — |
| `/api/ai/chat` | `(inline handler)` | — |
| `/api/annual-budget-upload` | `api_upload_annual_budgets` | `filename`, `rows` |
| `/api/ap/batch-pay` | `api_ap_batch_pay` | `bank_account_id`, `bill_ids`, `items`, `payment_date`, `reference` |
| `/api/ap/bills` | `api_ap_save_bill` | `amount_ghs`, `bill_date`, `bill_number`, `description`, `due_date`, `expense_coa_id`, `id`, `lines`, `project_id`, `vendor_id`, `vendor_invoice_no` |
| `/api/ap/bills/post` | `api_ap_post_bill` | `bill_id`, `id` |
| `/api/ap/debit-note` | `api_ap_debit_note` | `amount_ghs`, `bill_id`, `debit_date`, `id`, `reason` |
| `/api/ap/import-bills` | `api_ap_import_bills` | `post` |
| `/api/ap/payment` | `api_ap_payment` | `amount_ghs`, `bank_account_id`, `bill_id`, `id`, `notes`, `payment_date`, `payment_method`, `reference` |
| `/api/ap/payment-run-file` | `api_payment_run_file` | `email_remittance`, `jv_number` |
| `/api/ap/recurring` | `api_ap_save_recurring` | `active`, `amount_ghs`, `auto_post`, `day_offset`, `description`, `end_date`, `expense_coa_id`, `frequency`, `id`, `next_due_date`, `project_id`, `start_date`, `vendor_id` |
| `/api/ap/recurring/generate` | `api_ap_recurring_generate` | `as_of`, `id` |
| `/api/ap/recurring/toggle` | `api_ap_recurring_toggle` | `id` |
| `/api/approval-rules` | `api_save_approval_rule` | `document_type`, `id`, `is_active`, `level`, `level_label`, `max_amount`, `min_amount`, `required_role` |
| `/api/approval-rules-v55` | `api_save_approval_rule` | `document_type`, `id`, `is_active`, `level`, `level_label`, `max_amount`, `min_amount`, `required_role` |
| `/api/approvals/action` | `api_approval_action` | `action`, `approval_id`, `comments`, `id` |
| `/api/approvals/process` | `api_process_approval` | `action`, `notes`, `step_id` |
| `/api/approvals/submit` | `api_submit_approval` | `amount`, `amount_ghs`, `module`, `record_id` |
| `/api/ar/batch-receipt` | `api_ar_batch_receipt` | `bank_account_id`, `file_b64`, `filename`, `invoice_ids`, `items`, `receipt_date`, `reference`, `xlsx_b64` |
| `/api/ar/credit-note` | `api_ar_credit_note` | `amount_ghs`, `credit_date`, `id`, `invoice_id`, `reason` |
| `/api/ar/customers` | `api_ar_save_customer` | `address`, `contact_person`, `customer_code`, `customer_name`, `customer_type`, `email`, `id`, `notes`, `phone`, `tin` |
| `/api/ar/import-invoices` | `api_ar_import_invoices` | `post` |
| `/api/ar/invoices` | `api_ar_save_invoice` | `amount_ghs`, `customer_id`, `description`, `due_date`, `id`, `income_coa_id`, `invoice_date`, `invoice_number`, `lines`, `project_id`, `tax_ghs` |
| `/api/ar/invoices/post` | `api_ar_post_invoice` | `id`, `invoice_id` |
| `/api/ar/receipt` | `api_ar_receipt` | `amount_ghs`, `bank_account_id`, `id`, `invoice_id`, `notes`, `payment_method`, `receipt_date`, `reference` |
| `/api/ar/recurring` | `api_ar_save_recurring` | `active`, `amount_ghs`, `auto_post`, `customer_id`, `day_offset`, `description`, `end_date`, `frequency`, `id`, `income_coa_id`, `next_due_date`, `project_id`, `start_date` |
| `/api/ar/recurring/generate` | `api_ar_recurring_generate` | `as_of`, `id` |
| `/api/ar/recurring/toggle` | `api_ar_recurring_toggle` | `id` |
| `/api/assets` | `api_save_asset` | `accumulated_depreciation`, `acquisition_cost`, `acquisition_date`, `asset_category`, `asset_class`, `asset_coa_id`, `asset_code`, `asset_description`, `asset_name`, `asset_owner`, `asset_sub_class`, `asset_tag`, `barcode`, `category`, `condition_status`, `cost`, `custodian`, `department_code` … |
| `/api/assets/dispose` | `api_asset_dispose` | `accumdep_coa_id`, `asset_id`, `bank_account_id`, `cost_coa_id`, `disposal_date`, `id`, `proceeds`, `reason` |
| `/api/assets/maintenance` | `api_save_asset_maintenance` | `asset_id`, `cost`, `id`, `maintenance_date`, `maintenance_type`, `next_due_date`, `notes`, `provider` |
| `/api/assets/revalue` | `api_asset_revalue` | `asset_id`, `new_value`, `reason`, `valuation_date` |
| `/api/attachments` | `api_save_attachment` | `file_data`, `file_size`, `filename`, `id`, `mime_type`, `module`, `notes`, `record_id` |
| `/api/attachments/delete` | `api_delete_attachment` | `id` |
| `/api/auto-coa` | `api_auto_assign_coa` | `account_name`, `account_type`, `category`, `create`, `dept_code`, `sub_category` |
| `/api/backup/create` | `api_create_backup` | — |
| `/api/backup/restore` | `api_restore_backup` | `file_data`, `filename` |
| `/api/backup/schedule` | `api_save_backup_schedule` | `enabled`, `frequency_hours` |
| `/api/bank-accounts` | `api_save_bank_account` | `coa_id` |
| `/api/bank-import` | `api_bank_import` | `transactions` |
| `/api/bank-reconciliation-statement` | `api_save_bank_recon_statement` | `account_id`, `bank_charges`, `cashbook_balance`, `id`, `items`, `notes`, `outstanding_cheques`, `recon_date`, `statement_balance`, `status`, `uncredited_lodgements` |
| `/api/bank-reconciliation/balances` | `api_update_recon_balances` | — |
| `/api/bank-reconciliation/item` | `api_save_recon_item` | `amount`, `cleared_date`, `description`, `id`, `is_cleared`, `item_date`, `item_type`, `recon_id`, `reference` |
| `/api/bank-reconciliation/signoff` | `api_signoff_recon` | — |
| `/api/bank-reconciliations` | `api_save_bank_reconciliation_v563b` | `bank_account_id`, `book_balance`, `notes`, `recon_date`, `statement_balance`, `status` |
| `/api/bog/fetch-rates` | `api_bog_fetch_rates` | — |
| `/api/budget-control/check` | `api_budget_control_check` | — |
| `/api/budget-revisions` | `api_save_budget_revision` | `budget_id`, `reason`, `reference`, `revised_amount_ghs`, `revision_type` |
| `/api/budget-upload` | `api_upload_budget` | `academic_year`, `dept_code`, `filename`, `rows` |
| `/api/budgets` | `api_save_budget` | `budget_code`, `coa_id`, `id`, `notes` |
| `/api/budgets/renumber-codes` | `api_renumber_budget_codes` | — |
| `/api/budgets/vire` | `api_virement` | `amount`, `from_id`, `reason`, `to_id` |
| `/api/bulk-approve` | `api_bulk_approve_v560` | `items` |
| `/api/bulk-auto-coa` | `api_bulk_auto_coa` | `create`, `rows` |
| `/api/cagd-mappings` | `api_save_cagd_mapping` | `cagd_programme`, `cagd_subhead`, `cagd_vote_code`, `description`, `id`, `internal_code` |
| `/api/change-password` | `api_change_password` | `new_password`, `old_password` |
| `/api/client-error` | `api_log_client_error` | `browser`, `message`, `page`, `source`, `stack`, `user_agent` |
| `/api/client-errors/clear` | `api_clear_client_errors` | — |
| `/api/coa` | `api_save_coa` | `account_name`, `account_type`, `category`, `code`, `id`, `sub_category`, `vat_applicable` |
| `/api/commitments` | `api_save_commitment` | `commit_code`, `commit_date`, `id` |
| `/api/compute-vat` | `api_compute_vat` | `amount`, `gross_amount`, `vat_inclusive` |
| `/api/contracts` | `api_save_contract` | `contract_number`, `contract_type`, `contract_value_ghs`, `currency`, `deliverables`, `end_date`, `id`, `notes`, `payment_terms`, `performance_rating`, `project_id`, `renewal_alert_days`, `scope`, `start_date`, `status`, `title`, `vendor_id`, `vendor_name` |
| `/api/deleted-items/restore` | `api_restore_deleted_item` | `id`, `reason`, `soft_delete_id` |
| `/api/demo-data/load` | `api_load_sample_data` | — |
| `/api/demo-data/reset` | `api_reset_demo_data` | — |
| `/api/departments` | `api_save_department` | `academic_year`, `head_email`, `head_name`, `id`, `notes`, `status`, `total_allocation` |
| `/api/deployment/reset-clean` | `api_reset_for_deployment` | — |
| `/api/depreciation/run` | `api_run_depreciation` | `force`, `month` |
| `/api/dept-allocations` | `api_save_dept_allocation` | `academic_year`, `approved_by`, `notes`, `semester`, `source` |
| `/api/document-attachments` | `api_save_attachment` | `file_data`, `file_size`, `filename`, `id`, `mime_type`, `module`, `notes`, `record_id` |
| `/api/document-attachments/delete` | `api_delete_attachment` | `id` |
| `/api/document-attachments/get` | `api_get_attachment` | — |
| `/api/document-watermark` | `api_doc_watermark` | — |
| `/api/donor-reports` | `api_save_donor_report` | `donor_name`, `id`, `narrative`, `project_id`, `report_name`, `report_period`, `report_type`, `status` |
| `/api/donor-reports/submit` | `api_submit_donor_report` | — |
| `/api/dual-control` | `api_set_dual_control` | `threshold_ghs` |
| `/api/dunning-run` | `api_dunning_run` | `customer_ids` |
| `/api/email-statement` | `api_email_statement` | `customer_id`, `id`, `to`, `type`, `vendor_id` |
| `/api/email/test` | `api_send_test_email` | `to_email` |
| `/api/employee-upload` | `api_upload_employees` | `filename`, `rows` |
| `/api/export-file` | `api_export_file` | `filename`, `format`, `subtitle`, `title` |
| `/api/fixed-assets` | `api_save_fixed_asset` | `acquisition_cost`, `acquisition_date`, `asset_name`, `category`, `coa_id`, `condition`, `id`, `insurance_expiry`, `insured`, `location`, `notes`, `project_id`, `residual_value`, `serial_number`, `useful_life_years` |
| `/api/fixed-assets/depreciate` | `api_run_asset_depreciation` | — |
| `/api/fixed-assets/dispose` | `api_dispose_asset` | `asset_id`, `disposal_date`, `disposal_proceeds` |
| `/api/flash-email` | `api_send_flash_email` | — |
| `/api/flash-recipients` | `api_set_flash_recipients` | `recipients` |
| `/api/fuel-coupons/batch` | `api_save_fc_batch` | `post_to_ledger` |
| `/api/fuel-coupons/batch/post` | `api_post_fuel_procurement_batch` | — |
| `/api/fuel-coupons/batch/reverse` | `api_reverse_fuel_procurement_batch` | — |
| `/api/fuel-coupons/batch/update` | `api_update_fc_batch` | `cost_value`, `denomination`, `invoice_number`, `notes`, `procurement_date`, `project_id`, `quantity`, `received_by`, `serial_from`, `serial_to`, `supplier` |
| `/api/fuel-coupons/movement` | `api_save_fc_movement` | `batch_id`, `denomination`, `face_value`, `movement_type`, `project_id`, `quantity`, `serial_from`, `serial_to` |
| `/api/fuel-coupons/movement/post` | `api_post_fuel_movement` | — |
| `/api/fuel-coupons/movement/reverse` | `api_reverse_fuel_movement` | — |
| `/api/fuel-coupons/movement/update` | `api_update_fc_movement` | `id`, `issuing_officer`, `receiving_officer`, `serial_from`, `serial_to`, `vehicle_id`, `vehicle_is_external`, `vehicle_number` |
| `/api/fuel-coupons/receipt` | `api_update_fc_receipt` | `receipt_date` |
| `/api/fuel-coupons/reorder-level` | `api_set_fuel_reorder_level` | — |
| `/api/fuel-vehicles` | `api_save_fuel_vehicle` | `driver_name`, `notes`, `project_id`, `status`, `unit_code`, `vehicle_name`, `vehicle_type` |
| `/api/fund-receipts` | `api_save_fund_receipt` | `amount_fcy`, `bank_account_id`, `budget_id`, `currency`, `date`, `description`, `donor`, `edit_reason`, `fx_rate`, `grant_condition_status`, `id`, `income_coa_id`, `notes`, `payer`, `reason`, `receipt_code`, `receipt_date`, `receipt_no` … |
| `/api/fx-rates` | `api_save_fx_rate` | `source` |
| `/api/go-live-enforcement/checklist` | `api_update_go_live_checklist` | — |
| `/api/go-live-enforcement/mode` | `api_set_go_live_mode` | — |
| `/api/go-live-enforcement/signoff` | `api_go_live_signoff` | — |
| `/api/grns` | `api_save_grn` | `grn_number`, `id`, `notes`, `po_id`, `received_by`, `received_date`, `status` |
| `/api/import/csv` | `api_import_csv` | `filename`, `module` |
| `/api/interunit-transfers` | `api_save_interunit_transfer` | `amount_ghs`, `description`, `from_coa_id`, `from_unit`, `id`, `justification`, `period_code`, `status`, `to_coa_id`, `to_unit`, `transfer_date`, `transfer_number`, `transfer_type` |
| `/api/interunit-transfers/approve` | `api_approve_interunit_transfer` | `id` |
| `/api/inv/adjust` | `api_inv_adjust` | `direction`, `item_id`, `movement_date`, `notes`, `qty`, `reason`, `unit_cost` |
| `/api/inv/import` | `api_inv_import` | `bank_account_id`, `expense_coa_id` |
| `/api/inv/issue` | `api_inv_issue` | `item_id`, `movement_date`, `notes`, `party`, `project_id`, `qty`, `reference` |
| `/api/inv/items` | `api_inv_save_item` | `category`, `expense_coa_id`, `id`, `inventory_coa_id`, `item_code`, `item_name`, `notes`, `reorder_level`, `unit` |
| `/api/inv/receipt` | `api_inv_receipt` | `bank_account_id`, `item_id`, `movement_date`, `notes`, `party`, `project_id`, `qty`, `reference`, `unit_cost` |
| `/api/inv/reorder-po` | `api_inv_create_reorder_po` | `items`, `vendor_name` |
| `/api/invoices` | `api_save_invoice` | `approved_by`, `bank_account_id`, `client_address`, `client_contact`, `client_email`, `client_name`, `client_reference`, `contract_no`, `currency`, `discount_fcy`, `due_date`, `fx_rate`, `id`, `invoice_date`, `invoice_no`, `invoice_type`, `lines`, `notes` … |
| `/api/journal-vouchers` | `api_save_journal_voucher_v557` | `bank_account_id`, `description`, `id`, `jv_date`, `jv_type`, `lines`, `narration`, `period`, `period_code`, `project_id`, `reference` |
| `/api/journal-vouchers/approve` | `api_approve_journal_voucher` | — |
| `/api/journal-vouchers/post` | `api_post_journal_voucher` | — |
| `/api/journals/redate` | `api_redate_reversal` | `jv_number`, `new_date`, `reason` |
| `/api/jvs` | `api_save_jv` | `description`, `id`, `jv_date`, `narration` |
| `/api/jvs/auto-generate` | `api_auto_generate_jv` | `jv_date`, `source_id`, `source_module` |
| `/api/jvs/workflow` | `api_jv_workflow` | `action`, `jv_id`, `notes`, `reason`, `reversal_date` |
| `/api/launch-lock` | `api_set_launch_lock` | — |
| `/api/leave-requests` | `api_save_leave_request` | `employee_id`, `employee_name`, `end_date`, `leave_type`, `reason`, `start_date` |
| `/api/leave-requests/action` | `api_action_leave_request` | `action`, `notes`, `request_id` |
| `/api/ledger/reset-zero` | `api_reset_ledger_zero` | — |
| `/api/login` | `(inline handler)` | — |
| `/api/logout` | `(inline handler)` | — |
| `/api/mfa/totp-confirm` | `api_mfa_totp_confirm` | `code`, `user_id` |
| `/api/mfa/totp-setup` | `api_mfa_totp_setup` | `user_id` |
| `/api/notifications/settings` | `api_save_notification_settings` | — |
| `/api/notifications/test` | `api_send_test_notification` | `email` |
| `/api/opening-balances/post` | `api_post_opening_balances` | `date`, `description`, `lines`, `opening_date`, `reference` |
| `/api/opening-balances/reverse` | `api_reverse_opening_journal` | — |
| `/api/payroll/approve` | `api_approve_payroll` | `month` |
| `/api/payroll/employees` | `api_save_employee` | `account_number`, `bank_branch`, `bank_name`, `basic_salary`, `contract_end_date`, `cost_centre`, `date_appointed`, `date_of_birth`, `division`, `employee_id`, `employment_type`, `full_name`, `funding_source`, `gra_tin`, `grade`, `housing_allowance`, `id`, `job_title` … |
| `/api/payroll/reverse` | `api_reverse_payroll` | `month`, `reason` |
| `/api/payroll/run` | `api_run_payroll` | `month`, `overrides` |
| `/api/payroll/setting` | `api_get_payroll_setting_update` | `description` |
| `/api/period-close/signoff` | `api_sign_off_period_close` | `checks`, `period_code` |
| `/api/permissions` | `api_save_permissions` | `permissions`, `rows` |
| `/api/petty-cash` | `api_save_petty_cash` | `amount`, `category`, `description`, `entry_date`, `entry_type`, `id`, `receipt_ref` |
| `/api/petty-cash2/float` | `api_pc2_setup_float` | `bank_account_id`, `coa_id`, `custodian`, `date`, `department`, `department_code`, `imprest_amount`, `name`, `project_id`, `unit_code` |
| `/api/petty-cash2/reconcile` | `api_pc2_reconcile` | `counted_cash`, `date`, `float_id`, `notes`, `post_variance` |
| `/api/petty-cash2/replenish` | `api_pc2_replenish` | `amount_ghs`, `bank_account_id`, `date`, `float_id` |
| `/api/petty-cash2/voucher` | `api_pc2_voucher` | `amount_ghs`, `department`, `department_code`, `description`, `expense_coa_id`, `float_id`, `payee`, `project_id`, `receipt_ref`, `voucher_date` |
| `/api/pilot-feedback` | `api_save_pilot_feedback` | — |
| `/api/po-to-bill` | `api_po_to_bill` | `bill_date`, `due_date`, `expense_coa_id`, `force`, `po_id`, `post` |
| `/api/project-closeouts` | `api_initiate_project_closeout` | `closeout_date`, `notes`, `project_id`, `surplus_refunded_ghs` |
| `/api/projects` | `api_save_project` | `budget_fcy`, `currency`, `division`, `donor`, `end_date`, `fx_rate`, `id`, `notes`, `pi_name`, `project_code`, `start_date`, `status`, `title` |
| `/api/purchase-orders` | `api_save_purchase_order` | `currency`, `date_required`, `delivery_date`, `department`, `id`, `kind`, `lines`, `notes`, `po_date`, `po_number`, `pr_id`, `pr_number`, `project_id`, `purpose`, `requested_by`, `status`, `terms`, `total_amount_ghs` … |
| `/api/purchase-orders/approve` | `api_approve_po_v54` | `id` |
| `/api/pv-batch` | `api_pv_batch_upload` | — |
| `/api/pv-preview` | `api_pv_preview` | — |
| `/api/quarterly-budgets` | `api_save_quarterly_budget` | `academic_year`, `approved_by`, `category`, `coa_id`, `currency`, `description`, `id`, `notes`, `period_id`, `q1_amount`, `q2_amount`, `q3_amount`, `q4_amount`, `quarter` |
| `/api/rec-journals` | `api_save_rec_journal` | `active`, `description`, `end_date`, `frequency`, `id`, `lines`, `name`, `next_due_date`, `project_id`, `start_date` |
| `/api/rec-journals/generate` | `api_rec_journal_generate` | `as_of`, `id` |
| `/api/rec-journals/toggle` | `api_rec_journal_toggle` | `id` |
| `/api/recurring-commitments` | `api_save_recurring` | `amount`, `budget_line_id`, `category`, `day_of_month`, `description`, `frequency`, `id`, `next_run`, `project_code` |
| `/api/recurring-commitments/trigger` | `api_trigger_recurring` | — |
| `/api/report-designer` | `api_save_report_designer_v557` | `approved_label`, `audit_label`, `badge_text`, `confidentiality`, `footer_note`, `header_title`, `logo_text`, `prepared_label`, `reviewed_label`, `scope`, `subtitle` |
| `/api/saved-reports` | `api_save_report_config` | `filters`, `id`, `name`, `report_type`, `run` |
| `/api/saved-reports/delete` | `api_delete_saved_report` | `id` |
| `/api/security/mfa` | `api_save_mfa_settings` | `delivery`, `email`, `enabled`, `phone`, `user_id` |
| `/api/security/mfa/verify` | `api_verify_mfa` | `challenge_id`, `code`, `username` |
| `/api/sessions/force-logout` | `api_force_logout_session` | `sid`, `sid_full` |
| `/api/staff-advances` | `api_save_staff_advance_v563b` | `advance_type`, `amount_ghs`, `approved_by`, `date_issued`, `due_date`, `employee_id`, `employee_name`, `id`, `notes`, `project_id`, `purpose`, `pv_reference` |
| `/api/staff-advances/retire` | `api_retire_advance` | `advance_id`, `amount_retired`, `notes`, `receipts_attached`, `retirement_date`, `retirement_ref` |
| `/api/system-assurance/run` | `api_run_system_assurance` | — |
| `/api/unit-allocations` | `api_save_dept_allocation` | `academic_year`, `approved_by`, `notes`, `semester`, `source` |
| `/api/units` | `api_save_department` | `academic_year`, `head_email`, `head_name`, `id`, `notes`, `status`, `total_allocation` |
| `/api/util/parse-rows` | `api_parse_upload_rows` | — |
| `/api/vendors` | `api_save_vendor` | `account_name`, `account_number`, `address`, `bank_name`, `beneficiary_name`, `compliance_status`, `contact_person`, `email`, `id`, `momo_number`, `name`, `notes`, `phone`, `status`, `tax_status`, `tin`, `vendor_code`, `vendor_name` … |
| `/api/vendors-v55` | `api_save_vendor` | `account_name`, `account_number`, `address`, `bank_name`, `beneficiary_name`, `compliance_status`, `contact_person`, `email`, `id`, `momo_number`, `name`, `notes`, `phone`, `status`, `tax_status`, `tin`, `vendor_code`, `vendor_name` … |
| `/api/vendors-v55/delete` | `api_delete_vendor` | — |
| `/api/vote-on-account` | `api_save_vote_on_account` | `basis_year`, `fiscal_year`, `id`, `monthly_allowance`, `notes` |
| `/api/withholding-payables/settle` | `api_settle_withholding_payable` | `bank_account_id`, `cheque_no`, `id`, `notes`, `payment_date`, `payment_method`, `payment_reference`, `transfer_ref`, `withholding_id` |
| `/api/year-end-close` | `api_year_end_close` | `financial_year`, `notes` |

## Non-route behaviours a port must reproduce
- Startup/first-login migrations are idempotent (schema self-heals).
- Sequential codes are collision-proof (`_seq_code` semantics: MAX numeric suffix + bump-while-exists).
- Posted vouchers are immutable: edits auto-reverse INTO the original open period and repost.
- Period guard: no save/post into Closed/Locked periods.
- Go-live gate: SETUP blocks posting; UAT/LIVE allow.
- Dual control: PV posting above the configured threshold needs a second approver; creator≠approver.
- Ghana tax engine on PVs: VAT 20%% (15+2.5+2.5), WHT {Goods 3, Services 7.5, Income 10, Sitting 20, Works 5}%%,
  WHVAT 7%% of ex-VAT, UCF 5%% of (ex-VAT − WHT − WHVAT); withholdings credit liability accounts, settled later.
- All statements derive from `general_ledger` (never from source tables).
