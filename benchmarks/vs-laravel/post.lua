wrk.method = "POST"
wrk.body = '{"merchantId":"22222222-2222-4222-8222-222222222222","items":[{"sku":"TEA-1","quantity":2,"unitPriceCents":450},{"sku":"MUG","quantity":1,"unitPriceCents":1200}],"note":"leave at door"}'
wrk.headers["Content-Type"] = "application/json"
wrk.headers["X-Tenant-Id"] = "11111111-1111-4111-8111-111111111111"
