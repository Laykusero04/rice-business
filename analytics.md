# Existing Analytics — Rice Business System

Inventory of analytics already built in the app (as of July 2026). Sources: `frontend/dashboard.php`, `frontend/reports.php`, `backend/report_export.php`, plus related list/summary views.

---

## 1. Dashboard (`frontend/dashboard.php`)

### KPI cards

| Metric | Description |
| --- | --- |
| Today's Sales | Sum of `sales.total` for today |
| Monthly Sales | Sum of sales from the 1st of the month through today |
| Total Stock | Sum of `products.stock` (active products only), in kg |
| Today's Expenses | Sum of `expenses.amount` for today (card also notes low-stock item count) |

### Charts

| Chart | Type | Description |
| --- | --- | --- |
| Sales — Last 7 Days | Bar (Chart.js) | Daily sales totals for the past 7 days |

### Lists / alerts

| Widget | Description |
| --- | --- |
| Low Stock Alert | Up to 8 active products where `stock <= minimum_stock` |
| Recent Sales | Last 8 sales (date, customer, payment method, total) |
| Recent Purchases | Last 5 purchases (date, supplier, total) |

---

## 2. Reports (`frontend/reports.php`)

Date range filters (`from` / `to`, default: month start → today). Sales trend can be **daily**, **weekly**, or **monthly**.

### Summary KPI cards

| Metric | Description |
| --- | --- |
| Sales | Total sales + transaction count in range |
| Cost of Goods Sold | Sum of qty sold × product buying price |
| Gross Profit | Sales − COGS |
| Gross Margin % | (Gross Profit ÷ Sales) × 100 |
| Purchases | Total purchase cost in range |
| Expenses | Total expenses in range |
| Net (Sales − Expenses) | Simple net figure; does not deduct COGS |

### Charts & tables

| Report | Description |
| --- | --- |
| Gross Profit Trend | Daily/weekly/monthly chart of Sales, COGS, Gross Profit + period table with margin % |
| Month vs Previous Month | Current month-to-date vs last full month (sales, COGS, GP, margin, change %) |
| Highest gross profit day | Best day within the selected date range |
| Profit per Rice Variety | Products ranked by gross profit; qty, sales, COGS, margin %, profit/kg; chart + highlights |
| Fastest vs Slowest Selling | 30-day movement: top 10 fast movers, slowest movers, no sales 30d, excessive inventory |
| Inventory Value | Cost value vs selling value, potential GP/margin, by product + category breakdown |
| Low Stock Forecast | Days until empty from avg daily sales; stockout date; risk tiers; suggested reorder |
| Daily Net Income | Sales − COGS − Expenses; daily/weekly/monthly trends; best & worst profit days |
| Daily / Weekly / Monthly Sales | Bar chart + period table of sales totals |
| Expenses by Category | Expense amounts grouped by category |
| Top Selling Rice | Top 10 products by qty sold (kg) and sales amount (volume contrast) |

### Export & print

| Feature | Details |
| --- | --- |
| Print / PDF | Browser print stylesheet |
| CSV export | Via `backend/report_export.php` (see below) |

---

## 3. CSV exports (`backend/report_export.php`)

Same date range as Reports. Export types:

| Type | Columns |
| --- | --- |
| Summary | Sales, COGS, Gross Profit, Margin %, Purchases, Expenses, Net Income, Simple Net |
| Gross Profit | Date, Sales, COGS, Gross Profit, Gross Margin % |
| Net Income | Date, Sales, COGS, Gross Profit, Expenses, Net Income |
| Profit by Variety | Product, Category, Qty, Sales, COGS, Gross Profit, Margin %, Profit/kg, Stock |
| Inventory Movement | Product, Category, Stock, Qty Sold 30d, Avg/day, Days cover, Tied capital, flags |
| Inventory Value | Totals + per-product cost/sell/GP + category breakdown |
| Stock Forecast | Product, Stock, Avg/day, Days left, Stockout date, Hits min, Suggested reorder, Risk |
| Sales | Date, Sale ID, Customer, Payment, Total |
| Expenses | Date, Category, Amount, Notes |
| Top Products | Product, Qty Sold (kg), Sales Amount |
| Inventory | Product, Category, Stock, Min, Buy/Sell, Cost Value, Sell Value, Potential GP, Status |

---

## 4. Related operational summaries (not full analytics pages)

Useful numbers shown elsewhere, but not dedicated report modules:

| Location | What it shows |
| --- | --- |
| Expenses list | Filtered expense total for the current view |
| Sales list | Per-sale balance + payment status (paid / partial / unpaid utang) |
| Sale view | Total, amount paid, remaining balance |
| Inventory | Current stock levels + last 100 stock movements (IN / OUT / ADJUSTMENT) |

---

## 5. Outstanding Utang (`frontend/utang.php`)

Receivables dashboard for customer credit / unpaid balances.

| Widget | Description |
| --- | --- |
| Total outstanding | Sum of `total − amount_paid` where balance &gt; 0 |
| Paid vs unpaid | Counts and amounts by `payment_status` (paid / partial / unpaid) + doughnut chart |
| Aging of receivables | Open balances in 0–30, 31–60, 61–90, 90+ day buckets |
| Largest unpaid customers | Top 10 customers by outstanding balance |
| Recently paid accounts | Credit sales fully paid with collection notes |
| Open utang sales | Top 25 open sales with Collect link to sale view |

CSV exports: `utang_summary`, `utang_open`, `utang_customers` via `report_export.php`.

---

## Summary checklist

Already implemented:

- [x] Today's sales
- [x] Monthly sales
- [x] Total stock
- [x] Low stock alert
- [x] Recent sales / purchases
- [x] Today's expenses (dashboard)
- [x] 7-day sales chart
- [x] Date-range sales, purchases, expenses, profit
- [x] Gross profit with COGS (Sales − qty × buying price)
- [x] Gross margin percentage
- [x] Gross profit trend (daily / weekly / monthly)
- [x] Current month vs previous month gross profit
- [x] Highest gross profit day in range
- [x] Profit per rice variety (ranked by gross profit, margin, ₱/kg)
- [x] Fastest vs slowest selling rice (30-day movement, idle stock, overstock)
- [x] Inventory value (cost capital, selling value, potential GP by product/category)
- [x] Low stock forecast (days-to-empty from avg daily sales, reorder suggestions)
- [x] Daily net income (Sales − COGS − Expenses; daily/weekly/monthly; best/worst days)
- [x] Outstanding utang dashboard (aging, debtors, recently paid)
- [x] Daily / weekly / monthly sales trend
- [x] Top selling rice
- [x] Expenses by category
- [x] CSV export (summary, gross profit, net income, profit by variety, inventory movement/value, stock forecast, utang, sales, expenses, top products, inventory)
- [x] Print / PDF via browser print

Not found as dedicated analytics (gaps vs. possible future work):

- Customer purchase history / lifetime value
- Payment-method breakdown (cash / GCash / bank / credit)
- Supplier purchase analytics
- Historical cost locked at time of sale (uses current product buying price)
- Year-over-year or multi-month comparison beyond the current chart period
- Collection date field separate from notes (recently paid uses notes + status)