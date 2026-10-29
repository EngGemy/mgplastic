#!/usr/bin/env bash
# Capture the real exception when opening an invoice in Filament (HTTP 500).
set -euo pipefail
cd ~/mgplastic_app

echo "==== recent invoice / Filament 500s ===="
grep -nE "ViewInvoice|InvoiceResource|Maximum execution|production.ERROR" storage/logs/laravel.log | tail -n 40 || true

echo
echo "==== last ERROR block ===="
grep -n "production.ERROR" storage/logs/laravel.log | tail -n 3 || true

echo
echo "==== tip: open one invoice in browser, then re-run ===="
echo "tail -n 120 storage/logs/laravel.log"
